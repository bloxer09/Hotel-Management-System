<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Room Status Board';
$pdo = getPDO();

// ─── POST HANDLERS ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Single status change
    if ($action === 'change_status') {
        requireRole(['admin', 'front_desk', 'housekeeping']);
        $roomId    = (int)$_POST['room_id'];
        $newStatus = $_POST['new_status'] ?? '';
        $notes     = sanitize($_POST['notes'] ?? '');
        $allowedStatuses = ['vacant', 'cleaning', 'out_of_order'];

        if (in_array($newStatus, $allowedStatuses, true) && $roomId > 0) {
            $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $old = $stmt->fetch();

            if (!$old) {
                setFlash('error', 'Room not found.');
            } elseif (hasRole('housekeeping') && $old['status'] === 'occupied') {
                setFlash('error', 'Housekeeping cannot update an occupied room. Use Check-Out first.');
            } elseif ($old['status'] === 'occupied' && $newStatus !== 'cleaning') {
                setFlash('error', 'Cannot change occupied room status — use Check-Out first.');
            } else {
                $pdo->prepare("UPDATE rooms SET status = ?, notes = ? WHERE id = ?")->execute([$newStatus, $notes, $roomId]);
                auditLog($_SESSION['user_id'], 'ROOM_STATUS_CHANGE', 'rooms', $roomId, $old['status'], $newStatus, $notes ?: 'Manual update');
                setFlash('success', 'Room status updated.');
            }
        } else {
            setFlash('error', 'Invalid room status selected.');
        }
    }

    // Bulk mark cleaning -> vacant
    elseif ($action === 'bulk_clean') {
        // Housekeeping should be able to bulk mark rooms as cleaned.
        requireRole(['admin', 'front_desk', 'housekeeping']);
        $roomIds = $_POST['room_ids'] ?? [];
        $count = 0;
        foreach ($roomIds as $rid) {
            $rid = (int)$rid;
            $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
            $stmt->execute([$rid]);
            $old = $stmt->fetch();
            if ($old && $old['status'] === 'cleaning') {
                $pdo->prepare("UPDATE rooms SET status = 'vacant', notes = '' WHERE id = ?")->execute([$rid]);
                auditLog($_SESSION['user_id'], 'ROOM_BULK_CLEAN', 'rooms', $rid, 'cleaning', 'vacant', 'Bulk housekeeping');
                $count++;
            }
        }
        setFlash('success', "$count room(s) marked as Vacant / Ready.");
    }

    // Add new room (admin only)
    elseif ($action === 'add_room') {
        requireRole(['admin']);
        $roomNumber = strtoupper(trim(sanitize($_POST['room_number'])));
        $roomTypeId = (int)$_POST['room_type_id'];
        $floor      = (int)$_POST['floor'];
        $notes      = sanitize($_POST['notes'] ?? '');
        if (empty($roomNumber)) {
            setFlash('error', 'Room number is required.');
        } elseif ($floor < 1) {
            setFlash('error', 'Floor must be at least 1.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM rooms WHERE room_number = ?");
            $chk->execute([$roomNumber]);
            if ($chk->fetch()) {
                setFlash('error', "Room number <strong>$roomNumber</strong> already exists.");
            } else {
                $pdo->prepare("INSERT INTO rooms (room_number,room_type_id,floor,status,notes) VALUES (?,?,?,'vacant',?)")
                    ->execute([$roomNumber, $roomTypeId, $floor, $notes]);
                auditLog($_SESSION['user_id'], 'ROOM_ADDED', 'rooms', $pdo->lastInsertId(), null, "Room $roomNumber Floor $floor", 'New room added');
                setFlash('success', "Room <strong>$roomNumber</strong> added successfully.");
            }
        }
    }

    // Edit room (admin only)
    elseif ($action === 'edit_room') {
        requireRole(['admin']);
        $roomId     = (int)$_POST['room_id'];
        $roomTypeId = (int)$_POST['room_type_id'];
        $floor      = (int)$_POST['floor'];
        $notes      = sanitize($_POST['notes'] ?? '');
        $permOoo    = isset($_POST['perm_ooo']) ? 1 : 0;
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $old = $stmt->fetch();
        if (!$old) {
            setFlash('error', 'Room not found.');
        } elseif ($old['status'] === 'occupied') {
            setFlash('error', 'Cannot edit an occupied room — check out the guest first.');
        } else {
            $newStatus = $permOoo ? 'out_of_order' : $old['status'];
            $pdo->prepare("UPDATE rooms SET room_type_id=?,floor=?,notes=?,status=? WHERE id=?")
                ->execute([$roomTypeId, $floor, $notes, $newStatus, $roomId]);
            auditLog($_SESSION['user_id'], 'ROOM_EDITED', 'rooms', $roomId,
                json_encode(['type'=>$old['room_type_id'],'floor'=>$old['floor'],'status'=>$old['status']]),
                json_encode(['type'=>$roomTypeId,'floor'=>$floor,'status'=>$newStatus]),
                $notes ?: 'Room details updated');
            setFlash('success', "Room <strong>{$old['room_number']}</strong> updated.");
        }
    }

    // Delete room (admin only)
    elseif ($action === 'delete_room') {
        requireRole(['admin']);
        $roomId = (int)$_POST['room_id'];
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $old = $stmt->fetch();
        
        if (!$old) {
            setFlash('error', 'Room not found.');
        } elseif ($old['status'] === 'occupied') {
            setFlash('error', 'Cannot delete an occupied room.');
        } else {
            try {
                $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$roomId]);
                auditLog($_SESSION['user_id'], 'ROOM_DELETED', 'rooms', $roomId, $old['room_number'], null, 'Room deleted');
                setFlash('success', "Room <strong>{$old['room_number']}</strong> was successfully deleted.");
            } catch (PDOException $e) {
                // Catch foreign key constraint violations if the room is attached to past bookings
                setFlash('error', 'Cannot delete this room because it has associated booking history.');
            }
        }
    }

    header('Location: index.php');
    exit;
}

// ─── DATA ─────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$filterFloor  = $_GET['floor']  ?? 'all';
$where = '1=1';
if ($filterStatus !== 'all') $where .= " AND r.status = " . $pdo->quote($filterStatus);
if ($filterFloor  !== 'all') $where .= " AND r.floor = "  . (int)$filterFloor;

$rooms = $pdo->query("
    SELECT r.*, rt.type_name, rt.base_rate, rt.max_occupancy,
           b.guest_name, b.check_in, b.expected_check_out, b.booking_ref, b.id AS booking_id
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'active'
    WHERE $where ORDER BY r.floor, r.room_number")->fetchAll();

$floors    = $pdo->query("SELECT DISTINCT floor FROM rooms ORDER BY floor")->fetchAll(PDO::FETCH_COLUMN);
$roomTypes = $pdo->query("SELECT * FROM room_types ORDER BY type_name")->fetchAll();
$statusCounts = [];
foreach ($pdo->query("SELECT status, COUNT(*) as cnt FROM rooms GROUP BY status")->fetchAll() as $row) {
    $statusCounts[$row['status']] = $row['cnt'];
}
$allCleaningRooms = $pdo->query("
    SELECT r.*, rt.type_name FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id
    WHERE r.status = 'cleaning' ORDER BY r.floor, r.room_number")->fetchAll();

$canSeeCheckoutAlerts = hasRole(['admin', 'front_desk']);
$nowTs = time();
$soonTs = $nowTs + (5 * 60);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-door-open me-2 text-primary"></i>Room Status Board</h4>
        <p>Real-time overview — manage status, add rooms, bulk housekeeping updates</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (hasRole(['admin','front_desk','housekeeping']) && !empty($allCleaningRooms)): ?>
        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#bulkCleanModal">
            <i class="fas fa-broom me-2"></i>Bulk Clean
            <span class="badge bg-dark ms-1"><?= count($allCleaningRooms) ?></span>
        </button>
        <?php endif; ?>
        <?php if (hasRole('admin')): ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addRoomModal">
            <i class="fas fa-plus me-2"></i>Add Room
        </button>
        <?php endif; ?>
        <?php if (hasRole(['admin','front_desk'])): ?>
        <a href="<?= BASE_URL ?>modules/checkin/index.php" class="btn btn-primary">
            <i class="fas fa-sign-in-alt me-2"></i>New Check-In
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div class="d-flex gap-3 flex-wrap align-items-center">
            <?php $legend = ['vacant'=>['success','Vacant'],'occupied'=>['danger','Occupied'],'cleaning'=>['warning','Cleaning'],'out_of_order'=>['secondary','Out of Order']]; ?>
            <?php foreach ($legend as $key => [$color, $label]): $cnt = $statusCounts[$key] ?? 0; ?>
            <a href="?status=<?= $key ?>&floor=<?= $filterFloor ?>" class="text-decoration-none d-flex align-items-center gap-1">
                <span class="room-card <?= $key ?> d-inline-block" style="width:18px;height:18px;border-radius:3px;flex-shrink:0"></span>
                <span class="text-dark small <?= $filterStatus===$key?'fw-bold':'' ?>"><?= $label ?></span>
                <span class="badge bg-<?= $color ?> bg-opacity-75"><?= $cnt ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($filterStatus !== 'all'): ?>
            <a href="?status=all&floor=<?= $filterFloor ?>" class="btn btn-xs btn-outline-secondary py-0 px-2 small">✕ Clear</a>
            <?php endif; ?>
        </div>
        <form class="d-flex gap-2 align-items-center" method="GET">
            <input type="hidden" name="status" value="<?= $filterStatus ?>">
            <label class="form-label mb-0 small fw-semibold">Floor:</label>
            <select name="floor" class="form-select form-select-sm" style="width:130px" onchange="this.form.submit()">
                <option value="all" <?= $filterFloor==='all'?'selected':'' ?>>All Floors</option>
                <?php foreach ($floors as $f): ?>
                <option value="<?= $f ?>" <?= $filterFloor==(string)$f?'selected':'' ?>>Floor <?= $f ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php $byFloor = []; foreach ($rooms as $r) $byFloor[$r['floor']][] = $r; ?>

<?php foreach ($byFloor as $floorNum => $floorRooms): ?>
<div class="d-flex align-items-center gap-2 mb-2 mt-3">
    <span class="text-muted fw-bold text-uppercase" style="font-size:0.7rem;letter-spacing:1px">
        <i class="fas fa-layer-group me-1"></i>Floor <?= $floorNum ?>
    </span>
    <span class="text-muted" style="font-size:0.7rem">(<?= count($floorRooms) ?> rooms)</span>
    <hr class="flex-fill m-0 ms-2">
</div>
<div class="row g-2 mb-1">
    <?php foreach ($floorRooms as $room): ?>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <?php
            $checkoutState = '';
            $checkoutLabel = '';
            if ($canSeeCheckoutAlerts && $room['status'] === 'occupied' && !empty($room['expected_check_out'])) {
                $expectedTs = strtotime($room['expected_check_out']);
                if ($expectedTs !== false) {
                    if ($expectedTs <= $nowTs) {
                        $checkoutState = 'overdue';
                        $minsOver = max(1, (int)ceil(($nowTs - $expectedTs) / 60));
                        $checkoutLabel = 'Overdue ' . $minsOver . ' min';
                    } elseif ($expectedTs <= $soonTs) {
                        $checkoutState = 'upcoming';
                        $minsLeft = max(1, (int)ceil(($expectedTs - $nowTs) / 60));
                        $checkoutLabel = $minsLeft . ' min left';
                    }
                }
            }
            $roomCardClasses = trim('room-card ' . $room['status'] . ' h-100 position-relative' . ($checkoutState ? ' room-' . $checkoutState : ''));
        ?>
        <div class="<?= $roomCardClasses ?>"
             data-room="<?= htmlspecialchars(json_encode($room), ENT_QUOTES, 'UTF-8') ?>"
             onclick="handleRoomClick(JSON.parse(this.getAttribute('data-room')))"
             title="Room <?= sanitize($room['room_number']) ?> — click to choose action"
             style="cursor:pointer;min-height:90px">

            <div class="d-flex justify-content-between align-items-start">
                <div class="room-number"><?= sanitize($room['room_number']) ?></div>
                <small style="font-size:0.58rem;opacity:0.6;margin-top:2px">F<?= $room['floor'] ?></small>
            </div>
            <?php if ($checkoutState): ?>
            <div class="mb-1">
                <span class="badge room-alert-chip <?= $checkoutState === 'overdue' ? 'bg-danger' : 'bg-warning text-dark' ?>">
                    <i class="fas <?= $checkoutState === 'overdue' ? 'fa-triangle-exclamation' : 'fa-bell' ?> me-1"></i><?= sanitize($checkoutLabel) ?>
                </span>
            </div>
            <?php endif; ?>
            <div style="font-size:0.67rem;font-weight:600;opacity:0.8;line-height:1.2;margin-top:2px">
                <?= sanitize($room['type_name']) ?>
            </div>
            <?php if ($room['status'] === 'occupied' && $room['guest_name']): ?>
            <div style="font-size:0.6rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:3px">
                <i class="fas fa-user" style="font-size:0.55rem"></i> <?= sanitize($room['guest_name']) ?>
            </div>
            <div style="font-size:0.57rem;opacity:0.65">
                Out: <?= $room['expected_check_out'] ? date('M d h:iA', strtotime($room['expected_check_out'])) : '—' ?>
            </div>
            <?php if ($checkoutState): ?>
            <div style="font-size:0.57rem;font-weight:700;opacity:0.9" class="<?= $checkoutState === 'overdue' ? 'text-danger' : 'text-warning' ?>">
                <?= sanitize($checkoutState === 'overdue' ? 'Needs follow-up now' : 'Reminder due soon') ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($room['notes']) && $room['status'] !== 'occupied'): ?>
            <div style="font-size:0.57rem;opacity:0.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px">
                <i class="fas <?= $room['status'] === 'out_of_order' ? 'fa-tools' : 'fa-sticky-note' ?> me-1"></i><?= sanitize($room['notes']) ?>
            </div>
            <?php endif; ?>
            <div class="mt-1 d-flex justify-content-between align-items-end" style="margin-top:4px!important">
                <span class="badge" style="font-size:0.55rem;background:rgba(0,0,0,0.18)">
                    <?= ucwords(str_replace('_',' ',$room['status'])) ?>
                </span>
                <span style="font-size:0.57rem;opacity:0.6">₱<?= number_format($room['base_rate']) ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if (empty($rooms)): ?>
<div class="card mt-3"><div class="card-body text-center text-muted py-5">
    <i class="fas fa-door-open fs-2 d-block mb-2 opacity-25"></i>
    No rooms match the current filter.
    <a href="?" class="d-block mt-2 small">Clear all filters</a>
</div></div>
<?php endif; ?>


<!-- Room Action Menu Modal -->
<div class="modal fade" id="roomActionModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-body p-4 text-center">
                <div class="mb-3">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle" style="width: 50px; height: 50px;">
                        <i class="fas fa-door-open fs-4"></i>
                    </div>
                </div>
                <h5 class="fw-bold mb-1">Room <span id="actionRoomNum"></span></h5>
                <p class="text-muted small mb-4"><?= hasRole('housekeeping') ? 'View the current room details or update housekeeping status' : 'Select an action for this room' ?></p>
                <div class="d-grid gap-2">
                    <button class="btn btn-primary fw-bold" onclick="proceedToUpdate()">
                        <i class="fas fa-edit me-2"></i>Update
                    </button>
                    <?php if (hasRole('admin')): ?>
                    <button class="btn btn-danger fw-bold" id="actionDeleteBtn" onclick="proceedToDelete()">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                    <?php endif; ?>
                </div>
                <button class="btn btn-light mt-2 w-100 text-muted small fw-semibold" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="roomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-door-open me-2 text-primary"></i>Room <span id="modalRoomNum"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="roomDetails" class="mb-3"></div>
                <?php if (hasRole(['admin','front_desk','housekeeping'])): ?>
                <hr class="my-2">
                <h6 class="fw-bold text-muted small text-uppercase mb-2">Update Status</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="room_id" id="modalRoomId">
                    <div class="mb-3">
                        <select name="new_status" id="modalNewStatus" class="form-select">
                            <option value="vacant">✅ Vacant — Ready for guests</option>
                            <option value="cleaning">🧹 For Cleaning — Needs housekeeping</option>
                            <option value="out_of_order">⚠️ Out of Order — Under maintenance</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes / Reason</label>
                        <textarea name="notes" id="modalNotes" class="form-control" rows="2" placeholder="Add notes..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-save me-2"></i>Update Status
                    </button>
                </form>
                <?php endif; ?>
                
                <!-- <?php if (hasRole('admin')): ?>
                <div class="mt-3 text-end border-top pt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" onclick="openEditModalFromStatus()">
                        <i class="fas fa-cog me-1"></i>Edit Room Configuration
                    </button>
                </div>
                <?php endif; ?> -->
            </div>
        </div>
    </div>
</div>

<?php if (hasRole('admin')): ?>
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Room</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_room">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-sm-5">
                            <label class="form-label fw-semibold">Room Number <span class="text-danger">*</span></label>
                            <input type="text" name="room_number" class="form-control text-uppercase" required
                                   placeholder="e.g. 201, 301A" maxlength="10"
                                   oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label fw-semibold">Floor <span class="text-danger">*</span></label>
                            <input type="number" name="floor" class="form-control" required min="1" max="99" value="1">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Initial Status</label>
                            <input class="form-control bg-light" value="Vacant (default)" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Room Type <span class="text-danger">*</span></label>
                            <select name="room_type_id" class="form-select" required>
                                <option value="">— Select Room Type —</option>
                                <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?= $rt['id'] ?>">
                                    <?= sanitize($rt['type_name']) ?> — ₱<?= number_format($rt['base_rate']) ?>/night
                                    (max <?= $rt['max_occupancy'] ?> guests)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes <span class="text-muted small">(optional)</span></label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Special features, notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-plus me-2"></i>Add Room</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editRoomModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-cog me-2"></i>Edit Room <span id="editRoomNum"></span>
                </h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_room">
                <input type="hidden" name="room_id" id="editRoomId">
                <div class="modal-body">
                    <div class="alert alert-danger py-2 small mb-3" id="editOccupiedWarn" style="display:none">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>This room is currently occupied.</strong> Check-out the guest before editing room settings or deleting.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Room Number</label>
                            <input class="form-control bg-light fw-bold" id="editRoomNumDisplay" readonly>
                            <div class="form-text">Room number cannot be changed</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Floor Number <span class="text-danger">*</span></label>
                            <input type="number" name="floor" id="editFloor" class="form-control" min="1" max="99" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Current Status</label>
                            <input class="form-control bg-light" id="editCurrentStatus" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Reassign Room Type <span class="text-danger">*</span></label>
                            <select name="room_type_id" id="editRoomTypeId" class="form-select" required>
                                <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?= $rt['id'] ?>">
                                    <?= sanitize($rt['type_name']) ?> — ₱<?= number_format($rt['base_rate']) ?>/night
                                    (max <?= $rt['max_occupancy'] ?> pax)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Maintenance Notes</label>
                            <textarea name="notes" id="editNotes" class="form-control" rows="3"
                                      placeholder="Issues, repairs needed, special instructions for housekeeping..."></textarea>
                        </div>
                        <div class="col-12">
                            <hr class="mb-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input" type="checkbox" name="perm_ooo"
                                           id="editPermOoo" onchange="toggleOooWarning()">
                                </div>
                                <div>
                                    <label class="form-check-label fw-semibold text-danger" for="editPermOoo">
                                        <i class="fas fa-tools me-1"></i>Permanently mark as Out of Order
                                    </label>
                                    <div class="text-muted small">Room will be unavailable for check-in until manually restored</div>
                                    <div class="alert alert-danger py-2 mt-2 small" id="oooWarning" style="display:none">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Room will be set to <strong>Out of Order</strong> immediately after saving.
                                        A maintenance note is strongly recommended.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger fw-bold" id="deleteRoomBtn" onclick="deleteRoom(document.getElementById('editRoomNum').innerText)">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold" id="editSubmitBtn">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteRoomForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete_room">
    <input type="hidden" name="room_id" id="deleteRoomId">
</form>

<?php endif; // hasRole admin ?>

<?php if (hasRole(['admin','front_desk','housekeeping'])): ?>
<div class="modal fade" id="bulkCleanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-broom me-2"></i>Bulk Housekeeping Update
                </h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="bulk_clean">
                <div class="modal-body">
                    <?php if (empty($allCleaningRooms)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-check-circle fs-1 text-success d-block mb-2"></i>
                        All rooms are clean — no rooms marked for cleaning.
                    </div>
                    <?php else: ?>
                    <p class="text-muted small mb-3">
                        Select rooms to mark as <span class="badge bg-success">Vacant / Ready</span>.
                        Only rooms in <span class="badge bg-warning text-dark">Cleaning</span> status will be changed.
                    </p>
                    <div class="d-flex align-items-center justify-content-between mb-3 p-2 bg-light rounded">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="selectAllClean" onchange="toggleSelectAll(this)">
                            <label class="form-check-label fw-semibold" for="selectAllClean">
                                Select All <span class="text-muted">(<?= count($allCleaningRooms) ?> rooms)</span>
                            </label>
                        </div>
                        <span class="badge bg-primary" id="selectedCount">0 selected</span>
                    </div>
                    <?php $byFloorC = []; foreach ($allCleaningRooms as $cr) $byFloorC[$cr['floor']][] = $cr; ?>
                    <?php foreach ($byFloorC as $flr => $fRooms): ?>
                    <div class="mb-3">
                        <div class="text-muted small fw-bold text-uppercase mb-2">
                            <i class="fas fa-layer-group me-1"></i>Floor <?= $flr ?>
                        </div>
                        <div class="row g-2">
                            <?php foreach ($fRooms as $cr): ?>
                            <div class="col-6 col-sm-4 col-md-3">
                                <label class="form-check border rounded p-2 d-block cursor-pointer bulk-room-item"
                                       for="bulkRoom<?= $cr['id'] ?>" style="cursor:pointer">
                                    <div class="d-flex align-items-center gap-2">
                                        <input class="form-check-input bulk-room-check flex-shrink-0" type="checkbox"
                                               name="room_ids[]" value="<?= $cr['id'] ?>"
                                               id="bulkRoom<?= $cr['id'] ?>" onchange="updateSelectedCount()">
                                        <div>
                                            <strong>Room <?= sanitize($cr['room_number']) ?></strong><br>
                                            <span class="text-muted" style="font-size:0.73rem"><?= sanitize($cr['type_name']) ?></span>
                                        </div>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($allCleaningRooms)): ?>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold" id="bulkSubmitBtn" disabled>
                        <i class="fas fa-check-double me-2"></i>Mark Selected as Vacant
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extraScripts = <<<JS
<script>
let selectedActionRoom = null;

// New Option Selection Modal 
function handleRoomClick(room) {
    selectedActionRoom = room;
    document.getElementById('actionRoomNum').innerText = room.room_number;

    const isOcc = room.status === 'occupied';
    const delBtn = document.getElementById('actionDeleteBtn');
    
    // Safety check for non-admin viewers where actionDeleteBtn isn't rendered
    if (delBtn) {
        delBtn.disabled = isOcc;
        if (isOcc) {
            delBtn.title = "Cannot delete an occupied room";
        } else {
            delBtn.title = "";
        }
    }

    new bootstrap.Modal(document.getElementById('roomActionModal')).show();
}

// Option 1: Update
function proceedToUpdate() {
    // Hide Action Modal
    const actionModalEl = document.getElementById('roomActionModal');
    const actionModalIns = bootstrap.Modal.getInstance(actionModalEl);
    if (actionModalIns) actionModalIns.hide();

    // Show Details/Status Update Modal
    openRoomModal(selectedActionRoom);
}

// Option 2: Delete
function proceedToDelete() {
    if (selectedActionRoom.status === 'occupied') return;
    document.getElementById('deleteRoomId').value = selectedActionRoom.id;
    deleteRoom(selectedActionRoom.room_number);
}

// Sub-menu for Admin to edit structural config rather than status
function openEditModalFromStatus() {
    const statusModalEl = document.getElementById('roomModal');
    const statusModalIns = bootstrap.Modal.getInstance(statusModalEl);
    if (statusModalIns) statusModalIns.hide();
    
    openEditModal(selectedActionRoom);
}


// Existing Room Status modal population
function openRoomModal(room) {
    const colors = {vacant:'success',occupied:'danger',cleaning:'warning',out_of_order:'secondary'};
    const labels = {vacant:'Vacant',occupied:'Occupied',cleaning:'For Cleaning',out_of_order:'Out of Order'};
    document.getElementById('modalRoomNum').textContent = room.room_number;
    document.getElementById('modalRoomId').value = room.id;
    if (document.getElementById('modalNewStatus'))
        document.getElementById('modalNewStatus').value = room.status === 'occupied' ? 'vacant' : room.status;
    if (document.getElementById('modalNotes'))
        document.getElementById('modalNotes').value = room.notes || '';
    let html = `<div class="row g-2">
        <div class="col-6"><div class="text-muted small">Room Type</div><strong>\${room.type_name}</strong></div>
        <div class="col-6"><div class="text-muted small">Floor</div><strong>Floor \${room.floor}</strong></div>
        <div class="col-6"><div class="text-muted small">Base Rate</div><strong>₱\${parseFloat(room.base_rate||0).toLocaleString('en-PH')}/night</strong></div>
        <div class="col-6"><div class="text-muted small">Status</div><span class="badge bg-\${colors[room.status]}">\${labels[room.status]}</span></div>
    </div>`;
    if (room.status === 'occupied' && room.guest_name) {
        html += `<div class="alert alert-danger py-2 mt-2 small">
            <i class="fas fa-user me-2"></i><strong>\${room.guest_name}</strong><br>
            Check-In: \${room.check_in}<br>
            Expected Out: \${room.expected_check_out || '—'}<br>
            Ref: \${room.booking_ref || '—'}</div>`;
    }
    if (room.notes && room.status !== 'occupied')
        html += `<div class="alert alert-light py-1 mt-2 small"><i class="fas fa-sticky-note me-1"></i>\${room.notes}</div>`;
    document.getElementById('roomDetails').innerHTML = html;
    new bootstrap.Modal(document.getElementById('roomModal')).show();
}

// Edit room config modal (admin only)
function openEditModal(room) {
    const el = id => document.getElementById(id);
    el('editRoomId').value = room.id;
    el('editRoomNum').textContent = room.room_number;
    el('editRoomNumDisplay').value = room.room_number;
    el('editFloor').value = room.floor;
    el('editRoomTypeId').value = room.room_type_id;
    el('editNotes').value = room.notes || '';
    el('editPermOoo').checked = false;
    el('oooWarning').style.display = 'none';
    
    if(el('deleteRoomId')) el('deleteRoomId').value = room.id;

    const statLabels = {vacant:'Vacant',occupied:'Occupied',cleaning:'For Cleaning',out_of_order:'Out of Order'};
    el('editCurrentStatus').value = statLabels[room.status] || room.status;
    
    const isOcc = room.status === 'occupied';
    el('editOccupiedWarn').style.display = isOcc ? '' : 'none';
    el('editSubmitBtn').disabled = isOcc;
    
    if(el('deleteRoomBtn')) el('deleteRoomBtn').disabled = isOcc;

    ['editFloor','editRoomTypeId','editNotes','editPermOoo'].forEach(i => { el(i).disabled = isOcc; });
    
    new bootstrap.Modal(document.getElementById('editRoomModal')).show();
}

// Handle Room Deletion
function deleteRoom(roomNum) {
    if (confirm(`Are you sure you want to delete room \${roomNum || ''}? This action cannot be undone.`)) {
        document.getElementById('deleteRoomForm').submit();
    }
}

function toggleOooWarning() {
    document.getElementById('oooWarning').style.display =
        document.getElementById('editPermOoo').checked ? '' : 'none';
}

// Bulk clean helpers
function toggleSelectAll(cb) {
    document.querySelectorAll('.bulk-room-check').forEach(c => c.checked = cb.checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const all     = document.querySelectorAll('.bulk-room-check');
    const checked = document.querySelectorAll('.bulk-room-check:checked');
    const cntEl   = document.getElementById('selectedCount');
    const btnEl   = document.getElementById('bulkSubmitBtn');
    if (cntEl) cntEl.textContent = checked.length + ' selected';
    if (btnEl) btnEl.disabled = checked.length === 0;
    const allCb = document.getElementById('selectAllClean');
    if (allCb) {
        allCb.checked = checked.length === all.length && all.length > 0;
        allCb.indeterminate = checked.length > 0 && checked.length < all.length;
    }
    document.querySelectorAll('.bulk-room-item').forEach(item => {
        const cb = item.querySelector('input[type=checkbox]');
        item.classList.toggle('border-success', cb && cb.checked);
        item.style.background = (cb && cb.checked) ? 'rgba(25,135,84,0.08)' : '';
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>