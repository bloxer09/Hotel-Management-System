<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin','front_desk']);

$pageTitle = 'Sales Report';
$pdo = getPDO();
$export   = sanitize($_GET['export'] ?? '');

// Date range (defaults to today)
$dateFrom = sanitize($_GET['from'] ?? date('Y-m-d'));
$dateTo   = sanitize($_GET['to'] ?? date('Y-m-d'));

// Basic validation (expect YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $dateFrom;

// Ensure from <= to
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }


// ─── QUERY HELPERS ──────────────────────────────────────────────────────────
function buildReportData($pdo, $dateFrom, $dateTo) {
    $summary = $pdo->query("
        SELECT
            COUNT(*) as total_bookings,
            COALESCE(SUM(CASE WHEN status='checked_out' THEN 1 ELSE 0 END),0) as checked_out,
            COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END),0) as still_active,
            COALESCE(SUM(amount_paid),0) as total_revenue,
            COALESCE(SUM(cash_amount),0) as total_cash,
            COALESCE(SUM(gcash_amount),0) as total_gcash,
            COALESCE(SUM(CASE WHEN payment_method='split' THEN amount_paid ELSE 0 END),0) as total_split,
            COALESCE(SUM(peak_surcharge),0) as total_surcharge,
            COALESCE(SUM(discount_amount),0) as total_discount,
            COALESCE(SUM(extension_fee),0) as total_extension,
            COALESCE(SUM(late_checkout_fee),0) as total_late,
            COALESCE(SUM(base_amount),0) as total_base
        FROM bookings
        WHERE DATE(check_in) BETWEEN '$dateFrom' AND '$dateTo'
        AND status NOT IN ('cancelled','no_show')")->fetch();

    $byCashier = $pdo->query("
        SELECT u.full_name, u.username, u.role,
               COUNT(b.id) as txn_count,
               COALESCE(SUM(b.amount_paid),0) as total_collected,
               COALESCE(SUM(b.cash_amount),0) as cash,
               COALESCE(SUM(b.gcash_amount),0) as gcash,
               COALESCE(SUM(CASE WHEN b.payment_method='split' THEN b.amount_paid ELSE 0 END),0) as split_total
        FROM bookings b
        JOIN users u ON b.checked_out_by = u.id
        WHERE DATE(b.check_in) BETWEEN '$dateFrom' AND '$dateTo'
        AND b.status = 'checked_out'
        GROUP BY b.checked_out_by ORDER BY total_collected DESC")->fetchAll();

    $byRoomType = $pdo->query("
        SELECT rt.type_name,
               COUNT(b.id) as cnt,
               COALESCE(SUM(b.amount_paid),0) as revenue
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE DATE(b.check_in) BETWEEN '$dateFrom' AND '$dateTo'
        AND b.status NOT IN ('cancelled','no_show')
        GROUP BY rt.id ORDER BY revenue DESC")->fetchAll();

    $transactions = $pdo->query("
        SELECT b.*, r.room_number, rt.type_name, u.full_name as cashier_name
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN users u ON b.checked_out_by = u.id
        WHERE DATE(b.check_in) BETWEEN '$dateFrom' AND '$dateTo'
        AND b.status NOT IN ('cancelled','no_show')
        ORDER BY b.check_in DESC")->fetchAll();

    return compact('summary', 'byCashier', 'byRoomType', 'transactions');
}

$data = buildReportData($pdo, $dateFrom, $dateTo);
extract($data);

// ─── CSV EXPORT ──────────────────────────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($out, [HOTEL_NAME . ' — Sales Report']);
    fputcsv($out, ['Period:', $dateFrom . ' to ' . $dateTo]);
    fputcsv($out, ['Generated:', date('Y-m-d H:i:s'), 'By:', $currentUser['full_name']]);
    fputcsv($out, []);

    fputcsv($out, ['=== SUMMARY ===']);
    fputcsv($out, ['Total Bookings', $summary['total_bookings']]);
    fputcsv($out, ['Checked-Out', $summary['checked_out']]);
    fputcsv($out, ['Still Active', $summary['still_active']]);
    fputcsv($out, ['Total Cash', $summary['total_cash']]);
    fputcsv($out, ['Total GCash', $summary['total_gcash']]);
    fputcsv($out, ['Total Split', $summary['total_split']]);
    fputcsv($out, ['Total Revenue', $summary['total_revenue']]);
    fputcsv($out, ['Peak Surcharges', $summary['total_surcharge']]);
    fputcsv($out, ['Discounts Given', '-' . $summary['total_discount']]);
    fputcsv($out, ['Extension Fees', $summary['total_extension']]);
    fputcsv($out, ['Late Check-Out Fees', $summary['total_late']]);
    fputcsv($out, []);

    fputcsv($out, ['=== TRANSACTION DETAILS ===']);
    fputcsv($out, ['Receipt/Ref#','Guest Name','Room','Room Type','Check-In','Check-Out',
                   'Booking Type','Base Amount','Peak Surcharge','Discount Type','Discount Amt',
                   'Extension Fee','Late Checkout Fee','Total Amount','Amount Paid',
                   'Payment Method','Cash Amt','GCash Amt','GCash Ref','Cashier','Status','Notes']);
    foreach ($transactions as $t) {
        fputcsv($out, [
            $t['booking_ref'], $t['guest_name'], $t['room_number'], $t['type_name'],
            $t['check_in'], $t['check_out'] ?? '', $t['booking_type'],
            $t['base_amount'], $t['peak_surcharge'],
            $t['discount_type'] ?? '', $t['discount_amount'],
            $t['extension_fee'], $t['late_checkout_fee'],
            $t['total_amount'], $t['amount_paid'] ?: $t['total_amount'],
            strtoupper($t['payment_method'] ?? ''),
            $t['cash_amount'], $t['gcash_amount'], $t['gcash_ref'] ?? '',
            $t['cashier_name'] ?? '', $t['status'], $t['notes'] ?? ''
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== CASHIER REMITTANCE ===']);
    fputcsv($out, ['Staff Name','Username','Role','Transactions','Cash','GCash','Split','Total']);
    foreach ($byCashier as $c) {
        fputcsv($out, [$c['full_name'],$c['username'],$c['role'],$c['txn_count'],$c['cash'],$c['gcash'],$c['split_total'],$c['total_collected']]);
    }
    fclose($out);
    exit;
}

// ─── HTML PAGE ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- PAGE HEADER -->
<div class="page-header d-flex justify-content-between align-items-start no-print">
    <div>
        <h4><i class="fas fa-chart-bar me-2 text-primary"></i>Daily Sales Report</h4>
        <p>Revenue breakdown, cashier remittance, and export tools</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=csv"
           class="btn btn-success">
            <i class="fas fa-file-csv me-2"></i>Export CSV
        </a>
        <button class="btn btn-danger" onclick="window.print()">
            <i class="fas fa-file-pdf me-2"></i>Print / PDF
        </button>
        <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#eodModal">
            <i class="fas fa-cash-register me-2"></i>End-of-Day Report
        </button>
    </div>
</div>

<!-- DATE RANGE SELECTOR -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label fw-semibold mb-1 small">Date From</label>
                <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="col-auto">
                <label class="form-label fw-semibold mb-1 small">Date To</label>
                <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="col-auto d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Generate</button>
                <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm align-self-center">Today</a>
                <a href="?from=<?= date('Y-m-d',strtotime('monday this week')) ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm align-self-center">This Week</a>
                <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm align-self-center">This Month</a>
                <a href="?from=<?= date('Y-m-d',strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm align-self-center">Last 7 Days</a>
            </div>
        </form>
    </div>
</div>

<!-- PRINT HEADER (only in print view) -->
<div class="d-none d-print-block mb-4 text-center border-bottom pb-3">
    <h3 class="fw-bold"><?= HOTEL_NAME ?></h3>
    <h5>Sales Report: <?= formatDate($dateFrom) ?><?= $dateFrom !== $dateTo ? ' — '.formatDate($dateTo) : '' ?></h5>
    <p class="text-muted mb-0">Generated: <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp; By: <?= sanitize($currentUser['full_name']) ?> (<?= getRoleLabel($currentUser['role']) ?>)</p>
</div>

<!-- SUMMARY CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-primary"><?= $summary['total_bookings'] ?></div>
                <div class="text-muted small">Total Bookings</div>
                <div class="text-muted" style="font-size:0.7rem"><?= $summary['checked_out'] ?> checked out</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center h-100 border-success">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-success"><?= formatCurrency($summary['total_cash']) ?></div>
                <div class="text-muted small"><i class="fas fa-money-bill me-1"></i>Cash</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center h-100 border-primary">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-primary"><?= formatCurrency($summary['total_gcash']) ?></div>
                <div class="text-muted small"><i class="fas fa-mobile-alt me-1"></i>GCash</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center h-100 border-info">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-info"><?= formatCurrency($summary['total_split']) ?></div>
                <div class="text-muted small"><i class="fas fa-divide me-1"></i>Split</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center h-100 border-danger">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-danger">- <?= formatCurrency($summary['total_discount']) ?></div>
                <div class="text-muted small">Discounts Given</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center h-100 border-warning">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-warning"><?= formatCurrency($summary['total_revenue']) ?></div>
                <div class="text-muted small fw-bold">NET REVENUE</div>
            </div>
        </div>
    </div>
</div>

<!-- REVENUE BREAKDOWN + BY ROOM TYPE -->
<div class="row g-3 mb-4">
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-table me-2 text-info"></i>Revenue Breakdown</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td>Base Room Revenue</td><td class="text-end fw-bold"><?= formatCurrency($summary['total_base']) ?></td></tr>
                    <tr><td><i class="fas fa-star text-warning me-1" style="font-size:0.7rem"></i>Peak Surcharges</td><td class="text-end text-warning fw-bold">+ <?= formatCurrency($summary['total_surcharge']) ?></td></tr>
                    <tr><td>Extension Fees</td><td class="text-end">+ <?= formatCurrency($summary['total_extension']) ?></td></tr>
                    <tr><td>Late Check-Out Fees</td><td class="text-end">+ <?= formatCurrency($summary['total_late']) ?></td></tr>
                    <tr><td class="text-danger">Discounts Given</td><td class="text-end text-danger fw-bold">- <?= formatCurrency($summary['total_discount']) ?></td></tr>
                    <tr class="table-light"><td class="fw-bold">Gross Revenue</td><td class="text-end fw-bold"><?= formatCurrency($summary['total_revenue']) ?></td></tr>
                    <tr class="table-warning"><td class="fw-bold">NET REVENUE</td><td class="text-end fw-bold fs-6"><?= formatCurrency($summary['total_revenue']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-bed me-2 text-info"></i>By Room Type</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <?php foreach ($byRoomType as $rt): ?>
                    <tr>
                        <td><?= sanitize($rt['type_name']) ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $rt['cnt'] ?></span></td>
                        <td class="text-end fw-bold"><?= formatCurrency($rt['revenue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($byRoomType)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-wallet me-2 text-success"></i>Payment Mix</h6></div>
            <div class="card-body">
                <?php
                $gross = $summary['total_revenue'];
                $cashPct  = $gross > 0 ? round($summary['total_cash']  / $gross * 100) : 0;
                $gcashPct = $gross > 0 ? round($summary['total_gcash'] / $gross * 100) : 0;
                $splitPct = $gross > 0 ? round($summary['total_split'] / $gross * 100) : 0;
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-money-bill text-success me-2"></i>Cash</span>
                    <div class="text-end">
                        <strong class="text-success"><?= formatCurrency($summary['total_cash']) ?></strong>
                        <div class="text-muted" style="font-size:0.7rem"><?= $cashPct ?>%</div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-mobile-alt text-primary me-2"></i>GCash</span>
                    <div class="text-end">
                        <strong class="text-primary"><?= formatCurrency($summary['total_gcash']) ?></strong>
                        <div class="text-muted" style="font-size:0.7rem"><?= $gcashPct ?>%</div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span><i class="fas fa-divide text-info me-2"></i>Split</span>
                    <div class="text-end">
                        <strong class="text-info"><?= formatCurrency($summary['total_split']) ?></strong>
                        <div class="text-muted" style="font-size:0.7rem"><?= $splitPct ?>%</div>
                    </div>
                </div>
                <div class="progress" style="height:8px;border-radius:6px">
                    <div class="progress-bar bg-success" style="width:<?= $cashPct ?>%" title="Cash"></div>
                    <div class="progress-bar bg-primary" style="width:<?= $gcashPct ?>%" title="GCash"></div>
                    <div class="progress-bar bg-info"    style="width:<?= $splitPct ?>%" title="Split"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CASHIER REMITTANCE -->
<?php if (!empty($byCashier)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-user-tie me-2 text-warning"></i>Cashier Remittance Summary</h6>
        <span class="text-muted small">Total: <?= count($byCashier) ?> staff</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Staff Name</th><th class="text-center">Txns</th>
                    <th class="text-end">Cash</th><th class="text-end">GCash</th>
                    <th class="text-end">Split</th><th class="text-end fw-bold">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byCashier as $c): ?>
                <tr>
                    <td><strong><?= sanitize($c['full_name']) ?></strong><br><small class="text-muted"><?= sanitize($c['username']) ?></small></td>
                    <td class="text-center"><span class="badge bg-secondary"><?= $c['txn_count'] ?></span></td>
                    <td class="text-end text-success fw-bold"><?= formatCurrency($c['cash']) ?></td>
                    <td class="text-end text-primary fw-bold"><?= formatCurrency($c['gcash']) ?></td>
                    <td class="text-end text-info fw-bold"><?= formatCurrency($c['split_total']) ?></td>
                    <td class="text-end fw-bold fs-6"><?= formatCurrency($c['total_collected']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-warning">
                <tr>
                    <td colspan="3" class="fw-bold text-end">TOTALS:</td>
                    <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'cash'))) ?></td>
                    <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'gcash'))) ?></td>
                    <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'split_total'))) ?></td>
                    <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'total_collected'))) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- TRANSACTION DETAIL TABLE -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>Transaction Details</h6>
        <div class="d-flex gap-2 align-items-center no-print">
            <span class="badge bg-secondary"><?= count($transactions) ?> records</span>
            <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=csv" class="btn btn-xs btn-outline-success">
                <i class="fas fa-download me-1"></i>CSV
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm data-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Receipt Ref</th><th>Guest</th><th>Room</th>
                        <th>Check-In</th><th>Check-Out</th>
                        <th>Base</th><th>Surcharge</th><th>Discount</th><th>Extras</th>
                        <th>Total Paid</th><th>Method</th><th>Cashier</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td class="font-mono small fw-bold"><?= sanitize($t['booking_ref']) ?></td>
                        <td><?= sanitize($t['guest_name']) ?><?= $t['is_peak'] ? ' <span class="badge bg-warning text-dark" style="font-size:0.6rem">PEAK</span>' : '' ?></td>
                        <td><strong><?= sanitize($t['room_number']) ?></strong><br><small class="text-muted"><?= sanitize($t['type_name']) ?></small></td>
                        <td class="small"><?= formatDateTime($t['check_in']) ?></td>
                        <td class="small"><?= $t['check_out'] ? formatDateTime($t['check_out']) : '<span class="text-warning small">Active</span>' ?></td>
                        <td><?= formatCurrency($t['base_amount']) ?></td>
                        <td class="text-warning"><?= $t['peak_surcharge'] > 0 ? '+ '.formatCurrency($t['peak_surcharge']) : '—' ?></td>
                        <td class="text-danger"><?= $t['discount_amount'] > 0 ? '- '.formatCurrency($t['discount_amount']) : '—' ?></td>
                        <td><?= ($t['extension_fee']+$t['late_checkout_fee']) > 0 ? '+ '.formatCurrency($t['extension_fee']+$t['late_checkout_fee']) : '—' ?></td>
                        <td class="fw-bold text-success"><?= formatCurrency($t['amount_paid'] ?: $t['total_amount']) ?></td>
                        <td>
                            <?php if ($t['payment_method']): ?>
                            <span class="badge bg-<?= $t['payment_method']==='cash'?'success':($t['payment_method']==='gcash'?'primary':'info') ?>">
                                <?= strtoupper($t['payment_method']) ?>
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="small"><?= sanitize($t['cashier_name'] ?? '—') ?></td>
                        <td><?= getStatusBadge($t['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transactions)): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">No transactions for this period</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($transactions)): ?>
                <tfoot class="table-warning">
                    <tr>
                        <td colspan="5" class="text-end fw-bold">TOTALS:</td>
                        <td class="fw-bold"><?= formatCurrency(array_sum(array_column($transactions,'base_amount'))) ?></td>
                        <td class="text-warning fw-bold">+ <?= formatCurrency(array_sum(array_column($transactions,'peak_surcharge'))) ?></td>
                        <td class="text-danger fw-bold">- <?= formatCurrency(array_sum(array_column($transactions,'discount_amount'))) ?></td>
                        <td class="fw-bold">+ <?= formatCurrency(array_sum(array_column($transactions,'extension_fee'))+array_sum(array_column($transactions,'late_checkout_fee'))) ?></td>
                        <td class="fw-bold text-success"><?= formatCurrency($summary['total_revenue']) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>


<!-- ===== END-OF-DAY REPORT MODAL =========================================== -->
<div class="modal fade" id="eodModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-cash-register me-2"></i>End-of-Day Shift Report — <?= formatDate($dateFrom) ?><?= $dateFrom !== $dateTo ? ' to '.formatDate($dateTo) : '' ?>
                </h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="eodContent">
                <!-- EOD PRINTABLE CONTENT -->
                <div class="text-center mb-4 border-bottom pb-3">
                    <h4 class="fw-bold"><?= HOTEL_NAME ?></h4>
                    <h6 class="text-muted">END-OF-DAY SHIFT SUMMARY</h6>
                    <div class="text-muted small">
                        Period: <strong><?= formatDate($dateFrom) ?><?= $dateFrom !== $dateTo ? ' — '.formatDate($dateTo) : '' ?></strong>
                        &nbsp;|&nbsp; Generated: <strong><?= date('F d, Y h:i A') ?></strong>
                        &nbsp;|&nbsp; By: <strong><?= sanitize($currentUser['full_name']) ?></strong>
                    </div>
                </div>

                <!-- Summary boxes -->
                <div class="row g-3 mb-4">
                    <div class="col-3 text-center border rounded p-3">
                        <div class="fs-2 fw-bold text-primary"><?= $summary['total_bookings'] ?></div>
                        <div class="small text-muted">Total Transactions</div>
                    </div>
                    <div class="col-3 text-center border rounded p-3">
                        <div class="fs-3 fw-bold text-success"><?= formatCurrency($summary['total_cash']) ?></div>
                        <div class="small text-muted">Cash Collected</div>
                    </div>
                    <div class="col-3 text-center border rounded p-3">
                        <div class="fs-3 fw-bold text-primary"><?= formatCurrency($summary['total_gcash']) ?></div>
                        <div class="small text-muted">GCash Collected</div>
                    </div>
                    <div class="col-3 text-center border-2 border-warning rounded p-3">
                        <div class="fs-3 fw-bold text-warning"><?= formatCurrency($summary['total_revenue']) ?></div>
                        <div class="small text-muted fw-bold">NET REVENUE</div>
                    </div>
                </div>

                <!-- Remittance table -->
                <h6 class="fw-bold border-bottom pb-2 mb-2">Cashier Remittance</h6>
                <?php if (!empty($byCashier)): ?>
                <table class="table table-sm table-bordered mb-4">
					<thead class="table-dark">
						<tr>
							<th>Staff</th>
							<th class="text-center">Txns</th>
							<th class="text-end">Cash</th>
							<th class="text-end">GCash</th>
							<th class="text-end">Split</th>
							<th class="text-end">Total Remit</th>
							<th>Signature</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($byCashier as $c): ?>
						<tr>
							<td><strong><?= sanitize($c['full_name']) ?></strong><br><small class="text-muted"><?= sanitize($c['username']) ?></small></td>
							<td class="text-center"><?= (int)$c['txn_count'] ?></td>
							<td class="text-end fw-bold text-success"><?= formatCurrency($c['cash']) ?></td>
							<td class="text-end fw-bold text-primary"><?= formatCurrency($c['gcash']) ?></td>
							<td class="text-end"><?= formatCurrency($c['split_total']) ?></td>
							<td class="text-end fw-bold fs-6"><?= formatCurrency($c['total_collected']) ?></td>
							<td style="width:120px">&nbsp;</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
                    <tfoot class="table-warning">
                        <tr>
                            <td colspan="2" class="fw-bold text-end">TOTALS:</td>
                            <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'cash'))) ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'gcash'))) ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'split_total'))) ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency(array_sum(array_column($byCashier,'total_collected'))) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <?php else: ?>
                <div class="alert alert-light">No check-outs recorded in this period.</div>
                <?php endif; ?>

                <!-- Revenue summary -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <h6 class="fw-bold border-bottom pb-2 mb-2">Revenue Summary</h6>
                        <table class="table table-sm table-bordered">
                            <tr><td>Base Room Revenue</td><td class="text-end"><?= formatCurrency($summary['total_base']) ?></td></tr>
                            <tr><td>Peak Surcharges</td><td class="text-end text-warning">+ <?= formatCurrency($summary['total_surcharge']) ?></td></tr>
                            <tr><td>Extension / Late Fees</td><td class="text-end">+ <?= formatCurrency($summary['total_extension'] + $summary['total_late']) ?></td></tr>
                            <tr><td>Discounts Given</td><td class="text-end text-danger">- <?= formatCurrency($summary['total_discount']) ?></td></tr>
                            <tr class="table-light"><td class="fw-bold">Gross Revenue</td><td class="text-end fw-bold"><?= formatCurrency($summary['total_revenue']) ?></td></tr>
                            <tr class="table-warning"><td class="fw-bold">NET REVENUE</td><td class="text-end fw-bold"><?= formatCurrency($summary['total_revenue']) ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold border-bottom pb-2 mb-2">Verification Checklist</h6>
                        <table class="table table-sm table-bordered">
                            <tr><td>Cash in Drawer</td><td style="width:120px">&nbsp;</td></tr>
                            <tr><td>System Cash Total: <strong><?= formatCurrency($summary['total_cash']) ?></strong></td><td>&nbsp;</td></tr>
                            <tr><td>Variance (Drawer vs System)</td><td>&nbsp;</td></tr>
                            <tr><td>GCash Screenshot Attached</td><td class="text-center">☐ Yes &nbsp; ☐ No</td></tr>
                            <tr><td>Supervisor Verified</td><td>&nbsp;</td></tr>
                        </table>
                        <div class="mt-3">
                            <div class="row">
                                <div class="col-6 text-center border-top pt-3 mt-4">
                                    <div class="text-muted small">Cashier Signature</div>
                                </div>
                                <div class="col-6 text-center border-top pt-3 mt-4">
                                    <div class="text-muted small">Supervisor Signature</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transaction list (compact) -->
                <h6 class="fw-bold border-bottom pb-2 mb-2">Transaction Log (<?= count($transactions) ?> records)</h6>
                <table class="table table-sm table-bordered" style="font-size:0.8rem">
                    <thead class="table-dark">
                        <tr><th>#</th><th>Receipt Ref</th><th>Guest</th><th>Room</th><th>Check-In</th><th>Check-Out</th><th>Total</th><th>Method</th><th>Cashier</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $i => $t): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td class="font-mono"><?= sanitize($t['booking_ref']) ?></td>
                            <td><?= sanitize($t['guest_name']) ?></td>
                            <td><?= sanitize($t['room_number']) ?></td>
                            <td><?= date('M d h:iA', strtotime($t['check_in'])) ?></td>
                            <td><?= $t['check_out'] ? date('M d h:iA', strtotime($t['check_out'])) : 'Active' ?></td>
                            <td class="fw-bold"><?= formatCurrency($t['amount_paid'] ?: $t['total_amount']) ?></td>
                            <td><?= strtoupper($t['payment_method'] ?? '—') ?></td>
                            <td><?= sanitize($t['cashier_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer no-print">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=csv" class="btn btn-success">
                    <i class="fas fa-file-csv me-2"></i>Export CSV
                </a>
                <button class="btn btn-warning fw-bold" onclick="printEod()">
                    <i class="fas fa-print me-2"></i>Print EOD Report
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
function printEod() {
    const content = document.getElementById('eodContent').innerHTML;
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>EOD Report - <?= HOTEL_NAME ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <style>
            body { font-size: 12px; padding: 20px; }
            @media print { body { padding: 0; } }
        </style>
    </head><body>` + content + `</body></html>`);
    win.document.close();
    setTimeout(() => { win.print(); }, 600);
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
