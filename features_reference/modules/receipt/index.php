<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin', 'front_desk']);

$pageTitle = 'Guest Receipt';
$pdo = getPDO();
$bookingId = (int)($_GET['booking_id'] ?? 0);
$bookingRef = sanitize($_GET['ref'] ?? '');
$autoPrint = isset($_GET['print']) ? 1 : 0;

if ($bookingId <= 0 && $bookingRef !== '') {
    $stmt = $pdo->prepare("SELECT id FROM bookings WHERE booking_ref = ? LIMIT 1");
    $stmt->execute([$bookingRef]);
    $row = $stmt->fetch();
    if ($row) {
        $bookingId = (int)$row['id'];
    }
}

if ($bookingId <= 0) {
    setFlash('error', 'Receipt record not found.');
    header('Location: ' . BASE_URL . 'modules/checkout/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT
        b.*, r.room_number, r.floor, rt.type_name,
        ci.full_name AS checked_in_name,
        co.full_name AS checked_out_name,
        gp.email AS guest_email,
        gp.address AS guest_address
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN room_types rt ON r.room_type_id = rt.id
    LEFT JOIN users ci ON b.checked_in_by = ci.id
    LEFT JOIN users co ON b.checked_out_by = co.id
    LEFT JOIN guest_profiles gp ON b.guest_profile_id = gp.id
    WHERE b.id = ?
    LIMIT 1");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Receipt record not found.');
    header('Location: ' . BASE_URL . 'modules/checkout/index.php');
    exit;
}

$transactionsStmt = $pdo->prepare("SELECT t.*, u.full_name AS processed_by_name
    FROM transactions t
    LEFT JOIN users u ON t.processed_by = u.id
    WHERE t.booking_id = ?
    ORDER BY t.created_at ASC, t.id ASC");
$transactionsStmt->execute([$bookingId]);
$transactions = $transactionsStmt->fetchAll();

$displayStayType = ($booking['booking_type'] === 'short_time' || $booking['booking_type'] === 'hourly')
    ? 'Short Time'
    : 'Overnight';
$receiptNumber = 'RCP-' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$booking['booking_ref']));
$balanceDue = getBalanceDue((float)$booking['total_amount'], (float)$booking['amount_paid']);
$paymentMethodLabel = strtoupper((string)$booking['payment_method']);
if ($paymentMethodLabel === 'SPLIT') {
    $paymentMethodLabel = 'SPLIT (CASH + GCASH)';
}

$chargeRows = [
    ['Base Room Charge', (float)$booking['base_amount']],
];
if ((float)$booking['peak_surcharge'] > 0) {
    $chargeRows[] = ['Peak Date Surcharge', (float)$booking['peak_surcharge']];
}
if ((float)$booking['extension_fee'] > 0) {
    $chargeRows[] = ['Extension Fee', (float)$booking['extension_fee']];
}
if ((float)$booking['late_checkout_fee'] > 0) {
    $lateLabel = 'Late Check-Out Fee';
    if ((int)($booking['late_hours'] ?? 0) > 0) {
        $lateLabel .= ' (' . (int)$booking['late_hours'] . ' hr/s)';
    }
    $chargeRows[] = [$lateLabel, (float)$booking['late_checkout_fee']];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start no-print">
    <div>
        <h4><i class="fas fa-print me-2 text-primary"></i>Guest Receipt</h4>
        <p>Printable receipt for guest payment and stay details.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>modules/checkout/index.php<?= $booking['booking_ref'] ? '?q=' . urlencode($booking['booking_ref']) : '' ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back
        </a>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Print Receipt
        </button>
    </div>
</div>

<div class="card receipt-card mb-4">
    <div class="card-body p-4 p-md-5">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 border-bottom pb-3 mb-4">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= BASE_URL ?>assets/img/logo.jpg" alt="<?= HOTEL_NAME ?>" style="height:58px;width:auto;object-fit:contain;" class="receipt-logo">
                <div>
                    <div class="fs-4 fw-bold"><?= HOTEL_NAME ?></div>
                    <div class="text-muted small"><?= HOTEL_ADDRESS ?></div>
                    <div class="text-muted small"><?= HOTEL_CONTACT ?><?= HOTEL_EMAIL ? ' • ' . HOTEL_EMAIL : '' ?></div>
                </div>
            </div>
            <div class="text-md-end">
                <div class="small text-uppercase text-muted fw-bold">Guest Receipt</div>
                <div class="fw-bold font-mono fs-5"><?= sanitize($receiptNumber) ?></div>
                <div class="small text-muted">Booking Ref: <?= sanitize($booking['booking_ref']) ?></div>
                <div class="small text-muted">Printed: <?= date('M d, Y h:i A') ?></div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="text-muted text-uppercase small fw-bold mb-2">Guest Details</div>
                <div class="mb-1"><strong><?= sanitize($booking['guest_name']) ?></strong></div>
                <div class="small mb-1"><span class="text-muted">Contact:</span> <?= sanitize($booking['guest_contact'] ?: '—') ?></div>
                <div class="small mb-1"><span class="text-muted">ID:</span> <?= $booking['guest_id_type'] ? sanitize($booking['guest_id_type']) . ($booking['guest_id_number'] ? ' - ' . sanitize($booking['guest_id_number']) : '') : '—' ?></div>
                <div class="small mb-1"><span class="text-muted">Guests:</span> <?= (int)$booking['num_guests'] ?></div>
                <div class="small mb-1"><span class="text-muted">Email:</span> <?= sanitize($booking['guest_email'] ?: '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted text-uppercase small fw-bold mb-2">Stay Details</div>
                <div class="small mb-1"><span class="text-muted">Room:</span> <strong><?= sanitize($booking['room_number']) ?></strong> — <?= sanitize($booking['type_name']) ?></div>
                <div class="small mb-1"><span class="text-muted">Stay Type:</span> <?= sanitize($displayStayType) ?><?php if (($booking['booking_type'] === 'short_time' || $booking['booking_type'] === 'hourly') && (int)$booking['short_time_hours'] > 0): ?> (<?= (int)$booking['short_time_hours'] ?> hrs)<?php endif; ?></div>
                <div class="small mb-1"><span class="text-muted">Check-In:</span> <?= formatDateTime($booking['check_in']) ?></div>
                <div class="small mb-1"><span class="text-muted">Expected Check-Out:</span> <?= formatDateTime($booking['expected_check_out']) ?></div>
                <div class="small mb-1"><span class="text-muted">Actual Check-Out:</span> <?= formatDateTime($booking['check_out']) ?></div>
                <div class="small mb-1"><span class="text-muted">Status:</span> <?= ucfirst(str_replace('_', ' ', (string)$booking['status'])) ?></div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle receipt-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Charge Details</th>
                                <th class="text-end" style="width:180px">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($chargeRows as [$label, $amount]): ?>
                            <tr>
                                <td><?= sanitize($label) ?></td>
                                <td class="text-end fw-semibold"><?= formatCurrency($amount) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if ((float)$booking['discount_amount'] > 0): ?>
                            <tr>
                                <td>
                                    Discount<?= $booking['discount_type'] ? ' (' . sanitize(ucwords(str_replace('_', ' ', (string)$booking['discount_type']))) . ')' : '' ?>
                                </td>
                                <td class="text-end fw-semibold text-danger">- <?= formatCurrency($booking['discount_amount']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="table-success">
                                <td class="fw-bold">Total Bill</td>
                                <td class="text-end fw-bold"><?= formatCurrency($booking['total_amount']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="border rounded-3 p-3 bg-light-subtle h-100">
                    <div class="text-muted text-uppercase small fw-bold mb-2">Payment Summary</div>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>Total Paid</span><strong class="text-success"><?= formatCurrency($booking['amount_paid']) ?></strong></div>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>Balance Due</span><strong class="<?= $balanceDue > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($balanceDue) ?></strong></div>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>Payment Status</span><strong><?= strtoupper((string)$booking['payment_status']) ?></strong></div>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>Payment Method</span><strong><?= sanitize($paymentMethodLabel ?: '—') ?></strong></div>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>Cash Paid</span><span><?= formatCurrency($booking['cash_amount']) ?></span></div>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>GCash Paid</span><span><?= formatCurrency($booking['gcash_amount']) ?></span></div>
                    <?php if ($booking['gcash_ref']): ?>
                    <div class="d-flex justify-content-between py-1 border-bottom"><span>GCash Ref</span><strong class="font-mono"><?= sanitize($booking['gcash_ref']) ?></strong></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between py-1"><span>Processed By</span><strong><?= sanitize($booking['checked_out_name'] ?: $booking['checked_in_name'] ?: getCurrentUser()['full_name']) ?></strong></div>
                </div>
            </div>
        </div>

        <?php if (!empty($transactions)): ?>
        <div class="mt-4">
            <div class="text-muted text-uppercase small fw-bold mb-2">Payment / Activity Trail</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date/Time</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                        <tr>
                            <td class="small"><?= formatDateTime($txn['created_at']) ?></td>
                            <td><span class="badge bg-secondary"><?= sanitize(strtoupper(str_replace('_', ' ', (string)$txn['transaction_type']))) ?></span></td>
                            <td class="small"><?= sanitize($txn['description'] ?: '—') ?></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($txn['amount']) ?></td>
                            <td class="small"><?= sanitize(strtoupper((string)$txn['payment_method'])) ?><?= $txn['gcash_ref'] ? '<div class="text-muted font-mono">' . sanitize($txn['gcash_ref']) . '</div>' : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($booking['notes']): ?>
        <div class="mt-4 border-top pt-3">
            <div class="text-muted text-uppercase small fw-bold mb-2">Notes</div>
            <div class="small"><?= nl2br(sanitize((string)$booking['notes'])) ?></div>
        </div>
        <?php endif; ?>

        <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div class="small text-muted">
                This is a system-generated guest receipt for reference and internal hotel operations.
            </div>
            <div class="text-end">
                <div class="small text-muted">Received by:</div>
                <div class="fw-semibold" style="min-width:220px;border-top:1px solid #999;padding-top:6px">
                    <?= sanitize($booking['guest_name']) ?> / Authorized Representative
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
document.addEventListener('DOMContentLoaded', function () {
    const shouldAutoPrint = {$autoPrint};
    if (shouldAutoPrint) {
        setTimeout(() => window.print(), 500);
    }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
