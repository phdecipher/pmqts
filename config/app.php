<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

if (basename($scriptDirectory) === 'pages') {
    $scriptDirectory = dirname($scriptDirectory);
}

define('APP_BASE_URL', rtrim($scriptDirectory, '/') ?: '');
define('APP_ROOT_PATH', dirname(__DIR__));

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $appPrefix = APP_BASE_URL . '/';
        if (is_string($requestPath) && str_starts_with($requestPath, $appPrefix)) {
            $_SESSION['after_login'] = $requestPath;
            $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $_SESSION['after_login'] .= '?' . $query;
            }
        }
        set_flash('Sign in to continue.', 'warning');
        header('Location: ' . APP_BASE_URL . '/pages/login.php');
        exit();
    }
}