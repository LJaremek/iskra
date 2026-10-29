<?php
declare(strict_types=1);

const COUNTDOWN_SECONDS = 5;
const RESULTS_SECONDS = 15;

function quiz(int $id): array {
    $quiz = sql('SELECT * FROM quizzes WHERE id = ?', [$id])->fetch();
    if (!$quiz) fail('Nie znaleziono quizu.', 404);
    return $quiz;
}

function questions(int $id): array {
    $rows = sql('SELECT * FROM questions WHERE quiz_id = ? ORDER BY position, id', [$id])->fetchAll();
    foreach ($rows as &$row) $row['options'] = json_decode($row['options'], true, 512, JSON_THROW_ON_ERROR);
    return $rows;
}

function expire_runs(): void {
    sql("UPDATE runs SET state = 'finished', finished_at = ? WHERE state != 'finished' AND created_at < ?", [time(), time()-43200]);
}

function create_run(int $quizId): int {
    return transaction(function () use ($quizId) {
        expire_runs();
        $quiz = quiz($quizId);
        $questions = questions($quizId);
        if (!$questions) fail('Dodaj przynajmniej jedno pytanie przed rozpoczęciem gry.');
        $used = sql("SELECT code FROM runs WHERE state != 'finished'")->fetchAll(PDO::FETCH_COLUMN);
        $codes = array_values(array_diff(range(100,999), $used));
        if (!$codes) fail('Wszystkie kody są zajęte. Zakończ jedną z aktywnych gier.');
        sql('INSERT INTO runs (quiz_id,title,snapshot,token,code,created_at) VALUES (?,?,?,?,?,?)', [
            $quizId, $quiz['title'], json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            bin2hex(random_bytes(16)), (string)$codes[random_int(0,count($codes)-1)], time()
        ]);
        return (int)db()->lastInsertId();
    });
}

function run_by(string $column, string|int $value): array {
    if (!in_array($column, ['id','token','code'], true)) throw new LogicException('Invalid run key');
    $extra = $column === 'code' ? " AND state != 'finished'" : '';
    $run = sql("SELECT * FROM runs WHERE $column = ?$extra", [$value])->fetch();
    if (!$run) fail('Nie znaleziono aktywnej gry z tym kodem.', 404);
    return $run;
}

function snapshot(array $run): array {
    return json_decode($run['snapshot'], true, 512, JSON_THROW_ON_ERROR);
}

function current_question(array $run): ?array {
    return snapshot($run)[$run['question_index']] ?? null;
}

// started_at oznacza moment odsłonięcia pytania. Przez poprzednie 5 s trwa odliczanie.
// Dzięki temu nie potrzeba dodatkowego stanu ani migracji istniejącej bazy.
function next_question(array $run, float $countdownStart): array {
    $next = (int)$run['question_index'] + 1;
    if ($next >= count(snapshot($run))) {
        sql("UPDATE runs SET state='finished',finished_at=? WHERE id=?", [(int)$countdownStart, $run['id']]);
        $run['state'] = 'finished';
        $run['finished_at'] = (int)$countdownStart;
    } else {
        $start = $countdownStart + COUNTDOWN_SECONDS;
        sql("UPDATE runs SET state='question',question_index=?,started_at=? WHERE id=?", [$next, $start, $run['id']]);
        $run['state'] = 'question';
        $run['question_index'] = $next;
        $run['started_at'] = $start;
    }
    return $run;
}

function clock_due(array $run, float $now): bool {
    if ($run['state'] === 'finished' || $run['state'] === 'lobby') return false;
    $deadline = (float)$run['started_at'] + current_question($run)['duration'];
    if ($run['state'] === 'results') $deadline += RESULTS_SECONDS;
    return $now >= $deadline;
}

// Wywołuj pod blokadą SQLite. Terminy wynikają z czasu serwera, nie z kliknięć hosta.
function advance_clock(array $run): array {
    $now = microtime(true);
    if ($run['state'] !== 'finished' && (int)$run['created_at'] < time()-43200) {
        sql("UPDATE runs SET state='finished',finished_at=? WHERE id=?", [time(), $run['id']]);
        $run['state'] = 'finished';
        return $run;
    }
    // Po powrocie z uśpionej karty nadrabiamy także kilka minionych faz.
    while (clock_due($run, $now)) {
        if ($run['state'] === 'question') {
            sql("UPDATE runs SET state='results' WHERE id=?", [$run['id']]);
            $run['state'] = 'results';
        } else {
            $nextAt = (float)$run['started_at'] + current_question($run)['duration'] + RESULTS_SECONDS;
            $run = next_question($run, $nextAt);
        }
    }
    return $run;
}

function player(array $run): ?array {
    $secret = $_SESSION['players'][$run['id']] ?? null;
    return $secret ? (sql('SELECT * FROM players WHERE run_id = ? AND secret = ?', [$run['id'],hash('sha256',$secret)])->fetch() ?: null) : null;
}

function join_run(string $code, string $name): string {
    $secret = bin2hex(random_bytes(24));
    $result = transaction(function () use ($code,$name,$secret) {
        expire_runs();
        $run = run_by('code',$code);
        if (player($run)) return [$run,null];
        if ($run['state'] !== 'lobby') fail('Ta gra już trwa. Do następnej rozgrywki dołączysz w poczekalni.');
        if ((int)sql('SELECT COUNT(*) FROM players WHERE run_id = ?',[$run['id']])->fetchColumn() >= 200)
            fail('Ta rozgrywka ma już maksymalną liczbę 200 graczy.');
        $key = strtr(strtolower($name),['Ą'=>'ą','Ć'=>'ć','Ę'=>'ę','Ł'=>'ł','Ń'=>'ń','Ó'=>'ó','Ś'=>'ś','Ź'=>'ź','Ż'=>'ż']);
        if (sql('SELECT 1 FROM players WHERE run_id = ? AND name_key = ?',[$run['id'],$key])->fetchColumn())
            fail('Ta nazwa jest już zajęta w tej grze. Wybierz inną.');
        sql('INSERT INTO players (run_id,name,name_key,secret,joined_at) VALUES (?,?,?,?,?)',
            [$run['id'],$name,$key,hash('sha256',$secret),time()]);
        return [$run,$secret];
    });
    if ($result[1]) $_SESSION['players'][$result[0]['id']] = $result[1];
    return $result[0]['token'];
}

function rankings(int $runId, int $questionIndex = -1): array {
    $rows = sql('SELECT p.id,p.name,COALESCE(SUM(a.points),0) AS score,
        COALESCE(SUM(a.correct),0) AS correct,COALESCE(SUM(a.elapsed),0) AS elapsed,
        COALESCE(SUM(CASE WHEN a.question_index=? THEN a.points ELSE 0 END),0) AS round_points
        FROM players p LEFT JOIN answers a ON a.player_id=p.id WHERE p.run_id=?
        GROUP BY p.id ORDER BY score DESC,correct DESC,elapsed ASC,p.id ASC', [$questionIndex,$runId])->fetchAll();
    foreach ($rows as $i=>&$row) {
        $row['rank'] = $i+1;
        $row['score'] = (int)$row['score'];
        $row['correct'] = (int)$row['correct'];
        $row['roundPoints'] = (int)$row['round_points'];
        unset($row['elapsed'], $row['round_points']);
    }
    return $rows;
}

function answer_stats(array $run): array {
    $counts = array_fill(0, count(current_question($run)['options']), 0);
    $rows = sql('SELECT choice,COUNT(*) AS votes FROM answers WHERE run_id=? AND question_index=? GROUP BY choice',
        [$run['id'],$run['question_index']])->fetchAll();
    foreach ($rows as $row) $counts[(int)$row['choice']] = (int)$row['votes'];
    return $counts;
}

function game_state(string $token, bool $host): array {
    $run = run_by('token',$token);
    $me = $host ? null : player($run);
    if (!$host && !$me) fail('Dołącz do gry przez stronę /ng.',403);
    // Zwykły polling tylko czyta. Blokada jest potrzebna dopiero przy zmianie fazy.
    if (clock_due($run, microtime(true)) || ($run['state']!=='finished' && $run['created_at']<time()-43200))
        $run = transaction(fn()=>advance_clock(run_by('token',$token)));

    $now = microtime(true);
    $question = current_question($run);
    $phase = $run['state'];
    if ($phase === 'question' && $now < (float)$run['started_at']) $phase = 'countdown';
    $reveal = in_array($phase, ['results','finished'], true);
    $deadline = match ($phase) {
        'countdown' => (float)$run['started_at'],
        'question' => (float)$run['started_at'] + $question['duration'],
        'results' => (float)$run['started_at'] + $question['duration'] + RESULTS_SECONDS,
        default => null,
    };
    $answer = $me ? (sql('SELECT choice,correct,points FROM answers WHERE player_id=? AND question_index=?',
        [$me['id'],$run['question_index']])->fetch() ?: null) : null;
    $players = sql('SELECT id,name FROM players WHERE run_id=? ORDER BY id',[$run['id']])->fetchAll();
    $answeredCount = (int)sql('SELECT COUNT(*) FROM answers WHERE run_id=? AND question_index=?',
        [$run['id'],$run['question_index']])->fetchColumn();

    $data = [
        'state'=>$phase, 'title'=>$run['title'], 'code'=>$run['code'],
        'index'=>(int)$run['question_index'], 'total'=>count(snapshot($run)),
        'serverTime'=>$now, 'deadline'=>$deadline,
        // Przed końcem odliczania nie wysyłamy nawet treści i zdjęć pytania.
        'question'=>$question && $phase!=='countdown' ? [
            'text'=>$question['text'], 'image'=>$question['image'] ? url($question['image']) : null,
            'options'=>array_map(fn($option) => [
                'text'=>$option['text'], 'image'=>$option['image'] ? url($option['image']) : null,
            ], $question['options']),
            'duration'=>(int)$question['duration']
        ] : null,
        'correct'=>$reveal && $question ? (int)$question['correct'] : null,
        'answered'=>$answer ? (int)$answer['choice'] : null,
        'points'=>$reveal && $answer ? (int)$answer['points'] : null,
        'isCorrect'=>$reveal && $answer ? (bool)$answer['correct'] : null,
        'answeredCount'=>$answeredCount,
        'answerStats'=>$reveal && $question ? answer_stats($run) : null,
        'unansweredCount'=>$reveal ? max(0,count($players)-$answeredCount) : null,
        'me'=>$me ? ['id'=>(int)$me['id'],'name'=>$me['name']] : null,
        'players'=>$players,
    ];
    // Punkty i statystyki pojawiają się dopiero po zamknięciu odpowiedzi.
    if (!in_array($phase,['question','countdown'],true))
        $data['ranking'] = rankings((int)$run['id'], (int)$run['question_index']);
    return $data;
}

function host_action(string $token,string $action,int $expectedIndex): void {
    $error = transaction(function () use ($token,$action,$expectedIndex) {
        $run = advance_clock(run_by('token',$token));
        if ((int)$run['question_index'] !== $expectedIndex)
            return ['Stan gry już się zmienił. Odświeżono widok.',409];
        if ($action === 'finish' && $run['state'] !== 'finished') {
            sql("UPDATE runs SET state='finished',finished_at=? WHERE id=?", [time(),$run['id']]);
            return null;
        }
        if ($action === 'next' && in_array($run['state'],['lobby','results'],true)) {
            if ($run['state'] === 'lobby' && !sql('SELECT 1 FROM players WHERE run_id=?',[$run['id']])->fetchColumn())
                return ['Poczekaj, aż dołączy przynajmniej jeden gracz.',422];
            next_question($run, microtime(true));
            return null;
        }
        return ['Ta akcja nie jest teraz dostępna.',409];
    });
    // Także nieaktualna akcja hosta zatwierdza należne automatyczne przejścia.
    if ($error) fail($error[0],$error[1]);
}

function submit_answer(string $token,int $index,int $choice): void {
    $error = transaction(function () use ($token,$index,$choice) {
        $run = advance_clock(run_by('token',$token));
        $me = player($run);
        if (!$me) return ['Dołącz do gry przez /ng.',403];
        if ($run['state'] !== 'question' || (int)$run['question_index'] !== $index)
            return ['Czas na odpowiedź już minął.',409];
        $now = microtime(true);
        if ($now < (float)$run['started_at']) return ['Pytanie jeszcze się nie rozpoczęło.',409];
        $question = current_question($run);
        if ($choice<0 || $choice>=count($question['options'])) return ['Niepoprawna odpowiedź.',422];
        if (sql('SELECT 1 FROM answers WHERE player_id=? AND question_index=?',[$me['id'],$index])->fetchColumn())
            return ['Odpowiedź została już zapisana.',409];
        $elapsed = $now - (float)$run['started_at'];
        if ($elapsed >= $question['duration']) return ['Czas na odpowiedź już minął.',409];
        $correct = $choice === (int)$question['correct'];
        $points = $correct ? (int)round(1000-500*min(1,$elapsed/$question['duration'])) : 0;
        sql('INSERT INTO answers (run_id,player_id,question_index,choice,correct,points,elapsed) VALUES (?,?,?,?,?,?,?)',
            [$run['id'],$me['id'],$index,$choice,(int)$correct,$points,$elapsed]);
        return null;
    });
    if ($error) fail($error[0],$error[1]);
}
