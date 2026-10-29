<?php
require dirname(__DIR__) . '/app/store.php';
function fail(string $message,int $status=500): never { throw new RuntimeException($message,$status); }
$store = new JsonStore($argv[1]);
if (($argv[2] ?? '') === 'rollback') {
    try {
        $store->transaction(function () use ($store) {
            $store->insert('quizzes',['title'=>'Do cofnięcia','description'=>'','created_at'=>time()]);
            throw new RuntimeException('expected rollback');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'expected rollback') throw $error;
    }
} elseif (($argv[2] ?? '') === 'read') {
    $store->all('quizzes');
} else {
    for ($i=0;$i<40;$i++) $store->insert('quizzes',['title'=>'Worker '.getmypid().':'.$i,'description'=>'','created_at'=>time()]);
}
