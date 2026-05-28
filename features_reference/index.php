<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/hotel_pms/');
}

if (isLoggedIn()) {
    $redirectPath = (($_SESSION['user_role'] ?? '') === 'housekeeping')
        ? 'modules/rooms/index.php'
        : 'modules/dashboard/index.php';
    header('Location: ' . BASE_URL . $redirectPath);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } elseif (!login($username, $password)) {
        $error = 'Invalid username or password, or account is inactive.';
    } else {
        $redirectPath = (($_SESSION['user_role'] ?? '') === 'housekeeping')
            ? 'modules/rooms/index.php'
            : 'modules/dashboard/index.php';
        header('Location: ' . BASE_URL . $redirectPath);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= HOTEL_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<div class="login-bg">
    <div class="login-card">
        <div class="text-center mb-4">
            <div class="mb-2">
                <img src="<?= BASE_URL ?>assets/img/logo.jpg" alt="<?= HOTEL_NAME ?>" style="height:54px;width:auto;object-fit:contain;">
            </div>
            <h4 class="fw-bold text-primary"><?= HOTEL_NAME ?></h4>
            <p class="text-muted small">Property Management System</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-semibold">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Enter username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="password" id="passwordField" class="form-control" placeholder="Enter password" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePass()">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </button>
        </form>

        <hr class="my-4">
        <p class="text-center text-muted small mb-2">
            <i class="fas fa-info-circle me-1"></i>
            Demo staff accounts — password: <code>password</code>
        </p>
        <div class="row g-2">
            <div class="col-6">
                <button class="btn btn-outline-secondary btn-sm w-100 py-2" onclick="fillLogin('admin')">
                    <i class="fas fa-user d-block mb-1"></i>admin
                </button>
            </div>
            <div class="col-6">
                <button class="btn btn-outline-secondary btn-sm w-100 py-2" onclick="fillLogin('frontdesk1')">
                    <i class="fas fa-user d-block mb-1"></i>frontdesk1
                </button>
            </div>
        </div>

        <div class="text-center mt-4">
            <small class="text-muted">v<?= APP_VERSION ?> &copy; <?= date('Y') ?> <?= HOTEL_NAME ?></small>
        </div>
    </div>
</div>

<script>
function fillLogin(user) {
    document.querySelector('[name="username"]').value = user;
    document.querySelector('[name="password"]').value = 'password';
}
function togglePass() {
    const f = document.getElementById('passwordField');
    const i = document.getElementById('eyeIcon');
    if (f.type === 'password') {
        f.type = 'text';
        i.classList.replace('fa-eye','fa-eye-slash');
    } else {
        f.type = 'password';
        i.classList.replace('fa-eye-slash','fa-eye');
    }
}
</script>
</body>
</html>
