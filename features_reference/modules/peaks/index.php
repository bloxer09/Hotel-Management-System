<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin']);

$pageTitle = 'Peak Dates Configuration';
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'add') {
        $dateFrom = $_POST['date_from'];
        $dateTo   = $_POST['date_to'];
        $label    = sanitize($_POST['label']);
        $surchargeAmt = (float)$_POST['surcharge_amount'];
        $surchargeType = $_POST['surcharge_type'];

        if (strtotime($dateTo) < strtotime($dateFrom)) {
            setFlash('error', 'Date To must be after Date From.');
        } elseif (empty($label)) {
            setFlash('error', 'Label is required.');
        } else {
            $pdo->prepare("INSERT INTO peak_dates (date_from, date_to, label, surcharge_amount, surcharge_type, is_active, created_by) VALUES (?,?,?,?,?,1,?)")
                ->execute([$dateFrom, $dateTo, $label, $surchargeAmt, $surchargeType, $_SESSION['user_id']]);
            auditLog($_SESSION['user_id'], 'PEAK_DATE_ADD', 'peak_dates', $pdo->lastInsertId(), null, "$label ($dateFrom - $dateTo)", 'Peak date added');
            setFlash('success', 'Peak date added successfully.');
        }
    }

    elseif ($action === 'toggle') {
        $id = (int)$_POST['peak_id'];
        $status = (int)$_POST['is_active'];
        $pdo->prepare("UPDATE peak_dates SET is_active=? WHERE id=?")->execute([$status, $id]);
        auditLog($_SESSION['user_id'], 'PEAK_DATE_TOGGLE', 'peak_dates', $id, null, $status?'enabled':'disabled');
        setFlash('success', 'Peak date status updated.');
    }

    elseif ($action === 'delete') {
        requireRole(['admin']);
        $id = (int)$_POST['peak_id'];
        $old = $pdo->prepare("SELECT * FROM peak_dates WHERE id=?");
        $old->execute([$id]);
        $old = $old->fetch();
        $pdo->prepare("DELETE FROM peak_dates WHERE id=?")->execute([$id]);
        auditLog($_SESSION['user_id'], 'PEAK_DATE_DELETE', 'peak_dates', $id, json_encode($old), null, 'Peak date deleted');
        setFlash('success', 'Peak date deleted.');
    }

    header('Location: index.php');
    exit;
}

$peakDates = $pdo->query("
    SELECT pd.*, u.full_name as created_by_name
    FROM peak_dates pd LEFT JOIN users u ON pd.created_by = u.id
    ORDER BY pd.date_from")->fetchAll();

$today = date('Y-m-d');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-calendar-alt me-2 text-primary"></i>Peak Dates Configuration</h4>
        <p>Set peak periods with automatic surcharge — applied automatically during check-in</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-2"></i>Add Peak Date
    </button>
</div>

<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
    <i class="fas fa-info-circle fs-5"></i>
    <div>When a guest checks in during a peak date, the surcharge is <strong>automatically added</strong> to their bill. Staff cannot remove it unless they're Admin.</div>
</div>

<!-- Peak Dates Cards -->
<div class="row g-3">
    <?php foreach ($peakDates as $pd):
        $isPast = $pd['date_to'] < $today;
        $isCurrent = $pd['date_from'] <= $today && $pd['date_to'] >= $today;
        $isFuture = $pd['date_from'] > $today;
        $statusClass = $isCurrent ? 'success' : ($isPast ? 'secondary' : 'primary');
        $statusLabel = $isCurrent ? 'Active Now' : ($isPast ? 'Past' : 'Upcoming');
    ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100 border-<?= $statusClass ?> <?= !$pd['is_active']?'opacity-50':'' ?>">
            <div class="card-header d-flex justify-content-between align-items-center bg-<?= $statusClass ?> bg-opacity-10">
                <h6 class="mb-0 fw-bold"><?= sanitize($pd['label']) ?></h6>
                <span class="badge bg-<?= $statusClass ?>"><?= $statusLabel ?></span>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="text-muted small">From</div>
                        <strong><?= formatDate($pd['date_from']) ?></strong>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">To</div>
                        <strong><?= formatDate($pd['date_to']) ?></strong>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Surcharge</div>
                        <div class="fs-4 fw-bold text-warning">
                            <?php if ($pd['surcharge_type']==='fixed'): ?>
                            +<?= formatCurrency($pd['surcharge_amount']) ?> <small class="fs-6 text-muted">flat fee</small>
                            <?php else: ?>
                            +<?= $pd['surcharge_amount'] ?>% <small class="fs-6 text-muted">of base rate</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <form method="POST" class="flex-fill">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="peak_id" value="<?= $pd['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= $pd['is_active']?0:1 ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?= $pd['is_active']?'warning':'success' ?> w-100">
                            <i class="fas fa-<?= $pd['is_active']?'pause':'play' ?> me-1"></i>
                            <?= $pd['is_active']?'Disable':'Enable' ?>
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Delete this peak date?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="peak_id" value="<?= $pd['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
                <div class="text-muted small mt-2">
                    Created by: <?= sanitize($pd['created_by_name'] ?? '—') ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($peakDates)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-calendar-times fs-2 mb-3 d-block"></i>
                No peak dates configured yet. Click "Add Peak Date" to get started.
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Add Peak Date</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Label / Event Name <span class="text-danger">*</span></label>
                            <input type="text" name="label" class="form-control" required placeholder="e.g. Christmas Eve, New Year, Holy Week">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">From Date <span class="text-danger">*</span></label>
                            <input type="date" name="date_from" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">To Date <span class="text-danger">*</span></label>
                            <input type="date" name="date_to" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Surcharge Type</label>
                            <select name="surcharge_type" class="form-select">
                                <option value="fixed">Fixed Amount (₱)</option>
                                <option value="percent">Percentage (%)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Surcharge Amount <span class="text-danger">*</span></label>
                            <input type="number" name="surcharge_amount" class="form-control" step="0.01" min="0" value="100" required>
                            <div class="form-text">₱ for fixed, % for percentage</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="fas fa-save me-2"></i>Add Peak Date
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
