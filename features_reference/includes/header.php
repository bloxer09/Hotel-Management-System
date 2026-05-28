<?php
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Normalize to hotel_pms root
    $parts = explode('/', trim($script, '/'));
    $rootParts = [];
    foreach ($parts as $p) {
        if ($p === 'hotel_pms') { $rootParts[] = $p; break; }
        $rootParts[] = $p;
    }
    define('BASE_URL', $protocol . '://' . $host . '/' . implode('/', $rootParts) . '/');
}
$currentUser = getCurrentUser();
$currentPage = $_GET['page'] ?? basename(dirname($_SERVER['SCRIPT_NAME']));
$canSeeNotifications = in_array(($currentUser['role'] ?? ''), ['admin', 'front_desk'], true);

function navItem($label, $url, $icon, $roles = null, $currentPage = '') {
    $user = getCurrentUser();
    if ($roles && !in_array($user['role'], $roles)) return '';
    $pageName = basename(dirname($url));
    $active = (strpos($_SERVER['REQUEST_URI'], $pageName) !== false) ? 'active' : '';
    return '<li class="nav-item">
        <a class="nav-link ' . $active . '" href="' . BASE_URL . $url . '">
            <i class="' . $icon . ' me-2"></i>' . $label . '
        </a>
    </li>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? sanitize($pageTitle) . ' — ' : '' ?><?= HOTEL_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>

<!-- Top Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top shadow-sm" style="z-index:1050">
    <div class="container-fluid">
        <button class="btn btn-sm btn-outline-light me-2" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= BASE_URL ?><?= ($currentUser['role'] === 'housekeeping') ? 'modules/rooms/index.php' : 'modules/dashboard/index.php' ?>">
            <img src="<?= BASE_URL ?>assets/img/logo.jpg" alt="<?= HOTEL_NAME ?>" class="brand-logo me-2" style="height:32px;width:auto;max-height:32px;object-fit:contain;">
            <span class="brand-text"><?= HOTEL_NAME ?></span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="text-white-50 small d-none d-md-inline">
                <i class="fas fa-clock me-1"></i>
                <span id="liveClock"><?= date('h:i A') ?></span> | <?= date('M d, Y') ?>
            </span>
            <?php if ($canSeeNotifications): ?>
            <div class="dropdown" id="notificationsDropdownWrap">
                <button class="btn btn-sm btn-outline-light position-relative checkout-alert-bell" type="button" id="notificationsToggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="notificationCount">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-0 shadow checkout-alert-menu" aria-labelledby="notificationsToggle">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold small mb-0">Notifications</div>
                            <div class="text-muted" style="font-size:0.74rem">Checkout and inventory alerts every 30 seconds</div>
                        </div>
                        <div class="d-flex gap-1">
                            <a href="<?= BASE_URL ?>modules/rooms/index.php" class="btn btn-sm btn-outline-primary py-1 px-2">Room Status</a>
                            <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-sm btn-outline-info py-1 px-2">Inventory</a>
                        </div>
                    </div>
                    <div id="notificationList" class="checkout-alert-list">
                        <div class="px-3 py-3 text-center text-muted small">No notifications right now.</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle me-1"></i><?= sanitize($currentUser['full_name']) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header"><?= sanitize($currentUser['username']) ?></h6></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>includes/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-content pt-3">
        <ul class="nav flex-column px-2">
            <?= navItem('Dashboard', 'modules/dashboard/index.php', 'fas fa-tachometer-alt', ['admin','front_desk']) ?>
            <?= navItem('Room Status', 'modules/rooms/index.php', 'fas fa-door-open', ['admin','front_desk','housekeeping']) ?>
            <?= navItem('Check-In', 'modules/checkin/index.php', 'fas fa-sign-in-alt', ['admin','front_desk']) ?>
            <?= navItem('Bookings', 'modules/checkout/index.php', 'fas fa-list-check', ['admin','front_desk']) ?>
            <?= navItem('Guest History', 'modules/guests/index.php', 'fas fa-users', ['admin','front_desk']) ?>
            
            <li class="nav-section-label px-3 mt-3 mb-1">
                <small class="text-muted fw-bold text-uppercase">Finance</small>
            </li>
            <?= navItem('Sales Report', 'modules/reports/index.php', 'fas fa-chart-bar', ['admin','front_desk']) ?>
            <?= navItem('Shift Closing', 'modules/shifts/index.php', 'fas fa-user-clock', ['admin','front_desk']) ?>
            
            <li class="nav-section-label px-3 mt-3 mb-1">
                <small class="text-muted fw-bold text-uppercase">Management</small>
            </li>
            <?= navItem('Inventory', 'modules/inventory/index.php', 'fas fa-boxes', ['admin','front_desk']) ?>
            <?= navItem('Audit Trail', 'modules/audit/index.php', 'fas fa-history', ['admin']) ?>
            
            <li class="nav-section-label px-3 mt-3 mb-1">
                <small class="text-muted fw-bold text-uppercase">Settings</small>
            </li>
            <?= navItem('Room Rates', 'modules/rates/index.php', 'fas fa-tags', ['admin']) ?>
            <?= navItem('Peak Dates', 'modules/peaks/index.php', 'fas fa-calendar-alt', ['admin']) ?>
            <?= navItem('User Management', 'modules/users/index.php', 'fas fa-users-cog', ['admin']) ?>
        </ul>
    </div>
    <div class="sidebar-footer px-3 py-2">
        <small class="text-muted">v<?= APP_VERSION ?> &mdash; PMS</small>
        <a class="btn btn-outline-secondary btn-sm w-100 mt-2" href="<?= BASE_URL ?>includes/logout.php">
            <i class="fas fa-sign-out-alt me-1"></i>Logout
        </a>
    </div>
</div>

<!-- Main Content Wrapper -->
<div id="mainContent" class="main-content">
    <div class="container-fluid py-4 px-4">

        <?php
        $flashSuccess = getFlash('success');
        $flashError   = getFlash('error');
        $flashWarning = getFlash('warning');
        if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= sanitize($flashSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($flashWarning): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= sanitize($flashWarning) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($canSeeNotifications): ?>
        <div id="notificationToastContainer" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1085;margin-top:64px"></div>
        <?php endif; ?>
