<?php
/**
 * Thin JSON HTTP wrapper around the Google REST APIs, authenticated with the
 * service-account bearer token from GoogleAuth. Sheets/Drive classes call this.
 */
class GoogleClient
{
    /** @var GoogleAuth */
    private $auth;

    public function __construct(GoogleAuth $auth)
    {
        $this->auth = $auth;
    }

    public function auth(): GoogleAuth
    {
        return $this->auth;
    }

    /** GET a googleapis URL, return decoded JSON (throws on non-2xx). */
    public function get(string $url): array
    {
        return $this->request('GET', $url);
    }

    public function post(string $url, array $body): array
    {
        return $this->request('POST', $url, json_encode($body), 'application/json');
    }

    public function put(string $url, array $body): array
    {
        return $this->request('PUT', $url, json_encode($body), 'application/json');
    }

    /**
     * Raw request with an explicit body + content type — used by Drive's
     * multipart upload. $body is a string, $contentType its MIME type.
     */
    public function raw(string $method, string $url, string $body, string $contentType): array
    {
        return $this->request($method, $url, $body, $contentType);
    }

    private function request(string $method, string $url, ?string $body = null, ?string $contentType = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->auth->getAccessToken()];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error on $method $url: $err");
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) && isset($data['error']['message'])
                ? $data['error']['message'] : substr((string)$resp, 0, 500);
            throw new RuntimeException("Google API $method failed (HTTP $code): $msg");
        }
        return is_array($data) ? $data : [];
    }
}
