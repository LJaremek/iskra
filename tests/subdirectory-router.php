<?php
// Symulacja Apache: z prefiksem lub bez i z wyłączonym przepisywaniem URL-i.
$prefix = getenv('QUIZ_TEST_PREFIX') ?: '';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($prefix !== '' && $path !== $prefix && !str_starts_with($path,$prefix.'/')) {
    http_response_code(404);
    return;
}
$relative = substr($path,strlen($prefix));
$public = realpath(dirname(__DIR__).'/public');
$file = realpath($public.$relative);
if ($file && str_starts_with($file,$public.DIRECTORY_SEPARATOR) && is_file($file)
    && !in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['php','phtml','phar'],true)) {
    $types=['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml',
        'png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif'];
    header('Content-Type: '.($types[strtolower(pathinfo($file,PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return;
}
if (in_array($relative,['/ng','/ng/','/ng/index.php'],true)) {
    $_SERVER['SCRIPT_NAME']=$prefix.'/ng/index.php';
    require $public.'/ng/index.php';
    return;
}
if (getenv('QUIZ_PRETTY_URLS') !== '1' && !in_array($relative,['','/','/index.php'],true)) {
    http_response_code(404);
    return;
}
$_SERVER['SCRIPT_NAME']=$prefix.'/index.php';
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME']=$public.'/index.php';
require $public.'/index.php';
