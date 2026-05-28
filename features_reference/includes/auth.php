<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    $scriptName = str_replace('\\', '/', $scriptName);

    $needle = '/hotel_pms/';
    $pos = strpos($scriptName, $needle);

    if ($pos !== false) {
        $basePath = substr($scriptName, 0, $pos) . $needle;
    } else {
        $basePath = rtrim(dirname($scriptName), '/') . '/';
        if ($basePath === '//') {
            $basePath = '/';
        }
    }

    define('BASE_URL', $protocol . '://' . $host . $basePath);
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

function hasRole($roles)
{
    if (!isLoggedIn()) {
        return false;
    }

    if (is_string($roles)) {
        $roles = [$roles];
    }

    return in_array($_SESSION['user_role'], $roles, true);
}

function requireRole($roles)
{
    requireLogin();

    if (!hasRole($roles)) {
        $_SESSION['flash_error'] = 'Access denied. Insufficient permissions.';
        $redirectPath = (($_SESSION['user_role'] ?? '') === 'housekeeping')
            ? 'modules/rooms/index.php'
            : 'modules/dashboard/index.php';
        header('Location: ' . BASE_URL . $redirectPath);
        exit;
    }
}

function getCurrentUser()
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role'      => $_SESSION['user_role'],
    ];
}

function login($username, $password)
{
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if (!in_array($user['role'], ['admin', 'front_desk', 'housekeeping'], true)) {
            return false;
        }

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];

        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

        auditLog($user['id'], 'LOGIN', 'auth', $user['id'], null, null, 'User logged in');

        return true;
    }

    return false;
}

function logout()
{
    if (isLoggedIn()) {
        auditLog($_SESSION['user_id'], 'LOGOUT', 'auth', $_SESSION['user_id'], null, null, 'User logged out');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

function auditLog($userId, $action, $module, $recordId = null, $oldValue = null, $newValue = null, $reason = null)
{
    try {
        $pdo = getPDO();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, module, record_id, old_value, new_value, reason, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $userId,
            $action,
            $module,
            $recordId,
            is_array($oldValue) ? json_encode($oldValue) : $oldValue,
            is_array($newValue) ? json_encode($newValue) : $newValue,
            $reason,
            $ip
        ]);
    } catch (Exception $e) {
        // fail silently
    }
}

function setFlash($type, $msg)
{
    $_SESSION['flash_' . $type] = $msg;
}

function getFlash($type)
{
    $msg = $_SESSION['flash_' . $type] ?? null;
    unset($_SESSION['flash_' . $type]);
    return $msg;
}

function generateBookingRef()
{
    return 'BK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}
