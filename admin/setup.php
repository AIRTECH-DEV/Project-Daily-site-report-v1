<?php
/**
 * First-run setup: creates the very first admin account when admin_users is
 * empty. Self-disables the moment any account exists, so it can't be used to
 * mint extra admins later (use Admin Users for that).
 */
require __DIR__ . '/inc/bootstrap.php';

$dbDown = !Admin::dbHealthy();
if (!$dbDown && !Admin::needsSetup()) {
    header('Location: ' . Admin::BASE . '/login.php');
    exit;
}

$error = '';
$A = Admin::ASSETS;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbDown) {
    $error = 'Database is unreachable — cannot create the admin account yet.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Admin::checkCsrf()) {
        $error = 'Invalid request token. Refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $pass2    = $_POST['password2'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $error = 'Username must be 3–50 chars (letters, numbers, . _ -).';
        } elseif (strlen($pass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            Admin::db()->prepare(
                "INSERT INTO admin_users (username, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'admin', 1)"
            )->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $display ?: $username]);
            $id = (int)Admin::db()->lastInsertId();
            Admin::login(['id' => $id, 'username' => $username, 'display_name' => $display ?: $username, 'role' => 'admin']);
            Admin::audit('admin_setup', 'admin_users', $id);
            header('Location: ' . Admin::BASE . '/index.php');
            exit;
        }
    }
}
$csrf = Admin::csrf();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup Admin — PMS Tracker</title>
<link rel="icon" type="image/png" href="<?= $A ?>/favicon.png">
<link rel="stylesheet" href="<?= Admin::e(Admin::vendor('bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= Admin::e(Admin::vendor('bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>">
<link rel="stylesheet" href="<?= $A ?>/login.css">
</head>
<body>
<div class="login-page">
  <div class="login-shell">
    <div class="brand-panel">
      <img src="<?= $A ?>/logo.png" alt="Vakharia Airtech" class="logo-img">
      <div class="portal-title">PMS Admin Portal</div>
      <div class="welcome-title">First-time setup</div>
      <div class="welcome-text">Create the master admin account</div>
      <img src="<?= $A ?>/ac-unit.png" alt="Unit" class="machine-img">
    </div>

    <div class="form-panel">
      <div class="login-card">
        <h1>Create Admin</h1>
        <p class="card-sub">No admin exists yet. Set your login below — this becomes the master account.</p>

        <?php if ($dbDown): ?>
          <div class="alert alert-warning py-2 small mb-3"><i class="bi bi-database-exclamation me-2"></i><b>Database offline.</b> Start XAMPP MySQL (MariaDB) on port <?= Admin::e(Admin::cfg()['db']['port']) ?> and reload this page.</div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2 small mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= Admin::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= Admin::e($csrf) ?>">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" name="username" class="form-control" required autofocus placeholder="e.g. admin" value="<?= Admin::e($_POST['username'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Display name</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-card-text"></i></span>
              <input type="text" name="display_name" class="form-control" placeholder="e.g. Yash" value="<?= Admin::e($_POST['display_name'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="password" class="form-control" required placeholder="Min 8 characters">
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label">Confirm password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
              <input type="password" name="password2" class="form-control" required placeholder="Re-enter password">
            </div>
          </div>
          <button type="submit" class="btn btn-login w-100"><i class="bi bi-check2-circle me-2"></i>Create &amp; Sign In</button>
        </form>
        <div class="secure-text"><i class="bi bi-shield-lock me-1"></i>Runs once — disabled after first admin exists</div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
