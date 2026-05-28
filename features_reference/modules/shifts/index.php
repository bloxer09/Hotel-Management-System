<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin','front_desk']);

$pageTitle = 'Shift Closing';
$pdo = getPDO();
$currentUser = getCurrentUser();

// --- Helpers ---
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?
         LIMIT 1"
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function getShiftCollectionSummary(PDO $pdo, int $userId, string $start, string $end): array {
    $salesStmt = $pdo->prepare("SELECT
            COUNT(*) AS txn_count,
            COALESCE(SUM(amount),0) AS total_collected,
            COALESCE(SUM(cash_amount),0) AS cash,
            COALESCE(SUM(gcash_amount),0) AS gcash,
            COALESCE(SUM(CASE WHEN payment_method='split' THEN amount ELSE 0 END),0) AS split_total,
            COALESCE(SUM(CASE WHEN transaction_type='check_in' THEN amount ELSE 0 END),0) AS checkin_sales,
            COALESCE(SUM(CASE WHEN transaction_type='check_out' THEN amount ELSE 0 END),0) AS checkout_sales,
            COALESCE(SUM(CASE WHEN transaction_type='extension' THEN amount ELSE 0 END),0) AS extension_sales,
            COALESCE(SUM(CASE WHEN transaction_type='adjustment' THEN amount ELSE 0 END),0) AS adjustment_sales
        FROM transactions
        WHERE processed_by=?
          AND transaction_type IN ('check_in','check_out','extension','adjustment')
          AND created_at BETWEEN ? AND ?");
    $salesStmt->execute([$userId, $start, $end]);
    $sales = $salesStmt->fetch() ?: [];

    return [
        'txn_count'        => (int)($sales['txn_count'] ?? 0),
        'total_collected'  => (float)($sales['total_collected'] ?? 0),
        'cash'             => (float)($sales['cash'] ?? 0),
        'gcash'            => (float)($sales['gcash'] ?? 0),
        'split_total'      => (float)($sales['split_total'] ?? 0),
        'checkin_sales'    => (float)($sales['checkin_sales'] ?? 0),
        'checkout_sales'   => (float)($sales['checkout_sales'] ?? 0),
        'extension_sales'  => (float)($sales['extension_sales'] ?? 0),
        'adjustment_sales' => (float)($sales['adjustment_sales'] ?? 0),
    ];
}

$shiftDefs = [
    'morning' => ['label' => 'Morning Shift', 'start' => '07:00', 'end' => '16:00', 'display' => '7:00 AM – 4:00 PM'],
    'evening' => ['label' => 'Evening Shift', 'start' => '15:00', 'end' => '00:00', 'display' => '3:00 PM – 12:00 AM'],
    'night'   => ['label' => 'Night Shift',   'start' => '23:00', 'end' => '08:00', 'display' => '11:00 PM – 8:00 AM'],
];

// Guess shift code (for convenience only; receptionist can change it)
$nowHM = date('H:i');
$defaultShift = 'morning';
if ($nowHM >= '15:00' && $nowHM < '23:00') $defaultShift = 'evening';
if ($nowHM >= '23:00' || $nowHM < '08:30') $defaultShift = 'night';

$hasTable = tableExists($pdo, 'shift_sessions');
$lastClosingCash = 0.00;
if ($hasTable) {
    $lastShiftStmt = $pdo->prepare("SELECT closing_cash
                                    FROM shift_sessions
                                    WHERE user_id=? AND ended_at IS NOT NULL
                                    ORDER BY ended_at DESC, id DESC
                                    LIMIT 1");
    $lastShiftStmt->execute([$currentUser['id']]);
    $lastClosingCash = (float)($lastShiftStmt->fetchColumn() ?: 0);
}

// Handle actions
if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_shift') {
        $shiftCode = $_POST['shift_code'] ?? $defaultShift;
        $openingCash = (float)($_POST['opening_cash'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');

        if (!isset($shiftDefs[$shiftCode])) {
            setFlash('error', 'Invalid shift selected.');
            header('Location: index.php');
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM shift_sessions WHERE user_id=? AND ended_at IS NULL LIMIT 1");
        $stmt->execute([$currentUser['id']]);
        if ($stmt->fetch()) {
            setFlash('warning', 'You already have an active shift. Please end your current shift before starting a new one.');
            header('Location: index.php');
            exit;
        }

        $def = $shiftDefs[$shiftCode];
        $pdo->prepare("INSERT INTO shift_sessions (user_id, shift_code, scheduled_start, scheduled_end, started_at, opening_cash, notes)
                       VALUES (?,?,?,?,NOW(),?,?)")
            ->execute([$currentUser['id'], $shiftCode, $def['start'], $def['end'], $openingCash, $notes]);

        $shiftId = (int)$pdo->lastInsertId();
        auditLog($currentUser['id'], 'SHIFT_START', 'shift_sessions', $shiftId, null, $shiftCode, 'Shift started');
        setFlash('success', "Shift started: <strong>{$def['label']}</strong>");
        header('Location: index.php');
        exit;
    }

    if ($action === 'end_shift') {
        $closingCash = (float)($_POST['closing_cash'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM shift_sessions WHERE user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$currentUser['id']]);
        $active = $stmt->fetch();

        if (!$active) {
            setFlash('error', 'No active shift found.');
            header('Location: index.php');
            exit;
        }

        $pdo->prepare("UPDATE shift_sessions SET ended_at=NOW(), closing_cash=?, notes=TRIM(CONCAT(IFNULL(notes,''), IF(?='', '', CONCAT('\n', ?)))) WHERE id=?")
            ->execute([$closingCash, $notes, $notes, $active['id']]);

        auditLog($currentUser['id'], 'SHIFT_END', 'shift_sessions', (int)$active['id'], null, null, 'Shift ended');
        setFlash('success', 'Shift ended. You can now print your Shift Report.');
        header('Location: index.php?shift_id=' . (int)$active['id']);
        exit;
    }
}

$activeShift = null;
$activeShiftSummary = null;
if ($hasTable) {
    $stmt = $pdo->prepare("SELECT * FROM shift_sessions WHERE user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$currentUser['id']]);
    $activeShift = $stmt->fetch();

    if ($activeShift) {
        $liveStart = $activeShift['started_at'];
        $liveEnd = date('Y-m-d H:i:s');
        $salesLive = getShiftCollectionSummary($pdo, (int)$activeShift['user_id'], $liveStart, $liveEnd);
        $expectedDrawerCash = (float)$activeShift['opening_cash'] + (float)$salesLive['cash'];
        $activeShiftSummary = [
            'sales' => $salesLive,
            'expected_drawer_cash' => $expectedDrawerCash,
            'total_business' => (float)$activeShift['opening_cash'] + (float)$salesLive['total_collected'],
            'live_end' => $liveEnd,
        ];
    }
}

$shiftId = isset($_GET['shift_id']) ? (int)$_GET['shift_id'] : 0;
$selectedShift = null;
if ($hasTable && $shiftId > 0) {
    if (hasRole('admin')) {
        $stmt = $pdo->prepare("SELECT ss.*, u.full_name, u.username, u.role
                               FROM shift_sessions ss JOIN users u ON ss.user_id=u.id
                               WHERE ss.id=? LIMIT 1");
        $stmt->execute([$shiftId]);
    } else {
        $stmt = $pdo->prepare("SELECT ss.*, u.full_name, u.username, u.role
                               FROM shift_sessions ss JOIN users u ON ss.user_id=u.id
                               WHERE ss.id=? AND ss.user_id=? LIMIT 1");
        $stmt->execute([$shiftId, $currentUser['id']]);
    }
    $selectedShift = $stmt->fetch();
}

$report = null;
if ($selectedShift) {
    $start = $selectedShift['started_at'];
    $end   = $selectedShift['ended_at'] ?: date('Y-m-d H:i:s');
    $userId = (int)$selectedShift['user_id'];

    $sales = getShiftCollectionSummary($pdo, $userId, $start, $end);

    $checkins = $pdo->prepare("SELECT COUNT(*) AS cnt
        FROM transactions
        WHERE processed_by=?
          AND transaction_type='check_in'
          AND created_at BETWEEN ? AND ?");
    $checkins->execute([$userId, $start, $end]);
    $checkins = $checkins->fetch();

    $invSummaryStmt = $pdo->prepare("SELECT
            COALESCE(SUM(quantity),0) AS total_qty,
            COALESCE(SUM(total_price),0) AS total_value
        FROM inventory_usage
        WHERE recorded_by=?
          AND created_at BETWEEN ? AND ?");
    $invSummaryStmt->execute([$userId, $start, $end]);
    $invSummary = $invSummaryStmt->fetch();

    $invItemsStmt = $pdo->prepare("SELECT ii.item_name, iu.item_id,
            SUM(iu.quantity) AS qty,
            COALESCE(SUM(iu.total_price),0) AS total
        FROM inventory_usage iu
        JOIN inventory_items ii ON iu.item_id = ii.id
        WHERE iu.recorded_by=?
          AND iu.created_at BETWEEN ? AND ?
        GROUP BY iu.item_id
        ORDER BY total DESC, qty DESC");
    $invItemsStmt->execute([$userId, $start, $end]);
    $invItems = $invItemsStmt->fetchAll();

    $txnStmt = $pdo->prepare("SELECT t.*, b.booking_ref, b.guest_name, r.room_number
        FROM transactions t
        LEFT JOIN bookings b ON t.booking_id=b.id
        LEFT JOIN rooms r ON b.room_id=r.id
        WHERE t.processed_by=?
          AND t.transaction_type IN ('check_in','check_out','extension','adjustment')
          AND t.created_at BETWEEN ? AND ?
        ORDER BY t.created_at DESC");
    $txnStmt->execute([$userId, $start, $end]);
    $txnList = $txnStmt->fetchAll();

    $lowStock = $pdo->query("SELECT item_name, current_stock, minimum_stock
        FROM inventory_items
        WHERE is_active=1 AND current_stock <= minimum_stock
        ORDER BY (minimum_stock-current_stock) DESC, item_name")->fetchAll();

    $expectedDrawerCash = (float)$selectedShift['opening_cash'] + (float)$sales['cash'];
    $cashVariance = null;
    if ($selectedShift['ended_at'] !== null) {
        $cashVariance = round((float)$selectedShift['closing_cash'] - $expectedDrawerCash, 2);
    }

    $report = compact('sales','checkins','invSummary','invItems','txnList','lowStock','start','end','expectedDrawerCash','cashVariance');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start no-print">
    <div>
        <h4><i class="fas fa-user-clock me-2 text-primary"></i>Shift Closing</h4>
        <p>Start your shift, end your shift, and print your sales, opening cash, and cash-on-hand summary.</p>
    </div>
</div>

<?php if (!$hasTable): ?>
<div class="alert alert-warning">
    <div class="d-flex gap-2 align-items-start">
        <i class="fas fa-database fs-5 mt-1"></i>
        <div>
            <strong>Shift module needs 1 small database table.</strong>
            <div class="mt-1">Run this SQL once in phpMyAdmin (SQL tab):</div>
            <pre class="mt-2 mb-0"><code>CREATE TABLE IF NOT EXISTS shift_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  shift_code ENUM('morning','evening','night') NOT NULL,
  scheduled_start TIME,
  scheduled_end TIME,
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  opening_cash DECIMAL(10,2) DEFAULT 0.00,
  closing_cash DECIMAL(10,2) DEFAULT 0.00,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;</code></pre>
        </div>
    </div>
</div>
<?php else: ?>

<div class="row g-3 no-print">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white">
                <strong><i class="fas fa-play me-2 text-success"></i>Start Shift</strong>
            </div>
            <div class="card-body">
                <?php if ($activeShift): ?>
                    <div class="alert alert-info mb-0">
                        <strong>You already have an active shift.</strong><br>
                        Started: <?= formatDateTime($activeShift['started_at']) ?><br>
                        Shift: <span class="badge bg-primary"><?= $shiftDefs[$activeShift['shift_code']]['label'] ?? sanitize($activeShift['shift_code']) ?></span>
                        <div class="mt-2 small text-muted">End your shift on the right panel when your duty finishes.</div>
                    </div>
                <?php else: ?>
                <form method="POST" class="row g-2">
                    <input type="hidden" name="action" value="start_shift">

                    <div class="col-12">
                        <label class="form-label fw-semibold">Select Shift</label>
                        <select name="shift_code" class="form-select" required>
                            <?php foreach ($shiftDefs as $code => $def): ?>
                                <option value="<?= $code ?>" <?= $code===$defaultShift?'selected':'' ?>><?= $def['label'] ?> (<?= $def['display'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Opening Cash</label>
                        <input type="number" step="0.01" min="0" name="opening_cash" class="form-control" value="<?= number_format($lastClosingCash, 2, '.', '') ?>">
                        <div class="form-text">Auto-filled from your last closed shift. Change it if your actual drawer opening is different.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g., Cash float, special instructions..."></textarea>
                    </div>

                    <div class="col-12">
                        <button class="btn btn-success w-100" type="submit">
                            <i class="fas fa-play me-2"></i>Start Shift
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white">
                <strong><i class="fas fa-flag-checkered me-2 text-danger"></i>End Shift & Generate Report</strong>
            </div>
            <div class="card-body">
                <?php if (!$activeShift): ?>
                    <div class="alert alert-light mb-0">
                        No active shift yet. Start your shift first to enable shift closing.
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge bg-primary"><?= $shiftDefs[$activeShift['shift_code']]['label'] ?? sanitize($activeShift['shift_code']) ?></span>
                            <span class="text-muted">Started: <strong><?= formatDateTime($activeShift['started_at']) ?></strong></span>
                        </div>
                        <div class="small text-muted mt-1">Closing will summarize your opening cash, total sales, and expected cash-on-hand for this shift.</div>
                    </div>

                    <?php if ($activeShiftSummary): ?>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="small text-muted">Opening Cash</div>
                                <div class="fw-bold"><?= formatCurrency($activeShift['opening_cash']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="small text-muted">Total Sales So Far</div>
                                <div class="fw-bold text-success"><?= formatCurrency($activeShiftSummary['sales']['total_collected']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">Cash Sales</div>
                                <div class="fw-semibold"><?= formatCurrency($activeShiftSummary['sales']['cash']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">GCash Sales</div>
                                <div class="fw-semibold"><?= formatCurrency($activeShiftSummary['sales']['gcash']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-2 h-100 bg-light border border-warning">
                                <div class="small text-muted">Expected Drawer Cash</div>
                                <div class="fw-bold"><?= formatCurrency($activeShiftSummary['expected_drawer_cash']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" class="row g-2">
                        <input type="hidden" name="action" value="end_shift">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Closing Cash</label>
                            <input type="number" step="0.01" min="0" name="closing_cash" class="form-control" value="<?= $activeShiftSummary ? number_format($activeShiftSummary['expected_drawer_cash'], 2, '.', '') : '0.00' ?>">
                            <div class="form-text">Suggested amount is opening cash + cash sales during this shift.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Notes (optional)</label>
                            <input type="text" name="notes" class="form-control" placeholder="e.g., turnover notes">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-danger w-100" type="submit" onclick="return confirm('End shift now and generate the report?')">
                                <i class="fas fa-flag-checkered me-2"></i>End Shift & Generate Report
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($selectedShift && $report): ?>
<hr class="my-4">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <h5 class="mb-0"><i class="fas fa-receipt me-2 text-primary"></i>Shift Report</h5>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
        <a class="btn btn-outline-primary" href="index.php"><i class="fas fa-arrow-left me-2"></i>Back</a>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <img src="<?= BASE_URL ?>assets/img/logo.jpg" alt="<?= HOTEL_NAME ?>" style="height:42px;width:auto;" class="no-print">
                    <div>
                        <h5 class="mb-0"><?= HOTEL_NAME ?></h5>
                        <div class="text-muted small">Shift Report</div>
                    </div>
                </div>
            </div>
            <div class="text-end">
                <div class="small text-muted">Staff:</div>
                <div class="fw-semibold"><?= sanitize($selectedShift['full_name'] ?? $currentUser['full_name']) ?> (<?= sanitize($selectedShift['username'] ?? $currentUser['username']) ?>)</div>
                <div class="small text-muted">Role: <?= getRoleLabel($selectedShift['role'] ?? $currentUser['role']) ?></div>
            </div>
        </div>

        <hr>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 border rounded h-100">
                    <div class="fw-semibold mb-1">Shift Details</div>
                    <div class="small">Shift: <strong><?= $shiftDefs[$selectedShift['shift_code']]['label'] ?? sanitize($selectedShift['shift_code']) ?></strong></div>
                    <div class="small">Start: <strong><?= formatDateTime($report['start']) ?></strong></div>
                    <div class="small">End: <strong><?= formatDateTime($selectedShift['ended_at'] ?: $report['end']) ?></strong></div>
                    <div class="small">Opening Cash: <strong><?= formatCurrency($selectedShift['opening_cash']) ?></strong></div>
                    <div class="small">Closing Cash: <strong><?= formatCurrency($selectedShift['closing_cash']) ?></strong></div>
                    <div class="small">Expected Drawer Cash: <strong><?= formatCurrency($report['expectedDrawerCash']) ?></strong></div>
                    <?php if ($report['cashVariance'] !== null): ?>
                        <div class="small">Cash Variance: <strong class="<?= $report['cashVariance'] == 0.0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency($report['cashVariance']) ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border rounded h-100">
                    <div class="fw-semibold mb-1">Sales (Collections During Shift)</div>
                    <div class="small">Transactions processed: <strong><?= (int)$report['sales']['txn_count'] ?></strong></div>
                    <div class="small">Total Sales: <strong><?= formatCurrency($report['sales']['total_collected']) ?></strong></div>
                    <div class="small">Cash Sales: <strong><?= formatCurrency($report['sales']['cash']) ?></strong></div>
                    <div class="small">GCash Sales: <strong><?= formatCurrency($report['sales']['gcash']) ?></strong></div>
                    <div class="small">Split (total): <strong><?= formatCurrency($report['sales']['split_total']) ?></strong></div>
                    <div class="small mt-2">Check-in Sales: <strong><?= formatCurrency($report['sales']['checkin_sales']) ?></strong></div>
                    <div class="small">Check-out Sales: <strong><?= formatCurrency($report['sales']['checkout_sales']) ?></strong></div>
                    <div class="small">Extension Sales: <strong><?= formatCurrency($report['sales']['extension_sales']) ?></strong></div>
                    <div class="small">Adjustments: <strong><?= formatCurrency($report['sales']['adjustment_sales']) ?></strong></div>
                    <div class="small text-muted mt-2">Check-ins processed: <?= (int)$report['checkins']['cnt'] ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <div class="p-3 border rounded h-100">
                    <div class="fw-semibold mb-2">Inventory Movement (Sold/Issued During Shift)</div>
                    <div class="small mb-2">Total Items Qty: <strong><?= (int)$report['invSummary']['total_qty'] ?></strong> | Total Value: <strong><?= formatCurrency($report['invSummary']['total_value']) ?></strong></div>

                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($report['invItems'])): ?>
                                    <tr><td colspan="3" class="text-muted">No inventory recorded during this shift.</td></tr>
                                <?php else: foreach ($report['invItems'] as $it): ?>
                                    <tr>
                                        <td><?= sanitize($it['item_name']) ?></td>
                                        <td class="text-center"><?= (int)$it['qty'] ?></td>
                                        <td class="text-end"><?= formatCurrency($it['total']) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="p-3 border rounded h-100">
                    <div class="fw-semibold mb-2">Low Stock Items (Current)</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr><th>Item</th><th class="text-center">Stock</th><th class="text-center">Min</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($report['lowStock'])): ?>
                                    <tr><td colspan="3" class="text-muted">No low-stock items.</td></tr>
                                <?php else: foreach ($report['lowStock'] as $ls): ?>
                                    <tr>
                                        <td><?= sanitize($ls['item_name']) ?></td>
                                        <td class="text-center fw-semibold text-danger"><?= (int)$ls['current_stock'] ?></td>
                                        <td class="text-center text-muted"><?= (int)$ls['minimum_stock'] ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <div class="fw-semibold mb-2">Transaction Log During Shift</div>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Receipt/Ref</th>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Type</th>
                            <th class="text-end">Amount</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['txnList'])): ?>
                            <tr><td colspan="7" class="text-muted">No sales transactions for this shift.</td></tr>
                        <?php else: foreach ($report['txnList'] as $t): ?>
                            <tr>
                                <td><?= formatDateTime($t['created_at']) ?></td>
                                <td><?= sanitize($t['booking_ref'] ?? '—') ?></td>
                                <td><?= sanitize($t['guest_name'] ?? '—') ?></td>
                                <td><?= sanitize($t['room_number'] ?? '—') ?></td>
                                <td><?= strtoupper(str_replace('_', ' ', $t['transaction_type'] ?? '—')) ?></td>
                                <td class="text-end fw-semibold"><?= formatCurrency($t['amount']) ?></td>
                                <td><?= strtoupper($t['payment_method'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($selectedShift['notes'])): ?>
            <div class="mt-3 small">
                <div class="fw-semibold">Notes</div>
                <div class="text-muted" style="white-space:pre-wrap;"><?= sanitize($selectedShift['notes']) ?></div>
            </div>
        <?php endif; ?>

        <div class="row mt-4">
            <div class="col-md-6">
                <div class="border-top pt-2">Cashier Signature</div>
            </div>
            <div class="col-md-6">
                <div class="border-top pt-2">Supervisor Signature</div>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<?php endif; // hasTable ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
