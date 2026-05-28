<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin']);

$pageTitle = 'Room Rates';
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $typeId       = (int)($_POST['type_id'] ?? 0);
    $typeName     = sanitize(trim($_POST['type_name'] ?? ''));
    $desc         = sanitize(trim($_POST['description'] ?? ''));
    $baseRate     = (float)($_POST['base_rate'] ?? 0);
    $hourlyRate   = (float)($_POST['hourly_rate'] ?? 0);
    $rate3h       = (float)($_POST['short_time_3h_rate'] ?? 0);
    $rate6h       = (float)($_POST['short_time_6h_rate'] ?? 0);
    $rate12h      = (float)($_POST['short_time_12h_rate'] ?? 0);
    $rate24h      = (float)($_POST['short_time_24h_rate'] ?? 0);
    $maxOcc       = (int)($_POST['max_occupancy'] ?? 1);
    $amenities    = sanitize($_POST['amenities'] ?? '');

    if ($hourlyRate <= 0 && $rate3h > 0) {
        $hourlyRate = round($rate3h / 3, 2);
    }

    if ($action === 'update_rate') {
        if ($typeId <= 0) {
            setFlash('error', 'Invalid room type.');
        } elseif ($typeName === '') {
            setFlash('error', 'Room type name is required.');
        } else {
            $oldStmt = $pdo->prepare("SELECT * FROM room_types WHERE id=?");
            $oldStmt->execute([$typeId]);
            $old = $oldStmt->fetch();

            $pdo->prepare("UPDATE room_types
                SET type_name=?, description=?, base_rate=?, hourly_rate=?,
                    short_time_3h_rate=?, short_time_6h_rate=?, short_time_12h_rate=?, short_time_24h_rate=?,
                    max_occupancy=?, amenities=?
                WHERE id=?")
                ->execute([$typeName, $desc, $baseRate, $hourlyRate, $rate3h, $rate6h, $rate12h, $rate24h, $maxOcc, $amenities, $typeId]);

            auditLog($_SESSION['user_id'], 'PRICE_CHANGE', 'room_types', $typeId,
                json_encode($old),
                json_encode([
                    'type_name' => $typeName,
                    'description' => $desc,
                    'base_rate' => $baseRate,
                    'hourly_rate' => $hourlyRate,
                    'short_time_3h_rate' => $rate3h,
                    'short_time_6h_rate' => $rate6h,
                    'short_time_12h_rate' => $rate12h,
                    'short_time_24h_rate' => $rate24h,
                    'max_occupancy' => $maxOcc,
                    'amenities' => $amenities,
                ]),
                'Room type updated by admin');
            setFlash('success', 'Room type updated successfully.');
        }
    } elseif ($action === 'add_type') {
        if ($typeName === '') {
            setFlash('error', 'Room type name is required.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM room_types WHERE type_name = ?");
            $chk->execute([$typeName]);
            if ($chk->fetch()) {
                setFlash('error', 'Room type name already exists.');
            } else {
                $pdo->prepare("INSERT INTO room_types
                    (type_name, description, base_rate, hourly_rate, short_time_3h_rate, short_time_6h_rate, short_time_12h_rate, short_time_24h_rate, max_occupancy, amenities)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$typeName, $desc, $baseRate, $hourlyRate, $rate3h, $rate6h, $rate12h, $rate24h, $maxOcc, $amenities]);
                $newId = (int)$pdo->lastInsertId();
                auditLog($_SESSION['user_id'], 'ROOM_TYPE_ADDED', 'room_types', $newId, null, $typeName, 'New room type added');
                setFlash('success', 'Room type added successfully.');
            }
        }
    } elseif ($action === 'delete_type') {
        if ($typeId <= 0) {
            setFlash('error', 'Invalid room type.');
        } else {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_type_id = ?");
            $cnt->execute([$typeId]);
            $roomCount = (int)$cnt->fetchColumn();

            $oldStmt = $pdo->prepare("SELECT * FROM room_types WHERE id=?");
            $oldStmt->execute([$typeId]);
            $old = $oldStmt->fetch();

            if (!$old) {
                setFlash('error', 'Room type not found.');
            } elseif ($roomCount > 0) {
                setFlash('error', 'Cannot delete this room type because it is assigned to existing rooms.');
            } else {
                $pdo->prepare("DELETE FROM room_types WHERE id = ?")->execute([$typeId]);
                auditLog($_SESSION['user_id'], 'ROOM_TYPE_DELETED', 'room_types', $typeId, $old['type_name'], null, 'Room type deleted by admin');
                setFlash('success', 'Room type deleted.');
            }
        }
    }

    header('Location: index.php');
    exit;
}

$roomTypes = $pdo->query("
    SELECT rt.*, COUNT(r.id) as room_count,
           SUM(CASE WHEN r.status='occupied' THEN 1 ELSE 0 END) as occupied_count
    FROM room_types rt
    LEFT JOIN rooms r ON rt.id = r.room_type_id
    GROUP BY rt.id
    ORDER BY rt.base_rate")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h4><i class="fas fa-tags me-2 text-primary"></i>Room Rates Management</h4>
        <p>Configure overnight rate plus short-time package rates for 3, 6, 12, and 24 hours.</p>
    </div>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTypeModal">
        <i class="fas fa-plus me-2"></i>Add Room Type
    </button>
</div>

<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="fas fa-lock fs-5"></i>
    <div><strong>Admin Only:</strong> Rate changes affect automatic billing for check-in, short-time extension, and balance computation.</div>
</div>

<div class="row g-4">
    <?php foreach ($roomTypes as $rt): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><?= sanitize($rt['type_name']) ?></h6>
                <div>
                    <span class="badge bg-secondary"><?= $rt['room_count'] ?> rooms</span>
                    <span class="badge bg-<?= $rt['occupied_count'] > 0 ? 'danger' : 'success' ?> ms-1">
                        <?= $rt['occupied_count'] ?>/<?= $rt['room_count'] ?> occ.
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center mb-3">
                    <div class="col-6">
                        <div class="small text-muted">Overnight</div>
                        <div class="fs-5 fw-bold text-primary"><?= formatCurrency($rt['base_rate']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted">Ref. Hourly</div>
                        <div class="fs-5 fw-bold text-info"><?= formatCurrency($rt['hourly_rate']) ?></div>
                    </div>
                </div>

                <div class="row g-2 small mb-3">
                    <div class="col-6"><div class="border rounded p-2 h-100"><strong>3 hrs</strong><br><?= formatCurrency($rt['short_time_3h_rate']) ?></div></div>
                    <div class="col-6"><div class="border rounded p-2 h-100"><strong>6 hrs</strong><br><?= formatCurrency($rt['short_time_6h_rate']) ?></div></div>
                    <div class="col-6"><div class="border rounded p-2 h-100"><strong>12 hrs</strong><br><?= formatCurrency($rt['short_time_12h_rate']) ?></div></div>
                    <div class="col-6"><div class="border rounded p-2 h-100"><strong>24 hrs</strong><br><?= formatCurrency($rt['short_time_24h_rate']) ?></div></div>
                </div>

                <div class="mb-2 text-muted small">
                    <i class="fas fa-users me-1"></i>Max <?= (int)$rt['max_occupancy'] ?> guests
                </div>
                <?php if ($rt['amenities']): ?>
                <div class="mb-3">
                    <?php foreach (explode(',', $rt['amenities']) as $a): ?>
                    <span class="badge bg-light text-dark border me-1 mb-1" style="font-size:0.7rem"><?= trim($a) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm"
                        data-room-type="<?= htmlspecialchars(json_encode($rt), ENT_QUOTES, 'UTF-8') ?>"
                        onclick="openRateModal(JSON.parse(this.getAttribute('data-room-type')))">
                        <i class="fas fa-edit me-2"></i>Edit / Update
                    </button>

                    <form method="POST" onsubmit="return confirmDeleteType(<?= (int)$rt['room_count'] ?>);" class="m-0">
                        <input type="hidden" name="action" value="delete_type">
                        <input type="hidden" name="type_id" value="<?= (int)$rt['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"
                                <?= ((int)$rt['room_count'] > 0) ? 'disabled' : '' ?>
                                title="<?= ((int)$rt['room_count'] > 0) ? 'Cannot delete: assigned to rooms' : '' ?>">
                            <i class="fas fa-trash me-2"></i>Delete Type
                        </button>
                        <?php if ((int)$rt['room_count'] > 0): ?>
                            <div class="text-muted small mt-1 text-center">Remove rooms from this type first to delete.</div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php
function renderRateFields($prefix = '') {
    $idp = $prefix ? $prefix . '_' : '';
    ?>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label fw-semibold">Room Type Name <span class="text-danger">*</span></label>
            <input type="text" name="type_name" id="<?= $idp ?>type_name" class="form-control" required>
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Description</label>
            <textarea name="description" id="<?= $idp ?>description" class="form-control" rows="2"></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Overnight Rate (2PM–12PM) ₱</label>
            <input type="number" name="base_rate" id="<?= $idp ?>base_rate" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Reference Hourly Rate ₱</label>
            <input type="number" name="hourly_rate" id="<?= $idp ?>hourly_rate" class="form-control" step="0.01" min="0">
            <div class="form-text">Optional fallback only. Short-time billing uses the package rates below.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">3 Hours Rate ₱</label>
            <input type="number" name="short_time_3h_rate" id="<?= $idp ?>short_time_3h_rate" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">6 Hours Rate ₱</label>
            <input type="number" name="short_time_6h_rate" id="<?= $idp ?>short_time_6h_rate" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">12 Hours Rate ₱</label>
            <input type="number" name="short_time_12h_rate" id="<?= $idp ?>short_time_12h_rate" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">24 Hours Rate ₱</label>
            <input type="number" name="short_time_24h_rate" id="<?= $idp ?>short_time_24h_rate" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Max Occupancy</label>
            <input type="number" name="max_occupancy" id="<?= $idp ?>max_occupancy" class="form-control" min="1" max="20">
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Amenities (comma-separated)</label>
            <input type="text" name="amenities" id="<?= $idp ?>amenities" class="form-control" placeholder="AC, TV, WiFi, Hot Shower...">
        </div>
    </div>
    <?php
}
?>

<div class="modal fade" id="rateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-tags me-2"></i>Edit Room Rate</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_rate">
                <input type="hidden" name="type_id" id="edit_type_id">
                <div class="modal-body">
                    <?php renderRateFields('edit'); ?>
                    <div class="alert alert-warning py-2 mt-3 mb-0 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>This change updates automatic billing, short-time extension fees, and cashier totals.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fas fa-save me-2"></i>Save Rates</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Room Type</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_type">
                <div class="modal-body"><?php renderRateFields('add'); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-plus me-2"></i>Add Room Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
const rateModal = new bootstrap.Modal(document.getElementById('rateModal'));

function openRateModal(rt) {
    document.getElementById('edit_type_id').value = rt.id || '';
    document.getElementById('edit_type_name').value = rt.type_name || '';
    document.getElementById('edit_description').value = rt.description || '';
    document.getElementById('edit_base_rate').value = rt.base_rate || 0;
    document.getElementById('edit_hourly_rate').value = rt.hourly_rate || 0;
    document.getElementById('edit_short_time_3h_rate').value = rt.short_time_3h_rate || 0;
    document.getElementById('edit_short_time_6h_rate').value = rt.short_time_6h_rate || 0;
    document.getElementById('edit_short_time_12h_rate').value = rt.short_time_12h_rate || 0;
    document.getElementById('edit_short_time_24h_rate').value = rt.short_time_24h_rate || 0;
    document.getElementById('edit_max_occupancy').value = rt.max_occupancy || 1;
    document.getElementById('edit_amenities').value = rt.amenities || '';
    rateModal.show();
}

function confirmDeleteType(roomCount) {
    if (parseInt(roomCount || 0, 10) > 0) {
        alert('Cannot delete this room type because it is assigned to existing rooms.');
        return false;
    }
    return confirm('Delete this room type?');
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
