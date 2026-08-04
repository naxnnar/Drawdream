<?php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

if (!function_exists('drawdream_mail_config')) {
    /**
     * @return array{
     *   transport:string,
     *   host:string,
     *   port:int,
     *   user:string,
     *   password:string,
     *   encryption:string,
     *   from:string,
     *   from_name:string,
     *   timeout:int
     * }
     */
    function drawdream_mail_config(): array
    {
        drawdream_load_env_file(__DIR__ . '/../.env');

        $host = trim((string)(getenv('SMTP_HOST') ?: ''));
        $defaultTransport = $host !== '' ? 'smtp' : 'mail';

        return [
            'transport' => strtolower(trim((string)(getenv('MAIL_TRANSPORT') ?: $defaultTransport))),
            'host' => $host,
            'port' => max(1, (int)(getenv('SMTP_PORT') ?: 587)),
            'user' => trim((string)(getenv('SMTP_USER') ?: '')),
            'password' => trim((string)(getenv('SMTP_PASSWORD') ?: '')),
            'encryption' => strtolower(trim((string)(getenv('SMTP_ENCRYPTION') ?: 'tls'))),
            'from' => trim((string)(getenv('MAIL_FROM') ?: 'noreply@drawdream.org')),
            'from_name' => trim((string)(getenv('MAIL_FROM_NAME') ?: 'DrawDream')),
            'timeout' => max(5, (int)(getenv('SMTP_TIMEOUT') ?: 15)),
        ];
    }
}

if (!function_exists('drawdream_mail_is_smtp_configured')) {
    function drawdream_mail_is_smtp_configured(): bool
    {
        $cfg = drawdream_mail_config();

        return $cfg['transport'] === 'smtp' && $cfg['host'] !== '';
    }
}

if (!function_exists('drawdream_mail_send')) {
    /**
     * @return array{ok:bool,error:string,transport:string}
     */
    function drawdream_mail_send(string $to, string $subject, string $bodyPlain): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid recipient', 'transport' => ''];
        }

        $cfg = drawdream_mail_config();
        $from = $cfg['from'];
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid MAIL_FROM', 'transport' => $cfg['transport']];
        }

        if ($cfg['transport'] === 'smtp') {
            try {
                drawdream_mail_smtp_send($cfg, $to, $subject, $bodyPlain);
            } catch (Throwable $e) {
                error_log('DrawDream mail SMTP failed: ' . $e->getMessage());

                return ['ok' => false, 'error' => $e->getMessage(), 'transport' => 'smtp'];
            }

            return ['ok' => true, 'error' => '', 'transport' => 'smtp'];
        }

        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . 'From: ' . drawdream_mail_format_address($cfg['from_name'], $from) . "\r\n";

        $ok = @mail(
            $to,
            drawdream_mail_encode_subject($subject),
            $bodyPlain,
            $headers
        );
        if (!$ok) {
            error_log('DrawDream mail() failed for ' . $to);

            return ['ok' => false, 'error' => 'mail() returned false', 'transport' => 'mail'];
        }

        return ['ok' => true, 'error' => '', 'transport' => 'mail'];
    }
}

if (!function_exists('drawdream_mail_encode_subject')) {
    function drawdream_mail_encode_subject(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }
}

if (!function_exists('drawdream_mail_format_address')) {
    function drawdream_mail_format_address(string $name, string $email): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));
        if ($name === '') {
            return $email;
        }

        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }
}

if (!function_exists('drawdream_mail_smtp_send')) {
    /**
     * @param array{
     *   transport:string,
     *   host:string,
     *   port:int,
     *   user:string,
     *   password:string,
     *   encryption:string,
     *   from:string,
     *   from_name:string,
     *   timeout:int
     * } $cfg
     */
    function drawdream_mail_smtp_send(array $cfg, string $to, string $subject, string $bodyPlain): void
    {
        if ($cfg['host'] === '') {
            throw new RuntimeException('SMTP_HOST is not configured');
        }

        $encryption = $cfg['encryption'];
        $remote = $cfg['host'] . ':' . $cfg['port'];
        if ($encryption === 'ssl') {
            $remote = 'ssl://' . $remote;
        } else {
            $remote = 'tcp://' . $remote;
        }

        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $cfg['timeout'],
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($fp)) {
            throw new RuntimeException('SMTP connect failed: ' . ($errstr !== '' ? $errstr : (string)$errno));
        }

        stream_set_timeout($fp, $cfg['timeout']);

        try {
            drawdream_mail_smtp_expect($fp, [220]);

            $localHost = trim((string)(getenv('SMTP_EHLO_HOST') ?: ($_SERVER['SERVER_NAME'] ?? 'drawdream.org')));
            drawdream_mail_smtp_cmd($fp, 'EHLO ' . $localHost, [250]);

            if ($encryption === 'tls') {
                drawdream_mail_smtp_cmd($fp, 'STARTTLS', [220]);
                $cryptoOk = @stream_socket_enable_crypto(
                    $fp,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($cryptoOk !== true) {
                    throw new RuntimeException('SMTP STARTTLS failed');
                }
                drawdream_mail_smtp_cmd($fp, 'EHLO ' . $localHost, [250]);
            }

            if ($cfg['user'] !== '') {
                drawdream_mail_smtp_cmd($fp, 'AUTH LOGIN', [334]);
                drawdream_mail_smtp_cmd($fp, base64_encode($cfg['user']), [334]);
                drawdream_mail_smtp_cmd($fp, base64_encode($cfg['password']), [235]);
            }

            drawdream_mail_smtp_cmd($fp, 'MAIL FROM:<' . $cfg['from'] . '>', [250]);
            drawdream_mail_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            drawdream_mail_smtp_cmd($fp, 'DATA', [354]);

            $message = 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
                . 'To: <' . $to . ">\r\n"
                . 'From: ' . drawdream_mail_format_address($cfg['from_name'], $cfg['from']) . "\r\n"
                . 'Subject: ' . drawdream_mail_encode_subject($subject) . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n"
                . "\r\n"
                . str_replace("\n.", "\n..", str_replace("\r\n", "\n", $bodyPlain))
                . "\r\n";

            fwrite($fp, str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
            drawdream_mail_smtp_expect($fp, [250]);
            drawdream_mail_smtp_cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }
}

if (!function_exists('drawdream_mail_smtp_cmd')) {
  /**
   * @param array<int,int> $codes
   */
    function drawdream_mail_smtp_cmd($fp, string $cmd, array $codes): void
    {
        fwrite($fp, $cmd . "\r\n");
        drawdream_mail_smtp_expect($fp, $codes);
    }
}

if (!function_exists('drawdream_mail_smtp_expect')) {
    /**
     * @param array<int,int> $codes
     */
    function drawdream_mail_smtp_expect($fp, array $codes): void
    {
        $resp = drawdream_mail_smtp_read($fp);
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim(preg_replace('/\s+/', ' ', $resp) ?? $resp));
        }
    }
}

if (!function_exists('drawdream_mail_smtp_read')) {
    function drawdream_mail_smtp_read($fp): string
    {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return $data;
    }
}
