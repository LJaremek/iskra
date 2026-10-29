<?php
declare(strict_types=1);
const COUNTDOWN_SECONDS = 5;
const RESULTS_SECONDS = 15;

function quiz(int $id): array {
    return store()->find('quizzes', $id) ?? fail('Nie znaleziono quizu.', 404);
}
function questions(int $id): array {
    $rows = store()->filter('questions', fn($q) => $q['quiz_id'] === $id);
    usort($rows, fn($a,$b) => [$a['position'],$a['id']] <=> [$b['position'],$b['id']]);
    return $rows;
}
function run_players(int $id): array {
    return store()->filter('players', fn($p) => $p['run_id'] === $id);
}
function run_answers(int $id, ?int $index = null): array {
    return store()->filter('answers', fn($a) => $a['run_id'] === $id && ($index === null || $a['question_index'] === $index));
}
function question_answers(int $id, int $index): array {
    $players = array_column(run_players($id), null, 'id');
    $rows = run_answers($id, $index);
    foreach ($rows as &$row) $row['name'] = $players[$row['player_id']]['name'];
    usort($rows, fn($a,$b) => [$b['points'],$a['elapsed']] <=> [$a['points'],$b['elapsed']]);
    return $rows;
}
function delete_quiz(int $id): void {
    transaction(function () use ($id) {
        store()->delete('quizzes', fn($q) => $q['id'] === $id);
        store()->delete('questions', fn($q) => $q['quiz_id'] === $id);
        foreach (store()->filter('runs', fn($r) => $r['quiz_id'] === $id) as $run)
            store()->update('runs', $run['id'], ['quiz_id'=>null]);
    });
}
function expire_runs(): void {
    transaction(function () {
        foreach (store()->all('runs') as $run) {
            if ($run['state'] !== 'finished' && $run['created_at'] < time()-43200)
                store()->update('runs', $run['id'], ['state'=>'finished','finished_at'=>time()]);
        }
    });
}
function create_run(int $quizId): int {
    return transaction(function () use ($quizId) {
        expire_runs();
        $quiz = quiz($quizId);
        $questions = questions($quizId);
        if (!$questions) fail('Dodaj przynajmniej jedno pytanie przed rozpoczęciem gry.');
        $active = store()->filter('runs', fn($r) => $r['state'] !== 'finished');
        $codes = array_values(array_diff(range(100,999), array_column($active,'code')));
        if (!$codes) fail('Wszystkie kody są zajęte. Zakończ jedną z aktywnych gier.');
        return store()->insert('runs', [
            'quiz_id'=>$quizId,'title'=>$quiz['title'],'snapshot'=>$questions,
            'token'=>bin2hex(random_bytes(16)), 'code'=>(string)$codes[random_int(0,count($codes)-1)],
            'state'=>'lobby','question_index'=>-1,'started_at'=>null,'created_at'=>time(),'finished_at'=>null,
        ]);
    });
}
function run_by(string $column, string|int $value): array {
    if (!in_array($column,['id','token','code'],true)) throw new LogicException('Invalid run key');
    $rows = store()->filter('runs', fn($r) => (string)$r[$column] === (string)$value
        && ($column !== 'code' || $r['state'] !== 'finished'));
    return $rows[0] ?? fail('Nie znaleziono aktywnej gry z tym kodem.',404);
}
// Starsze pytania i kopie rozgrywek mają tylko jedno pole correct.
function correct_choices(array $question): array {
    return $question['correct_choices'] ?? [$question['correct']];
}
function snapshot(array $run): array { return $run['snapshot']; }
function current_question(array $run): ?array { return snapshot($run)[$run['question_index']] ?? null; }
function next_question(array $run, float $countdownStart): array {
    $next = $run['question_index'] + 1;
    $changes = $next >= count(snapshot($run))
        ? ['state'=>'finished','finished_at'=>(int)$countdownStart]
        : ['state'=>'question','question_index'=>$next,'started_at'=>$countdownStart+COUNTDOWN_SECONDS];
    store()->update('runs', $run['id'], $changes);
    return array_replace($run, $changes);
}
function clock_due(array $run, float $now): bool {
    if (in_array($run['state'],['finished','lobby'],true)) return false;
    $deadline = $run['started_at'] + current_question($run)['duration'];
    if ($run['state'] === 'results') $deadline += RESULTS_SECONDS;
    return $now >= $deadline;
}
// Wywołuj pod wyłączną blokadą zapisu. Terminy wyznacza serwer.
function advance_clock(array $run): array {
    $now = microtime(true);
    if ($run['state'] !== 'finished' && $run['created_at'] < time()-43200) {
        store()->update('runs', $run['id'], ['state'=>'finished','finished_at'=>time()]);
        $run['state'] = 'finished';
        return $run;
    }
    while (clock_due($run,$now)) {
        if ($run['state'] === 'question') {
            store()->update('runs',$run['id'],['state'=>'results']);
            $run['state'] = 'results';
        } else {
            $run = next_question($run,$run['started_at']+current_question($run)['duration']+RESULTS_SECONDS);
        }
    }
    return $run;
}
function player(array $run): ?array {
    $secret = $_SESSION['players'][$run['id']] ?? null;
    if (!$secret) return null;
    $rows = store()->filter('players', fn($p) => $p['run_id'] === $run['id'] && hash_equals($p['secret'],hash('sha256',$secret)));
    return $rows[0] ?? null;
}
function join_run(string $code, string $name): string {
    $secret = bin2hex(random_bytes(24));
    $result = transaction(function () use ($code,$name,$secret) {
        expire_runs();
        $run = run_by('code',$code);
        if (player($run)) return [$run,null];
        if ($run['state'] !== 'lobby') fail('Ta gra już trwa. Do następnej rozgrywki dołączysz w poczekalni.');
        $players = run_players($run['id']);
        if (count($players) >= 200) fail('Ta rozgrywka ma już maksymalną liczbę 200 graczy.');
        $key = strtr(strtolower($name),['Ą'=>'ą','Ć'=>'ć','Ę'=>'ę','Ł'=>'ł','Ń'=>'ń','Ó'=>'ó','Ś'=>'ś','Ź'=>'ź','Ż'=>'ż']);
        if (in_array($key,array_column($players,'name_key'),true)) fail('Ta nazwa jest już zajęta w tej grze. Wybierz inną.');
        store()->insert('players',['run_id'=>$run['id'],'name'=>$name,'name_key'=>$key,'secret'=>hash('sha256',$secret),'joined_at'=>time()]);
        return [$run,$secret];
    });
    if ($result[1]) $_SESSION['players'][$result[0]['id']] = $result[1];
    return $result[0]['token'];
}
function rankings(int $runId, int $questionIndex = -1): array {
    $rows = [];
    foreach (run_players($runId) as $p) $rows[$p['id']] = [
        'id'=>$p['id'],'name'=>$p['name'],'score'=>0,'correct'=>0,'elapsed'=>0,'roundPoints'=>0,
    ];
    foreach (run_answers($runId) as $answer) {
        $id = $answer['player_id'];
        $rows[$id]['score'] += $answer['points'];
        $rows[$id]['correct'] += (int)$answer['correct'];
        $rows[$id]['elapsed'] += $answer['elapsed'];
        if ($answer['question_index'] === $questionIndex) $rows[$id]['roundPoints'] += $answer['points'];
    }
    $rows = array_values($rows);
    usort($rows, fn($a,$b) => [$b['score'],$b['correct'],$a['elapsed'],$a['id']] <=> [$a['score'],$a['correct'],$b['elapsed'],$b['id']]);
    foreach ($rows as $i=>&$row) { $row['rank']=$i+1; unset($row['elapsed']); }
    return $rows;
}
function answer_stats(array $run): array {
    $counts = array_fill(0,count(current_question($run)['options']),0);
    foreach (run_answers($run['id'],$run['question_index']) as $answer) $counts[$answer['choice']]++;
    return $counts;
}
function game_state(string $token, bool $host): array {
    $run = run_by('token',$token);
    $me = $host ? null : player($run);
    if (!$host && !$me) fail('Dołącz do gry przez stronę /ng.',403);
    if (clock_due($run,microtime(true)) || ($run['state']!=='finished' && $run['created_at']<time()-43200))
        $run = transaction(fn()=>advance_clock(run_by('token',$token)));
    $now = microtime(true);
    $question = current_question($run);
    $phase = $run['state'];
    if ($phase === 'question' && $now < $run['started_at']) $phase = 'countdown';
    $reveal = in_array($phase,['results','finished'],true);
    $deadline = match ($phase) {
        'countdown' => $run['started_at'],
        'question' => $run['started_at']+$question['duration'],
        'results' => $run['started_at']+$question['duration']+RESULTS_SECONDS,
        default => null,
    };
    $answers = run_answers($run['id'],$run['question_index']);
    $answer = null;
    foreach ($answers as $a) if ($me && $a['player_id'] === $me['id']) $answer=$a;
    $players = array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name']],run_players($run['id']));
    $data = [
        'state'=>$phase,'title'=>$run['title'],'code'=>$run['code'],'index'=>$run['question_index'],
        'total'=>count(snapshot($run)),'serverTime'=>$now,'deadline'=>$deadline,
        'question'=>$question && $phase!=='countdown' ? [
            'text'=>$question['text'],'image'=>$question['image'] ? asset_url($question['image']) : null,
            'options'=>array_map(fn($o)=>['text'=>$o['text'],'image'=>$o['image'] ? asset_url($o['image']) : null],$question['options']),
            'duration'=>$question['duration'],
        ] : null,
        'correct'=>$reveal && $question ? $question['correct'] : null,
        'correctChoices'=>$reveal && $question ? correct_choices($question) : null,
        'answered'=>$answer['choice'] ?? null,'points'=>$reveal && $answer ? $answer['points'] : null,
        'isCorrect'=>$reveal && $answer ? (bool)$answer['correct'] : null,
        'answeredCount'=>count($answers),'answerStats'=>$reveal && $question ? answer_stats($run) : null,
        'unansweredCount'=>$reveal ? max(0,count($players)-count($answers)) : null,
        'me'=>$me ? ['id'=>$me['id'],'name'=>$me['name']] : null,'players'=>$players,
    ];
    if (!in_array($phase,['question','countdown'],true)) $data['ranking']=rankings($run['id'],$run['question_index']);
    return $data;
}
function host_action(string $token,string $action,int $expectedIndex): void {
    $error = transaction(function () use ($token,$action,$expectedIndex) {
        $run = advance_clock(run_by('token',$token));
        if ($run['question_index'] !== $expectedIndex) return ['Stan gry już się zmienił. Odświeżono widok.',409];
        if ($action === 'finish' && $run['state'] !== 'finished') {
            store()->update('runs',$run['id'],['state'=>'finished','finished_at'=>time()]);
            return null;
        }
        if ($action === 'next' && in_array($run['state'],['lobby','results'],true)) {
            if ($run['state']==='lobby' && !run_players($run['id'])) return ['Poczekaj, aż dołączy przynajmniej jeden gracz.',422];
            next_question($run,microtime(true));
            return null;
        }
        return ['Ta akcja nie jest teraz dostępna.',409];
    });
    if ($error) fail($error[0],$error[1]);
}
function submit_answer(string $token,int $index,int $choice): void {
    $error = transaction(function () use ($token,$index,$choice) {
        $run = advance_clock(run_by('token',$token));
        $me = player($run);
        if (!$me) return ['Dołącz do gry przez /ng.',403];
        if ($run['state']!=='question' || $run['question_index']!==$index) return ['Czas na odpowiedź już minął.',409];
        $now = microtime(true);
        if ($now < $run['started_at']) return ['Pytanie jeszcze się nie rozpoczęło.',409];
        $question = current_question($run);
        if ($choice<0 || $choice>=count($question['options'])) return ['Niepoprawna odpowiedź.',422];
        foreach (run_answers($run['id'],$index) as $answer)
            if ($answer['player_id'] === $me['id']) return ['Odpowiedź została już zapisana.',409];
        $elapsed = $now-$run['started_at'];
        if ($elapsed >= $question['duration']) return ['Czas na odpowiedź już minął.',409];
        $correct = in_array($choice,correct_choices($question),true);
        $points = $correct ? (int)round(1000-500*min(1,$elapsed/$question['duration'])) : 0;
        store()->insert('answers',['run_id'=>$run['id'],'player_id'=>$me['id'],'question_index'=>$index,
            'choice'=>$choice,'correct'=>(int)$correct,'points'=>$points,'elapsed'=>$elapsed]);
        return null;
    });
    if ($error) fail($error[0],$error[1]);
}
