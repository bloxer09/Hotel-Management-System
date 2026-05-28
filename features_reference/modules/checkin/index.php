<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin', 'front_desk']);

$pageTitle = 'Check-In';
$pdo = getPDO();
$standardIdTypes = ['PhilSys / National ID', 'Passport', "Driver's License", 'SSS ID', 'UMID', 'PRC ID', "Voter's ID", 'Senior Citizen ID', 'PWD ID'];
$shortTimeDurations = getShortTimeDurations();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId           = (int)($_POST['room_id'] ?? 0);
    $guestName        = sanitize($_POST['guest_name'] ?? '');
    $guestContact     = sanitize($_POST['guest_contact'] ?? '');
    $guestIdType      = sanitize($_POST['guest_id_type'] ?? '');
    $guestIdNum       = sanitize($_POST['guest_id_number'] ?? '');
    $numGuests        = max(1, (int)($_POST['num_guests'] ?? 1));
    $bookingType      = $_POST['booking_type'] ?? 'overnight';
    $bookingType      = $bookingType === 'hourly' ? 'short_time' : $bookingType;
    $checkInInput     = $_POST['check_in'] ?? date('Y-m-d\TH:i');
    $discountType     = sanitize($_POST['discount_type'] ?? '');
    $discountAmount   = (float)($_POST['discount_amount'] ?? 0);
    $notes            = sanitize($_POST['notes'] ?? '');
    $numNights        = max(1, (int)($_POST['num_nights'] ?? 1));
    $shortTimeHours   = (int)($_POST['short_time_hours'] ?? 3);
    $paymentMethod    = $_POST['payment_method'] ?? 'cash';
    $cashAmount       = (float)($_POST['cash_amount'] ?? 0);
    $gcashAmount      = (float)($_POST['gcash_amount'] ?? 0);
    $gcashRef         = sanitize($_POST['gcash_ref'] ?? '');

    $stmt = $pdo->prepare("SELECT r.*, rt.type_name, rt.base_rate, rt.hourly_rate, rt.short_time_3h_rate, rt.short_time_6h_rate, rt.short_time_12h_rate, rt.short_time_24h_rate, rt.max_occupancy, rt.amenities
                           FROM rooms r
                           JOIN room_types rt ON r.room_type_id = rt.id
                           WHERE r.id = ? AND r.status = 'vacant'");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    $allowedDiscounts = ['', 'senior', 'pwd', 'promo', 'complimentary'];
    if (!in_array($discountType, $allowedDiscounts, true)) {
        $discountType = '';
        $discountAmount = 0;
    }

    if ($discountType === 'complimentary') {
        $discountAmount = 0; // computed later once base is known
    }

    if (!$room) {
        setFlash('error', 'Selected room is not available.');
    } elseif ($guestName === '') {
        setFlash('error', 'Guest name is required.');
    } elseif ($guestContact === '') {
        setFlash('error', 'Contact number is required.');
    } elseif (in_array($guestIdType, $standardIdTypes, true) && $guestIdNum === '') {
        setFlash('error', "ID Number is required when '$guestIdType' is selected.");
    } elseif ($bookingType !== 'overnight' && $bookingType !== 'short_time') {
        setFlash('error', 'Invalid stay type selected.');
    } elseif ($bookingType === 'short_time' && !isValidShortTimeDuration($shortTimeHours)) {
        setFlash('error', 'Please select a valid short-time duration.');
    } elseif ($numGuests > (int)$room['max_occupancy']) {
        setFlash('error', 'Selected room exceeds the maximum allowed occupancy.');
    } else {
        $checkInDateTime = $bookingType === 'overnight'
            ? buildOvernightCheckIn($checkInInput)->format('Y-m-d H:i:s')
            : (new DateTime($checkInInput ?: 'now'))->format('Y-m-d H:i:s');

        try {
            $calc = calculateBookingAmounts($room, $bookingType, $checkInDateTime, $numNights, $shortTimeHours, $discountType, $discountAmount);

            if ($discountType === 'complimentary') {
                $discountAmount = $calc['base_amount'] + $calc['peak_surcharge'];
                $calc = calculateBookingAmounts($room, $bookingType, $checkInDateTime, $numNights, $shortTimeHours, $discountType, $discountAmount);
            }

            if ($discountType && !in_array($discountType, ['senior', 'pwd', 'promo', 'complimentary'], true)) {
                $discountType = '';
            }

            if ($discountType === 'promo' && !hasRole('admin')) {
                $discountAmount = 0;
                $discountType = '';
                $calc = calculateBookingAmounts($room, $bookingType, $checkInDateTime, $numNights, $shortTimeHours, '', 0);
                setFlash('warning', 'Promo discount was removed because only admin can apply it.');
            }

            $amountPaid = $paymentMethod === 'cash'
                ? $cashAmount
                : ($paymentMethod === 'gcash' ? $gcashAmount : $cashAmount + $gcashAmount);
            $paymentStatus = computePaymentStatus($calc['total_amount'], $amountPaid);

            if ($paymentMethod === 'gcash' && $gcashRef === '') {
                setFlash('error', 'GCash reference number is required.');
            } elseif ($paymentMethod === 'split' && $gcashAmount > 0 && $gcashRef === '') {
                setFlash('error', 'GCash reference number is required for split payment with GCash amount.');
            } elseif ($paymentStatus !== 'paid') {
                setFlash('error', 'Full payment is required before check-in.');
            } else {
                $bookingRef = generateBookingRef();

                $stmt = $pdo->prepare("
                    INSERT INTO bookings (
                        booking_ref, room_id, guest_name, guest_contact, guest_id_type, guest_id_number, num_guests,
                        booking_type, short_time_hours, check_in, expected_check_out, status,
                        base_amount, peak_surcharge, discount_type, discount_amount,
                        extension_fee, late_checkout_fee, late_hours, total_amount,
                        amount_paid, payment_status, payment_method, cash_amount, gcash_amount, gcash_ref,
                        is_peak, notes, checked_in_by
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active',
                        ?, ?, ?, ?,
                        0, 0, 0, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?
                    )");

                $stmt->execute([
                    $bookingRef,
                    $roomId,
                    $guestName,
                    $guestContact,
                    $guestIdType,
                    $guestIdNum,
                    $numGuests,
                    $bookingType,
                    $bookingType === 'short_time' ? $shortTimeHours : null,
                    $checkInDateTime,
                    $calc['expected_check_out'],
                    $calc['base_amount'],
                    $calc['peak_surcharge'],
                    $discountType,
                    $calc['discount_amount'],
                    $calc['total_amount'],
                    $amountPaid,
                    'paid',
                    $paymentMethod,
                    $cashAmount,
                    $gcashAmount,
                    $gcashRef,
                    $calc['is_peak'],
                    $notes,
                    $_SESSION['user_id']
                ]);
                $bookingId = (int)$pdo->lastInsertId();

                $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?")->execute([$roomId]);

                auditLog($_SESSION['user_id'], 'CHECK_IN', 'bookings', $bookingId, null,
                    json_encode([
                        'booking_ref' => $bookingRef,
                        'guest' => $guestName,
                        'room' => $room['room_number'],
                        'stay_type' => $bookingType,
                        'total_amount' => $calc['total_amount'],
                        'payment_method' => $paymentMethod,
                    ]),
                    'Guest checked in with full payment');

                $desc = sprintf(
                    'Check-in payment: %s - Room %s | %s | Paid %s',
                    $guestName,
                    $room['room_number'],
                    strtoupper(str_replace('_', ' ', $bookingType)),
                    formatCurrency($amountPaid)
                );
                $pdo->prepare("INSERT INTO transactions (booking_id, transaction_type, description, amount, payment_method, cash_amount, gcash_amount, gcash_ref, processed_by)
                               VALUES (?, 'check_in', ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$bookingId, $desc, $amountPaid, $paymentMethod, $cashAmount, $gcashAmount, $gcashRef, $_SESSION['user_id']]);

                $guestStmt = $pdo->prepare("SELECT id FROM guest_profiles WHERE contact_number = ? OR (id_number != '' AND id_number = ?) LIMIT 1");
                $guestStmt->execute([$guestContact, $guestIdNum]);
                $guestProfile = $guestStmt->fetch();
                if ($guestProfile) {
                    $pdo->prepare("UPDATE guest_profiles SET full_name = ?, id_type = ?, id_number = ?, total_stays = total_stays + 1, total_spent = total_spent + ?, last_visit = CURDATE(), updated_at = NOW() WHERE id = ?")
                        ->execute([$guestName, $guestIdType, $guestIdNum, $amountPaid, $guestProfile['id']]);
                    $pdo->prepare("UPDATE bookings SET guest_profile_id = ? WHERE id = ?")->execute([$guestProfile['id'], $bookingId]);
                } else {
                    $pdo->prepare("INSERT INTO guest_profiles (full_name, contact_number, id_type, id_number, total_stays, total_spent, last_visit) VALUES (?, ?, ?, ?, 1, ?, CURDATE())")
                        ->execute([$guestName, $guestContact, $guestIdType, $guestIdNum, $amountPaid]);
                    $profileId = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE bookings SET guest_profile_id = ? WHERE id = ?")->execute([$profileId, $bookingId]);
                }

                $_SESSION['last_receipt_booking_id'] = $bookingId;
                setFlash('success', "Check-in successful! Booking Ref: <strong>$bookingRef</strong>");
                header("Location: ../checkout/index.php?ref=$bookingRef");
                exit;
            }
        } catch (Throwable $e) {
            setFlash('error', 'Unable to process check-in: ' . sanitize($e->getMessage()));
        }
    }
}

$vacantRooms = $pdo->query("SELECT r.*, rt.type_name, rt.base_rate, rt.hourly_rate, rt.short_time_3h_rate, rt.short_time_6h_rate, rt.short_time_12h_rate, rt.short_time_24h_rate, rt.max_occupancy, rt.amenities
                            FROM rooms r
                            JOIN room_types rt ON r.room_type_id = rt.id
                            WHERE r.status = 'vacant'
                            ORDER BY r.room_number")->fetchAll();

$todayPeak = isPeakDate(date('Y-m-d H:i:s'));
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-sign-in-alt me-2 text-success"></i>Guest Check-In</h4>
        <p>Collect full payment first, then check the guest in. Overnight stays are fixed at 2:00 PM to 12:00 PM.</p>
    </div>
    <a href="<?= BASE_URL ?>modules/rooms/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-door-open me-2"></i>Room Board
    </a>
</div>

<?php if ($todayPeak): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fas fa-star fs-5"></i>
    <div>
        <strong>Peak Date Active:</strong> <?= sanitize($todayPeak['label']) ?>
        — Surcharge: <?= $todayPeak['surcharge_type'] === 'fixed' ? formatCurrency($todayPeak['surcharge_amount']) : $todayPeak['surcharge_amount'] . '%' ?> per booking
    </div>
</div>
<?php endif; ?>

<div id="vipBanner" class="alert alert-success d-flex align-items-center gap-2 mb-3 d-none">
    <span class="vip-badge">⭐ VIP</span>
    <div>VIP Guest detected! Details pre-filled from guest history. <strong id="vipGuestName"></strong></div>
</div>

<form method="POST" id="checkInForm" novalidate>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-user me-2 text-primary"></i>Guest Information</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="guest_name" id="guestName" class="form-control" required value="<?= sanitize($_POST['guest_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">No. of Guests</label>
                            <input type="number" name="num_guests" class="form-control" min="1" max="10" value="<?= (int)($_POST['num_guests'] ?? 1) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" name="guest_contact" id="guestContact" class="form-control" value="<?= sanitize($_POST['guest_contact'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ID Type</label>
                            <input type="text" name="guest_id_type" id="guestIdType" class="form-control" list="idTypeOptions" value="<?= sanitize($_POST['guest_id_type'] ?? '') ?>" oninput="checkIdRequirement()">
                            <datalist id="idTypeOptions">
                                <?php foreach ($standardIdTypes as $idType): ?>
                                <option value="<?= sanitize($idType) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" id="idNumLabel">ID Number</label>
                            <input type="text" name="guest_id_number" id="guestIdNum" class="form-control" value="<?= sanitize($_POST['guest_id_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Discount Type</label>
                            <select name="discount_type" id="discountType" class="form-select" onchange="updateDiscount()">
                                <option value="" <?= (($_POST['discount_type'] ?? '') === '') ? 'selected' : '' ?>>None</option>
                                <option value="senior" <?= (($_POST['discount_type'] ?? '') === 'senior') ? 'selected' : '' ?>>Senior Citizen (20%)</option>
                                <option value="pwd" <?= (($_POST['discount_type'] ?? '') === 'pwd') ? 'selected' : '' ?>>PWD (20%)</option>
                                <option value="promo" <?= (($_POST['discount_type'] ?? '') === 'promo') ? 'selected' : '' ?>>Promo / Manual</option>
                                <option value="complimentary" <?= (($_POST['discount_type'] ?? '') === 'complimentary') ? 'selected' : '' ?>>Complimentary</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Discount Amount (₱)</label>
                            <input type="number" name="discount_amount" id="discountAmount" class="form-control" step="0.01" min="0" value="<?= sanitize($_POST['discount_amount'] ?? '0') ?>" oninput="calcTotal()">
                            <div class="form-text">Senior / PWD / complimentary discounts auto-compute.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."><?= sanitize($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-bed me-2 text-info"></i>Room & Stay Setup</h6></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Room <span class="text-danger">*</span></label>
                        <select name="room_id" id="roomId" class="form-select" required onchange="onRoomChange()">
                            <option value="">— Choose Available Room —</option>
                            <?php foreach ($vacantRooms as $room): ?>
                            <option value="<?= (int)$room['id'] ?>"
                                data-room='<?= htmlspecialchars(json_encode($room), ENT_QUOTES, 'UTF-8') ?>'
                                <?= ((string)($room['id'] ?? '') === (string)($_POST['room_id'] ?? '')) ? 'selected' : '' ?>>
                                Room <?= sanitize($room['room_number']) ?> — <?= sanitize($room['type_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="roomInfo" class="mt-2 text-muted small"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Stay Type</label>
                        <div class="btn-group w-100">
                            <input type="radio" class="btn-check" name="booking_type" id="typeOvernight" value="overnight" <?= (($_POST['booking_type'] ?? 'overnight') !== 'short_time') ? 'checked' : '' ?> onchange="onTypeChange()">
                            <label class="btn btn-outline-primary" for="typeOvernight"><i class="fas fa-moon me-2"></i>Overnight</label>
                            <input type="radio" class="btn-check" name="booking_type" id="typeShortTime" value="short_time" <?= (($_POST['booking_type'] ?? '') === 'short_time') ? 'checked' : '' ?> onchange="onTypeChange()">
                            <label class="btn btn-outline-info" for="typeShortTime"><i class="fas fa-clock me-2"></i>Short Time</label>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Check-In Date/Time</label>
                            <input type="datetime-local" name="check_in" id="checkIn" class="form-control" value="<?= sanitize($_POST['check_in'] ?? date('Y-m-d\T14:00')) ?>" onchange="calcTotal()">
                            <div class="form-text" id="checkInHelp">Overnight check-in is automatically fixed to 2:00 PM. Short time uses the exact selected time.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Expected Check-Out</label>
                            <input type="text" name="expected_preview" id="expectedCheckOut" class="form-control" readonly>
                        </div>
                    </div>

                    <div id="nightsRow" class="mb-3">
                        <label class="form-label fw-semibold">Number of Nights</label>
                        <input type="number" name="num_nights" id="numNights" class="form-control" min="1" value="<?= (int)($_POST['num_nights'] ?? 1) ?>" oninput="calcTotal()">
                    </div>

                    <div id="shortTimeRow" class="mb-3 d-none">
                        <label class="form-label fw-semibold">Short Time Duration</label>
                        <select name="short_time_hours" id="shortTimeHours" class="form-select" onchange="calcTotal()">
                            <?php foreach ($shortTimeDurations as $hours): ?>
                            <option value="<?= $hours ?>" <?= ((int)($_POST['short_time_hours'] ?? 3) === $hours) ? 'selected' : '' ?>><?= $hours ?> Hours</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Available extension options use the same duration packages.</div>
                    </div>
                </div>
            </div>

            <div class="card border-primary mb-4">
                <div class="card-header bg-primary text-white"><h6 class="mb-0 fw-bold"><i class="fas fa-receipt me-2"></i>Billing Summary</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-1"><span>Base Rate</span><strong id="summBaseRate">₱0.00</strong></div>
                    <div class="d-flex justify-content-between mb-1 text-warning"><span>Peak Surcharge</span><strong id="summPeakSurcharge">₱0.00</strong></div>
                    <div class="d-flex justify-content-between mb-2 text-danger"><span>Discount</span><strong id="summDiscount">- ₱0.00</strong></div>
                    <div class="small text-muted mb-2" id="peakNote"></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center"><span class="fw-bold fs-5">TOTAL BILL</span><strong class="fs-5 text-success" id="summTotal">₱0.00</strong></div>
                    <div class="small text-muted mt-2" id="staySummaryText"></div>
                </div>
            </div>

            <div class="card border-success">
                <div class="card-header bg-success text-white"><h6 class="mb-0 fw-bold"><i class="fas fa-cash-register me-2"></i>Payment First</h6></div>
                <div class="card-body">
                    <div class="alert alert-light small mb-3">
                        Guest cannot be checked in unless the full total bill is paid.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <div class="btn-group w-100">
                            <input type="radio" class="btn-check" name="payment_method" id="payCash" value="cash" <?= (($_POST['payment_method'] ?? 'cash') === 'cash') ? 'checked' : '' ?> onchange="onPaymentMethodChange()">
                            <label class="btn btn-outline-success" for="payCash">Cash</label>
                            <input type="radio" class="btn-check" name="payment_method" id="payGcash" value="gcash" <?= (($_POST['payment_method'] ?? '') === 'gcash') ? 'checked' : '' ?> onchange="onPaymentMethodChange()">
                            <label class="btn btn-outline-primary" for="payGcash">GCash</label>
                            <input type="radio" class="btn-check" name="payment_method" id="paySplit" value="split" <?= (($_POST['payment_method'] ?? '') === 'split') ? 'checked' : '' ?> onchange="onPaymentMethodChange()">
                            <label class="btn btn-outline-warning" for="paySplit">Split</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6" id="cashRow">
                            <label class="form-label fw-semibold">Cash Amount (₱)</label>
                            <input type="number" name="cash_amount" id="cashAmount" class="form-control" step="0.01" min="0" value="<?= sanitize($_POST['cash_amount'] ?? '0') ?>" oninput="updatePaymentSummary()">
                        </div>
                        <div class="col-md-6" id="gcashRow" style="display:none;">
                            <label class="form-label fw-semibold">GCash Amount (₱)</label>
                            <input type="number" name="gcash_amount" id="gcashAmount" class="form-control" step="0.01" min="0" value="<?= sanitize($_POST['gcash_amount'] ?? '0') ?>" oninput="updatePaymentSummary()">
                        </div>
                        <div class="col-12" id="gcashRefRow" style="display:none;">
                            <label class="form-label fw-semibold">GCash Reference #</label>
                            <input type="text" name="gcash_ref" id="gcashRef" class="form-control" value="<?= sanitize($_POST['gcash_ref'] ?? '') ?>">
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-1"><span>Amount Paid</span><strong id="summPaid">₱0.00</strong></div>
                    <div class="d-flex justify-content-between"><span>Remaining Balance</span><strong id="summBalance" class="text-danger">₱0.00</strong></div>
                </div>
            </div>

            <button type="submit" class="btn btn-success w-100 mt-3 py-3 fw-bold fs-5" id="submitBtn">
                <i class="fas fa-check-circle me-2"></i>Collect Payment & Confirm Check-In
            </button>
        </div>
    </div>
</form>

<?php
$peakDatesJson = json_encode($pdo->query("SELECT * FROM peak_dates WHERE is_active = 1")->fetchAll(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$extraScripts = <<<JS
<script>
const peakDates = {$peakDatesJson};
const standardIds = ["PhilSys / National ID", "Passport", "Driver's License", "SSS ID", "UMID", "PRC ID", "Voter's ID", "Senior Citizen ID", "PWD ID"];

function fmtMoney(n) {
    return '₱' + (parseFloat(n || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
}

function getSelectedRoom() {
    const sel = document.getElementById('roomId');
    if (!sel.value || sel.selectedIndex < 0) return null;
    try {
        return JSON.parse(sel.options[sel.selectedIndex].dataset.room || '{}');
    } catch (e) {
        return null;
    }
}

function checkIdRequirement() {
    const idType = document.getElementById('guestIdType').value;
    const idNumInput = document.getElementById('guestIdNum');
    const idNumLabel = document.getElementById('idNumLabel');
    const required = standardIds.includes(idType);
    idNumInput.required = required;
    idNumLabel.innerHTML = required ? 'ID Number <span class="text-danger">*</span>' : 'ID Number';
}

function isPeakDate(dateStr) {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    if (isNaN(d)) return null;
    const ymd = d.toISOString().split('T')[0];
    return peakDates.find(p => ymd >= p.date_from && ymd <= p.date_to) || null;
}

function setOvernightCheckInTime() {
    const input = document.getElementById('checkIn');
    if (!document.getElementById('typeOvernight').checked || !input.value) return;
    const dt = new Date(input.value);
    if (isNaN(dt)) return;
    dt.setHours(14, 0, 0, 0);
    const local = new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    input.value = local;
}

function computeExpectedCheckout() {
    const checkIn = document.getElementById('checkIn').value;
    if (!checkIn) return '';

    const dt = new Date(checkIn);
    if (isNaN(dt)) return '';

    if (document.getElementById('typeOvernight').checked) {
        dt.setHours(14, 0, 0, 0);
        const nights = Math.max(parseInt(document.getElementById('numNights').value || '1', 10), 1);
        dt.setDate(dt.getDate() + nights);
        dt.setHours(12, 0, 0, 0);
        return dt;
    }

    const hours = parseInt(document.getElementById('shortTimeHours').value || '3', 10);
    dt.setHours(dt.getHours() + hours);
    return dt;
}

function onRoomChange() {
    const room = getSelectedRoom();
    const info = document.getElementById('roomInfo');
    if (!room) {
        info.innerHTML = '';
        calcTotal();
        return;
    }

    info.innerHTML = `
        <strong>
            <i class="fas fa-bed me-1"></i>
            \${room.type_name}
        </strong>
        | Overnight: \${fmtMoney(room.base_rate)}
        <br>
        <small>
            3h: \${fmtMoney(room.short_time_3h_rate)} | 6h: \${fmtMoney(room.short_time_6h_rate)} | 12h: \${fmtMoney(room.short_time_12h_rate)} | 24h: \${fmtMoney(room.short_time_24h_rate)}
            <br>Max: \${room.max_occupancy} guest(s)
            <br>\${room.amenities || ''}
        </small>`;
    calcTotal();
}

function onTypeChange() {
    const isOvernight = document.getElementById('typeOvernight').checked;
    document.getElementById('nightsRow').classList.toggle('d-none', !isOvernight);
    document.getElementById('shortTimeRow').classList.toggle('d-none', isOvernight);
    document.getElementById('checkInHelp').textContent = isOvernight
        ? 'Overnight check-in is automatically fixed to 2:00 PM. Check-out is 12:00 PM based on number of nights.'
        : 'Short time uses the exact selected check-in date and time.';
    if (isOvernight) setOvernightCheckInTime();
    calcTotal();
}

function updateDiscount() {
    const type = document.getElementById('discountType').value;
    const discountField = document.getElementById('discountAmount');
    if (type === 'senior' || type === 'pwd' || type === 'complimentary') {
        discountField.readOnly = true;
    } else {
        discountField.readOnly = false;
    }
    calcTotal();
}

function computeBaseAmount(room) {
    if (!room) return 0;
    if (document.getElementById('typeOvernight').checked) {
        const nights = Math.max(parseInt(document.getElementById('numNights').value || '1', 10), 1);
        return parseFloat(room.base_rate || 0) * nights;
    }

    const hours = parseInt(document.getElementById('shortTimeHours').value || '3', 10);
    const rateMap = {
        3: parseFloat(room.short_time_3h_rate || 0),
        6: parseFloat(room.short_time_6h_rate || 0),
        12: parseFloat(room.short_time_12h_rate || 0),
        24: parseFloat(room.short_time_24h_rate || 0),
    };
    return rateMap[hours] || (parseFloat(room.hourly_rate || 0) * hours);
}

function updatePaymentSummary(autoFill = false) {
    const total = parseFloat(document.getElementById('summTotal').dataset.total || '0');
    const method = document.querySelector('[name="payment_method"]:checked').value;
    const cashInput = document.getElementById('cashAmount');
    const gcashInput = document.getElementById('gcashAmount');

    document.getElementById('cashRow').style.display = (method === 'cash' || method === 'split') ? '' : 'none';
    document.getElementById('gcashRow').style.display = (method === 'gcash' || method === 'split') ? '' : 'none';
    document.getElementById('gcashRefRow').style.display = (method === 'gcash' || method === 'split') ? '' : 'none';

    if (autoFill) {
        if (method === 'cash') {
            cashInput.value = total.toFixed(2);
            gcashInput.value = '0.00';
        } else if (method === 'gcash') {
            cashInput.value = '0.00';
            gcashInput.value = total.toFixed(2);
        } else {
            cashInput.value = total.toFixed(2);
            gcashInput.value = '0.00';
        }
    }

    const paid = (parseFloat(cashInput.value || '0') || 0) + (parseFloat(gcashInput.value || '0') || 0);
    const balance = Math.max(0, total - paid);
    document.getElementById('summPaid').textContent = fmtMoney(paid);
    document.getElementById('summBalance').textContent = fmtMoney(balance);
    document.getElementById('submitBtn').disabled = !(document.getElementById('roomId').value && balance <= 0.009);
}

function onPaymentMethodChange() {
    updatePaymentSummary(true);
}

function calcTotal() {
    const room = getSelectedRoom();
    if (document.getElementById('typeOvernight').checked) setOvernightCheckInTime();

    const expected = computeExpectedCheckout();
    document.getElementById('expectedCheckOut').value = expected ? expected.toLocaleString('en-PH') : '';

    const baseAmount = computeBaseAmount(room);
    const peak = isPeakDate(document.getElementById('checkIn').value);
    let surcharge = 0;
    let peakNote = '';
    if (peak) {
        surcharge = peak.surcharge_type === 'percent'
            ? Math.round(baseAmount * (parseFloat(peak.surcharge_amount || 0) / 100) * 100) / 100
            : parseFloat(peak.surcharge_amount || 0);
        peakNote = 'Peak: ' + peak.label;
    }

    const discountType = document.getElementById('discountType').value;
    let discount = parseFloat(document.getElementById('discountAmount').value || '0') || 0;
    if (discountType === 'senior' || discountType === 'pwd') {
        discount = Math.round((baseAmount + surcharge) * 0.20 * 100) / 100;
        document.getElementById('discountAmount').value = discount.toFixed(2);
    } else if (discountType === 'complimentary') {
        discount = baseAmount + surcharge;
        document.getElementById('discountAmount').value = discount.toFixed(2);
    }

    const total = Math.max(0, baseAmount + surcharge - discount);
    document.getElementById('summBaseRate').textContent = fmtMoney(baseAmount);
    document.getElementById('summPeakSurcharge').textContent = fmtMoney(surcharge);
    document.getElementById('summDiscount').textContent = '- ' + fmtMoney(discount);
    document.getElementById('summTotal').textContent = fmtMoney(total);
    document.getElementById('summTotal').dataset.total = total.toFixed(2);
    document.getElementById('peakNote').textContent = peakNote;

    const stayText = document.getElementById('typeOvernight').checked
        ? 'Overnight: 2:00 PM check-in to 12:00 PM check-out × ' + Math.max(parseInt(document.getElementById('numNights').value || '1', 10), 1) + ' night(s)'
        : 'Short Time: ' + parseInt(document.getElementById('shortTimeHours').value || '3', 10) + ' hour(s)';
    document.getElementById('staySummaryText').textContent = stayText;

    updatePaymentSummary(true);
}

window.addEventListener('load', () => {
    const gd = sessionStorage.getItem('quickCheckinGuest');
    if (gd) {
        try {
            const g = JSON.parse(gd);
            document.getElementById('guestName').value = g.full_name || '';
            document.getElementById('guestContact').value = g.contact_number || '';
            document.getElementById('guestIdType').value = g.id_type || '';
            document.getElementById('guestIdNum').value = g.id_number || '';
            document.getElementById('vipBanner').classList.remove('d-none');
            document.getElementById('vipGuestName').textContent = g.full_name || '';
        } catch (e) {}
        sessionStorage.removeItem('quickCheckinGuest');
    }
    checkIdRequirement();
    onRoomChange();
    onTypeChange();
    updateDiscount();
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
