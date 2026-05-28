<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin']);

$pageTitle = 'Audit Trail';
$pdo = getPDO();

$search     = sanitize($_GET['q'] ?? '');
$filterAction = sanitize($_GET['action'] ?? '');
$dateFrom   = sanitize($_GET['from'] ?? date('Y-m-d'));
$dateTo     = sanitize($_GET['to']   ?? date('Y-m-d'));
$userId     = (int)($_GET['user_id'] ?? 0);

$where = "DATE(al.created_at) BETWEEN '$dateFrom' AND '$dateTo'";
if ($search) $where .= " AND (al.action LIKE " . $pdo->quote('%'.$search.'%') . " OR u.full_name LIKE " . $pdo->quote('%'.$search.'%') . " OR al.reason LIKE " . $pdo->quote('%'.$search.'%') . ")";
if ($filterAction) $where .= " AND al.action = " . $pdo->quote($filterAction);
if ($userId) $where .= " AND al.user_id = $userId";

$logs = $pdo->query("
    SELECT al.*, u.full_name, u.username, u.role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $where
    ORDER BY al.created_at DESC
    LIMIT 500")->fetchAll();

// Get distinct actions for filter
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="fas fa-history me-2 text-primary"></i>Audit Trail</h4>
    <p>Full log of all system actions — who did what, when, and why</p>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Search action, user, reason..."
                       value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Action</label>
                <select name="action" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= $a ?>" <?= $filterAction===$a?'selected':'' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Staff</label>
                <select name="user_id" class="form-select">
                    <option value="">All Staff</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $userId==$u['id']?'selected':'' ?>><?= sanitize($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Log Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2"></i>Activity Log</h6>
        <span class="badge bg-secondary"><?= count($logs) ?> entries</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover data-table mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th><th>Date & Time</th><th>Staff</th><th>Role</th>
                        <th>Action</th><th>Module</th><th>Record</th>
                        <th>Details</th><th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $actionColor = [
                            'LOGIN'=>'success','LOGOUT'=>'secondary',
                            'CHECK_IN'=>'primary','CHECK_OUT'=>'info',
                            'CANCEL_BOOKING'=>'danger',
                            'ROOM_STATUS_CHANGE'=>'warning','USER_CREATED'=>'success','USER_UPDATED'=>'info',
                            'PRICE_CHANGE'=>'danger','DISCOUNT_APPLIED'=>'warning',
                            'INVENTORY_UPDATE'=>'info','VIP_FLAG'=>'success',
                        ][$log['action']] ?? 'secondary';
                    ?>
                    <tr>
                        <td class="text-muted small"><?= $log['id'] ?></td>
                        <td class="small"><?= formatDateTime($log['created_at']) ?></td>
                        <td>
                            <strong><?= sanitize($log['full_name'] ?? 'System') ?></strong>
                            <div class="text-muted small"><?= sanitize($log['username'] ?? '') ?></div>
                        </td>
                        <td><?= $log['role'] ? getRoleBadge($log['role']) : '—' ?></td>
                        <td><span class="badge bg-<?= $actionColor ?> font-mono"><?= sanitize($log['action']) ?></span></td>
                        <td class="small text-muted"><?= sanitize($log['module'] ?? '') ?></td>
                        <td class="small"><?= $log['record_id'] ? '#'.$log['record_id'] : '—' ?></td>
                        <td>
                            <?php if ($log['reason']): ?>
                            <div class="small"><?= sanitize($log['reason']) ?></div>
                            <?php endif; ?>
                            <?php if ($log['old_value'] || $log['new_value']): ?>
                            <button class="btn btn-xs btn-outline-secondary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#log<?= $log['id'] ?>">
                                <i class="fas fa-eye"></i> Changes
                            </button>
                            <div class="collapse mt-1" id="log<?= $log['id'] ?>">
                                <?php if ($log['old_value']): ?>
                                <div class="small text-danger"><strong>Before:</strong> <?= htmlspecialchars($log['old_value']) ?></div>
                                <?php endif; ?>
                                <?php if ($log['new_value']): ?>
                                <div class="small text-success"><strong>After:</strong> <?= htmlspecialchars($log['new_value']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted font-mono"><?= sanitize($log['ip_address'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No audit logs for this period</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
