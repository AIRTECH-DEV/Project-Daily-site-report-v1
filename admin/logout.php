<?php
require __DIR__ . '/inc/bootstrap.php';
if (Admin::isLoggedIn()) {
    Admin::audit('admin_logout', 'admin_users', Admin::user()['id'] ?: null);
}
Admin::logout();
header('Location: ' . Admin::BASE . '/login.php');
exit;
