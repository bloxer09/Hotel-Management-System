<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin','front_desk']);

$pageTitle = 'Dashboard';
$pdo = getPDO();

// Stats
$today = date('Y-m-d');

// Occupancy
$rooms = $pdo->query("SELECT status, COUNT(*) as cnt FROM rooms GROUP BY status")->fetchAll();
$roomStats = ['vacant'=>0,'occupied'=>0,'cleaning'=>0,'out_of_order'=>0];
$totalRooms = 0;
foreach ($rooms as $r) { $roomStats[$r['status']] = $r['cnt']; $totalRooms += $r['cnt']; }
$occupancyRate = $totalRooms ? round($roomStats['occupied'] / $totalRooms * 100) : 0;

// Today revenue
$todayRev = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) as total,
    COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(gcash_amount),0) as gcash
    FROM bookings WHERE DATE(check_in) = '$today' AND status != 'cancelled'")->fetch();

// Active bookings
$activeBookings = $pdo->query("SELECT COUNT(*) as cnt FROM bookings WHERE status = 'active'")->fetch()['cnt'];

// Today check-ins
$todayCheckIns = $pdo->query("SELECT COUNT(*) as cnt FROM bookings WHERE DATE(check_in) = '$today' AND status != 'cancelled'")->fetch()['cnt'];

// Recent bookings
$recentBookings = $pdo->query("
    SELECT b.*, r.room_number, rt.type_name
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN room_types rt ON r.room_type_id = rt.id
    ORDER BY b.created_at DESC LIMIT 8")->fetchAll();

// Room list for status board
$allRooms = $pdo->query("
    SELECT r.*, rt.type_name, rt.base_rate,
           b.guest_name, b.check_in, b.expected_check_out
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'active'
    ORDER BY r.room_number")->fetchAll();

// Low stock items
$lowStock = getLowStockInventoryCount($pdo);

$checkoutAlerts = $pdo->query("
    SELECT b.id, b.booking_ref, b.guest_name, b.expected_check_out, r.room_number,
           TIMESTAMPDIFF(SECOND, NOW(), b.expected_check_out) AS seconds_until
    FROM bookings b
    INNER JOIN rooms r ON r.id = b.room_id
    WHERE b.status = 'active'
      AND b.expected_check_out IS NOT NULL
      AND b.expected_check_out <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)
    ORDER BY b.expected_check_out ASC, r.room_number ASC
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-tachometer-alt me-2 text-primary"></i>Dashboard</h4>
        <p>Welcome back, <?= sanitize($currentUser['full_name']) ?>! Here's your overview for today.</p>
    </div>
    <div class="text-end">
        <span class="badge bg-light text-dark border fs-6"><?= date('l, F j, Y') ?></span>
    </div>
</div>

<!-- Alert Banners -->

<?php if (!empty($checkoutAlerts)): ?>
<div class="alert alert-warning border-0 shadow-sm mb-3">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <div class="fw-bold mb-1"><i class="fas fa-bell me-2"></i>Upcoming / Overdue Checkouts</div>
            <div class="small text-muted">These rooms need a reminder or immediate follow-up from front desk.</div>
        </div>
        <a href="<?= BASE_URL ?>modules/rooms/index.php" class="btn btn-sm btn-outline-dark">Open Room Status</a>
    </div>
    <div class="mt-3 d-flex flex-column gap-2">
        <?php foreach ($checkoutAlerts as $alert): ?>
        <?php
            $secondsUntil = (int)($alert['seconds_until'] ?? 0);
            $isOverdue = $secondsUntil < 0;
            $mins = max(1, (int)ceil(abs($secondsUntil) / 60));
        ?>
        <div class="d-flex justify-content-between align-items-center gap-3 bg-white rounded border px-3 py-2">
            <div>
                <div class="fw-semibold">Room <?= sanitize($alert['room_number']) ?> — <?= sanitize($alert['guest_name']) ?></div>
                <div class="small text-muted">Expected out: <?= formatDateTime($alert['expected_check_out']) ?> • Ref: <?= sanitize($alert['booking_ref']) ?></div>
            </div>
            <span class="badge <?= $isOverdue ? 'bg-danger' : 'bg-warning text-dark' ?>">
                <?= $isOverdue ? 'Overdue by ' . $mins . ' min' : 'In ' . $mins . ' min' ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($lowStock > 0): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3">
    <i class="fas fa-box-open"></i>
    <strong><?= $lowStock ?> inventory item<?= $lowStock > 1 ? 's' : '' ?></strong> at or below minimum stock level.
    <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-sm btn-info ms-auto">View Inventory</a>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-15">
                    <i class="fas fa-door-open text-success"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-success"><?= $roomStats['vacant'] ?></div>
                    <div class="text-muted small">Vacant Rooms</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-15">
                    <i class="fas fa-bed text-danger"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-danger"><?= $roomStats['occupied'] ?></div>
                    <div class="text-muted small">Occupied Rooms</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-15">
                    <i class="fas fa-sign-in-alt text-primary"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-primary"><?= $todayCheckIns ?></div>
                    <div class="text-muted small">Today's Check-Ins</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-15">
                    <i class="fas fa-peso-sign text-warning"></i>
                </div>
                <div>
                    <div class="fs-5 fw-bold text-warning"><?= formatCurrency($todayRev['total']) ?></div>
                    <div class="text-muted small">Today's Revenue</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column -->
    <div class="col-lg-8">
        <!-- Occupancy Bar -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2 text-primary"></i>Occupancy Overview</h6>
                <span class="badge bg-primary fs-6"><?= $occupancyRate ?>% Occupied</span>
            </div>
            <div class="card-body">
                <div class="row text-center mb-3">
                    <div class="col"><div class="fw-bold text-success fs-5"><?= $roomStats['vacant'] ?></div><small class="text-muted">Vacant</small></div>
                    <div class="col"><div class="fw-bold text-danger fs-5"><?= $roomStats['occupied'] ?></div><small class="text-muted">Occupied</small></div>
                    <div class="col"><div class="fw-bold text-warning fs-5"><?= $roomStats['cleaning'] ?></div><small class="text-muted">Cleaning</small></div>
                    <div class="col"><div class="fw-bold text-secondary fs-5"><?= $roomStats['out_of_order'] ?></div><small class="text-muted">Out of Order</small></div>
                    <div class="col"><div class="fw-bold text-dark fs-5"><?= $totalRooms ?></div><small class="text-muted">Total</small></div>
                </div>
                <div class="progress" style="height: 22px; border-radius: 12px;">
                    <?php if ($totalRooms > 0): ?>
                    <div class="progress-bar bg-danger" style="width:<?= ($roomStats['occupied']/$totalRooms*100) ?>%" title="Occupied"><?= $roomStats['occupied'] ?></div>
                    <div class="progress-bar bg-warning" style="width:<?= ($roomStats['cleaning']/$totalRooms*100) ?>%" title="Cleaning"><?= $roomStats['cleaning'] ?></div>
                    <div class="progress-bar bg-secondary" style="width:<?= ($roomStats['out_of_order']/$totalRooms*100) ?>%" title="Out of Order"></div>
                    <div class="progress-bar bg-success" style="width:<?= ($roomStats['vacant']/$totalRooms*100) ?>%" title="Vacant"><?= $roomStats['vacant'] ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Bookings -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>Recent Bookings</h6>
                <a href="<?= BASE_URL ?>modules/checkout/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ref</th><th>Guest</th><th>Room</th>
                                <th>Check-In</th><th>Amount</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentBookings as $b): ?>
                            <tr>
                                <td><span class="font-mono small"><?= sanitize($b['booking_ref']) ?></span></td>
                                <td><?= sanitize($b['guest_name']) ?></td>
                                <td><strong><?= sanitize($b['room_number']) ?></strong> <small class="text-muted"><?= sanitize($b['type_name']) ?></small></td>
                                <td class="small"><?= formatDateTime($b['check_in']) ?></td>
                                <td class="fw-bold"><?= formatCurrency($b['total_amount']) ?></td>
                                <td><?= getStatusBadge($b['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentBookings)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No bookings yet today</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column -->
    <div class="col-lg-4">
        <!-- Today Revenue Breakdown -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-wallet me-2 text-success"></i>Today's Revenue</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-money-bill-wave text-success me-2"></i>Cash</span>
                    <strong class="text-success"><?= formatCurrency($todayRev['cash']) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span><i class="fab fa-google-pay text-primary me-2"></i>GCash</span>
                    <strong class="text-primary"><?= formatCurrency($todayRev['gcash']) ?></strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <strong>Total</strong>
                    <strong class="text-warning fs-5"><?= formatCurrency($todayRev['total']) ?></strong>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h6>
            </div>
            <div class="card-body d-grid gap-2">
                <?php if (hasRole(['admin','front_desk'])): ?>
                <a href="<?= BASE_URL ?>modules/checkin/index.php" class="btn btn-success">
                    <i class="fas fa-sign-in-alt me-2"></i>New Check-In
                </a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>modules/checkout/index.php" class="btn btn-primary">
                    <i class="fas fa-list-check me-2"></i>View Bookings
                </a>
                <a href="<?= BASE_URL ?>modules/rooms/index.php" class="btn btn-info text-white">
                    <i class="fas fa-door-open me-2"></i>Room Status Board
                </a>
                <?php if (hasRole(['admin','front_desk'])): ?>
                <a href="<?= BASE_URL ?>modules/reports/index.php" class="btn btn-warning">
                    <i class="fas fa-chart-bar me-2"></i>Sales Report
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Room Status Mini Board -->
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0 fw-bold"><i class="fas fa-th me-2 text-info"></i>Room Status</h6>
                <a href="<?= BASE_URL ?>modules/rooms/index.php" class="btn btn-sm btn-outline-info">Full View</a>
            </div>
            <div class="card-body">
                <div class="row g-1">
                    <?php foreach ($allRooms as $room): ?>
                    <div class="col-3">
                        <div class="room-card <?= $room['status'] ?> text-center py-2 px-1"
                             title="<?= sanitize($room['room_number']) ?> - <?= sanitize($room['type_name']) ?><?= $room['guest_name'] ? ' | ' . sanitize($room['guest_name']) : '' ?>">
                            <div class="room-number" style="font-size:1rem"><?= sanitize($room['room_number']) ?></div>
                            <div style="font-size:0.55rem;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7">
                                <?= $room['status'] === 'out_of_order' ? 'OOO' : ucfirst($room['status']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
