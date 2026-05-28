<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin','front_desk']);

$pageTitle = 'Bookings & Check-Out';
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'checkout')) {
    $bookingId      = (int)($_POST['booking_id'] ?? 0);
    $paymentMethod  = $_POST['payment_method'] ?? 'cash';
    $cashAmount     = (float)($_POST['cash_amount'] ?? 0);
    $gcashAmount    = (float)($_POST['gcash_amount'] ?? 0);
    $gcashRef       = sanitize($_POST['gcash_ref'] ?? '');
    $addlNotes      = sanitize($_POST['notes'] ?? '');

    $stmt = $pdo->prepare("SELECT b.*, r.room_number, r.id AS room_id, rt.type_name
                           FROM bookings b
                           JOIN rooms r ON b.room_id = r.id
                           JOIN room_types rt ON r.room_type_id = rt.id
                           WHERE b.id = ? AND b.status = 'active'");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        setFlash('error', 'Booking not found or already checked out.');
    } else {
        $actualCheckout = new DateTime('now');
        $lateHours      = calculateLateCheckoutHours($booking['expected_check_out'], $actualCheckout);
        $lateFee        = round($lateHours * LATE_CHECKOUT_FEE, 2);
        $finalTotal     = round((float)$booking['base_amount'] + (float)$booking['peak_surcharge'] - (float)$booking['discount_amount'] + (float)$booking['extension_fee'] + $lateFee, 2);
        $previousPaid   = (float)$booking['amount_paid'];
        $balanceDue     = getBalanceDue($finalTotal, $previousPaid);
        $tenderedNow    = $paymentMethod === 'cash' ? $cashAmount : ($paymentMethod === 'gcash' ? $gcashAmount : $cashAmount + $gcashAmount);

        if (($paymentMethod === 'gcash' || $paymentMethod === 'split') && $gcashAmount > 0 && $gcashRef === '') {
            setFlash('error', 'GCash reference number is required.');
        } elseif ($balanceDue > 0 && $tenderedNow + 0.009 < $balanceDue) {
            setFlash('error', 'Outstanding balance must be fully paid before checkout.');
        } else {
            $newAmountPaid = round($previousPaid + $tenderedNow, 2);
            $paymentStatus = computePaymentStatus($finalTotal, $newAmountPaid);

            if ($paymentStatus !== 'paid') {
                setFlash('error', 'Booking cannot be checked out because there is still an unpaid balance.');
            } else {
                $updatedCash  = round((float)$booking['cash_amount'] + $cashAmount, 2);
                $updatedGcash = round((float)$booking['gcash_amount'] + $gcashAmount, 2);
                $updatedMethod = $booking['payment_method'];
                if ($tenderedNow > 0) {
                    if ($booking['payment_method'] !== $paymentMethod && (float)$booking['amount_paid'] > 0) {
                        $updatedMethod = 'split';
                    } else {
                        $updatedMethod = $paymentMethod;
                    }
                }

                $pdo->prepare("UPDATE bookings
                               SET status='checked_out',
                                   check_out=?,
                                   amount_paid=?,
                                   payment_status='paid',
                                   payment_method=?,
                                   cash_amount=?,
                                   gcash_amount=?,
                                   gcash_ref=CASE WHEN ? != '' THEN ? ELSE gcash_ref END,
                                   late_hours=?,
                                   late_checkout_fee=?,
                                   total_amount=?,
                                   notes=CONCAT(IFNULL(notes,''), CASE WHEN ? != '' THEN CONCAT('\n[Checkout Note] ', ?) ELSE '' END),
                                   checked_out_by=?
                               WHERE id=?")
                    ->execute([
                        $actualCheckout->format('Y-m-d H:i:s'),
                        $newAmountPaid,
                        $updatedMethod,
                        $updatedCash,
                        $updatedGcash,
                        $gcashRef,
                        $gcashRef,
                        $lateHours,
                        $lateFee,
                        $finalTotal,
                        $addlNotes,
                        $addlNotes,
                        $_SESSION['user_id'],
                        $bookingId,
                    ]);

                $pdo->prepare("UPDATE rooms SET status='cleaning' WHERE id=?")->execute([$booking['room_id']]);

                if ($booking['guest_profile_id']) {
                    $pdo->prepare("UPDATE guest_profiles SET total_spent = total_spent + ?, last_visit = CURDATE() WHERE id=?")
                        ->execute([$tenderedNow, $booking['guest_profile_id']]);
                }

                $desc = "Check-out: {$booking['guest_name']} - Room {$booking['room_number']}";
                if ((float)$booking['extension_fee'] > 0) $desc .= ' | Extension: ' . formatCurrency($booking['extension_fee']);
                if ($lateFee > 0) $desc .= ' | Late checkout: ' . formatCurrency($lateFee) . " ({$lateHours} hr/s)";
                if ($balanceDue > 0) $desc .= ' | Settlement: ' . formatCurrency($tenderedNow);
                $pdo->prepare("INSERT INTO transactions (booking_id, transaction_type, description, amount, payment_method, cash_amount, gcash_amount, gcash_ref, processed_by)
                               VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$bookingId, 'check_out', $desc, $tenderedNow, $paymentMethod, $cashAmount, $gcashAmount, $gcashRef, $_SESSION['user_id']]);

                auditLog($_SESSION['user_id'], 'CHECK_OUT', 'bookings', $bookingId, 'active', 'checked_out', 'Checked out with full settlement.');
                setFlash('success', "Check-out successful for <strong>{$booking['guest_name']}</strong>. Total Bill: <strong>" . formatCurrency($finalTotal) . "</strong>");
            }
        }
    }

    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'move_room')) {
    requireRole(['admin', 'front_desk']);
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $newRoomId = (int)($_POST['new_room_id'] ?? 0);
    $reason    = sanitize($_POST['reason'] ?? '');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT b.*, r.id AS old_room_id, r.room_number AS old_room_number
                               FROM bookings b
                               JOIN rooms r ON b.room_id = r.id
                               WHERE b.id = ? AND b.status = 'active' FOR UPDATE");
        $stmt->execute([$bookingId]);
        $bk = $stmt->fetch();

        if (!$bk) {
            throw new RuntimeException('Booking not found or not active.');
        }
        if ($newRoomId <= 0 || $newRoomId == (int)$bk['old_room_id']) {
            throw new RuntimeException('Please select a different room.');
        }

        $stmt = $pdo->prepare("SELECT r.*, rt.type_name
                               FROM rooms r
                               JOIN room_types rt ON r.room_type_id = rt.id
                               WHERE r.id = ? AND r.status = 'vacant' FOR UPDATE");
        $stmt->execute([$newRoomId]);
        $newRoom = $stmt->fetch();

        if (!$newRoom) {
            throw new RuntimeException('Selected new room is not available.');
        }

        $transferRoomNote = "Moved guest to Room {$newRoom['room_number']}" . ($reason ? " | $reason" : '');
        $transferBookingNote = "[Room Transfer] {$bk['old_room_number']} → {$newRoom['room_number']}" . ($reason ? " | {$reason}" : '');
        $transferTxDesc = "Room change: {$bk['guest_name']} from {$bk['old_room_number']} to {$newRoom['room_number']}" . ($reason ? " | {$reason}" : '');

        $pdo->prepare("UPDATE bookings
                       SET room_id = ?,
                           notes = TRIM(CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '
'), ?)),
                           updated_at = NOW()
                       WHERE id = ?")
            ->execute([$newRoomId, $transferBookingNote, $bookingId]);
        $pdo->prepare("UPDATE rooms SET status='cleaning', notes=? WHERE id=?")
            ->execute([$transferRoomNote, $bk['old_room_id']]);
        $pdo->prepare("UPDATE rooms SET status='occupied', notes='' WHERE id=?")->execute([$newRoomId]);

        auditLog($_SESSION['user_id'], 'ROOM_REASSIGNED', 'bookings', $bookingId,
            json_encode(['room_id' => $bk['old_room_id'], 'room_number' => $bk['old_room_number']]),
            json_encode(['room_id' => $newRoomId, 'room_number' => $newRoom['room_number']]),
            $reason ?: 'Room reassigned');

        $pdo->prepare("INSERT INTO transactions (booking_id, transaction_type, description, amount, processed_by)
                       VALUES (?, 'adjustment', ?, 0, ?)")
            ->execute([$bookingId, $transferTxDesc, $_SESSION['user_id']]);

        $pdo->commit();
        setFlash('success', "Room reassigned: <strong>{$bk['old_room_number']}</strong> → <strong>{$newRoom['room_number']}</strong>.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlash('error', sanitize($e->getMessage()));
    }

    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'extend_stay')) {
    requireRole(['admin', 'front_desk']);
    $bookingId       = (int)($_POST['booking_id'] ?? 0);
    $extensionHours  = (int)($_POST['extension_hours'] ?? 0);
    $newExpected     = $_POST['expected_check_out'] ?? '';
    $additionalFee   = (float)($_POST['additional_fee'] ?? 0);
    $reason          = sanitize($_POST['reason'] ?? '');
    $paymentMethod   = $_POST['payment_method'] ?? 'cash';
    $cashAmount      = (float)($_POST['cash_amount'] ?? 0);
    $gcashAmount     = (float)($_POST['gcash_amount'] ?? 0);
    $gcashRef        = sanitize($_POST['gcash_ref'] ?? '');

    $stmt = $pdo->prepare("SELECT b.*, r.room_number, rt.type_name, rt.hourly_rate, rt.short_time_3h_rate, rt.short_time_6h_rate, rt.short_time_12h_rate, rt.short_time_24h_rate
                           FROM bookings b
                           JOIN rooms r ON b.room_id = r.id
                           JOIN room_types rt ON r.room_type_id = rt.id
                           WHERE b.id = ? AND b.status = 'active'");
    $stmt->execute([$bookingId]);
    $bk = $stmt->fetch();

    if (!$bk) {
        setFlash('error', 'Booking not found or not active.');
    } else {
        $bookingType = $bk['booking_type'] === 'hourly' ? 'short_time' : $bk['booking_type'];

        try {
            if (!in_array($paymentMethod, ['cash', 'gcash', 'split'], true)) {
                $paymentMethod = 'cash';
            }
            if (($paymentMethod === 'gcash' || $paymentMethod === 'split') && $gcashAmount > 0 && $gcashRef === '') {
                throw new RuntimeException('GCash reference number is required.');
            }

            if ($bookingType === 'short_time') {
                if (!isValidShortTimeDuration($extensionHours)) {
                    throw new RuntimeException('Please select a valid short-time extension.');
                }

                $addlFee = round((float)getExtensionRateForBooking($bk, $bk, $extensionHours), 2);
                $currentExpected = new DateTime($bk['expected_check_out']);
                $currentExpected->modify('+' . $extensionHours . ' hour');
                $computedExpected = $currentExpected->format('Y-m-d H:i:s');
            } else {
                if (!$newExpected || strtotime($newExpected) === false) {
                    throw new RuntimeException('Please provide a valid new expected check-out date/time.');
                }
                if ($bk['expected_check_out'] && strtotime($newExpected) <= strtotime($bk['expected_check_out'])) {
                    throw new RuntimeException('New expected check-out must be later than the current expected check-out.');
                }

                $computedExpected = date('Y-m-d H:i:s', strtotime($newExpected));
                $addlFee = round(max(0, $additionalFee), 2);
            }

            $newTotal = round((float)$bk['total_amount'] + $addlFee, 2);
            $amountDueNow = getBalanceDue($newTotal, (float)$bk['amount_paid']);
            $tenderedNow = $paymentMethod === 'cash' ? $cashAmount : ($paymentMethod === 'gcash' ? $gcashAmount : $cashAmount + $gcashAmount);

            if ($amountDueNow > 0 && $tenderedNow + 0.009 < $amountDueNow) {
                throw new RuntimeException('Extension must be fully paid before saving.');
            }

            $newAmountPaid = round((float)$bk['amount_paid'] + $tenderedNow, 2);
            $paymentStatus = computePaymentStatus($newTotal, $newAmountPaid);
            if ($paymentStatus !== 'paid') {
                throw new RuntimeException('Extension cannot be saved because the booking is still not fully paid.');
            }

            $updatedCash = round((float)$bk['cash_amount'] + $cashAmount, 2);
            $updatedGcash = round((float)$bk['gcash_amount'] + $gcashAmount, 2);
            $updatedMethod = $bk['payment_method'];
            if ($tenderedNow > 0) {
                if ($bk['payment_method'] !== $paymentMethod && (float)$bk['amount_paid'] > 0) {
                    $updatedMethod = 'split';
                } else {
                    $updatedMethod = $paymentMethod;
                }
            }

            $extensionNote = $reason !== ''
                ? '[Extension Note] ' . $reason
                : '[Extension] ' . (($bookingType === 'short_time') ? ('+' . $extensionHours . ' hour(s)') : ('New expected out: ' . $computedExpected));
            $extensionDesc = ($bookingType === 'short_time')
                ? "Short-time extension payment: {$bk['guest_name']} | +{$extensionHours} hour(s) | Paid " . formatCurrency($tenderedNow)
                : "Overnight extension payment: {$bk['guest_name']} | New expected out: {$computedExpected} | Paid " . formatCurrency($tenderedNow);
            if ($reason !== '') {
                $extensionDesc .= ' | Note: ' . $reason;
            }

            $pdo->beginTransaction();

            $pdo->prepare("UPDATE bookings
                           SET expected_check_out=?,
                               extension_fee=IFNULL(extension_fee,0) + ?,
                               total_amount=?,
                               amount_paid=?,
                               payment_status='paid',
                               payment_method=?,
                               cash_amount=?,
                               gcash_amount=?,
                               gcash_ref=CASE WHEN ? != '' THEN ? ELSE gcash_ref END,
                               notes=TRIM(CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '
'), ?)),
                               updated_at=NOW()
                           WHERE id=?")
                ->execute([
                    $computedExpected,
                    $addlFee,
                    $newTotal,
                    $newAmountPaid,
                    $updatedMethod,
                    $updatedCash,
                    $updatedGcash,
                    $gcashRef,
                    $gcashRef,
                    $extensionNote,
                    $bookingId,
                ]);

            auditLog($_SESSION['user_id'], 'EXTEND_STAY', 'bookings', $bookingId,
                json_encode(['expected_check_out' => $bk['expected_check_out'], 'extension_fee' => $bk['extension_fee'], 'amount_paid' => $bk['amount_paid']]),
                json_encode(['expected_check_out' => $computedExpected, 'added_fee' => $addlFee, 'amount_paid' => $newAmountPaid]),
                $reason ?: 'Stay extended with full payment');

            $pdo->prepare("INSERT INTO transactions (booking_id, transaction_type, description, amount, payment_method, cash_amount, gcash_amount, gcash_ref, processed_by)
                           VALUES (?, 'extension', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$bookingId, $extensionDesc, $tenderedNow, $paymentMethod, $cashAmount, $gcashAmount, $gcashRef, $_SESSION['user_id']]);

            if (!empty($bk['guest_profile_id']) && $tenderedNow > 0) {
                $pdo->prepare("UPDATE guest_profiles SET total_spent = total_spent + ?, last_visit = CURDATE(), updated_at = NOW() WHERE id = ?")
                    ->execute([$tenderedNow, $bk['guest_profile_id']]);
            }

            $pdo->commit();
            setFlash('success', 'Stay extended successfully with full payment collected first.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('error', sanitize($e->getMessage()));
        }
    }

    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'cancel')) {
    requireRole(['admin', 'front_desk']);
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $reason    = sanitize($_POST['reason'] ?? '');
    $stmt = $pdo->prepare("SELECT b.*, r.id as room_id FROM bookings b JOIN rooms r ON b.room_id=r.id WHERE b.id=? AND b.status='active'");
    $stmt->execute([$bookingId]);
    $bk = $stmt->fetch();
    if ($bk) {
        $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$bookingId]);
        $pdo->prepare("UPDATE rooms SET status='vacant' WHERE id=?")->execute([$bk['room_id']]);
        auditLog($_SESSION['user_id'], 'CANCEL_BOOKING', 'bookings', $bookingId, 'active', 'cancelled', $reason);
        setFlash('success', 'Booking cancelled successfully.');
    }
    header('Location: index.php');
    exit;
}

$filterStatus = $_GET['status'] ?? 'active';
$search       = sanitize($_GET['q'] ?? '');

$where = '1=1';
if ($filterStatus !== 'all') $where .= " AND b.status = " . $pdo->quote($filterStatus);
if ($search) {
    $where .= " AND (b.guest_name LIKE " . $pdo->quote('%' . $search . '%') . "
               OR b.booking_ref LIKE " . $pdo->quote('%' . $search . '%') . "
               OR r.room_number LIKE " . $pdo->quote('%' . $search . '%') . ")";
}

$bookings = $pdo->query("
    SELECT b.*, r.room_number, rt.type_name, rt.short_time_3h_rate, rt.short_time_6h_rate, rt.short_time_12h_rate, rt.short_time_24h_rate, rt.hourly_rate
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN room_types rt ON r.room_type_id = rt.id
    WHERE $where
    ORDER BY b.created_at DESC")->fetchAll();

$vacantRooms = $pdo->query("SELECT r.id, r.room_number, r.floor, rt.type_name
                            FROM rooms r
                            JOIN room_types rt ON r.room_type_id = rt.id
                            WHERE r.status = 'vacant'
                            ORDER BY r.floor, r.room_number")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
$recentReceiptBookingId = (int)($_SESSION['last_receipt_booking_id'] ?? 0);
unset($_SESSION['last_receipt_booking_id']);
?>

<?php if ($recentReceiptBookingId > 0 && hasRole(['admin','front_desk'])): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><i class="fas fa-receipt me-2"></i>Check-in saved. You can print the guest receipt now.</div>
    <a href="<?= BASE_URL ?>modules/receipt/index.php?booking_id=<?= $recentReceiptBookingId ?>&print=1" class="btn btn-sm btn-dark" target="_blank">
        <i class="fas fa-print me-2"></i>Print Guest Receipt
    </a>
</div>
<?php endif; ?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-list-check me-2 text-primary"></i>Bookings & Check-Out</h4>
        <p>Late checkout is computed automatically at ₱<?= number_format(LATE_CHECKOUT_FEE, 2) ?> per hour. Short-time extensions follow the 3/6/12/24-hour package rates.</p>
    </div>
    <?php if (hasRole(['admin','front_desk'])): ?>
    <a href="<?= BASE_URL ?>modules/checkin/index.php" class="btn btn-success">
        <i class="fas fa-plus me-2"></i>New Check-In
    </a>
    <?php endif; ?>
</div>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <form class="d-flex gap-2 align-items-center flex-wrap" method="GET">
            <div class="input-group" style="max-width:300px">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Search guest, ref, room..." value="<?= sanitize($search) ?>">
            </div>
            <select name="status" class="form-select" style="max-width:160px">
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="checked_out" <?= $filterStatus === 'checked_out' ? 'selected' : '' ?>>Checked Out</option>
                <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
            <a href="index.php" class="btn btn-outline-secondary">Reset</a>
        </form>
        <span class="ms-auto text-muted small"><?= count($bookings) ?> record(s)</span>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive bookings-table-wrap">
            <table class="table table-hover mb-0 checkout-table">
                <thead class="table-light">
                    <tr>
                        <th>Ref</th><th>Guest</th><th>Room</th><th>Stay Type</th>
                        <th>Check-In</th><th>Expected Out</th>
                        <th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                    <?php $balance = getBalanceDue((float)$b['total_amount'], (float)$b['amount_paid']); ?>
                    <tr>
                        <td><span class="font-mono small fw-bold"><?= sanitize($b['booking_ref']) ?></span></td>
                        <td><?= sanitize($b['guest_name']) ?><?php if (!empty($b['notes'])): ?><div class="small text-muted mt-1"><i class="fas fa-sticky-note me-1"></i>Has notes</div><?php endif; ?></td>
                        <td><strong><?= sanitize($b['room_number']) ?></strong><div class="small text-muted"><?= sanitize($b['type_name']) ?></div></td>
                        <td>
                            <span class="badge bg-<?= (($b['booking_type'] === 'short_time' || $b['booking_type'] === 'hourly') ? 'info' : 'primary') ?>">
                                <?= ($b['booking_type'] === 'short_time' || $b['booking_type'] === 'hourly') ? 'Short Time' : 'Overnight' ?>
                            </span>
                            <?php if (($b['booking_type'] === 'short_time' || $b['booking_type'] === 'hourly') && (int)$b['short_time_hours'] > 0): ?>
                            <div class="small text-muted mt-1"><?= (int)$b['short_time_hours'] ?> hrs</div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= formatDateTime($b['check_in']) ?></td>
                        <td class="small"><?= formatDateTime($b['expected_check_out']) ?></td>
                        <td class="fw-bold"><?= formatCurrency($b['total_amount']) ?></td>
                        <td><?= formatCurrency($b['amount_paid']) ?><div class="small text-muted"><?= strtoupper($b['payment_status'] ?? 'UNPAID') ?></div></td>
                        <td class="<?= $balance > 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= formatCurrency($balance) ?></td>
                        <td><?= getStatusBadge($b['status']) ?></td>
                        <td class="action-cell">
                            <div class="action-buttons">
                                <button type="button" class="btn btn-sm btn-outline-info" data-booking-action="view" data-booking-id="<?= (int)$b['id'] ?>" title="View Details"><i class="fas fa-eye"></i></button>
                                <a href="<?= BASE_URL ?>modules/receipt/index.php?booking_id=<?= (int)$b['id'] ?>&print=1" class="btn btn-sm btn-outline-dark" target="_blank" title="Print Receipt"><i class="fas fa-print"></i></a>
                                <?php if ($b['status'] === 'active'): ?>
                                    <button type="button" class="btn btn-sm btn-success" data-booking-action="checkout" data-booking-id="<?= (int)$b['id'] ?>" title="Check-Out"><i class="fas fa-sign-out-alt"></i></button>
                                    <?php if (hasRole(['admin','front_desk'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-booking-action="move" data-booking-id="<?= (int)$b['id'] ?>" title="Move Room"><i class="fas fa-exchange-alt"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" data-booking-action="extend" data-booking-id="<?= (int)$b['id'] ?>" title="Extend Stay"><i class="fas fa-clock"></i></button>
                                    <button type="button" class="btn btn-sm btn-danger" data-booking-action="cancel" data-booking-id="<?= (int)$b['id'] ?>" title="Cancel Booking"><i class="fas fa-times"></i></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bookings)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No bookings found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Booking Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i>Process Check-Out</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="booking_id" id="coBookingId">
                <div class="modal-body">
                    <div id="coSummary" class="alert alert-light mb-3"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Current Total Bill</label>
                            <input type="text" class="form-control" id="coCurrentTotal" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Already Paid</label>
                            <input type="text" class="form-control" id="coAlreadyPaid" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Extension Fee</label>
                            <input type="text" class="form-control" id="coExtFeeDisplay" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Auto Late Checkout Fee</label>
                            <input type="text" class="form-control" id="coLateFeeDisplay" readonly>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-warning py-2 mb-0">
                                <strong>Outstanding Balance to Collect: <span id="coBalanceDue" class="text-danger fs-5">₱0.00</span></strong>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <div class="btn-group w-100">
                                <input type="radio" class="btn-check" name="payment_method" id="pmCash" value="cash" checked onchange="coPayMethodChange()">
                                <label class="btn btn-outline-success" for="pmCash">Cash</label>
                                <input type="radio" class="btn-check" name="payment_method" id="pmGcash" value="gcash" onchange="coPayMethodChange()">
                                <label class="btn btn-outline-primary" for="pmGcash">GCash</label>
                                <input type="radio" class="btn-check" name="payment_method" id="pmSplit" value="split" onchange="coPayMethodChange()">
                                <label class="btn btn-outline-warning" for="pmSplit">Split</label>
                            </div>
                        </div>
                        <div class="col-md-6" id="cashRow">
                            <label class="form-label fw-semibold">Cash Amount (₱)</label>
                            <input type="number" name="cash_amount" id="coCash" class="form-control" step="0.01" min="0" value="0" oninput="coUpdateTendered()">
                        </div>
                        <div class="col-md-6" id="gcashRow" style="display:none;">
                            <label class="form-label fw-semibold">GCash Amount (₱)</label>
                            <input type="number" name="gcash_amount" id="coGcash" class="form-control" step="0.01" min="0" value="0" oninput="coUpdateTendered()">
                        </div>
                        <div class="col-12" id="gcashRefRow" style="display:none;">
                            <label class="form-label fw-semibold">GCash Reference #</label>
                            <input type="text" name="gcash_ref" id="coGcashRef" class="form-control" placeholder="GCash transaction reference number">
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between small">
                                <span>Amount Tendered Now</span>
                                <strong id="coTendered">₱0.00</strong>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Additional Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4" id="coSubmitBtn">
                        <i class="fas fa-check me-2"></i>Confirm Check-Out
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="moveRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Reassign Room</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="move_room">
                <input type="hidden" name="booking_id" id="mvBookingId">
                <div class="modal-body">
                    <?php if (empty($vacantRooms)): ?>
                        <div class="alert alert-warning mb-0">No vacant rooms available for reassignment.</div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select New Room</label>
                            <select class="form-select" name="new_room_id" required>
                                <option value="">— Choose Vacant Room —</option>
                                <?php foreach ($vacantRooms as $vr): ?>
                                <option value="<?= (int)$vr['id'] ?>">Room <?= sanitize($vr['room_number']) ?> (F<?= (int)$vr['floor'] ?>) — <?= sanitize($vr['type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason / Notes</label>
                            <textarea class="form-control" name="reason" rows="2" placeholder="Optional reason..."></textarea>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($vacantRooms)): ?>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">Confirm Move</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="extendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><i class="fas fa-clock me-2"></i>Extend Stay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="extend_stay">
                <input type="hidden" name="booking_id" id="exBookingId">
                <div class="modal-body">
                    <div class="alert alert-light small mb-3" id="exSummary"></div>

                    <div id="shortTimeExtensionFields">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Short-Time Extension</label>
                            <select name="extension_hours" id="exDuration" class="form-select" onchange="updateExtensionPreview()">
                                <option value="3">3 Hours</option>
                                <option value="6">6 Hours</option>
                                <option value="12">12 Hours</option>
                                <option value="24">24 Hours</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Added Fee</label>
                            <input type="text" class="form-control" id="exAutoFee" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Expected Check-Out</label>
                            <input type="text" class="form-control" id="exAutoOut" readonly>
                        </div>
                    </div>

                    <div id="overnightExtensionFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Expected Check-Out</label>
                            <input type="text" name="expected_check_out" id="exExpected" class="form-control flatpickr-datetime" onchange="updateExtensionPreview()">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Additional Fee (₱)</label>
                            <input type="number" name="additional_fee" class="form-control" value="0" step="0.01" min="0" oninput="updateExtensionPreview()">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason / Notes</label>
                        <textarea class="form-control" name="reason" rows="2" placeholder="Optional reason..."></textarea>
                    </div>

                    <div class="border rounded p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold">Payment First</span>
                            <strong id="exBalanceDue" class="text-danger">₱0.00</strong>
                        </div>
                        <div class="small text-muted mb-3">Collect the extension payment first before saving this transaction.</div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <div class="btn-group w-100">
                                <input type="radio" class="btn-check" name="payment_method" id="exPmCash" value="cash" checked onchange="exPayMethodChange()">
                                <label class="btn btn-outline-success" for="exPmCash">Cash</label>
                                <input type="radio" class="btn-check" name="payment_method" id="exPmGcash" value="gcash" onchange="exPayMethodChange()">
                                <label class="btn btn-outline-primary" for="exPmGcash">GCash</label>
                                <input type="radio" class="btn-check" name="payment_method" id="exPmSplit" value="split" onchange="exPayMethodChange()">
                                <label class="btn btn-outline-warning" for="exPmSplit">Split</label>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6" id="exCashRow">
                                <label class="form-label fw-semibold">Cash Amount (₱)</label>
                                <input type="number" name="cash_amount" id="exCash" class="form-control" step="0.01" min="0" value="0" oninput="exUpdateTendered()">
                            </div>
                            <div class="col-md-6" id="exGcashRow" style="display:none;">
                                <label class="form-label fw-semibold">GCash Amount (₱)</label>
                                <input type="number" name="gcash_amount" id="exGcash" class="form-control" step="0.01" min="0" value="0" oninput="exUpdateTendered()">
                            </div>
                            <div class="col-12" id="exGcashRefRow" style="display:none;">
                                <label class="form-label fw-semibold">GCash Reference #</label>
                                <input type="text" name="gcash_ref" id="exGcashRef" class="form-control" placeholder="GCash transaction reference number">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between small mt-3 mb-0">
                            <span>Amount Tendered</span>
                            <strong id="exTendered">₱0.00</strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold" id="exSubmitBtn">Collect Payment & Save Extension</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times me-2"></i>Cancel Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="booking_id" id="cancelBookingId">
                <div class="modal-body">
                    <p>Are you sure you want to cancel booking for <strong id="cancelGuestName"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for Cancellation <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Enter reason..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$bookingsMap = [];
foreach ($bookings as $bk) {
    $bk['computed_balance'] = getBalanceDue((float)$bk['total_amount'], (float)$bk['amount_paid']);
    $bookingsMap[$bk['id']] = $bk;
}
ob_start();
?>
<script>
(function () {
    'use strict';
    var bookingsData   = <?= json_encode($bookingsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var checkoutBaseUrl = <?= json_encode(BASE_URL) ?>;
    var appModals      = {};
    var coOutstanding  = 0;
    var exOutstanding  = 0;
    var currentBk      = null;

    function fmtMoney(n) {
        return '\u20b1' + parseFloat(n || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function escH(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }
    function nl2b(s) { return escH(s).replace(/\n/g, '<br>'); }

    /* ---- Init modals & click delegation ---- */
    window.addEventListener('load', function () {
        appModals.view     = new bootstrap.Modal(document.getElementById('viewModal'));
        appModals.checkout = new bootstrap.Modal(document.getElementById('checkoutModal'));
        appModals.move     = new bootstrap.Modal(document.getElementById('moveRoomModal'));
        appModals.extend   = new bootstrap.Modal(document.getElementById('extendModal'));
        appModals.cancels   = new bootstrap.Modal(document.getElementById('cancelModal'));

        /* Fix: After any modal closes, remove stale backdrops and restore body scroll.
           Bootstrap sometimes leaves .modal-backdrop in the DOM after hide, which
           blocks all subsequent clicks until the page is reloaded. */
        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('hidden.bs.modal', function () {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
            });
        });

        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-booking-action]');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var id     = parseInt(btn.getAttribute('data-booking-id') || '0', 10);
            var action = btn.getAttribute('data-booking-action') || '';
            if (!id || !action) return;
            if      (action === 'view')     openViewModal(id);
            else if (action === 'checkout') openCheckoutModal(id);
            else if (action === 'move')     openMoveModal(id);
            else if (action === 'extend')   openExtendModal(id);
            else if (action === 'cancel')   openCancelModal(id);
        });
    });

    /* ---- View modal ---- */
    function openViewModal(id) {
        var b = bookingsData[id]; if (!b) return;
        var h = '<div class="row g-2 mb-3">';
        h += '<div class="col-6"><div class="text-muted small">Booking Ref</div><strong class="font-mono">' + escH(b.booking_ref) + '</strong></div>';
        h += '<div class="col-6"><div class="text-muted small">Status</div>' + escH(String(b.status).replace(/_/g, ' ')) + '</div>';
        h += '<div class="col-6"><div class="text-muted small">Guest</div><strong>' + escH(b.guest_name) + '</strong></div>';
        h += '<div class="col-6"><div class="text-muted small">Room</div><strong>' + escH(b.room_number + ' \u2014 ' + b.type_name) + '</strong></div>';
        h += '<div class="col-6"><div class="text-muted small">Contact</div>' + escH(b.guest_contact || '\u2014') + '</div>';
        h += '<div class="col-6"><div class="text-muted small">Stay Type</div>' + escH((b.booking_type === 'short_time' || b.booking_type === 'hourly') ? 'Short Time' : 'Overnight') + '</div>';
        h += '<div class="col-6"><div class="text-muted small">Check-In</div>' + escH(b.check_in || '\u2014') + '</div>';
        h += '<div class="col-6"><div class="text-muted small">Expected Out</div>' + escH(b.expected_check_out || '\u2014') + '</div>';
        h += '</div>';
        h += '<div class="mb-3 text-end"><a class="btn btn-sm btn-outline-dark" target="_blank" href="' + checkoutBaseUrl + 'modules/receipt/index.php?booking_id=' + encodeURIComponent(b.id) + '&print=1"><i class="fas fa-print me-1"></i>Print Receipt</a></div>';
        h += '<table class="table table-sm table-bordered">';
        h += '<tr><td>Base Amount</td><td class="text-end fw-bold">' + fmtMoney(b.base_amount) + '</td></tr>';
        h += '<tr><td>Peak Surcharge</td><td class="text-end text-warning fw-bold">' + fmtMoney(b.peak_surcharge) + '</td></tr>';
        h += '<tr><td>Discount</td><td class="text-end text-danger fw-bold">\u2212 ' + fmtMoney(b.discount_amount) + '</td></tr>';
        h += '<tr><td>Extension Fee</td><td class="text-end">' + fmtMoney(b.extension_fee) + '</td></tr>';
        h += '<tr><td>Late Check-Out Fee</td><td class="text-end">' + fmtMoney(b.late_checkout_fee) + '</td></tr>';
        h += '<tr class="table-success"><td><strong>Total Bill</strong></td><td class="text-end fw-bold">' + fmtMoney(b.total_amount) + '</td></tr>';
        h += '<tr><td>Amount Paid</td><td class="text-end text-primary fw-bold">' + fmtMoney(b.amount_paid) + '</td></tr>';
        h += '<tr><td>Balance</td><td class="text-end ' + (parseFloat(b.computed_balance) > 0 ? 'text-danger' : 'text-success') + ' fw-bold">' + fmtMoney(b.computed_balance) + '</td></tr>';
        h += '</table>';
        if (b.notes) h += '<div class="alert alert-light border small mb-0"><div class="fw-semibold mb-1"><i class="fas fa-sticky-note me-1"></i>Booking Notes</div>' + nl2b(b.notes) + '</div>';
        document.getElementById('viewModalBody').innerHTML = h;
        appModals.view.show();
    }

    /* ---- Checkout modal ---- */
    function lateFeePreview(expectedOut) {
        var due = new Date(expectedOut), now = new Date();
        if (isNaN(due.getTime()) || now <= due) return {hours: 0, fee: 0};
        var hrs = Math.ceil((now - due) / 3600000);
        return {hours: hrs, fee: hrs * 150};
    }

    function openCheckoutModal(id) {
        var b = bookingsData[id]; if (!b) return;
        var late = lateFeePreview(b.expected_check_out);
        var finalTotal = parseFloat(b.base_amount || 0) + parseFloat(b.peak_surcharge || 0) - parseFloat(b.discount_amount || 0) + parseFloat(b.extension_fee || 0) + late.fee;
        coOutstanding = Math.max(0, finalTotal - parseFloat(b.amount_paid || 0));
        document.getElementById('coBookingId').value = b.id;
        document.getElementById('coSummary').innerHTML = '<strong>' + escH(b.guest_name) + '</strong> \u2014 Room ' + escH(b.room_number) + '<br><span class="text-muted small">Expected Out: ' + escH(b.expected_check_out || '\u2014') + '</span>';
        document.getElementById('coCurrentTotal').value = fmtMoney(finalTotal);
        document.getElementById('coAlreadyPaid').value  = fmtMoney(b.amount_paid || 0);
        document.getElementById('coExtFeeDisplay').value = fmtMoney(b.extension_fee || 0);
        document.getElementById('coLateFeeDisplay').value = fmtMoney(late.fee) + (late.hours > 0 ? ' (' + late.hours + ' hr/s)' : '');
        document.getElementById('coBalanceDue').textContent = fmtMoney(coOutstanding);
        document.getElementById('coGcashRef').value = '';
        document.getElementById('pmCash').checked = true;
        coPayMethodChange();
        appModals.checkout.show();
    }

    /* ---- Payment helpers ---- */
    function togglePaymentFields(prefix, outstanding) {
        var modalId = (prefix === 'co' ? 'checkout' : 'extend') + 'Modal';
        var method  = document.querySelector('#' + modalId + ' [name="payment_method"]:checked').value;
        var isEx    = (prefix === 'ex');
        document.getElementById(isEx ? 'exCashRow'    : 'cashRow').style.display    = (method === 'cash'  || method === 'split') ? '' : 'none';
        document.getElementById(isEx ? 'exGcashRow'   : 'gcashRow').style.display   = (method === 'gcash' || method === 'split') ? '' : 'none';
        document.getElementById(isEx ? 'exGcashRefRow': 'gcashRefRow').style.display = (method === 'gcash' || method === 'split') ? '' : 'none';
        var cashEl  = document.getElementById(prefix + 'Cash');
        var gcashEl = document.getElementById(prefix + 'Gcash');
        if (method === 'cash')        { cashEl.value = outstanding.toFixed(2);  gcashEl.value = '0.00'; }
        else if (method === 'gcash')  { cashEl.value = '0.00';  gcashEl.value = outstanding.toFixed(2); }
        else                          { cashEl.value = outstanding.toFixed(2);  gcashEl.value = '0.00'; }
        updateTendered(prefix, outstanding);
    }
    function updateTendered(prefix, outstanding) {
        var paid = (parseFloat(document.getElementById(prefix + 'Cash').value || '0') || 0) +
                   (parseFloat(document.getElementById(prefix + 'Gcash').value || '0') || 0);
        document.getElementById(prefix + 'Tendered').textContent = fmtMoney(paid);
        document.getElementById(prefix + 'SubmitBtn').disabled = (paid + 0.009 < outstanding);
    }
    window.coPayMethodChange  = function () { togglePaymentFields('co', coOutstanding); };
    window.coUpdateTendered   = function () { updateTendered('co', coOutstanding); };
    window.exPayMethodChange  = function () { togglePaymentFields('ex', exOutstanding); };
    window.exUpdateTendered   = function () { updateTendered('ex', exOutstanding); };

    /* ---- Move modal ---- */
    function openMoveModal(id) {
        document.getElementById('mvBookingId').value = id;
        appModals.move.show();
    }

    /* ---- Extend modal ---- */
    function openExtendModal(id) {
        var b = bookingsData[id]; if (!b) return;
        currentBk = b;
        document.getElementById('exBookingId').value = b.id;
        document.getElementById('exSummary').innerHTML = '<strong>' + escH(b.guest_name) + '</strong> \u2014 Room ' + escH(b.room_number) + '<br><span class="text-muted small">Current expected out: ' + escH(b.expected_check_out || '\u2014') + '</span>';
        var isSt = (b.booking_type === 'short_time' || b.booking_type === 'hourly');
        document.getElementById('shortTimeExtensionFields').style.display  = isSt ? '' : 'none';
        document.getElementById('overnightExtensionFields').style.display  = isSt ? 'none' : '';
        document.getElementById('exDuration').value = '3';
        document.getElementById('exExpected').value = b.expected_check_out ? b.expected_check_out.replace(' ', 'T') : '';
        var fi = document.querySelector('#extendModal [name="additional_fee"]');
        if (fi) fi.value = '0.00';
        var ri = document.querySelector('#extendModal [name="reason"]');
        if (ri) ri.value = '';
        document.getElementById('exPmCash').checked = true;
        document.getElementById('exGcashRef').value = '';
        updateExtensionPreview();
        window.exPayMethodChange();
        appModals.extend.show();
    }
    window.updateExtensionPreview = function () {
        if (!currentBk) return;
        var isSt = (currentBk.booking_type === 'short_time' || currentBk.booking_type === 'hourly');
        var fee  = 0;
        if (isSt) {
            var hrs = parseInt(document.getElementById('exDuration').value || '3', 10);
            var rm  = {3: parseFloat(currentBk.short_time_3h_rate || 0), 6: parseFloat(currentBk.short_time_6h_rate || 0),
                       12: parseFloat(currentBk.short_time_12h_rate || 0), 24: parseFloat(currentBk.short_time_24h_rate || 0)};
            fee = rm[hrs] || ((parseFloat(currentBk.hourly_rate || 0) || 0) * hrs);
            var dt = new Date(currentBk.expected_check_out);
            if (!isNaN(dt.getTime())) dt.setHours(dt.getHours() + hrs);
            document.getElementById('exAutoFee').value = fmtMoney(fee);
            document.getElementById('exAutoOut').value = isNaN(dt.getTime()) ? '' : dt.toLocaleString('en-PH');
        } else {
            var af = document.querySelector('#extendModal [name="additional_fee"]');
            fee = parseFloat(af ? af.value : '0') || 0;
        }
        exOutstanding = Math.max(0, (parseFloat(currentBk.computed_balance || 0) || 0) + fee);
        document.getElementById('exBalanceDue').textContent = fmtMoney(exOutstanding);
        window.exUpdateTendered();
    };

    /* ---- Cancel modal ---- */
    function openCancelModal(id) {
        var b = bookingsData[id]; if (!b) return;
        document.getElementById('cancelBookingId').value = b.id;
        document.getElementById('cancelGuestName').textContent = b.guest_name || '';
        appModals.cancel.show();
    }
}());
</script>
<?php
$extraScripts = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';

