<?php
/**
 * GoogleAuth — service-account OAuth2 access tokens, native PHP (no composer).
 *
 * Builds and RS256-signs a JWT with the SA private key (openssl), exchanges it
 * at the Google token endpoint for a short-lived bearer token, and caches the
 * token on disk until it nears expiry. One instance per process is plenty.
 *
 * Mirrors what Apps Script did implicitly (ScriptApp.getOAuthToken); here the
 * service account service-data-syncer@design-sheet-492811.iam.gserviceaccount.com
 * is the identity, so every sheet/drive it touches must be shared with it.
 */
class GoogleAuth
{
    /** @var array decoded service-account JSON */
    private $sa;
    /** @var string space-separated OAuth scopes */
    private $scope;
    /** @var string cache file for the current token */
    private $cacheFile;

    public function __construct(string $serviceAccountPath, array $scopes, string $cacheDir)
    {
        if (!is_file($serviceAccountPath)) {
            throw new RuntimeException("Service account file not found: $serviceAccountPath");
        }
        $json = json_decode((string)file_get_contents($serviceAccountPath), true);
        if (!is_array($json) || empty($json['private_key']) || empty($json['client_email'])) {
            throw new RuntimeException("Invalid service account JSON: $serviceAccountPath");
        }
        $this->sa = $json;
        $this->scope = implode(' ', $scopes);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        // One cache file per (account, scope) combination.
        $this->cacheFile = rtrim($cacheDir, '/\\') . '/token_'
            . substr(sha1($json['client_email'] . '|' . $this->scope), 0, 16) . '.json';
    }

    /** Service account email — useful for "share this sheet with X" messages. */
    public function clientEmail(): string
    {
        return $this->sa['client_email'];
    }

    /** Returns a valid bearer token, minting a new one only when needed. */
    public function getAccessToken(): string
    {
        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }
        $token = $this->fetchToken();
        $this->writeCache($token['access_token'], (int)($token['expires_in'] ?? 3600));
        return $token['access_token'];
    }

    private function readCache(): ?string
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($this->cacheFile), true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) {
            return null;
        }
        // Renew 60s early to avoid using a token that dies mid-request.
        if ($data['expires_at'] - 60 <= time()) {
            return null;
        }
        return $data['access_token'];
    }

    private function writeCache(string $token, int $expiresIn): void
    {
        @file_put_contents($this->cacheFile, json_encode([
            'access_token' => $token,
            'expires_at'   => time() + $expiresIn,
        ]), LOCK_EX);
    }

    /** Signs the assertion JWT and exchanges it for a token. */
    private function fetchToken(): array
    {
        $now = time();
        $claims = [
            'iss'   => $this->sa['client_email'],
            'scope' => $this->scope,
            'aud'   => $this->sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $segments = [
            self::b64url(json_encode($header)),
            self::b64url(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $this->sa['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }
        $assertion = $signingInput . '.' . self::b64url($signature);

        $resp = $this->post($claims['aud'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);

        $data = json_decode($resp['body'], true);
        if ($resp['code'] !== 200 || empty($data['access_token'])) {
            throw new RuntimeException('Token exchange failed (HTTP ' . $resp['code'] . '): ' . $resp['body']);
        }
        return $data;
    }

    private function post(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error contacting Google token endpoint: $err");
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$body];
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
