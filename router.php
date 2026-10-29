<?php
// Wyłącznie dla php -S. Na produkcji document root musi wskazywać public/.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__ . '/public' . $path);
$public = realpath(__DIR__ . '/public') . DIRECTORY_SEPARATOR;
if ($file && str_starts_with($file, $public) && is_file($file)
    && !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['php', 'phtml', 'phar'], true)) return false;
require __DIR__ . '/public/index.php';
