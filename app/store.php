<?php
declare(strict_types=1);

// Jeden prywatny plik JSON. Osobny plik blokady pozostaje stały przy atomowej zamianie danych.
final class JsonStore {
    public const HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n";
    private const TABLES = ['admins','quizzes','questions','runs','players','answers','limits'];
    private ?array $data = null;
    private bool $writing = false;
    private bool $dirty = false;
    private bool $shared = false;

    public function __construct(private string $file) {
        $folder = dirname($file);
        if ((!is_dir($folder) && !@mkdir($folder, 0700, true)) || !is_writable($folder)
            || (is_file($file) && !is_writable($file))) {
            fail('PHP nie może zapisywać danych. Nadaj użytkownikowi PHP prawo zapisu do katalogu storage/ i jego plików.', 500);
        }
        // Setgid + zapis grupowy na katalogu włącza trwały dostęp zespołu.
        $this->shared = (fileperms($folder) & 02020) === 02020;
    }

    private function permissions(string $path): void {
        clearstatcache(true,$path);
        $mode = $this->shared ? 0660 : 0600;
        if ($this->shared) {
            $group = filegroup(dirname($this->file));
            if (filegroup($path) !== $group && !@chgrp($path,$group))
                throw new RuntimeException('Nie można ustawić wspólnej grupy danych. PHP i deweloperzy muszą należeć do grupy katalogu storage/.');
        }
        clearstatcache(true,$path);
        // Inny członek grupy nie musi być właścicielem poprawnie ustawionego pliku.
        if ((fileperms($path) & 0777) !== $mode && !@chmod($path,$mode))
            throw new RuntimeException('Nie można ustawić uprawnień danych. Uruchom skrypt permissions jako właściciel plików lub poproś administratora hostingu.');
    }

    private function lock(int $mode) {
        $handle = @fopen($this->file . '.lock', 'c+b');
        if (!$handle || !flock($handle, $mode)) {
            if ($handle) fclose($handle);
            throw new RuntimeException('Nie można zablokować pliku danych.');
        }
        try {
            $this->permissions($this->file . '.lock');
            if ($this->shared && is_file($this->file)) $this->permissions($this->file);
            return $handle;
        } catch (Throwable $error) {
            flock($handle,LOCK_UN);
            fclose($handle);
            throw $error;
        }
    }

    private function load(): array {
        if (!is_file($this->file)) {
            $data = ['version'=>1, 'next_ids'=>[]];
            foreach (self::TABLES as $table) $data[$table] = [];
            return $data;
        }
        $contents = file_get_contents($this->file);
        if ($contents === false) throw new RuntimeException('Nie można odczytać pliku danych.');
        if (!str_starts_with($contents,self::HEADER)) throw new RuntimeException('Niepoprawny nagłówek danych.');
        $data = json_decode(substr($contents,strlen(self::HEADER)), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['next_ids'] ?? null))
            throw new RuntimeException('Niepoprawny format danych. Przywróć kopię zapasową.');
        foreach (self::TABLES as $table) {
            if (!is_array($data[$table] ?? null)) throw new RuntimeException('Niekompletny plik danych.');
        }
        return $data;
    }

    private function read(): array {
        if ($this->data !== null) return $this->data;
        $lock = $this->lock(LOCK_SH);
        try { return $this->data = $this->load(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function save(): void {
        $encoded = self::HEADER . json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = dirname($this->file).'/.quiz-'.bin2hex(random_bytes(16)).'.php';
        try {
            $handle = fopen($temporary, 'xb');
            if (!$handle) throw new RuntimeException('Nie można zapisać danych.');
            try {
                $this->permissions($temporary);
                $offset = 0;
                while ($offset < strlen($encoded)) {
                    $written = fwrite($handle, substr($encoded, $offset));
                    if (!$written) throw new RuntimeException('Niepełny zapis danych.');
                    $offset += $written;
                }
                if (!fflush($handle)) throw new RuntimeException('Nie można zakończyć zapisu danych.');
                if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Nie można utrwalić danych.');
            } finally { fclose($handle); }
            if (!rename($temporary, $this->file)) throw new RuntimeException('Nie można podmienić pliku danych.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    public function transaction(callable $callback): mixed {
        if ($this->writing) return $callback();
        $lock = $this->lock(LOCK_EX);
        $before = $this->data;
        try {
            // Zawsze odczytuj ponownie po zdobyciu blokady, aby nie nadpisać cudzej odpowiedzi.
            $this->data = $this->load();
            $before = $this->data;
            $this->writing = true;
            $this->dirty = false;
            $result = $callback();
            if ($this->dirty) $this->save();
            return $result;
        } catch (Throwable $error) {
            $this->data = $before;
            throw $error;
        } finally {
            $this->writing = false;
            $this->dirty = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function all(string $table): array {
        if (!in_array($table, self::TABLES, true)) throw new LogicException('Nieznany zbiór danych.');
        return array_values($this->read()[$table]);
    }

    public function find(string $table, int $id): ?array {
        $this->all($table);
        return $this->data[$table][$id] ?? null;
    }

    public function filter(string $table, callable $matches): array {
        return array_values(array_filter($this->all($table), $matches));
    }

    public function insert(string $table, array $row, ?int $id = null): int {
        return $this->transaction(function () use ($table, $row, $id) {
            $this->all($table);
            $id ??= ($this->data['next_ids'][$table] ?? 0) + 1;
            if (isset($this->data[$table][$id])) throw new RuntimeException('Powtórzony identyfikator.');
            $this->data['next_ids'][$table] = max($id, $this->data['next_ids'][$table] ?? 0);
            $this->data[$table][$id] = ['id'=>$id] + $row;
            $this->dirty = true;
            return $id;
        });
    }

    public function update(string $table, int $id, array $changes): void {
        $this->transaction(function () use ($table, $id, $changes) {
            $old = $this->find($table, $id);
            if (!$old) fail('Nie znaleziono rekordu.', 404);
            $new = array_replace($old, $changes);
            $new['id'] = $id;
            if ($new !== $old) {
                $this->data[$table][$id] = $new;
                $this->dirty = true;
            }
        });
    }

    public function delete(string $table, callable $matches): void {
        $this->transaction(function () use ($table, $matches) {
            foreach ($this->filter($table, $matches) as $row) {
                unset($this->data[$table][$row['id']]);
                $this->dirty = true;
            }
        });
    }
}
