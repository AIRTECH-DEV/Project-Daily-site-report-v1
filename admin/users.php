<?php
/**
 * Admin Users — manage who can sign in. Admin-role accounts manage everyone;
 * viewer-role accounts are read-only (they never reach this page). Guards against
 * locking yourself out (can't delete/deactivate the last active admin or self).
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

if (Admin::isViewer()) {
    header('Location: ' . Admin::BASE . '/index.php');
    exit;
}

$db = Admin::db();
$me = Admin::user();
$flash = ''; $flashType = 'ok';

$activeAdmins = fn() => (int)$db->query("SELECT COUNT(*) FROM admin_users WHERE role='admin' AND is_active=1")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Admin::checkCsrf()) {
        $flash = 'Invalid request token.'; $flashType = 'bad';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add') {
                $u = trim($_POST['username'] ?? '');
                $dn = trim($_POST['display_name'] ?? '');
                $pw = $_POST['password'] ?? '';
                $role = ($_POST['role'] ?? 'admin') === 'viewer' ? 'viewer' : 'admin';
                if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $u)) {
                    $flash = 'Username must be 3–50 chars (letters, numbers, . _ -).'; $flashType = 'bad';
                } elseif (strlen($pw) < 8) {
                    $flash = 'Password must be at least 8 characters.'; $flashType = 'bad';
                } else {
                    $ex = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username=?"); $ex->execute([$u]);
                    if ($ex->fetchColumn() > 0) {
                        $flash = 'Username already exists.'; $flashType = 'bad';
                    } else {
                        $db->prepare("INSERT INTO admin_users (username, password_hash, display_name, role, is_active) VALUES (?,?,?,?,1)")
                           ->execute([$u, password_hash($pw, PASSWORD_DEFAULT), $dn ?: $u, $role]);
                        Admin::audit('add_admin_user', 'admin_users', (int)$db->lastInsertId(), '', $u . ' (' . $role . ')');
                        $flash = "User “$u” created.";
                    }
                }
            } elseif ($action === 'toggle') {
                $uid = (int)($_POST['uid'] ?? 0);
                $t = $db->prepare("SELECT * FROM admin_users WHERE id=?"); $t->execute([$uid]); $tu = $t->fetch();
                if ($tu) {
                    if ($tu['is_active'] && $tu['role'] === 'admin' && $activeAdmins() <= 1) {
                        $flash = 'Cannot deactivate the last active admin.'; $flashType = 'bad';
                    } else {
                        $db->prepare("UPDATE admin_users SET is_active = 1 - is_active WHERE id=?")->execute([$uid]);
                        Admin::audit('toggle_admin_user', 'admin_users', $uid, (string)$tu['is_active']);
                        $flash = 'User updated.';
                    }
                }
            } elseif ($action === 'delete') {
                $uid = (int)($_POST['uid'] ?? 0);
                if ($uid === (int)$me['id']) {
                    $flash = 'You cannot delete your own account.'; $flashType = 'bad';
                } else {
                    $t = $db->prepare("SELECT * FROM admin_users WHERE id=?"); $t->execute([$uid]); $tu = $t->fetch();
                    if ($tu && $tu['role'] === 'admin' && $tu['is_active'] && $activeAdmins() <= 1) {
                        $flash = 'Cannot delete the last active admin.'; $flashType = 'bad';
                    } elseif ($tu) {
                        $db->prepare("DELETE FROM admin_users WHERE id=?")->execute([$uid]);
                        Admin::audit('delete_admin_user', 'admin_users', $uid, $tu['username']);
                        $flash = "User “{$tu['username']}” deleted.";
                    }
                }
            } elseif ($action === 'chpass') {
                $cur = $_POST['current'] ?? '';
                $new = $_POST['newpass'] ?? '';
                $t = $db->prepare("SELECT * FROM admin_users WHERE id=?"); $t->execute([(int)$me['id']]); $tu = $t->fetch();
                if (!$tu || !password_verify($cur, $tu['password_hash'])) {
                    $flash = 'Current password is incorrect.'; $flashType = 'bad';
                } elseif (strlen($new) < 8) {
                    $flash = 'New password must be at least 8 characters.'; $flashType = 'bad';
                } else {
                    $db->prepare("UPDATE admin_users SET password_hash=? WHERE id=?")
                       ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$me['id']]);
                    Admin::audit('change_own_password', 'admin_users', (int)$me['id']);
                    $flash = 'Your password was changed.';
                }
            }
        } catch (Throwable $e) {
            $flash = 'Error: ' . $e->getMessage(); $flashType = 'bad';
        }
    }
}

$users = $db->query("SELECT * FROM admin_users ORDER BY role, username")->fetchAll();

require __DIR__ . '/inc/layout.php';
Layout::head('Admin Users', 'users');
?>
<?php if ($flash): ?><div class="alert2 <?= $flashType ?>"><i class="bi bi-<?= $flashType === 'ok' ? 'check-circle' : 'exclamation-octagon' ?>"></i> <?= Admin::e($flash) ?></div><?php endif; ?>

<div class="card2">
  <div class="card2-head"><i class="bi bi-people text-primary"></i><h2>Accounts</h2><span class="sub"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="mono"><?= Admin::e($u['username']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="pill pill-info">you</span>' : '' ?></td>
            <td><?= Admin::e($u['display_name']) ?></td>
            <td><span class="pill <?= $u['role'] === 'admin' ? 'pill-info' : 'pill-muted' ?>"><?= Admin::e(ucfirst($u['role'])) ?></span></td>
            <td><?= $u['is_active'] ? '<span class="pill pill-ok">Active</span>' : '<span class="pill pill-bad">Disabled</span>' ?></td>
            <td title="<?= Admin::e(fmtDateTime($u['last_login_at'])) ?>"><?= $u['last_login_at'] ? Admin::e(ago($u['last_login_at'])) : 'never' ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                <form method="POST" onsubmit="return true"><?= Admin::csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $u['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i></button></form>
                <form method="POST" onsubmit="return confirm('Delete user <?= Admin::e($u['username']) ?>?')"><?= Admin::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" title="Delete"><i class="bi bi-trash"></i></button></form>
                <?php else: ?><span style="color:#94a3b8;font-size:12px">—</span><?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-person-plus text-primary"></i><h2>Add User</h2></div>
    <div class="card2-body">
      <form method="POST">
        <?= Admin::csrfField() ?><input type="hidden" name="action" value="add">
        <div class="filters" style="flex-direction:column;align-items:stretch;gap:12px">
          <div class="fld"><label>Username</label><input class="inp" type="text" name="username" required placeholder="e.g. supervisor"></div>
          <div class="fld"><label>Display name</label><input class="inp" type="text" name="display_name" placeholder="e.g. Site Supervisor"></div>
          <div class="fld"><label>Password</label><input class="inp" type="password" name="password" required placeholder="Min 8 characters"></div>
          <div class="fld"><label>Role</label>
            <select class="inp" name="role"><option value="admin">Admin — full access</option><option value="viewer">Viewer — read only</option></select></div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Create User</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-key text-primary"></i><h2>Change My Password</h2></div>
    <div class="card2-body">
      <form method="POST">
        <?= Admin::csrfField() ?><input type="hidden" name="action" value="chpass">
        <div class="filters" style="flex-direction:column;align-items:stretch;gap:12px">
          <div class="fld"><label>Current password</label><input class="inp" type="password" name="current" required></div>
          <div class="fld"><label>New password</label><input class="inp" type="password" name="newpass" required placeholder="Min 8 characters"></div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php Layout::foot();
