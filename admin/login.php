<?php
/**
 * Admin login. UI mirrors the shared VAPL portal login (brand panel + card),
 * pulling the same login.css and brand art from /pms/assets/assets.
 * First run (no admin account yet) bounces to setup.php.
 */
require __DIR__ . '/inc/bootstrap.php';

if (Admin::isLoggedIn()) {
    header('Location: ' . Admin::BASE . '/index.php');
    exit;
}
if (Admin::needsSetup()) {
    header('Location: ' . Admin::BASE . '/setup.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Admin::rateLimit('admin_login')) {
        $error = 'Too many login attempts. Wait 5 minutes and try again.';
    } elseif (!Admin::checkCsrf()) {
        $error = 'Invalid request token. Refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $error = 'Enter your username and password.';
        } else {
            $st = Admin::db()->prepare("SELECT id, username, password_hash, display_name, role FROM admin_users WHERE username = ? AND is_active = 1");
            $st->execute([$username]);
            $user = $st->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                Admin::login($user);
                Admin::db()->prepare("UPDATE admin_users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
                Admin::audit('admin_login', 'admin_users', $user['id']);

                $next = $_GET['next'] ?? (Admin::BASE . '/index.php');
                if (strpos(urldecode($next), Admin::BASE) !== 0) {
                    $next = Admin::BASE . '/index.php';
                }
                header('Location: ' . $next);
                exit;
            }
            $error = 'Invalid username or password.';
            usleep(400000);
        }
    }
}

$csrf = Admin::csrf();
$A = Admin::ASSETS;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — PMS Tracker</title>
<link rel="icon" type="image/png" href="<?= $A ?>/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= $A ?>/login.css">
</head>
<body>
<div class="login-page">
  <div class="login-shell">
    <div class="brand-panel">
      <img src="<?= $A ?>/logo.png" alt="Vakharia Airtech" class="logo-img">
      <div class="portal-title">PMS Admin Portal</div>
      <div class="welcome-title">Welcome back!</div>
      <div class="welcome-text">Sign in to the Site Report tracker</div>
      <img src="<?= $A ?>/ac-unit.png" alt="Vakharia Airtech Unit" class="machine-img">
    </div>

    <div class="form-panel">
      <div class="login-card">
        <h1>Sign In</h1>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show py-2 small mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= Admin::e($error) ?>
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= Admin::e($csrf) ?>">

          <div class="mb-3">
            <label class="form-label">Username</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" name="username" class="form-control" required autocomplete="username" autofocus
                     placeholder="Enter your username" value="<?= Admin::e($_POST['username'] ?? '') ?>">
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="password" id="passwordField" class="form-control" required
                     autocomplete="current-password" placeholder="Enter your password">
              <button type="button" class="password-toggle" id="togglePassword" tabindex="-1" aria-label="Show password">
                <i class="bi bi-eye" id="togglePasswordIcon"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-login w-100"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</button>
        </form>

        <div class="secure-text"><i class="bi bi-shield-lock me-1"></i>Secure — internal access only</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  const btn = document.getElementById('togglePassword');
  const field = document.getElementById('passwordField');
  const icon = document.getElementById('togglePasswordIcon');
  if (!btn || !field || !icon) return;
  btn.addEventListener('click', function () {
    const showing = field.type === 'text';
    field.type = showing ? 'password' : 'text';
    icon.classList.toggle('bi-eye', showing);
    icon.classList.toggle('bi-eye-slash', !showing);
  });
})();
</script>
</body>
</html>
