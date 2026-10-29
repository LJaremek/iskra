<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/game.php';
require dirname(__DIR__) . '/app/views.php';

$path = route_path();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) fail('Niedozwolona metoda.',405);
    if ($post) csrf();
    if (str_starts_with($path, '/api/')) {
        if (!preg_match('~^/api/game/([a-f0-9]{32})(?:/(answer|host))?$~', $path, $m)) fail('Nie znaleziono endpointu.',404);
        $token=$m[1]; $action=$m[2] ?? '';
        if (!$post && $action==='') {
            $host=($_GET['host'] ?? '')==='1';
            if ($host) admin();
            $state=game_state($token,$host);
            session_write_close();
            json_response($state);
        }
        if ($post && $action==='answer') {
            submit_answer($token,integer('index',0,999),integer('choice',0,3));
            json_response(['ok'=>true]);
        }
        if ($post && $action==='host') {
            admin(); host_action($token,input('action',10),integer('index',-1,999));
            json_response(['ok'=>true]);
        }
        fail('Niedozwolona metoda.',405);
    }
    $hasAdmin=(bool)store()->find('admins',1);
    if ($path==='/admin/setup') {
        if ($hasAdmin) redirect('/admin/login');
        if ($post) {
            limited('setup',10,900);
            $username=input('username',40); $password=input('password',200);
            if (preg_match_all('/./us',$password)<12 || strlen($password)>72) fail('Hasło musi mieć przynajmniej 12 znaków i zajmować najwyżej 72 bajty.');
            transaction(function () use ($username,$password) {
                if (store()->find('admins',1)) fail('Konto administratora jest już utworzone.',409);
                store()->insert('admins',['username'=>$username,'password'=>password_hash($password,PASSWORD_DEFAULT)],1);
            });
            session_regenerate_id(true); $_SESSION['admin']=true;
            flash('Konto gotowe. Czas na pierwszy quiz!'); redirect('/admin');
        }
        page('Pierwszy krok', 'auth', ['setup'=>true]); exit;
    }
    if ($path==='/admin/login') {
        if (!$hasAdmin) redirect('/admin/setup');
        if (!empty($_SESSION['admin'])) redirect('/admin');
        if ($post) {
            limited('login',10,900);
            $record=store()->find('admins',1);
            $password=input('password',200);
            if (strlen($password)>72 || !hash_equals($record['username'],input('username',40)) || !password_verify($password,$record['password']))
                fail('Niepoprawny login lub hasło.',401);
            session_regenerate_id(true); $_SESSION['admin']=true; redirect('/admin');
        }
        page('Zaloguj się', 'auth', ['setup'=>false]); exit;
    }
    if ($path==='/admin/logout' && $post) {
        unset($_SESSION['admin']); session_regenerate_id(true); redirect('/admin/login');
    }
    if ($path==='/' || $path==='/ng') {
        if ($post) {
            limited('join',30,60); $code=input('code',3);
            if (!preg_match('/^[1-9][0-9]{2}$/D',$code)) fail('Kod gry składa się z 3 cyfr.');
            $name=input('name',24);
            if (preg_match('/[\x00-\x1F\x7F]/u',$name)) fail('Wpisz nazwę bez znaków sterujących.');
            redirect('/play/' . join_run($code,$name));
        }
        page('Dołącz do gry', 'join'); exit;
    }
    if (preg_match('~^/play/([a-f0-9]{32})$~',$path,$m) && !$post) {
        $run=run_by('token',$m[1]);
        if (!player($run)) redirect('/ng');
        page($run['title'],'live',['run'=>$run,'host'=>false]); exit;
    }
    if (str_starts_with($path,'/admin')) {
        if (!$hasAdmin) redirect('/admin/setup');
        admin();
        if ($path==='/admin' && !$post) {
            expire_runs();
            $quizzes=array_reverse(store()->all('quizzes'));
            foreach ($quizzes as &$q) {
                $q['question_count']=count(questions($q['id']));
                $q['run_count']=count(store()->filter('runs',fn($r)=>$r['quiz_id']===$q['id']));
            }
            unset($q);
            $active=array_reverse(store()->filter('runs',fn($r)=>$r['state']!=='finished'));
            foreach ($active as &$r) $r['player_count']=count(run_players($r['id']));
            unset($r);
            page('Twoje quizy','dashboard',['quizzes'=>$quizzes,'active'=>$active]); exit;
        }
        if ($path==='/admin/quiz/create' && $post) {
            $id=store()->insert('quizzes',['title'=>input('title',100),'description'=>input('description',300,false),'created_at'=>time()]);
            redirect('/admin/quiz/'.$id);
        }
        if (preg_match('~^/admin/quiz/(\d+)(?:/(save|delete|start))?$~',$path,$m)) {
            $id=(int)$m[1]; $quiz=quiz($id); $action=$m[2] ?? '';
            if (!$post && $action==='') { page($quiz['title'],'editor',['quiz'=>$quiz,'questions'=>questions($id)]); exit; }
            if ($post && $action==='save') {
                store()->update('quizzes',$id,['title'=>input('title',100),'description'=>input('description',300,false)]);
                flash('Zapisano quiz.'); redirect('/admin/quiz/'.$id);
            }
            if ($post && $action==='delete') {
                delete_quiz($id); flash('Usunięto quiz. Historia rozgrywek została zachowana.'); redirect('/admin');
            }
            if ($post && $action==='start') redirect('/admin/host/'.create_run($id));
        }
        if (preg_match('~^/admin/quiz/(\d+)/question/(new|save|\d+)(?:/(delete|move))?$~',$path,$m)) {
            $quizId=(int)$m[1]; $quiz=quiz($quizId); $part=$m[2]; $action=$m[3] ?? '';
            $question=null;
            if (preg_match('/^\d+$/D',$part)) {
                $question=store()->find('questions',(int)$part);
                if ($question && $question['quiz_id'] !== $quizId) $question=null;
                if (!$question) fail('Nie znaleziono pytania.',404);
            }
            if (!$post && ($part==='new' || $question)) { page($question?'Edytuj pytanie':'Nowe pytanie','question',['quiz'=>$quiz,'question'=>$question]); exit; }
            if ($post && $part==='save') {
                $qid=integer('id',0,PHP_INT_MAX);
                $old=$qid ? store()->find('questions',$qid) : null;
                if ($old && $old['quiz_id'] !== $quizId) $old=null;
                if ($qid && !$old) fail('Nie znaleziono pytania.',404);
                $oldOptions=$old['options'] ?? [];
                $count=integer('count',2,4); $correct=integer('correct',0,$count-1); $duration=integer('duration',5,180);
                $text=input('text',500,false);
                $image=upload('image',isset($_POST['remove_image']) ? null : ($old['image'] ?? null));
                if ($text==='' && !$image) fail('Dodaj treść pytania lub zdjęcie.');
                $options=[];
                for ($i=0;$i<$count;$i++) {
                    $optionText=input('option_'.$i,200,false);
                    $optionImage=upload('option_image_'.$i,isset($_POST['remove_option_'.$i]) ? null : ($oldOptions[$i]['image'] ?? null));
                    if ($optionText==='' && !$optionImage) fail('Każda odpowiedź musi zawierać tekst lub zdjęcie.');
                    $options[]=['text'=>$optionText,'image'=>$optionImage];
                }
                $values=['text'=>$text,'image'=>$image,'duration'=>$duration,'options'=>$options,'correct'=>$correct];
                transaction(function () use ($old,$qid,$quizId,$values) {
                    if ($old) store()->update('questions',$qid,$values);
                    else {
                        $positions=array_column(questions($quizId),'position');
                        store()->insert('questions',$values+['quiz_id'=>$quizId,'position'=>($positions ? max($positions) : 0)+1]);
                    }
                });
                flash('Pytanie zapisane.'); redirect('/admin/quiz/'.$quizId);
            }
            if ($post && $question && $action==='delete') {
                store()->delete('questions',fn($q)=>$q['id']===$question['id'] && $q['quiz_id']===$quizId); flash('Usunięto pytanie.'); redirect('/admin/quiz/'.$quizId);
            }
            if ($post && $question && $action==='move') {
                $direction=integer('direction',-1,1);
                transaction(function () use ($quizId,$question,$direction) {
                    $all=questions($quizId); $ids=array_column($all,'id'); $at=array_search($question['id'],$ids); $to=$at+$direction;
                    if (isset($all[$to])) { [$all[$at],$all[$to]]=[$all[$to],$all[$at]]; }
                    foreach ($all as $i=>$q) store()->update('questions',$q['id'],['position'=>$i+1]);
                });
                redirect('/admin/quiz/'.$quizId);
            }
        }
        if (preg_match('~^/admin/host/(\d+)$~',$path,$m) && !$post) {
            $run=run_by('id',(int)$m[1]); page($run['title'],'live',['run'=>$run,'host'=>true]); exit;
        }
        if ($path==='/admin/history' && !$post) {
            expire_runs();
            $quizId=isset($_GET['quiz']) && is_string($_GET['quiz']) && preg_match('/^\d+$/D',$_GET['quiz']) ? (int)$_GET['quiz'] : null;
            $runs=array_slice(array_reverse(store()->filter('runs',fn($r)=>$quizId===null || $r['quiz_id']===$quizId)),0,200);
            foreach ($runs as &$r) $r['player_count']=count(run_players($r['id']));
            unset($r);
            page('Historia rozgrywek','history',['runs'=>$runs]); exit;
        }
        if (preg_match('~^/admin/run/(\d+)$~',$path,$m) && !$post) {
            $run=run_by('id',(int)$m[1]);
            $run=transaction(fn()=>advance_clock($run));
            page('Wyniki rozgrywki','report',['run'=>$run,'ranking'=>rankings((int)$run['id'])]); exit;
        }
    }
    fail('Nie znaleziono strony.',404);
} catch (AppError $error) {
    $status=$error->getCode() ?: 422;
    if (str_starts_with($path,'/api/')) json_response(['error'=>$error->getMessage()],$status);
    http_response_code($status); page('Coś wymaga poprawki','error',['message'=>$error->getMessage(),'status'=>$status]);
} catch (Throwable $error) {
    error_log((string)$error);
    $message='Nie udało się wykonać operacji. Spróbuj ponownie. Administrator może sprawdzić dziennik błędów PHP.';
    if (str_starts_with($path,'/api/')) json_response(['error'=>$message],500);
    http_response_code(500); page('Błąd serwera','error',['message'=>$message,'status'=>500]);
}

function integer(string $key,int $min,int $max): int {
    $raw=$_POST[$key] ?? null;
    if (!is_string($raw) || !preg_match('/^-?\d+$/D',$raw)) fail('Niepoprawna wartość liczbowa.');
    $value=filter_var($raw,FILTER_VALIDATE_INT);
    if ($value===false || $value<$min || $value>$max) fail('Wartość musi być pomiędzy '.$min.' a '.$max.'.');
    return $value;
}
