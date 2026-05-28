<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if (!hasRole(['admin', 'front_desk'])) {
    jsonResponse(['success' => false, 'message' => 'Access denied.'], 403);
}

$pdo = getPDO();
$minutesAhead = 5;
$now = new DateTime('now');

$checkoutSql = "SELECT
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

$checkoutStmt = $pdo->prepare($checkoutSql);
$checkoutStmt->bindValue(':minutesAhead', $minutesAhead, PDO::PARAM_INT);
$checkoutStmt->execute();
$checkoutRows = $checkoutStmt->fetchAll();

$items = [];
$upcomingCount = 0;
$overdueCount = 0;

foreach ($checkoutRows as $row) {
    if (empty($row['expected_check_out'])) {
        continue;
    }

    $expected = new DateTime($row['expected_check_out']);
    $secondsUntil = (int)($row['seconds_until'] ?? 0);

    if ($secondsUntil < 0) {
        $state = 'overdue';
        $overdueCount++;
        $minutesValue = (int)max(1, ceil(abs($secondsUntil) / 60));
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
        $minutesValue = (int)max(1, ceil($secondsUntil / 60));
        $message = sprintf(
            'Room %s (%s) will check out in %d minute%s.',
            $row['room_number'],
            $row['guest_name'],
            $minutesValue,
            $minutesValue === 1 ? '' : 's'
        );
    }

    $items[] = [
        'type' => 'checkout',
        'alert_key' => 'checkout-' . $row['booking_id'] . '-' . $state,
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
        'target_url' => BASE_URL . 'modules/rooms/index.php',
    ];
}

$lowStockRows = getLowStockInventoryItems($pdo);
$inventoryCount = count($lowStockRows);
$outOfStockCount = 0;

foreach ($lowStockRows as $row) {
    $currentStock = (int)$row['current_stock'];
    $minimumStock = (int)$row['minimum_stock'];
    $isOutOfStock = $currentStock <= 0;

    if ($isOutOfStock) {
        $outOfStockCount++;
    }

    $items[] = [
        'type' => 'inventory',
        'alert_key' => 'inventory-' . $row['id'] . '-' . $currentStock . '-' . $minimumStock,
        'item_id' => (int)$row['id'],
        'item_name' => $row['item_name'],
        'category' => $row['category'],
        'unit' => $row['unit'],
        'current_stock' => $currentStock,
        'minimum_stock' => $minimumStock,
        'state' => $isOutOfStock ? 'out_of_stock' : 'low_stock',
        'message' => $isOutOfStock
            ? sprintf('%s is out of stock.', $row['item_name'])
            : sprintf('%s only has %d %s left (minimum %d).', $row['item_name'], $currentStock, $row['unit'], $minimumStock),
        'target_url' => BASE_URL . 'modules/inventory/index.php',
    ];
}

jsonResponse([
    'success' => true,
    'generated_at' => $now->format('Y-m-d H:i:s'),
    'minutes_ahead' => $minutesAhead,
    'counts' => [
        'total' => count($items),
        'checkout' => count($checkoutRows),
        'upcoming' => $upcomingCount,
        'overdue' => $overdueCount,
        'inventory' => $inventoryCount,
        'out_of_stock' => $outOfStockCount,
    ],
    'items' => $items,
]);
