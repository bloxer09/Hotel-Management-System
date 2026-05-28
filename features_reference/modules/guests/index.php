<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Guest History';
$pdo = getPDO();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'toggle_vip') {
        requireRole(['admin']);
        $guestId = (int)$_POST['guest_id'];
        $isVip   = (int)$_POST['is_vip'];
        $vipNotes = sanitize($_POST['vip_notes'] ?? '');
        $old = $pdo->prepare("SELECT * FROM guest_profiles WHERE id=?");
        $old->execute([$guestId]);
        $old = $old->fetch();
        $pdo->prepare("UPDATE guest_profiles SET is_vip=?, vip_notes=? WHERE id=?")->execute([$isVip, $vipNotes, $guestId]);
        auditLog($_SESSION['user_id'], 'VIP_FLAG', 'guest_profiles', $guestId,
            $old['is_vip']?'VIP':'Regular',
            $isVip?'VIP':'Regular',
            $vipNotes ?: ($isVip?'Guest marked as VIP':'VIP status removed'));
        setFlash('success', $isVip ? 'Guest marked as VIP!' : 'VIP status removed.');
    }

    elseif ($action === 'update_notes') {
        $guestId = (int)$_POST['guest_id'];
        $notes   = sanitize($_POST['vip_notes']);
        $pdo->prepare("UPDATE guest_profiles SET vip_notes=? WHERE id=?")->execute([$notes, $guestId]);
        setFlash('success', 'Guest notes updated.');
    }

    elseif ($action === 'sync') {
        requireRole(['admin']);
        // Build guest profiles from booking history
        $bookings = $pdo->query("
            SELECT guest_name, guest_contact, guest_id_type, guest_id_number,
                   COUNT(*) as stays, SUM(amount_paid) as spent, MAX(DATE(check_in)) as last_visit
            FROM bookings
            WHERE status = 'checked_out' AND guest_contact != '' AND guest_contact IS NOT NULL
            GROUP BY guest_contact")->fetchAll();

        $synced = 0;
        foreach ($bookings as $b) {
            $exists = $pdo->prepare("SELECT id FROM guest_profiles WHERE contact_number=?");
            $exists->execute([$b['guest_contact']]);
            if (!$exists->fetch()) {
                $pdo->prepare("INSERT INTO guest_profiles (full_name,contact_number,id_type,id_number,total_stays,total_spent,last_visit) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$b['guest_name'], $b['guest_contact'], $b['guest_id_type'], $b['guest_id_number'],
                        $b['stays'], $b['spent'], $b['last_visit']]);
                $synced++;
            }
        }
        setFlash('success', "Synced $synced new guest profiles from booking history.");
    }

    header('Location: index.php' . (isset($_GET['id']) ? '?id='.(int)$_GET['id'] : ''));
    exit;
}

// Search / Filter
$search    = sanitize($_GET['q'] ?? '');
$filterVip = sanitize($_GET['vip'] ?? '');

$where = '1=1';
if ($search) {
    $like = $pdo->quote('%'.$search.'%');
    $where .= " AND (gp.full_name LIKE $like OR gp.contact_number LIKE $like OR gp.id_number LIKE $like OR gp.email LIKE $like)";
}
if ($filterVip === '1') $where .= " AND gp.is_vip = 1";
elseif ($filterVip === '0') $where .= " AND gp.is_vip = 0";

$guests = $pdo->query("
    SELECT gp.*
    FROM guest_profiles gp
    WHERE $where
    ORDER BY gp.total_stays DESC, gp.updated_at DESC
    LIMIT 200")->fetchAll();

// Guest detail view
$guestDetail = null;
$guestBookings = [];
if (isset($_GET['id'])) {
    $gid = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM guest_profiles WHERE id=?");
    $stmt->execute([$gid]);
    $guestDetail = $stmt->fetch();
    if ($guestDetail) {
        $guestBookings = $pdo->query("
            SELECT b.*, r.room_number, rt.type_name
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN room_types rt ON r.room_type_id = rt.id
            WHERE b.guest_profile_id = $gid OR b.guest_contact = " . $pdo->quote($guestDetail['contact_number']) . "
            ORDER BY b.check_in DESC")->fetchAll();
    }
}

$totalGuests = $pdo->query("SELECT COUNT(*) FROM guest_profiles")->fetchColumn();
$totalVip    = $pdo->query("SELECT COUNT(*) FROM guest_profiles WHERE is_vip=1")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-users me-2 text-primary"></i>Guest History & Profiles</h4>
        <p>View past guests, track stays, manage VIP status, and quick check-in</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasRole('admin')): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn btn-outline-secondary" title="Build guest profiles from booking history">
                <i class="fas fa-sync me-2"></i>Sync from Bookings
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-4 col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-primary"><?= $totalGuests ?></div>
                <div class="text-muted small">Total Guests</div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="card text-center border-warning">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-warning"><?= $totalVip ?></div>
                <div class="text-muted small">VIP Guests</div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="card text-center border-success">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold text-success"><?= $totalGuests - $totalVip ?></div>
                <div class="text-muted small">Regular Guests</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Guest List -->
    <div class="col-lg-<?= $guestDetail ? '5' : '12' ?>">
        <!-- Search & Filter -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
                    <div class="input-group flex-fill" style="max-width:350px">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Search name, contact, ID..."
                               value="<?= sanitize($search) ?>">
                    </div>
                    <div class="btn-group">
                        <a href="?" class="btn btn-sm <?= $filterVip===''?'btn-secondary':'btn-outline-secondary' ?>">All (<?= $totalGuests ?>)</a>
                        <a href="?vip=1<?= $search?'&q='.urlencode($search):'' ?>" class="btn btn-sm <?= $filterVip==='1'?'btn-warning':'btn-outline-warning' ?>">⭐ VIP (<?= $totalVip ?>)</a>
                        <a href="?vip=0<?= $search?'&q='.urlencode($search):'' ?>" class="btn btn-sm <?= $filterVip==='0'?'btn-success':'btn-outline-success' ?>">Regular</a>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i></button>
                </form>
            </div>
        </div>

        <!-- Guest Cards / Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover data-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Guest</th>
                                <th>Contact</th>
                                <?php if (!$guestDetail): ?>
                                <th>Stays</th>
                                <th>Total Spent</th>
                                <th>Last Visit</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($guests as $g): ?>
                            <tr class="<?= isset($_GET['id']) && $_GET['id']==$g['id'] ? 'table-primary' : '' ?>">
                                <td>
                                    <?php if ($g['is_vip']): ?><span class="vip-badge me-1">⭐ VIP</span><?php endif; ?>
                                    <strong><?= sanitize($g['full_name']) ?></strong>
                                    <?php if ($g['id_type']): ?>
                                    <div class="text-muted small"><?= sanitize($g['id_type']) ?>: <?= sanitize($g['id_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= sanitize($g['contact_number'] ?? '—') ?></td>
                                <?php if (!$guestDetail): ?>
                                <td class="text-center"><span class="badge bg-secondary"><?= $g['total_stays'] ?></span></td>
                                <td class="fw-bold text-success"><?= formatCurrency($g['total_spent']) ?></td>
                                <td class="small"><?= $g['last_visit'] ? formatDate($g['last_visit']) : '—' ?></td>
                                <?php endif; ?>
                                <td>
                                    <a href="?id=<?= $g['id'] ?><?= $search?'&q='.urlencode($search):'' ?><?= $filterVip!==''?'&vip='.$filterVip:'' ?>"
                                       class="btn btn-xs btn-outline-primary me-1">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <!-- Quick Check-In -->
                                    <button class="btn btn-xs btn-success" title="Quick Check-In"
                                        onclick="quickCheckIn(<?= htmlspecialchars(json_encode($g)) ?>)">
                                        <i class="fas fa-sign-in-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($guests)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No guests found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($guestDetail): ?>
    <!-- Guest Detail -->
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <?php if ($guestDetail['is_vip']): ?><span class="vip-badge me-2">⭐ VIP GUEST</span><?php endif; ?>
                    <h5 class="mb-0 fw-bold d-inline"><?= sanitize($guestDetail['full_name']) ?></h5>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-success btn-sm" onclick="quickCheckIn(<?= htmlspecialchars(json_encode($guestDetail)) ?>)">
                        <i class="fas fa-sign-in-alt me-1"></i>Quick Check-In
                    </button>
                    <?php if (hasRole('admin')): ?>
                    <button class="btn btn-<?= $guestDetail['is_vip']?'secondary':'warning' ?> btn-sm"
                        onclick="openVipModal(<?= htmlspecialchars(json_encode($guestDetail)) ?>)">
                        <i class="fas fa-star me-1"></i><?= $guestDetail['is_vip']?'Remove VIP':'Mark VIP' ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-3 fw-bold text-primary"><?= $guestDetail['total_stays'] ?></div>
                        <div class="text-muted small">Total Stays</div>
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-4 fw-bold text-success"><?= formatCurrency($guestDetail['total_spent']) ?></div>
                        <div class="text-muted small">Total Spent</div>
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-4 fw-bold text-info"><?= $guestDetail['total_stays'] > 0 ? formatCurrency($guestDetail['total_spent']/$guestDetail['total_stays']) : '₱0' ?></div>
                        <div class="text-muted small">Avg per Stay</div>
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        <div class="fw-bold"><?= $guestDetail['last_visit'] ? formatDate($guestDetail['last_visit']) : 'N/A' ?></div>
                        <div class="text-muted small">Last Visit</div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <div class="text-muted small">Contact</div>
                        <strong><?= sanitize($guestDetail['contact_number'] ?? '—') ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">ID</div>
                        <strong><?= $guestDetail['id_type'] ? sanitize($guestDetail['id_type']).': '.sanitize($guestDetail['id_number']) : '—' ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Email</div>
                        <strong><?= sanitize($guestDetail['email'] ?? '—') ?></strong>
                    </div>
                </div>

                <?php if ($guestDetail['vip_notes']): ?>
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fas fa-star me-2"></i><strong>VIP Notes / Preferences:</strong><br>
                    <?= nl2br(sanitize($guestDetail['vip_notes'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stay History -->
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i>Stay History</h6>
                <span class="badge bg-secondary"><?= count($guestBookings) ?> stays</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($guestBookings)): ?>
                <div class="text-center text-muted py-4">No booking history found</div>
                <?php else: ?>
                <div class="accordion accordion-flush" id="stayAccordion">
                    <?php foreach ($guestBookings as $idx => $bk): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?= $idx>0?'collapsed':'' ?>" type="button"
                                data-bs-toggle="collapse" data-bs-target="#stay<?= $idx ?>">
                                <div class="d-flex align-items-center gap-3 w-100">
                                    <div>
                                        <strong><?= sanitize($bk['booking_ref']) ?></strong>
                                        <span class="ms-2"><?= getStatusBadge($bk['status']) ?></span>
                                    </div>
                                    <div class="ms-auto text-end me-3">
                                        <div class="fw-bold text-success"><?= formatCurrency($bk['amount_paid'] ?: $bk['total_amount']) ?></div>
                                        <div class="text-muted small"><?= formatDate($bk['check_in']) ?></div>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="stay<?= $idx ?>" class="accordion-collapse collapse <?= $idx===0?'show':'' ?>" data-bs-parent="#stayAccordion">
                            <div class="accordion-body py-2">
                                <div class="row g-2">
                                    <div class="col-6"><div class="text-muted small">Room</div><strong><?= sanitize($bk['room_number']) ?> — <?= sanitize($bk['type_name']) ?></strong></div>
                                    <div class="col-6"><div class="text-muted small">Type</div><strong><?= ucfirst($bk['booking_type']) ?></strong></div>
                                    <div class="col-6"><div class="text-muted small">Check-In</div><?= formatDateTime($bk['check_in']) ?></div>
                                    <div class="col-6"><div class="text-muted small">Check-Out</div><?= formatDateTime($bk['check_out'] ?? $bk['expected_check_out']) ?></div>
                                    <div class="col-12"><hr class="my-1"></div>
                                    <div class="col-4"><div class="text-muted small">Base</div><?= formatCurrency($bk['base_amount']) ?></div>
                                    <div class="col-4"><div class="text-muted small">Surcharge</div><?= $bk['peak_surcharge']>0 ? '<span class="text-warning">'.formatCurrency($bk['peak_surcharge']).'</span>' : '—' ?></div>
                                    <div class="col-4"><div class="text-muted small">Discount</div><?= $bk['discount_amount']>0 ? '<span class="text-danger">-'.formatCurrency($bk['discount_amount']).'</span>' : '—' ?></div>
                                    <div class="col-4"><div class="text-muted small">Extension</div><?= ($bk['extension_fee']+$bk['late_checkout_fee'])>0 ? formatCurrency($bk['extension_fee']+$bk['late_checkout_fee']) : '—' ?></div>
                                    <div class="col-4"><div class="text-muted small">Payment</div>
                                        <?php if ($bk['payment_method']): ?>
                                        <span class="badge bg-<?= $bk['payment_method']==='cash'?'success':($bk['payment_method']==='gcash'?'primary':'warning') ?>"><?= strtoupper($bk['payment_method']) ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </div>
                                    <div class="col-4"><div class="text-muted small">Total Paid</div><strong class="text-success"><?= formatCurrency($bk['amount_paid'] ?: $bk['total_amount']) ?></strong></div>
                                    <div class="col-12 d-flex justify-content-end">
                                        <a href="<?= BASE_URL ?>modules/receipt/index.php?booking_id=<?= (int)$bk['id'] ?>&print=1" class="btn btn-sm btn-outline-dark" target="_blank">
                                            <i class="fas fa-print me-1"></i>Print Receipt
                                        </a>
                                    </div>
                                    <?php if ($bk['notes']): ?>
                                    <div class="col-12"><div class="text-muted small">Notes</div><?= sanitize($bk['notes']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($bk['discount_type']): ?>
                                    <div class="col-12"><div class="text-muted small">Discount Type</div><span class="badge bg-warning text-dark"><?= sanitize($bk['discount_type']) ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- VIP Modal -->
<?php if (hasRole('admin')): ?>
<div class="modal fade" id="vipModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="vipModalTitle"><i class="fas fa-star me-2"></i>VIP Management</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="toggle_vip">
                <input type="hidden" name="guest_id" id="vipGuestId">
                <input type="hidden" name="is_vip" id="vipIsVip">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">VIP Notes / Preferences</label>
                        <textarea name="vip_notes" id="vipNotes" class="form-control" rows="4"
                                  placeholder="Special preferences, allergies, room type preferences, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold" id="vipSubmitBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extraScripts = <<<JS
<script>
function quickCheckIn(guest) {
    sessionStorage.setItem('quickCheckinGuest', JSON.stringify(guest));
    window.location.href = '<?= BASE_URL ?>modules/checkin/index.php';
}

function openVipModal(guest) {
    document.getElementById('vipGuestId').value = guest.id;
    document.getElementById('vipIsVip').value = guest.is_vip ? 0 : 1;
    document.getElementById('vipNotes').value = guest.vip_notes || '';
    document.getElementById('vipModalTitle').innerHTML = guest.is_vip
        ? '<i class="fas fa-star-half-alt me-2"></i>Remove VIP: ' + guest.full_name
        : '<i class="fas fa-star me-2"></i>Mark as VIP: ' + guest.full_name;
    document.getElementById('vipSubmitBtn').textContent = guest.is_vip ? 'Remove VIP' : 'Mark as VIP';
    new bootstrap.Modal(document.getElementById('vipModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
