<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if (!hasRole(['admin', 'front_desk'])) {
    jsonResponse(['success' => false, 'message' => 'Access denied.'], 403);
}

$pdo = getPDO();
$minutesAhead = 5;
$secondsAhead = $minutesAhead * 60;

$sql = "SELECT
            b.id AS booking_id,
            b.booking_ref,
            b.guest_name,
            b.expected_check_out,
            r.room_number,
            rt.type_name,
            TIMESTAMPDIFF(SECOND, NOW(), b.expected_check_out) AS seconds_until
        FROM bookings b
        INNER JOIN rooms r ON r.id = b.room_id
        INNER JOIN room_types rt ON rt.id = r.room_type_id
        WHERE b.status = 'active'
          AND b.expected_check_out IS NOT NULL
          AND b.expected_check_out <= DATE_ADD(NOW(), INTERVAL :minutesAhead MINUTE)
        ORDER BY b.expected_check_out ASC, r.room_number ASC";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':minutesAhead', $minutesAhead, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$items = [];
$upcomingCount = 0;
$overdueCount = 0;
$now = new DateTime('now');

foreach ($rows as $row) {
    if (empty($row['expected_check_out'])) {
        continue;
    }

    $expected = new DateTime($row['expected_check_out']);
    $secondsUntil = (int)($row['seconds_until'] ?? 0);

    if ($secondsUntil < 0) {
        $state = 'overdue';
        $overdueCount++;
        $minutesValue = (int) max(1, ceil(abs($secondsUntil) / 60));
        $message = sprintf(
            'Room %s (%s) is overdue for checkout by %d minute%s.',
            $row['room_number'],
            $row['guest_name'],
            $minutesValue,
            $minutesValue === 1 ? '' : 's'
        );
    } else {
        $state = 'upcoming';
        $upcomingCount++;
        $minutesValue = (int) max(1, ceil($secondsUntil / 60));
        $message = sprintf(
            'Room %s (%s) will check out in %d minute%s.',
            $row['room_number'],
            $row['guest_name'],
            $minutesValue,
            $minutesValue === 1 ? '' : 's'
        );
    }

    $items[] = [
        'alert_key' => $row['booking_id'] . '-' . $state,
        'booking_id' => (int)$row['booking_id'],
        'booking_ref' => $row['booking_ref'],
        'room_number' => $row['room_number'],
        'room_type' => $row['type_name'],
        'guest_name' => $row['guest_name'],
        'expected_check_out' => $row['expected_check_out'],
        'expected_check_out_label' => $expected->format('M d, Y h:i A'),
        'state' => $state,
        'minutes_value' => $minutesValue,
        'seconds_until' => $secondsUntil,
        'message' => $message,
    ];
}

jsonResponse([
    'success' => true,
    'generated_at' => $now->format('Y-m-d H:i:s'),
    'minutes_ahead' => $minutesAhead,
    'counts' => [
        'total' => count($items),
        'upcoming' => $upcomingCount,
        'overdue' => $overdueCount,
    ],
    'items' => $items,
]);
