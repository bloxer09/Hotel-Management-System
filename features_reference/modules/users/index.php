<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireRole(['admin']);

$pageTitle = 'User Management';
$pdo = getPDO();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'add') {
        $username  = sanitize($_POST['username']);
        $fullName  = sanitize($_POST['full_name']);
        $role      = $_POST['role'];
        $email     = sanitize($_POST['email'] ?? '');
        $phone     = sanitize($_POST['phone'] ?? '');
        $password  = $_POST['password'];

        // Check if username exists
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            setFlash('error', 'Username already exists.');
        } elseif (strlen($password) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
        } elseif (!in_array($role, ['admin','front_desk','housekeeping'])) {
            setFlash('error', 'Invalid role.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username,password,full_name,role,email,phone) VALUES (?,?,?,?,?,?)")
                ->execute([$username, $hash, $fullName, $role, $email, $phone]);
            auditLog($_SESSION['user_id'], 'USER_CREATED', 'users', $pdo->lastInsertId(), null, "$username ($role)", 'New user created');
            setFlash('success', "User <strong>$username</strong> created successfully.");
        }
    }

    elseif ($action === 'toggle') {
        $userId = (int)$_POST['user_id'];
        $newStatus = (int)$_POST['is_active'];
        if ($userId == $_SESSION['user_id']) {
            setFlash('error', 'Cannot deactivate your own account.');
        } else {
            $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$newStatus, $userId]);
            auditLog($_SESSION['user_id'], 'USER_TOGGLE', 'users', $userId, null, $newStatus?'activated':'deactivated');
            setFlash('success', 'User status updated.');
        }
    }

    elseif ($action === 'change_password') {
        $userId = (int)$_POST['user_id'];
        $newPass = $_POST['new_password'];
        if (strlen($newPass) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);
            auditLog($_SESSION['user_id'], 'PASSWORD_CHANGE', 'users', $userId, null, null, 'Password changed by admin');
            setFlash('success', 'Password changed successfully.');
        }
    }

    elseif ($action === 'update') {
        $userId   = (int)$_POST['user_id'];
        $fullName = sanitize($_POST['full_name']);
        $email    = sanitize($_POST['email'] ?? '');
        $phone    = sanitize($_POST['phone'] ?? '');
        $role     = $_POST['role'];
        if (!in_array($role, ['admin','front_desk','housekeeping'])) {
            setFlash('error', 'Invalid role.');
            header('Location: index.php');
            exit;
        }
        if ($userId == $_SESSION['user_id'] && $role !== 'admin') {
            setFlash('error', 'Cannot change your own role.');
        } else {
            $old = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $old->execute([$userId]);
            $old = $old->fetch();
            $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=? WHERE id=?")->execute([$fullName, $email, $phone, $role, $userId]);
            auditLog($_SESSION['user_id'], 'USER_UPDATED', 'users', $userId, json_encode($old), json_encode(['full_name'=>$fullName,'role'=>$role]), 'User info updated');
            setFlash('success', 'User updated successfully.');
        }
    }

    header('Location: index.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4><i class="fas fa-users-cog me-2 text-primary"></i>User Management</h4>
        <p>Manage staff accounts, roles, and access levels</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-user-plus me-2"></i>Add New User
    </button>
</div>

<!-- Access Level Info -->
<div class="card mb-4 border-info">
    <div class="card-body py-2">
        <div class="row g-2 small">
            <div class="col-md-4">
                <strong>Admin:</strong> Full system access — pricing, user management, audit trail, all reports
            </div>
            <div class="col-md-4">
                <strong>Front Desk:</strong> Check-in / check-out, room status, guests, inventory, sales & shift reports
            </div>
            <div class="col-md-4">
                <strong>Housekeeping:</strong> Room status board access — update room status to Cleaning, Vacant, or Out of Order and bulk mark cleaned rooms as ready
            </div>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>Username</th><th>Full Name</th><th>Role</th>
                        <th>Email</th><th>Phone</th><th>Last Login</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr class="<?= !$u['is_active']?'table-secondary':'' ?>">
                        <td><?= $u['id'] ?></td>
                        <td class="font-mono"><strong><?= sanitize($u['username']) ?></strong>
                            <?= $u['id'] == $_SESSION['user_id'] ? '<span class="badge bg-info ms-1">You</span>' : '' ?>
                        </td>
                        <td><?= sanitize($u['full_name']) ?></td>
                        <td><?= getRoleBadge($u['role']) ?></td>
                        <td class="small"><?= sanitize($u['email'] ?? '—') ?></td>
                        <td class="small"><?= sanitize($u['phone'] ?? '—') ?></td>
                        <td class="small"><?= $u['last_login'] ? formatDateTime($u['last_login']) : 'Never' ?></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= $u['is_active']?0:1 ?>">
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <button type="submit" class="btn btn-xs <?= $u['is_active']?'btn-success':'btn-secondary' ?>">
                                    <?= $u['is_active']?'Active':'Inactive' ?>
                                </button>
                                <?php else: ?>
                                <span class="badge bg-success">Active</span>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td>
                            <button class="btn btn-xs btn-outline-primary me-1"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-warning"
                                onclick="openPwdModal(<?= $u['id'] ?>, '<?= sanitize($u['full_name']) ?>')">
                                <i class="fas fa-key"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required placeholder="Unique username"></div>
                        <div class="col-6"><label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <option value="front_desk">Front Desk</option>
                                <option value="housekeeping">Housekeeping</option>
                                <option value="admin">Admin</option>
                            </select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required placeholder="Staff full name"></div>
                        <div class="col-6"><label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="email@example.com"></div>
                        <div class="col-6"><label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="09XX-XXX-XXXX"></div>
                        <div class="col-12"><label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required minlength="8" placeholder="Minimum 8 characters"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit User</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label fw-semibold">Full Name</label>
                            <input type="text" name="full_name" id="editFullName" class="form-control" required></div>
                        <div class="col-6"><label class="form-label fw-semibold">Role</label>
                            <select name="role" id="editRole" class="form-select">
                                <option value="front_desk">Front Desk</option>
                                <option value="housekeeping">Housekeeping</option>
                                <option value="admin">Admin</option>
                            </select></div>
                        <div class="col-6"><label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" id="editEmail" class="form-control"></div>
                        <div class="col-6"><label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" id="editPhone" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="pwdModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning"><h5 class="modal-title" id="pwdModalTitle"><i class="fas fa-key me-2"></i>Change Password</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="user_id" id="pwdUserId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required placeholder="Minimum 8 characters">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold"><i class="fas fa-key me-2"></i>Change Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
function openEditModal(u) {
    document.getElementById('editUserId').value = u.id;
    document.getElementById('editFullName').value = u.full_name;
    document.getElementById('editRole').value = u.role;
    document.getElementById('editEmail').value = u.email || '';
    document.getElementById('editPhone').value = u.phone || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function openPwdModal(id, name) {
    document.getElementById('pwdUserId').value = id;
    document.getElementById('pwdModalTitle').innerHTML = '<i class="fas fa-key me-2"></i>Change Password: ' + name;
    new bootstrap.Modal(document.getElementById('pwdModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
