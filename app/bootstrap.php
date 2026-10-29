<?php
declare(strict_types=1);
require __DIR__ . '/store.php';
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

function asset_url(string $path): string {
    return base_path() . '/' . ltrim($path, '/');
}
function pretty_urls(): bool { return getenv('QUIZ_PRETTY_URLS') === '1'; }
function url(string $path): string {
    [$route,$query] = array_pad(explode('?', $path, 2), 2, '');
    if ($route === '/ng' && $query === '') return asset_url('/ng/');
    if (pretty_urls()) return asset_url($route) . ($query !== '' ? '?' . $query : '');
    return asset_url('/index.php') . '?route=' . rawurlencode($route) . ($query !== '' ? '&' . $query : '');
}
function route_path(): string {
    $route = $_GET['route'] ?? null;
    if ($route !== null) {
        if (!is_string($route) || !str_starts_with($route,'/') || str_starts_with($route,'//') || strlen($route)>200)
            return '/invalid-route';
        return rtrim($route,'/') ?: '/';
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path,$base.'/')))
        $path = substr($path,strlen($base));
    $path = rtrim($path,'/') ?: '/';
    return $path === '/index.php' ? '/' : $path;
}
function back_path(): string {
    $path = route_path();
    if ($path === '/admin/setup' || $path === '/admin/login') return $path;
    if (preg_match('~^/admin/quiz/(\d+)~',$path,$match)) return '/admin/quiz/'.$match[1];
    if (str_starts_with($path,'/admin')) return '/admin';
    return '/ng';
}
function store(): JsonStore {
    static $store;
    if ($store) return $store;
    $file = getenv('QUIZ_DATA') ?: dirname(__DIR__).'/storage/quiz.json.php';
    if (!getenv('QUIZ_DATA') && !is_file($file) && is_file(dirname(__DIR__).'/storage/quiz.sqlite'))
        fail('Znaleziono starszą bazę SQLite. Przed uruchomieniem przenieś ją poleceniem python3 scripts/migrate_sqlite.py. Oryginalna baza pozostaje bez zmian.',500);
    return $store = new JsonStore($file);
}
function transaction(callable $callback): mixed { return store()->transaction($callback); }
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
function limited(string $scope,int $maximum,int $seconds): void {
    $key = hash('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $allowed = transaction(function () use ($key,$maximum,$seconds) {
        store()->delete('limits',fn($r)=>$r['expires']<time());
        $row = store()->filter('limits',fn($r)=>$r['key']===$key)[0] ?? null;
        if (!$row) { store()->insert('limits',['key'=>$key,'hits'=>1,'expires'=>time()+$seconds]); return true; }
        if ($row['hits'] >= $maximum) return false;
        store()->update('limits',$row['id'],['hits'=>$row['hits']+1]);
        return true;
    });
    if (!$allowed) fail('Zbyt wiele prób. Odczekaj chwilę i spróbuj ponownie.',429);
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
    $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
    $size = @getimagesize($file['tmp_name']);
    $mime = $size['mime'] ?? '';
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
