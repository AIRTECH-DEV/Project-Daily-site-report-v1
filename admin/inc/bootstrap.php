<?php
/**
 * Admin-panel bootstrap: session, DB, and the Admin helper (auth, CSRF, rate
 * limit, audit, escaping). Every admin page starts with:
 *
 *     require __DIR__ . '/inc/bootstrap.php';   // login/setup pages
 *     Admin::requireAuth();                      // protected pages only
 *
 * Self-contained on purpose — the tracker app is an API with no session layer,
 * so the panel brings its own. Reads config/app.php only for DB credentials +
 * timezone; it never touches the Google service objects.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('PMSADMIN');
    session_start();
}

class Admin
{
    /** URL base the panel is served from (…/pms/admin). */
    const BASE = '/pms/admin';
    /** URL base for shared brand assets (logo, machine art, login.css). */
    const ASSETS = '/pms/assets/assets';

    /** @var array */ private static $cfg;
    /** @var PDO */   private static $pdo;

    private const RATE_LIMITS = [
        'admin_login' => [6, 300],   // 6 tries / 5 min per IP
        'default'     => [200, 3600],
    ];

    public static function cfg(): array
    {
        if (self::$cfg === null) {
            self::$cfg = require __DIR__ . '/../../config/app.php';
            date_default_timezone_set(self::$cfg['timezone'] ?? 'Asia/Kolkata');
        }
        return self::$cfg;
    }

    public static function db(): PDO
    {
        if (self::$pdo === null) {
            $d = self::cfg()['db'];
            self::$pdo = new PDO(
                "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}",
                $d['user'], $d['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'",
                ]
            );
        }
        return self::$pdo;
    }

    /* ---------------- auth ---------------- */

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_user']);
    }

    public static function requireAuth(): void
    {
        if (self::isLoggedIn()) {
            return;
        }
        $next = urlencode($_SERVER['REQUEST_URI'] ?? self::BASE . '/index.php');
        header('Location: ' . self::BASE . '/login.php?next=' . $next);
        exit;
    }

    public static function user(): array
    {
        return [
            'id'    => $_SESSION['admin_id']   ?? 0,
            'user'  => $_SESSION['admin_user'] ?? '',
            'name'  => $_SESSION['admin_name'] ?? ($_SESSION['admin_user'] ?? ''),
            'role'  => $_SESSION['admin_role'] ?? 'admin',
        ];
    }

    public static function isViewer(): bool
    {
        return (self::user()['role'] ?? '') === 'viewer';
    }

    /** Blocks write actions for viewer-role accounts. */
    public static function requireEditor(): void
    {
        if (self::isViewer()) {
            http_response_code(403);
            exit('Forbidden: your account is read-only.');
        }
    }

    public static function login(array $u): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_id']   = $u['id'];
        $_SESSION['admin_user'] = $u['username'];
        $_SESSION['admin_name'] = $u['display_name'] ?: $u['username'];
        $_SESSION['admin_role'] = $u['role'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** True when no admin account exists yet (drives first-run setup). */
    public static function needsSetup(): bool
    {
        try {
            return (int)self::db()->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ---------------- CSRF ---------------- */

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        $t = $_POST['csrf'] ?? '';
        return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
    }

    /** Field helper for forms. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(self::csrf()) . '">';
    }

    /* ---------------- rate limit ---------------- */

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function rateLimit(string $action): bool
    {
        [$max, $window] = self::RATE_LIMITS[$action] ?? self::RATE_LIMITS['default'];
        $key = hash('sha256', self::clientIp() . ':' . $action);
        $db  = self::db();
        try {
            $db->prepare("DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)")
               ->execute([$window]);
            $st = $db->prepare("SELECT requests, blocked_until FROM rate_limits WHERE key_hash = ? AND action = ?");
            $st->execute([$key, $action]);
            $row = $st->fetch();
            if ($row) {
                if ($row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
                    return false;
                }
                if ((int)$row['requests'] >= $max) {
                    $db->prepare("UPDATE rate_limits SET blocked_until = ?, requests = requests + 1 WHERE key_hash = ? AND action = ?")
                       ->execute([date('Y-m-d H:i:s', time() + $window), $key, $action]);
                    return false;
                }
                $db->prepare("UPDATE rate_limits SET requests = requests + 1 WHERE key_hash = ? AND action = ?")
                   ->execute([$key, $action]);
            } else {
                $db->prepare("INSERT INTO rate_limits (key_hash, action, requests, window_start) VALUES (?, ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE requests = requests + 1")
                   ->execute([$key, $action]);
            }
            return true;
        } catch (Throwable $e) {
            return true; // never lock users out on a rate-table error
        }
    }

    /* ---------------- audit ---------------- */

    public static function audit(string $action, string $entityType = '', $entityId = null, string $old = '', string $new = ''): void
    {
        try {
            self::db()->prepare(
                "INSERT INTO audit_logs (admin_user, action, entity_type, entity_id, old_value, new_value, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                self::user()['user'] ?: 'system', $action, $entityType ?: null,
                $entityId ?: null, $old ?: null, $new ?: null, self::clientIp(),
            ]);
        } catch (Throwable $e) { /* non-fatal */ }
    }

    /* ---------------- misc ---------------- */

    public static function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /** overrides.json path + current contents (admin-tunable runtime config). */
    public static function overridesPath(): string
    {
        return __DIR__ . '/../../config/overrides.json';
    }

    public static function overrides(): array
    {
        $f = self::overridesPath();
        if (is_file($f)) {
            $o = json_decode((string)file_get_contents($f), true);
            if (is_array($o)) {
                return $o;
            }
        }
        return [];
    }

    public static function saveOverrides(array $data): bool
    {
        return file_put_contents(
            self::overridesPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }
}
