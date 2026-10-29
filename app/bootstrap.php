<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Warsaw');
ini_set('session.use_strict_mode', '1');
session_name('iskra_session');
session_set_cookie_params([
    'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax', 'path' => base_path() . '/',
]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

// Apache podaje tutaj np. /~user/quiz/public/index.php.
// Serwer lokalny php -S korzysta z katalogu głównego i nie potrzebuje prefiksu.
function base_path(): string {
    static $base;
    if ($base !== null) return $base;
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base = str_ends_with($script, '/index.php') ? rtrim(dirname($script), '/') : '';
    return $base === '.' ? '' : $base;
}

function url(string $path): string {
    return base_path() . '/' . ltrim($path, '/');
}

function route_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/')))
        $path = substr($path, strlen($base));
    $path = rtrim($path, '/') ?: '/';
    return $path === '/index.php' ? '/' : $path;
}

function db(): PDO {
    static $db;
    if ($db) return $db;
    if (!class_exists('PDO') || !extension_loaded('pdo_sqlite'))
        fail('Na serwerze brakuje rozszerzenia PHP pdo_sqlite. Włącz obsługę SQLite w PHP obsługującym stronę.', 500);
    $file = getenv('QUIZ_DB') ?: dirname(__DIR__) . '/storage/quiz.sqlite';
    $folder = dirname($file);
    if ((!is_dir($folder) && !@mkdir($folder, 0700, true)) || !is_writable($folder)
        || (is_file($file) && !is_writable($file)))
        fail('PHP nie może zapisywać bazy danych. Nadaj użytkownikowi PHP prawo zapisu do katalogu bazy (domyślnie storage/) i jej pliku.', 500);
    $db = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() === 0) {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY CHECK (id = 1), username TEXT NOT NULL, password TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS quizzes (id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS questions (
 id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
 position INTEGER NOT NULL, text TEXT NOT NULL, image TEXT, duration INTEGER NOT NULL CHECK (duration BETWEEN 5 AND 180),
 options TEXT NOT NULL, correct INTEGER NOT NULL CHECK (correct BETWEEN 0 AND 3)
);
CREATE TABLE IF NOT EXISTS runs (
 id INTEGER PRIMARY KEY, quiz_id INTEGER REFERENCES quizzes(id) ON DELETE SET NULL,
 title TEXT NOT NULL, snapshot TEXT NOT NULL, token TEXT NOT NULL UNIQUE, code TEXT NOT NULL,
 state TEXT NOT NULL DEFAULT 'lobby' CHECK (state IN ('lobby','question','results','finished')),
 question_index INTEGER NOT NULL DEFAULT -1, started_at REAL, created_at INTEGER NOT NULL, finished_at INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS active_code ON runs(code) WHERE state != 'finished';
CREATE TABLE IF NOT EXISTS players (
 id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
 name TEXT NOT NULL, name_key TEXT NOT NULL, secret TEXT NOT NULL UNIQUE, joined_at INTEGER NOT NULL, UNIQUE (run_id, name_key)
);
CREATE INDEX IF NOT EXISTS players_run ON players(run_id);
CREATE TABLE IF NOT EXISTS answers (
 id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
 player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE, question_index INTEGER NOT NULL,
 choice INTEGER NOT NULL, correct INTEGER NOT NULL, points INTEGER NOT NULL, elapsed REAL NOT NULL, UNIQUE (player_id, question_index)
);
CREATE INDEX IF NOT EXISTS answers_run ON answers(run_id, question_index);
CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);
PRAGMA user_version = 1;
SQL);
        @chmod($file, 0600);
    }
    return $db;
}
function sql(string $query, array $params = []): PDOStatement {
    $stmt = db()->prepare($query); $stmt->execute($params); return $stmt;
}
function transaction(callable $callback): mixed {
    db()->exec('BEGIN IMMEDIATE');
    try { $result = $callback(); db()->exec('COMMIT'); return $result; }
    catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . url($url), true, 303); exit; }
function json_response(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
class AppError extends RuntimeException {}
function fail(string $message, int $status = 422): never { throw new AppError($message, $status); }
function csrf(): void {
    $value = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!is_string($value) || !hash_equals($_SESSION['csrf'], $value))
        fail('Sesja wygasła. Odśwież stronę i spróbuj ponownie.', 403);
}
function admin(): void {
    if (empty($_SESSION['admin'])) {
        if (str_starts_with(route_path(), '/api/')) fail('Zaloguj się ponownie.', 401);
        redirect('/admin/login');
    }
}
function limited(string $scope, int $maximum, int $seconds): void {
    $key = hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $allowed = transaction(function () use ($key, $maximum, $seconds) {
        sql('DELETE FROM limits WHERE expires < ?', [time()]);
        $row = sql('SELECT * FROM limits WHERE key = ?', [$key])->fetch();
        if (!$row) { sql('INSERT INTO limits VALUES (?, 1, ?)', [$key, time() + $seconds]); return true; }
        if ($row['hits'] >= $maximum) return false;
        sql('UPDATE limits SET hits = hits + 1 WHERE key = ?', [$key]); return true;
    });
    if (!$allowed) fail('Zbyt wiele prób. Odczekaj chwilę i spróbuj ponownie.', 429);
}
function input(string $key, int $max, bool $required = true): string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) fail('Niepoprawne dane formularza.');
    $value = trim($raw);
    if (!preg_match('//u', $value)) fail('Niepoprawne kodowanie tekstu.');
    if (($required && $value === '') || preg_match_all('/./us', $value) > $max)
        fail('Uzupełnij pole poprawnie (maksymalnie ' . $max . ' znaków).');
    return $value;
}
function upload(string $field, ?string $previous = null): ?string {
    $file = $_FILES[$field] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) return $previous;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024)
        fail('Zdjęcie może mieć maksymalnie 5 MB. Sprawdź też limit uploadu PHP.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
    $size = @getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || !$size || $size[0] > 6000 || $size[1] > 6000)
        fail('Wybierz zdjęcie JPG, PNG, WebP lub GIF o wymiarach do 6000 × 6000 pikseli.');
    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $folder = dirname(__DIR__) . '/public/uploads';
    if (!is_dir($folder)) mkdir($folder, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name))
        throw new RuntimeException('Nie udało się zapisać zdjęcia. Sprawdź uprawnienia public/uploads.');
    @chmod($folder . '/' . $name, 0644);
    return '/uploads/' . $name;
}
function flash(string $message): void { $_SESSION['flash'] = $message; }
