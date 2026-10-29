<?php
// Fizyczny endpoint dołączania działa również bez reguł mod_rewrite.
$_GET['route'] = '/ng';
$_SERVER['SCRIPT_NAME'] = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/index.php';
require dirname(__DIR__) . '/index.php';
