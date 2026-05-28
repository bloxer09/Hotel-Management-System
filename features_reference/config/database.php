<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hotel_pms');
define('DB_PORT', 3306);

// Hotel Settings
define('HOTEL_NAME', 'Uptown Pension House');
define('HOTEL_ADDRESS', '123 Main Street, Cebu City');
define('HOTEL_CONTACT', '(032) 123-4567');
define('HOTEL_EMAIL', 'info@grandvista.com');

// System Settings
define('OVERNIGHT_CHECKIN_HOUR', 14);   // 2:00 PM
define('OVERNIGHT_CHECKOUT_HOUR', 12); // 12:00 PM
define('LATE_CHECKOUT_FEE', 150.00);   // Per hour, rounded up
define('EXTENSION_MIN_HOURS', 1);
define('APP_VERSION', '1.2.0');
define('TIMEZONE', 'Asia/Manila');

date_default_timezone_set(TIMEZONE);
session_name('hotel_pms_session');

function getShortTimeDurations() {
    return [3, 6, 12, 24];
}

// Create Database Connection
function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// PDO Connection
function getPDO() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4;port=" . DB_PORT;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            ensurePmsSchema($pdo);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function ensurePmsSchema(PDO $pdo) {
    static $done = false;
    if ($done) return;

    try {
        $pdo->exec("ALTER TABLE room_types ADD COLUMN short_time_3h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE room_types ADD COLUMN short_time_6h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE room_types ADD COLUMN short_time_12h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE room_types ADD COLUMN short_time_24h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE bookings MODIFY COLUMN booking_type ENUM('overnight','hourly','short_time') DEFAULT 'overnight'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN short_time_hours INT DEFAULT NULL AFTER booking_type");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid' AFTER amount_paid");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN late_hours INT NOT NULL DEFAULT 0 AFTER late_checkout_fee");
    } catch (Exception $e) {}

    try {
        $pdo->exec("UPDATE room_types
            SET short_time_3h_rate = CASE WHEN IFNULL(short_time_3h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 3, 2) ELSE short_time_3h_rate END,
                short_time_6h_rate = CASE WHEN IFNULL(short_time_6h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 6, 2) ELSE short_time_6h_rate END,
                short_time_12h_rate = CASE WHEN IFNULL(short_time_12h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 12, 2) ELSE short_time_12h_rate END,
                short_time_24h_rate = CASE WHEN IFNULL(short_time_24h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 24, 2) ELSE short_time_24h_rate END");
    } catch (Exception $e) {}

    try {
        $pdo->exec("UPDATE bookings
                   SET payment_status = CASE
                       WHEN IFNULL(amount_paid,0) <= 0 THEN 'unpaid'
                       WHEN IFNULL(amount_paid,0) + 0.009 >= IFNULL(total_amount,0) THEN 'paid'
                       ELSE 'partial'
                   END
                   WHERE payment_status IS NULL OR payment_status = ''");
    } catch (Exception $e) {}

    $done = true;
}

function buildOvernightCheckIn($inputDateTime) {
    $dt = new DateTime($inputDateTime ?: 'now');
    $dt->setTime(OVERNIGHT_CHECKIN_HOUR, 0, 0);
    return $dt;
}

function buildOvernightExpectedCheckOut($inputDateTime, $numNights = 1) {
    $numNights = max(1, (int)$numNights);
    $dt = buildOvernightCheckIn($inputDateTime);
    $dt->modify('+' . $numNights . ' day');
    $dt->setTime(OVERNIGHT_CHECKOUT_HOUR, 0, 0);
    return $dt;
}

function buildShortTimeExpectedCheckOut($checkInDateTime, $hours) {
    $hours = max(1, (int)$hours);
    $dt = new DateTime($checkInDateTime ?: 'now');
    $dt->modify('+' . $hours . ' hour');
    return $dt;
}

function getShortTimeRate(array $roomTypeOrRoom, $hours) {
    $hours = (int)$hours;
    $map = [
        3  => 'short_time_3h_rate',
        6  => 'short_time_6h_rate',
        12 => 'short_time_12h_rate',
        24 => 'short_time_24h_rate',
    ];

    if (isset($map[$hours]) && isset($roomTypeOrRoom[$map[$hours]])) {
        $rate = (float)$roomTypeOrRoom[$map[$hours]];
        if ($rate > 0) return $rate;
    }

    return round((float)($roomTypeOrRoom['hourly_rate'] ?? 0) * $hours, 2);
}

function calculateLateCheckoutHours($expectedCheckOut, $actualCheckOut = null) {
    if (empty($expectedCheckOut)) return 0;

    $expected = $expectedCheckOut instanceof DateTime ? clone $expectedCheckOut : new DateTime($expectedCheckOut);
    $actual = $actualCheckOut instanceof DateTime ? clone $actualCheckOut : new DateTime($actualCheckOut ?: 'now');

    if ($actual <= $expected) return 0;

    $diffSeconds = $actual->getTimestamp() - $expected->getTimestamp();
    return (int)ceil($diffSeconds / 3600);
}

function calculateLateCheckoutFee($expectedCheckOut, $actualCheckOut = null) {
    return round(calculateLateCheckoutHours($expectedCheckOut, $actualCheckOut) * LATE_CHECKOUT_FEE, 2);
}

function computePaymentStatus($totalAmount, $amountPaid) {
    $totalAmount = round((float)$totalAmount, 2);
    $amountPaid = round((float)$amountPaid, 2);

    if ($amountPaid <= 0) return 'unpaid';
    if ($amountPaid + 0.009 >= $totalAmount) return 'paid';
    return 'partial';
}

function getBalanceDue($totalAmount, $amountPaid) {
    return round(max(0, (float)$totalAmount - (float)$amountPaid), 2);
}

function calculateBookingAmounts(array $room, $bookingType, $checkIn, $numNights = 1, $shortTimeHours = 3, $discountType = '', $discountAmount = 0) {
    $bookingType = $bookingType === 'hourly' ? 'short_time' : $bookingType;
    $numNights = max(1, (int)$numNights);
    $shortTimeHours = (int)$shortTimeHours;

    if ($bookingType === 'overnight') {
        $baseAmount = round((float)$room['base_rate'] * $numNights, 2);
        $expectedCheckOut = buildOvernightExpectedCheckOut($checkIn, $numNights)->format('Y-m-d H:i:s');
    } else {
        if (!in_array($shortTimeHours, getShortTimeDurations(), true)) {
            throw new InvalidArgumentException('Invalid short-time duration selected.');
        }
        $baseAmount = getShortTimeRate($room, $shortTimeHours);
        $expectedCheckOut = buildShortTimeExpectedCheckOut($checkIn, $shortTimeHours)->format('Y-m-d H:i:s');
    }

    $peakDate = isPeakDate($checkIn);
    $peakSurcharge = calculateSurcharge($peakDate, $baseAmount);
    $isPeak = $peakDate ? 1 : 0;

    $discountType = trim((string)$discountType);
    $discountAmount = (float)$discountAmount;
    if ($discountType === 'senior' || $discountType === 'pwd') {
        $discountAmount = round(($baseAmount + $peakSurcharge) * 0.20, 2);
    }

    $totalAmount = round(max(0, $baseAmount + $peakSurcharge - $discountAmount), 2);

    return [
        'base_amount' => $baseAmount,
        'peak_surcharge' => $peakSurcharge,
        'discount_amount' => $discountAmount,
        'total_amount' => $totalAmount,
        'expected_check_out' => $expectedCheckOut,
        'is_peak' => $isPeak,
        'peak_date' => $peakDate,
    ];
}

function isValidShortTimeDuration($hours) {
    return in_array((int)$hours, getShortTimeDurations(), true);
}

function getExtensionRateForBooking(array $booking, array $roomType, $hours) {
    $hours = (int)$hours;
    if (!isValidShortTimeDuration($hours)) {
        throw new InvalidArgumentException('Invalid extension duration.');
    }
    return getShortTimeRate($roomType, $hours);
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim((string)$input)), ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

function formatDateTime($dt) {
    if (!$dt) return '—';
    return date('M d, Y h:i A', strtotime($dt));
}

function formatDate($dt) {
    if (!$dt) return '—';
    return date('M d, Y', strtotime($dt));
}

function isLowStockItem(array $item) {
    return (int)($item['minimum_stock'] ?? 0) > 0
        && (int)($item['current_stock'] ?? 0) <= (int)($item['minimum_stock'] ?? 0);
}

function getLowStockInventoryItems(PDO $pdo, ?int $limit = null): array {
    $sql = "SELECT id, item_name, category, unit, current_stock, minimum_stock, unit_cost, selling_price, is_active
            FROM inventory_items
            WHERE is_active = 1
              AND minimum_stock > 0
              AND current_stock <= minimum_stock
            ORDER BY
              CASE WHEN current_stock <= 0 THEN 0 ELSE 1 END ASC,
              CASE
                WHEN minimum_stock > 0 THEN current_stock / minimum_stock
                ELSE 999999
              END ASC,
              current_stock ASC,
              item_name ASC";

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    return $pdo->query($sql)->fetchAll();
}

function getLowStockInventoryCount(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND minimum_stock > 0 AND current_stock <= minimum_stock")->fetchColumn();
}

function isPeakDate($checkIn) {
    $pdo = getPDO();
    $date = date('Y-m-d', strtotime($checkIn));
    $stmt = $pdo->prepare(
        "SELECT * FROM peak_dates WHERE is_active = 1 AND ? BETWEEN date_from AND date_to LIMIT 1"
    );
    $stmt->execute([$date]);
    return $stmt->fetch();
}

function calculateSurcharge($peakDate, $baseAmount) {
    if (!$peakDate) return 0;
    if (($peakDate['surcharge_type'] ?? '') === 'percent') {
        return round($baseAmount * ((float)$peakDate['surcharge_amount'] / 100), 2);
    }
    return (float)($peakDate['surcharge_amount'] ?? 0);
}

function getRoleLabel($role) {
    $labels = [
        'admin'      => 'Administrator',
        'front_desk' => 'Front Desk',
        'cashier'    => 'Cashier',
        'housekeeping' => 'Housekeeping',
    ];
    return $labels[$role] ?? ucfirst($role);
}

function getRoleBadge($role) {
    $colors = [
        'admin'      => 'danger',
        'front_desk' => 'primary',
        'cashier'    => 'success',
        'housekeeping' => 'info',
    ];
    $color = $colors[$role] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . getRoleLabel($role) . '</span>';
}

function getStatusBadge($status) {
    $map = [
        'vacant'       => ['success', 'Vacant'],
        'occupied'     => ['danger', 'Occupied'],
        'cleaning'     => ['warning', 'For Cleaning'],
        'out_of_order' => ['secondary', 'Out of Order'],
        'active'       => ['primary', 'Active'],
        'checked_out'  => ['success', 'Checked Out'],
        'cancelled'    => ['danger', 'Cancelled'],
        'no_show'      => ['warning', 'No Show'],
        'pending'      => ['warning', 'Pending'],
        'approved'     => ['success', 'Approved'],
        'rejected'     => ['danger', 'Rejected'],
    ];
    $info = $map[$status] ?? ['secondary', ucfirst($status)];
    return '<span class="badge bg-' . $info[0] . '">' . $info[1] . '</span>';
}
