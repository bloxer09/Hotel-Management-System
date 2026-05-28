<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin', 'front_desk']);

$pageTitle = 'Inventory Management';
$pdo = getPDO();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'add' && hasRole('admin')) {
        $pdo->prepare("INSERT INTO inventory_items (item_name,category,unit,current_stock,minimum_stock,unit_cost,selling_price) VALUES (?,?,?,?,?,?,?)")
            ->execute([
                sanitize($_POST['item_name']),
                $_POST['category'],
                sanitize($_POST['unit']),
                (int)$_POST['current_stock'],
                (int)$_POST['minimum_stock'],
                (float)$_POST['unit_cost'],
                (float)$_POST['selling_price']
            ]);
        auditLog($_SESSION['user_id'], 'INVENTORY_ADD', 'inventory_items', $pdo->lastInsertId(), null, sanitize($_POST['item_name']), 'New item added');
        setFlash('success', 'Item added successfully.');
    }

    elseif ($action === 'adjust') {
        $itemId  = (int)$_POST['item_id'];
        $adjType = $_POST['adj_type']; // add / subtract / set
        $qty     = (int)$_POST['quantity'];
        $reason  = sanitize($_POST['reason'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id=?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if ($item) {
            $oldQty = $item['current_stock'];
            $newQty = match($adjType) {
                'add'      => $oldQty + $qty,
                'subtract' => max(0, $oldQty - $qty),
                'set'      => $qty,
                default    => $oldQty
            };
            $pdo->prepare("UPDATE inventory_items SET current_stock=? WHERE id=?")->execute([$newQty, $itemId]);
            auditLog($_SESSION['user_id'], 'INVENTORY_UPDATE', 'inventory_items', $itemId,
                "Stock: $oldQty", "Stock: $newQty", $reason ?: "$adjType $qty units");
            setFlash('success', "Stock updated: {$item['item_name']} — {$oldQty} → {$newQty}");
        }
    }

    elseif ($action === 'deactivate' && hasRole('admin')) {
        $itemId = (int)$_POST['item_id'];
        $pdo->prepare("UPDATE inventory_items SET is_active=0 WHERE id=?")->execute([$itemId]);
        auditLog($_SESSION['user_id'], 'INVENTORY_DEACTIVATE', 'inventory_items', $itemId);
        setFlash('success', 'Item deactivated.');
    }

    elseif ($action === 'use') {
        $bookingIdRaw = trim((string)($_POST['booking_id'] ?? ''));
        $bookingId = $bookingIdRaw !== '' ? (int)$bookingIdRaw : null;
        $consumerName = sanitize($_POST['consumer_name'] ?? '');
        $itemIds = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (!is_array($itemIds)) {
            $itemIds = [$itemIds];
        }
        if (!is_array($quantities)) {
            $quantities = [$quantities];
        }

        $lineItems = [];
        $requestedTotals = [];

        foreach ($itemIds as $index => $itemIdValue) {
            $itemId = (int)$itemIdValue;
            $qty = isset($quantities[$index]) ? (int)$quantities[$index] : 0;

            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }

            $lineItems[] = [
                'item_id' => $itemId,
                'quantity' => $qty,
            ];

            if (!isset($requestedTotals[$itemId])) {
                $requestedTotals[$itemId] = 0;
            }
            $requestedTotals[$itemId] += $qty;
        }

        if (empty($lineItems)) {
            setFlash('error', 'Please add at least one valid item and quantity.');
        } else {
            $placeholders = implode(',', array_fill(0, count($requestedTotals), '?'));
            $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id IN ($placeholders) AND is_active=1");
            $stmt->execute(array_keys($requestedTotals));
            $fetchedItems = $stmt->fetchAll();

            $itemsById = [];
            foreach ($fetchedItems as $fetchedItem) {
                $itemsById[(int)$fetchedItem['id']] = $fetchedItem;
            }

            $errors = [];
            foreach ($requestedTotals as $itemId => $totalQtyRequested) {
                if (!isset($itemsById[$itemId])) {
                    $errors[] = 'One of the selected items was not found or is inactive.';
                    continue;
                }

                if ((int)$itemsById[$itemId]['current_stock'] < $totalQtyRequested) {
                    $errors[] = sanitize($itemsById[$itemId]['item_name']) . ' has insufficient stock.';
                }
            }

            if (!empty($errors)) {
                setFlash('error', implode(' ', array_unique($errors)));
            } else {
                try {
                    $pdo->beginTransaction();

                    $grandTotal = 0;
                    $usageCount = 0;
                    $usedItemNames = [];

                    $notes = null;
                    if ($consumerName !== '') {
                        $notes = 'Consumer: ' . $consumerName;
                    }

                    foreach ($lineItems as $lineItem) {
                        $item = $itemsById[$lineItem['item_id']];
                        $qty = $lineItem['quantity'];
                        $lineTotal = ((float)$item['selling_price']) * $qty;

                        $pdo->prepare("INSERT INTO inventory_usage (booking_id,item_id,quantity,unit_price,total_price,recorded_by,notes) VALUES (?,?,?,?,?,?,?)")
                            ->execute([
                                $bookingId,
                                $lineItem['item_id'],
                                $qty,
                                $item['selling_price'],
                                $lineTotal,
                                $_SESSION['user_id'],
                                $notes
                            ]);

                        $grandTotal += $lineTotal;
                        $usageCount++;
                        $usedItemNames[] = $item['item_name'] . ' x' . $qty;
                    }

                    foreach ($requestedTotals as $itemId => $totalQtyRequested) {
                        $pdo->prepare("UPDATE inventory_items SET current_stock = current_stock - ? WHERE id=?")
                            ->execute([$totalQtyRequested, $itemId]);
                    }

                    if ($bookingId !== null && $grandTotal > 0) {
                        $pdo->prepare("UPDATE bookings SET total_amount = total_amount + ? WHERE id=?")
                            ->execute([$grandTotal, $bookingId]);
                    }

                    auditLog(
                        $_SESSION['user_id'],
                        'INVENTORY_USAGE',
                        'inventory_usage',
                        null,
                        null,
                        $consumerName !== '' ? $consumerName : null,
                        implode(', ', $usedItemNames)
                    );

                    $pdo->commit();

                    $successMessage = "Usage recorded for {$usageCount} item" . ($usageCount > 1 ? 's' : '');
                    if ($consumerName !== '') {
                        $successMessage .= " for {$consumerName}";
                    }
                    if ($grandTotal > 0) {
                        $successMessage .= ' — Total: ' . formatCurrency($grandTotal);
                    }
                    setFlash('success', $successMessage);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    setFlash('error', 'Failed to record item usage. Please try again.');
                }
            }
        }
    }

    header('Location: index.php');
    exit;
}

$filterCat = sanitize($_GET['cat'] ?? '');
$where = "is_active = 1";
if ($filterCat) $where .= " AND category = " . $pdo->quote($filterCat);

$items = $pdo->query("SELECT * FROM inventory_items WHERE $where ORDER BY category, item_name")->fetchAll();
$allActiveItems = $pdo->query("SELECT * FROM inventory_items WHERE is_active = 1 ORDER BY category, item_name")->fetchAll();

$categories = ['minibar', 'toiletries', 'laundry', 'amenities', 'supplies'];

$lowStockCount = getLowStockInventoryCount($pdo);

// Active bookings for usage form
$activeBookings = $pdo->query("SELECT id, booking_ref, guest_name, room_id FROM bookings WHERE status='active' ORDER BY check_in DESC")->fetchAll();

$usageItemOptions = '';
foreach ($allActiveItems as $i) {
    if ((int)$i['current_stock'] <= 0) {
        continue;
    }

    $usageItemOptions .= '<option value="' . (int)$i['id'] . '">' .
        sanitize($i['item_name']) .
        ' (Stock: ' . (int)$i['current_stock'] . ' ' . sanitize($i['unit']) . ')' .
        ((float)$i['selling_price'] > 0 ? ' — ₱' . number_format((float)$i['selling_price'], 2) : '') .
        '</option>';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-boxes me-2 text-primary"></i>Inventory Management</h4>
        <p>Minibar, toiletries, laundry add-ons, and amenities tracking</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-info text-white" data-bs-toggle="modal" data-bs-target="#useModal">
            <i class="fas fa-minus-circle me-2"></i>Record Usage
        </button>
        <?php if (hasRole('admin')): ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus me-2"></i>Add Item
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($lowStockCount > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fas fa-exclamation-triangle fs-5"></i>
    <strong><?= $lowStockCount ?> item<?= $lowStockCount>1?'s':'' ?> at or below minimum stock level!</strong>
    Please restock soon.
</div>
<?php endif; ?>

<!-- Category Filter -->
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex gap-2 flex-wrap">
            <a href="?" class="btn btn-sm <?= !$filterCat?'btn-primary':'btn-outline-primary' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
            <a href="?cat=<?= $cat ?>" class="btn btn-sm <?= $filterCat===$cat?'btn-primary':'btn-outline-secondary' ?>">
                <?= ucfirst($cat) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item Name</th><th>Category</th><th>Unit</th>
                        <th class="text-center">Current Stock</th><th class="text-center">Min. Stock</th>
                        <th>Unit Cost</th><th>Selling Price</th><th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $isLow = isLowStockItem($item);
                    ?>
                    <tr <?= $isLow ? 'class="low-stock-row"' : '' ?>>
                        <td>
                            <strong><?= sanitize($item['item_name']) ?></strong>
                            <?php if ($isLow): ?><span class="badge bg-warning ms-1">Low Stock</span><?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?= ucfirst($item['category']) ?></span></td>
                        <td><?= sanitize($item['unit']) ?></td>
                        <td class="text-center fw-bold <?= $isLow?'text-danger':'' ?>"><?= $item['current_stock'] ?></td>
                        <td class="text-center text-muted"><?= $item['minimum_stock'] ?></td>
                        <td><?= $item['unit_cost'] > 0 ? formatCurrency($item['unit_cost']) : '—' ?></td>
                        <td><?= $item['selling_price'] > 0 ? formatCurrency($item['selling_price']) : 'Complimentary' ?></td>
                        <td><?= $item['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                        <td>
                            <button class="btn btn-xs btn-outline-primary me-1"
                                onclick="openAdjustModal(<?= htmlspecialchars(json_encode($item)) ?>)">
                                <i class="fas fa-edit"></i> Adjust
                            </button>
                            <?php if (hasRole('admin')): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Deactivate this item?')">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<?php if (hasRole('admin')): ?>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Inventory Item</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" class="form-control" required placeholder="Item name">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Unit</label>
                            <input type="text" name="unit" class="form-control" value="pcs" placeholder="pcs, kg, etc.">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $cat): ?><option value="<?= $cat ?>"><?= ucfirst($cat) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold">Current Stock</label>
                            <input type="number" name="current_stock" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold">Min. Stock</label>
                            <input type="number" name="minimum_stock" class="form-control" value="5" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Unit Cost (₱)</label>
                            <input type="number" name="unit_cost" class="form-control" value="0" step="0.01" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Selling Price (₱)</label>
                            <input type="number" name="selling_price" class="form-control" value="0" step="0.01" min="0">
                            <div class="form-text">Set to 0 for complimentary items</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="adjustTitle"><i class="fas fa-edit me-2"></i>Adjust Stock</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="item_id" id="adjustItemId">
                <div class="modal-body">
                    <div class="alert alert-light mb-3" id="adjustItemInfo"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Adjustment Type</label>
                        <select name="adj_type" class="form-select">
                            <option value="add">➕ Add to Stock</option>
                            <option value="subtract">➖ Remove from Stock</option>
                            <option value="set">🔄 Set Exact Quantity</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason</label>
                        <input type="text" name="reason" class="form-control" placeholder="Delivery, spoilage, correction...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Usage Modal -->
<div class="modal fade" id="useModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="fas fa-minus-circle me-2"></i>Record Item Usage</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST" id="usageForm">
                <input type="hidden" name="action" value="use">
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Booking / Guest</label>
                            <select name="booking_id" class="form-select">
                                <option value="">— No specific booking —</option>
                                <?php foreach ($activeBookings as $ab): ?>
                                <option value="<?= $ab['id'] ?>"><?= sanitize($ab['booking_ref']) ?> — <?= sanitize($ab['guest_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">You may leave this blank for walk-in or direct item sales.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Buyer / Consumer Name</label>
                            <input type="text" name="consumer_name" class="form-control" placeholder="Type guest or customer name">
                            <div class="form-text">Optional, but useful when Booking / Guest is left blank.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">Items <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addUsageRowBtn">
                            <i class="fas fa-plus me-1"></i>Add Another Item
                        </button>
                    </div>

                    <div id="usageItemsContainer">
                        <div class="usage-item-row border rounded p-3 mb-3 bg-light">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold">Item</label>
                                    <select name="item_id[]" class="form-select usage-item-select" required>
                                        <option value="">— Select Item —</option>
                                        <?= $usageItemOptions ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Quantity</label>
                                    <input type="number" name="quantity[]" class="form-control" min="1" value="1" required>
                                </div>
                                <div class="col-md-1 d-grid">
                                    <button type="button" class="btn btn-outline-danger remove-usage-row" title="Remove item" disabled>
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white fw-bold"><i class="fas fa-check me-2"></i>Record Usage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="usageItemRowTemplate">
    <div class="usage-item-row border rounded p-3 mb-3 bg-light">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Item</label>
                <select name="item_id[]" class="form-select usage-item-select" required>
                    <option value="">— Select Item —</option>
                    <?= $usageItemOptions ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Quantity</label>
                <input type="number" name="quantity[]" class="form-control" min="1" value="1" required>
            </div>
            <div class="col-md-1 d-grid">
                <button type="button" class="btn btn-outline-danger remove-usage-row" title="Remove item">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
</template>

<?php
$extraScripts = <<<JS
<script>
function openAdjustModal(item) {
    document.getElementById('adjustItemId').value = item.id;
    document.getElementById('adjustTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Adjust: ' + item.item_name;
    document.getElementById('adjustItemInfo').innerHTML =
        '<strong>' + item.item_name + '</strong><br>' +
        'Category: ' + item.category + ' | Unit: ' + item.unit + '<br>' +
        'Current Stock: <strong>' + item.current_stock + '</strong> | Min: ' + item.minimum_stock;
    new bootstrap.Modal(document.getElementById('adjustModal')).show();
}

(function () {
    const container = document.getElementById('usageItemsContainer');
    const addRowBtn = document.getElementById('addUsageRowBtn');
    const template = document.getElementById('usageItemRowTemplate');
    const usageForm = document.getElementById('usageForm');
    const useModal = document.getElementById('useModal');

    if (!container || !addRowBtn || !template || !usageForm || !useModal) {
        return;
    }

    function updateRemoveButtons() {
        const rows = container.querySelectorAll('.usage-item-row');
        rows.forEach((row, index) => {
            const removeBtn = row.querySelector('.remove-usage-row');
            if (!removeBtn) {
                return;
            }
            removeBtn.disabled = rows.length === 1 && index === 0;
        });
    }

    function addUsageRow() {
        container.insertAdjacentHTML('beforeend', template.innerHTML.trim());
        updateRemoveButtons();
    }

    addRowBtn.addEventListener('click', addUsageRow);

    container.addEventListener('click', function (event) {
        const removeBtn = event.target.closest('.remove-usage-row');
        if (!removeBtn) {
            return;
        }

        const row = removeBtn.closest('.usage-item-row');
        if (!row) {
            return;
        }

        if (container.querySelectorAll('.usage-item-row').length > 1) {
            row.remove();
            updateRemoveButtons();
        }
    });

    useModal.addEventListener('hidden.bs.modal', function () {
        usageForm.reset();
        const rows = container.querySelectorAll('.usage-item-row');
        rows.forEach((row, index) => {
            if (index > 0) {
                row.remove();
            }
        });
        updateRemoveButtons();
    });

    updateRemoveButtons();
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
