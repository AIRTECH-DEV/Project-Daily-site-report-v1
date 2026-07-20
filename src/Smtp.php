<?php
/**
 * Minimal SMTP client (no composer). Supports STARTTLS or implicit SSL, AUTH
 * LOGIN, CC, and a single file attachment as multipart/mixed. Enough to send the
 * site-report PDF the way sendReportEmail.js did via Gmail — here over SMTP with
 * the crm@ app password.
 *
 * Throws RuntimeException with the SMTP transcript on any unexpected reply, so
 * failures are diagnosable (wrong password, blocked port, etc.).
 */
class Smtp
{
    /** @var array */ private $cfg;
    /** @var resource */ private $conn;
    /** @var string */ private $log = '';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param string $to      comma-separated recipients
     * @param string $subject
     * @param string $html
     * @param array|null $attachment ['name'=>..,'mime'=>..,'bytes'=>..]
     * @param string $cc      comma-separated CC (optional)
     */
    public function send(string $to, string $subject, string $html, ?array $attachment = null, string $cc = ''): void
    {
        $host = $this->cfg['smtp_host'];
        $port = (int)$this->cfg['smtp_port'];
        $secure = strtolower((string)($this->cfg['smtp_secure'] ?? 'tls'));

        $transport = ($secure === 'ssl') ? "ssl://$host" : "tcp://$host";
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $err = 0; $errStr = '';
        $this->conn = @stream_socket_client("$transport:$port", $err, $errStr, 30, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->conn) {
            throw new RuntimeException("SMTP connect failed to $host:$port — $errStr");
        }
        stream_set_timeout($this->conn, 30);

        $this->expect('220');
        $ehloHost = $this->localHost();
        $this->cmd("EHLO $ehloHost", '250');

        if ($secure === 'tls') {
            $this->cmd('STARTTLS', '220');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->conn, true, $crypto)) {
                throw new RuntimeException("STARTTLS negotiation failed with $host");
            }
            $this->cmd("EHLO $ehloHost", '250');
        }

        // AUTH LOGIN
        if (!empty($this->cfg['smtp_user'])) {
            $this->cmd('AUTH LOGIN', '334');
            $this->cmd(base64_encode($this->cfg['smtp_user']), '334');
            $this->cmd(base64_encode((string)$this->cfg['smtp_pass']), '235');
        }

        $from = $this->cfg['from'] ?: $this->cfg['smtp_user'];
        $this->cmd("MAIL FROM:<$from>", '250');

        $recips = array_merge($this->split($to), $this->split($cc));
        foreach (array_unique($recips) as $rcpt) {
            $this->cmd("RCPT TO:<$rcpt>", '250');
        }

        $this->cmd('DATA', '354');
        $message = $this->buildMime($from, $to, $cc, $subject, $html, $attachment);
        // Dot-stuff any line starting with '.'
        $message = preg_replace('/^\./m', '..', $message);
        $this->write($message . "\r\n.");
        $this->expect('250');

        $this->cmd('QUIT', '221', false);
        @fclose($this->conn);
    }

    /* ---------------- MIME ---------------- */

    private function buildMime(string $from, string $to, string $cc, string $subject, string $html, ?array $att): string
    {
        $fromName = $this->cfg['from_name'] ?? '';
        $fromHdr = $fromName !== '' ? $this->encHeader($fromName) . " <$from>" : $from;
        $eol = "\r\n";
        $boundary = 'pmsbnd_' . bin2hex(random_bytes(10));

        $h = [];
        $h[] = "Date: " . date('r');
        $h[] = "From: $fromHdr";
        $h[] = "To: $to";
        if ($cc !== '') { $h[] = "Cc: $cc"; }
        $h[] = "Subject: " . $this->encHeader($subject);
        $h[] = "Message-ID: <" . bin2hex(random_bytes(12)) . "@vakhariaairtech.com>";
        $h[] = "MIME-Version: 1.0";

        if ($att) {
            $h[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
            $body  = "--$boundary$eol";
            $body .= "Content-Type: text/html; charset=UTF-8$eol";
            $body .= "Content-Transfer-Encoding: base64$eol$eol";
            $body .= chunk_split(base64_encode($html)) . $eol;
            $body .= "--$boundary$eol";
            $body .= "Content-Type: " . ($att['mime'] ?? 'application/octet-stream')
                   . "; name=\"" . $att['name'] . "\"$eol";
            $body .= "Content-Transfer-Encoding: base64$eol";
            $body .= "Content-Disposition: attachment; filename=\"" . $att['name'] . "\"$eol$eol";
            $body .= chunk_split(base64_encode($att['bytes'])) . $eol;
            $body .= "--$boundary--$eol";
        } else {
            $h[] = "Content-Type: text/html; charset=UTF-8";
            $h[] = "Content-Transfer-Encoding: base64";
            $body = $eol . chunk_split(base64_encode($html));
        }
        return implode($eol, $h) . $eol . ($att ? $eol . $body : $body);
    }

    private function encHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }

    /* ---------------- wire ---------------- */

    private function cmd(string $cmd, string $expect, bool $check = true): void
    {
        $this->write($cmd);
        if ($check) {
            $this->expect($expect);
        } else {
            $this->read();
        }
    }

    private function write(string $data): void
    {
        $this->log .= '> ' . (strlen($data) > 120 ? substr($data, 0, 120) . '…' : $data) . "\n";
        fwrite($this->conn, $data . "\r\n");
    }

    private function read(): string
    {
        $data = '';
        while (($line = fgets($this->conn, 515)) !== false) {
            $data .= $line;
            // Multiline replies: "250-..." continue, "250 ..." ends.
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        $this->log .= '< ' . trim($data) . "\n";
        return $data;
    }

    private function expect(string $code): void
    {
        $resp = $this->read();
        if (strncmp($resp, $code, strlen($code)) !== 0) {
            throw new RuntimeException("SMTP expected $code, got: " . trim($resp) . "\nTranscript:\n" . $this->log);
        }
    }

    private function split(string $csv): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $csv))));
    }

    private function localHost(): string
    {
        return gethostname() ?: 'localhost';
    }
}
