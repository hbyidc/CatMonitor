<?php
/**
 * 轻量 SMTP 客户端（支持 SSL / STARTTLS / AUTH LOGIN）
 */

final class SmtpClient
{
    /**
     * 发送邮件
     *
     * @param array  $cfg     host/port/encryption(ssl|tls|none)/username/password/from
     * @param string $to      收件人，多个用逗号分隔
     * @param string $subject 主题
     * @param string $body    正文（纯文本）
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function send(array $cfg, string $to, string $subject, string $body): array
    {
        $host = trim((string)($cfg['host'] ?? ''));
        $port = (int)($cfg['port'] ?? 465);
        $from = trim((string)($cfg['from'] ?? ''));

        if ($host === '' || $port <= 0) {
            return ['ok' => false, 'message' => 'SMTP 服务器未配置'];
        }
        if ($from === '') {
            return ['ok' => false, 'message' => '发信人地址未配置'];
        }
        $to = trim($to);
        if ($to === '') {
            return ['ok' => false, 'message' => '收件人未配置'];
        }

        $enc    = $cfg['encryption'] ?? 'ssl';
        $scheme = $enc === 'ssl' ? 'ssl' : 'tcp';

        $fp = @stream_socket_client(sprintf('%s://%s:%d', $scheme, $host, $port), $errno, $errstr, 15);
        if (!$fp) {
            return ['ok' => false, 'message' => "无法连接 SMTP 服务器：{$errstr}"];
        }
        stream_set_timeout($fp, 15);

        try {
            self::expect($fp, [220]);
            self::cmd($fp, 'EHLO probe.local', [250]);

            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                    throw new RuntimeException('TLS 握手失败');
                }
                self::cmd($fp, 'EHLO probe.local', [250]);
            }

            if (($cfg['username'] ?? '') !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode((string)$cfg['username']), [334]);
                self::cmd($fp, base64_encode((string)($cfg['password'] ?? '')), [235]);
            }

            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            foreach (array_filter(array_map('trim', explode(',', $to))) as $rcpt) {
                self::cmd($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            }

            self::cmd($fp, 'DATA', [354]);
            $headers = 'From: =?UTF-8?B?' . base64_encode('服务器探针') . "?= <{$from}>\r\n"
                . "To: {$to}\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n";
            fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
            self::expect($fp, [250]);

            fwrite($fp, "QUIT\r\n");
            fclose($fp);

            return ['ok' => true, 'message' => '发送成功'];
        } catch (Throwable $e) {
            fclose($fp);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function cmd($fp, string $cmd, array $expect): void
    {
        fwrite($fp, $cmd . "\r\n");
        self::expect($fp, $expect);
    }

    /** 读取 SMTP 响应并校验状态码（支持多行响应） */
    private static function expect($fp, array $codes): string
    {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            // 第 4 个字符为空格表示响应结束（多行响应为 -）
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP 响应异常：' . trim($resp));
        }

        return $resp;
    }
}
