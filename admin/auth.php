<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../build/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const ADMIN_SESSION_KEY = 'admin_user';

function admin_logged_in(): bool
{
    return isset($_SESSION[ADMIN_SESSION_KEY]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('CSRF token mismatch. Go back, reload the form, and try again.');
    }
}

function admin_require(): void
{
    if (!admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function admin_login(string $username, string $password): bool
{
    $row = one('SELECT UserName, Password FROM admins WHERE UserName = ?', [$username]);
    if (!$row) {
        return false;
    }
    if (!password_verify($password, (string) $row['Password'])) {
        return false;
    }
    if (password_needs_rehash((string) $row['Password'], PASSWORD_DEFAULT)) {
        q('UPDATE admins SET Password = ? WHERE UserName = ?',
            [password_hash($password, PASSWORD_DEFAULT), $username]);
    }
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_KEY] = $row['UserName'];
    return true;
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function admin_redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}
