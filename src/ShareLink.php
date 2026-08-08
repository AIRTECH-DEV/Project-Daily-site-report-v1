<?php
/**
 * Client share links — mint, verify, revoke, audit.
 *
 * A link is a bearer credential: whoever holds the URL sees the project. So the
 * design is deliberately narrow:
 *   • 256-bit random token, only its sha256 stored (a DB dump yields no links);
 *   • one scope per link (one flat / one building / one developer) enforced on
 *     EVERY query the public page runs — never "the admin page minus the navbar";
 *   • hard expiry (default 24 h) plus manual revoke;
 *   • every mint / send / view / download / denial recorded, recipients masked.
 *
 * Used by admin/share_action.php (mint + send), share.php (verify + render) and
 * admin/shares.php (list + revoke).
 */
class ShareLink
{
    /** Bot user agents that fetch a link automatically (WhatsApp/Slack previews,
     *  Outlook Safe Links, Gmail image proxy). They must NOT count as a client
     *  view, and must never be able to burn a link. */
    private const BOT_UA = [
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'slackbot', 'twitterbot',
        'linkedinbot', 'discordbot', 'skypeuripreview', 'bingpreview', 'googlebot',
        'bingbot', 'yandexbot', 'duckduckbot', 'applebot', 'petalbot', 'ahrefsbot',
        'semrushbot', 'python-requests', 'curl/', 'wget', 'headlesschrome',
        'microsoft office', 'ms-office', 'outlook', 'safelinks', 'proofpoint',
        'barracuda', 'mimecast', 'googleimageproxy', 'go-http-client',
    ];

    /* ---------------- scope keys ---------------- */

    /** Scope key for a whole building of a developer. */
    public static function buildingScopeKey(string $developer, string $building): string
    {
        return 'B|' . strtolower(trim($developer) . '|' . trim($building));
    }

    /** Scope key for every building of a developer. */
    public static function developerScopeKey(string $developer): string
    {
        return 'V|' . strtolower(trim($developer));
    }

    /* ---------------- mint ---------------- */

    /**
     * Issues a link and returns ['token' => raw token, 'id' => row id, 'expires_at' => Y-m-d H:i:s].
     * The raw token is returned ONCE and never persisted — losing it means minting a new link.
     *
     * @param array $opts what the client may see (see share_links.opts_json)
     */
    public static function mint(
        PDO $db,
        string $scope,
        string $scopeKey,
        ?int $projectId,
        string $label,
        array $opts,
        string $createdBy,
        int $ttlHours = 24
    ): array {
        if (!in_array($scope, ['project', 'building', 'developer'], true)) {
            throw new InvalidArgumentException('bad share scope');
        }
        $token = self::newToken();
        $ttlHours = max(1, min(168, $ttlHours));           // 1 h … 7 days, no "forever"
        $expires = date('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $db->prepare(
            "INSERT INTO share_links
               (token_hash, scope, scope_key, project_id, label, opts_json, created_by, expires_at)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            self::hash($token), $scope, $scopeKey, $projectId ?: null, $label,
            json_encode($opts, JSON_UNESCAPED_UNICODE), $createdBy, $expires,
        ]);

        $id = (int)$db->lastInsertId();
        self::log($db, $id, 'created', null, null, $createdBy . ' · ' . $scope . ' · ' . $ttlHours . 'h');
        return ['id' => $id, 'token' => $token, 'expires_at' => $expires];
    }

    /** 43-char URL-safe token carrying 256 bits of entropy. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /* ---------------- verify ---------------- */

    /**
     * Resolves a presented token.
     * @return array ['state' => ok|expired|revoked|unknown|exhausted, 'link' => row|null]
     */
    public static function resolve(PDO $db, string $token, int $maxViews = 300): array
    {
        // Cheap shape check first: anything else is a probe, not a typo.
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return ['state' => 'unknown', 'link' => null];
        }
        $st = $db->prepare("SELECT * FROM share_links WHERE token_hash = ? LIMIT 1");
        $st->execute([self::hash($token)]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return ['state' => 'unknown', 'link' => null];
        }
        if (!empty($link['revoked_at'])) {
            return ['state' => 'revoked', 'link' => $link];
        }
        if (strtotime((string)$link['expires_at']) <= time()) {
            return ['state' => 'expired', 'link' => $link];
        }
        if ($maxViews > 0 && (int)$link['views'] > $maxViews) {
            return ['state' => 'exhausted', 'link' => $link];
        }
        return ['state' => 'ok', 'link' => $link];
    }

    /** Marks a view. Bot fetches (link previews, mail scanners) are logged but never counted. */
    public static function touch(PDO $db, array $link, string $ua, string $ip): bool
    {
        $isBot = self::isBot($ua);
        if (!$isBot) {
            $db->prepare(
                "UPDATE share_links
                    SET views = views + 1,
                        first_view_at = COALESCE(first_view_at, NOW()),
                        last_view_at = NOW()
                  WHERE id = ?"
            )->execute([(int)$link['id']]);
        }
        self::log($db, (int)$link['id'], $isBot ? 'bot' : 'view', null, null, null, $ip, $ua, (string)$link['token_hash']);
        return !$isBot;
    }

    public static function isBot(string $ua): bool
    {
        $u = strtolower($ua);
        if ($u === '') {
            return true;                       // no UA at all = automation
        }
        foreach (self::BOT_UA as $needle) {
            if (strpos($u, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function revoke(PDO $db, int $id, string $by): void
    {
        $db->prepare("UPDATE share_links SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL")
           ->execute([$by, $id]);
        self::log($db, $id, 'revoked', null, null, 'by ' . $by);
    }

    /** Drops dead links (and their events) after the retention window. */
    public static function purge(PDO $db, int $retainDays = 30): int
    {
        $st = $db->prepare(
            "DELETE FROM share_links
              WHERE (revoked_at IS NOT NULL OR expires_at < NOW())
                AND expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $st->execute([max(1, $retainDays)]);
        return $st->rowCount();
    }

    /* ---------------- audit ---------------- */

    public static function log(
        PDO $db,
        int $linkId,
        string $event,
        ?string $channel = null,
        ?string $target = null,
        ?string $detail = null,
        string $ip = '',
        string $ua = '',
        string $salt = ''
    ): void {
        try {
            $db->prepare(
                "INSERT INTO share_events (link_id, event, channel, target, ip_hash, ua, detail)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $linkId, $event, $channel, $target !== null ? self::mask($target) : null,
                $ip !== '' ? hash('sha256', $ip . '|' . $salt) : null,
                $ua !== '' ? substr($ua, 0, 190) : null,
                $detail !== null ? substr($detail, 0, 255) : null,
            ]);
        } catch (Throwable $e) { /* audit must never break the page */ }
    }

    /** ya***@gmail.com · 91****3893 — enough to recognise, not enough to harvest. */
    public static function mask(string $target): string
    {
        $t = trim($target);
        if ($t === '') {
            return '';
        }
        if (strpos($t, '@') !== false) {
            [$user, $host] = explode('@', $t, 2);
            $keep = mb_substr($user, 0, min(2, mb_strlen($user)));
            return $keep . str_repeat('*', max(1, mb_strlen($user) - mb_strlen($keep))) . '@' . $host;
        }
        $d = preg_replace('/\D/', '', $t);
        if (strlen($d) < 5) {
            return str_repeat('*', strlen($d));
        }
        return substr($d, 0, 2) . str_repeat('*', strlen($d) - 6) . substr($d, -4);
    }

    /* ---------------- links ---------------- */

    /**
     * Public URL for a token.
     * style 'path'  -> <base>/s/<token>   (prod; needs the nginx location rule)
     * style 'query' -> <base>/share.php?t=<token>  (works anywhere, incl. XAMPP)
     */
    public static function url(string $token, array $shareCfg, string $fallbackBase = ''): string
    {
        $base = rtrim((string)($shareCfg['base_url'] ?? ''), '/');
        if ($base === '') {
            $base = rtrim($fallbackBase, '/');
        }
        return (($shareCfg['link_style'] ?? 'query') === 'path')
            ? $base . '/s/' . $token
            : $base . '/share.php?t=' . $token;
    }

    /** "23 h 12 m" left on a link (or "expired"). */
    public static function timeLeft(string $expiresAt): string
    {
        $left = strtotime($expiresAt) - time();
        if ($left <= 0) {
            return 'expired';
        }
        $h = intdiv($left, 3600);
        $m = intdiv($left % 3600, 60);
        return $h > 0 ? ($h . ' h ' . $m . ' m') : ($m . ' m');
    }
}
