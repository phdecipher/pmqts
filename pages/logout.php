<?php
require_once __DIR__ . '/../config/app.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $parameters = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
}
session_destroy();

header('Location: ' . APP_BASE_URL . '/pages/login.php');
exit();
