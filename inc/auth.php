<?php
function ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

ensure_session();

function is_authenticated(): bool
{
    return isset($_SESSION['user_login']);
}

function require_login(): void
{
    if (!is_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

function login_user(string $username): void
{
    $_SESSION['user_login'] = $username;
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
