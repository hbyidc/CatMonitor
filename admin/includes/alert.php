<?php
/**
 * 预警服务：规则判定 + SMTP / Webhook 发送
 *
 * 规则（均可在系统设置中配置）：
 *   1. 离线预警：连续 X 次检测失败
 *   2. SSL 预警：证书剩余 ≤ X 天
 *   3. 频率限制：每 X 天内最多预警 X 次（按节点+类型）
 *   4. 时间窗口：仅在设定时段内发送（支持跨天）
 */

require_once __DIR__ . '/smtp.php';

final class AlertService
{
    /* ---------------- 数据库 ---------------- */

    /** 自动建表/补列（兼容旧版本升级） */
    public static function ensureSchema(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk     = $driver === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $db->exec("CREATE TABLE IF NOT EXISTS alerts (
            id {$pk},
            server_id INTEGER NOT NULL,
            alert_type VARCHAR(20) NOT NULL,
            message VARCHAR(500) DEFAULT '',
            channels VARCHAR(100) DEFAULT '',
            sent_at INTEGER DEFAULT 0
        )");

        $cols = [];
        if ($driver === 'sqlite') {
            foreach ($db->query('PRAGMA table_info(servers)') as $r) {
                $cols[] = $r['name'];
            }
        } else {
            foreach ($db->query('SHOW COLUMNS FROM servers') as $r) {
                $cols[] = $r['Field'];
            }
        }
        if (!in_array('fail_count', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN fail_count INTEGER DEFAULT 0');
        }
        if (!in_array('ssl_days', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN ssl_days INTEGER DEFAULT NULL');
        }
        if (!in_array('ssh_monitor', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN ssh_monitor INTEGER DEFAULT 0');
        }
        if (!in_array('cpu_usage', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN cpu_usage FLOAT DEFAULT NULL');
        }
        if (!in_array('mem_usage', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN mem_usage FLOAT DEFAULT NULL');
        }
        if (!in_array('disk_usage', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN disk_usage FLOAT DEFAULT NULL');
        }
        if (!in_array('disk_mount', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN disk_mount VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('disk_used', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN disk_used BIGINT DEFAULT NULL');
        }
        if (!in_array('disk_total', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN disk_total BIGINT DEFAULT NULL');
        }
        if (!in_array('disks_json', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN disks_json TEXT DEFAULT NULL');
        }
        if (!in_array('packet_loss', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN packet_loss FLOAT DEFAULT NULL');
        }
        if (!in_array('load_usage', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN load_usage FLOAT DEFAULT NULL');
        }
        if (!in_array('mem_used', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN mem_used BIGINT DEFAULT NULL');
        }
        if (!in_array('mem_total', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN mem_total BIGINT DEFAULT NULL');
        }
        if (!in_array('swap_used', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN swap_used BIGINT DEFAULT NULL');
        }
        if (!in_array('swap_total', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN swap_total BIGINT DEFAULT NULL');
        }
        if (!in_array('load_history', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN load_history VARCHAR(100) DEFAULT NULL');
        }
        if (!in_array('net_rx_rate', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN net_rx_rate BIGINT DEFAULT NULL');
        }
        if (!in_array('net_tx_rate', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN net_tx_rate BIGINT DEFAULT NULL');
        }
        if (!in_array('sort_order', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN sort_order INTEGER DEFAULT 0');
        }
        if (!in_array('metrics_retain_days', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN metrics_retain_days INTEGER DEFAULT 0');
        }
        if (!in_array('ping_latency', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN ping_latency FLOAT NULL');
        }
        if (!in_array('front_hidden', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN front_hidden INTEGER DEFAULT 0');
        }
        // logs 表补 packet_loss 列（记录每次探测的丢包率，用于网络质量分析）
        $logCols = [];
        if ($driver === 'sqlite') {
            foreach ($db->query('PRAGMA table_info(logs)') as $r) { $logCols[] = $r['name']; }
        } else {
            foreach ($db->query('SHOW COLUMNS FROM logs') as $r) { $logCols[] = $r['Field']; }
        }
        if (!in_array('packet_loss', $logCols, true)) {
            $db->exec('ALTER TABLE logs ADD COLUMN packet_loss FLOAT DEFAULT NULL');
        }
        // 长期监控历史数据表
        if ($driver === 'sqlite') {
            $hasMetrics = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='server_metrics'")->fetch();
        } else {
            $hasMetrics = $db->query("SHOW TABLES LIKE 'server_metrics'")->fetch();
        }
        if (!$hasMetrics) {
            if ($driver === 'sqlite') {
                $db->exec('CREATE TABLE IF NOT EXISTS server_metrics (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    server_id INTEGER NOT NULL,
                    cpu FLOAT, mem FLOAT, disk FLOAT, "load" FLOAT,
                    mem_used BIGINT, mem_total BIGINT, disk_used BIGINT, disk_total BIGINT,
                    net_rx BIGINT, net_tx BIGINT,
                    disks_json TEXT DEFAULT NULL,
                    checked_at INTEGER NOT NULL
                )');
                $db->exec('CREATE INDEX IF NOT EXISTS idx_server_time ON server_metrics (server_id, checked_at)');
            } else {
                $db->exec('CREATE TABLE IF NOT EXISTS server_metrics (
                    id INTEGER AUTO_INCREMENT PRIMARY KEY,
                    server_id INTEGER NOT NULL,
                    cpu FLOAT, mem FLOAT, disk FLOAT, `load` FLOAT,
                    mem_used BIGINT, mem_total BIGINT, disk_used BIGINT, disk_total BIGINT,
                    net_rx BIGINT, net_tx BIGINT,
                    disks_json TEXT DEFAULT NULL,
                    checked_at INTEGER NOT NULL,
                    KEY idx_server_time (server_id, checked_at)
                )');
            }
        } else {
            if ($driver === 'sqlite') {
                $mcols = [];
                foreach ($db->query('PRAGMA table_info(server_metrics)') as $r) { $mcols[] = $r['name']; }
            } else {
                $mcols = array_column($db->query('SHOW COLUMNS FROM server_metrics')->fetchAll(), 'Field');
            }
            if (!in_array('disks_json', $mcols, true)) {
                $db->exec('ALTER TABLE server_metrics ADD COLUMN disks_json TEXT DEFAULT NULL');
            }
            if (!in_array('net_rx', $mcols, true)) {
                $db->exec('ALTER TABLE server_metrics ADD COLUMN net_rx BIGINT DEFAULT NULL');
            }
            if (!in_array('net_tx', $mcols, true)) {
                $db->exec('ALTER TABLE server_metrics ADD COLUMN net_tx BIGINT DEFAULT NULL');
            }
        }
    }

    /* ---------------- 规则判定 ---------------- */

    /**
     * 每次探测完成后调用，评估是否触发预警
     */
    public static function evaluate(PDO $db, array $server, array $result): void
    {
        if (!self::inTimeWindow(
            self::setting($db, 'alert_time_start', '00:00'),
            self::setting($db, 'alert_time_end', '23:59')
        )) {
            return;
        }

        // 离线预警：连续失败次数达到阈值
        $offlineEnabled  = self::setting($db, 'alert_offline_enabled', '1') === '1';
        $offlineThreshold = max(1, (int)self::setting($db, 'alert_offline_threshold', '3'));
        if ($offlineEnabled && !$result['status']
            && (int)($server['fail_count'] ?? 0) >= $offlineThreshold
            && self::throttleOk($db, (int)$server['id'], 'offline')
        ) {
            $msg = sprintf(
                '节点「%s」（%s %s）已连续 %d 次检测失败，可能已宕机。最新信息：%s。时间：%s',
                $server['name'],
                strtoupper((string)$server['type']),
                $server['address'],
                (int)$server['fail_count'],
                $result['message'],
                date('Y-m-d H:i:s')
            );
            self::send($db, $server, 'offline', $msg);
        }

        // SSL 预警：证书剩余天数不足
        $sslEnabled = self::setting($db, 'alert_ssl_enabled', '1') === '1';
        $sslLimit   = max(0, (int)self::setting($db, 'alert_ssl_days', '30'));
        $sslDays    = $server['ssl_days'] ?? null;
        if ($sslEnabled && $sslDays !== null && $sslDays <= $sslLimit
            && self::throttleOk($db, (int)$server['id'], 'ssl')
        ) {
            $msg = $sslDays < 0
                ? sprintf('节点「%s」（%s）的 SSL 证书已过期 %d 天，请立即处理！时间：%s', $server['name'], $server['address'], abs($sslDays), date('Y-m-d H:i:s'))
                : sprintf('节点「%s」（%s）的 SSL 证书剩余 %d 天，请及时续期。时间：%s', $server['name'], $server['address'], $sslDays, date('Y-m-d H:i:s'));
            self::send($db, $server, 'ssl', $msg);
        }

        // 资源预警：SSH 节点 CPU / 内存 / 根分区占用超阈值
        $resourceEnabled = self::setting($db, 'alert_resource_enabled', '1') === '1';
        $res             = $result['resource'] ?? null;
        if ($resourceEnabled && is_array($res)) {
            $metrics = [
                'cpu'  => ['label' => 'CPU 使用率', 'threshold' => max(1, (int)self::setting($db, 'alert_cpu_threshold', '90'))],
                'mem'  => ['label' => '内存使用率', 'threshold' => max(1, (int)self::setting($db, 'alert_mem_threshold', '90'))],
                // 磁盘在下方按挂载点独立评估，避免遗漏非根分区。
            ];
            $exceeded = [];
            foreach ($metrics as $key => $cfg) {
                $value = $res[$key] ?? null;
                if ($value !== null && $value >= $cfg['threshold']) {
                    $exceeded[] = sprintf('%s %s%%（阈值 %d%%）', $cfg['label'], $value, $cfg['threshold']);
                }
            }
            $diskLimit = max(1, (int)self::setting($db, 'alert_disk_threshold', '90'));
            foreach (resource_disks($res) as $disk) {
                if ($disk['pct'] !== null && $disk['pct'] >= $diskLimit) {
                    $exceeded[] = sprintf('磁盘 %s 占用 %s%%（阈值 %d%%）', $disk['mount'], $disk['pct'], $diskLimit);
                }
            }
            if ($exceeded && self::throttleOk($db, (int)$server['id'], 'resource')) {
                $msg = sprintf(
                    '节点「%s」（%s）资源超限：%s。当前：CPU %s%% / 内存 %s%%。时间：%s',
                    $server['name'],
                    $server['address'],
                    implode('；', $exceeded),
                    $res['cpu'] ?? '-', $res['mem'] ?? '-',
                    date('Y-m-d H:i:s')
                );
                self::send($db, $server, 'resource', $msg);
            }
        }
    }

    /** 频率限制：每 X 天内最多 X 次（按节点+类型） */
    private static function throttleOk(PDO $db, int $serverId, string $type): bool
    {
        $limitDays  = max(1, (int)self::setting($db, 'alert_limit_days', '1'));
        $limitCount = max(1, (int)self::setting($db, 'alert_limit_count', '3'));

        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM alerts WHERE server_id = ? AND alert_type = ? AND sent_at >= ?');
        $stmt->execute([$serverId, $type, time() - $limitDays * 86400]);

        return (int)$stmt->fetch()['c'] < $limitCount;
    }

    /** 时间窗口判断（支持跨天，如 22:00-08:00） */
    public static function inTimeWindow(string $start, string $end): bool
    {
        $now   = date('H:i');
        $start = substr(trim($start) ?: '00:00', 0, 5);
        $end   = substr(trim($end) ?: '23:59', 0, 5);

        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        return $now >= $start || $now <= $end;
    }

    /* ---------------- 发送 ---------------- */

    /** 短信单接口选择；旧配置按短信宝、腾讯云、阿里云顺序兼容。 */
    public static function smsConfig(PDO $db): array
    {
        $providers = ['smsbao', 'tencent_sms', 'aliyun_sms'];
        $provider = self::setting($db, 'sms_provider');
        if (!in_array($provider, $providers, true)) {
            $provider = 'smsbao';
            foreach ($providers as $candidate) {
                if (self::setting($db, $candidate . '_enabled', '0') === '1') {
                    $provider = $candidate;
                    break;
                }
            }
        }
        return [
            'provider' => $provider,
            'enabled' => self::setting($db, $provider . '_enabled', '0') === '1',
        ];
    }

    private static function send(PDO $db, array $server, string $type, string $message): void
    {
        $channels = [];

        if (self::setting($db, 'smtp_enabled', '0') === '1') {
            $r          = self::sendSmtp($db, $type, $server, $message);
            $channels[] = 'SMTP' . ($r['ok'] ? '✓' : '✗(' . $r['message'] . ')');
        }
        if (self::setting($db, 'webhook_enabled', '0') === '1') {
            $r          = self::sendWebhook($db, $type, $server, $message);
            $channels[] = 'Webhook' . ($r['ok'] ? '✓' : '✗(' . $r['message'] . ')');
        }
        $sms = self::smsConfig($db);
        if ($sms['enabled']) {
            $r = match ($sms['provider']) {
                'tencent_sms' => self::sendTencentSms($db, $type, $server, $message),
                'aliyun_sms' => self::sendAliyunSms($db, $type, $server, $message),
                default => self::sendSmsbao($db, $type, $server, $message),
            };
            $label = ['smsbao' => '短信宝', 'tencent_sms' => '腾讯云', 'aliyun_sms' => '阿里云'][$sms['provider']];
            $channels[] = $label . ($r['ok'] ? '✓' : '✗(' . $r['message'] . ')');
        }
        if (self::setting($db, 'telegram_enabled', '0') === '1') {
            $r          = self::sendTelegram($db, $type, $server, $message);
            $channels[] = 'Telegram' . ($r['ok'] ? '✓' : '✗(' . $r['message'] . ')');
        }
        if (!$channels) {
            $channels[] = '无可用通知渠道';
        }

        $stmt = $db->prepare('INSERT INTO alerts (server_id, alert_type, message, channels, sent_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int)$server['id'], $type, mb_substr($message, 0, 480), mb_substr(implode(' / ', $channels), 0, 100), time()]);
    }

    public static function sendSmtp(PDO $db, string $type, array $server, string $message): array
    {
        $cfg = [
            'host'       => self::setting($db, 'smtp_host'),
            'port'       => (int)self::setting($db, 'smtp_port', '465'),
            'encryption' => self::setting($db, 'smtp_encryption', 'ssl'),
            'username'   => self::setting($db, 'smtp_username'),
            'password'   => self::setting($db, 'smtp_password'),
            'from'       => self::setting($db, 'smtp_from'),
        ];
        $to      = self::setting($db, 'smtp_to');
        $subject = '【服务器探针预警】' . match ($type) {
            'offline'  => '节点离线',
            'ssl'      => 'SSL 证书到期',
            'resource' => '资源超限',
            default    => '通知',
        } . ' - ' . $server['name'];

        return SmtpClient::send($cfg, $to, $subject, $message);
    }

    public static function sendWebhook(PDO $db, string $type, array $server, string $message): array
    {
        $url = trim(self::setting($db, 'webhook_url'));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'Webhook URL 未配置或不合法'];
        }

        $headers = ['Content-Type: application/json'];
        $token   = trim(self::setting($db, 'webhook_token'));
        if ($token !== '') {
            $headers[] = 'X-Probe-Token: ' . $token;
        }

        $payload = json_encode([
            'source'     => 'server-probe',
            'type'       => $type,                       // offline | ssl | resource
            'level'      => 'warning',
            'server'     => [
                'id'      => (int)$server['id'],
                'name'    => $server['name'],
                'type'    => $server['type'],
                'address' => $server['address'],
            ],
            'fail_count' => (int)($server['fail_count'] ?? 0),
            'ssl_days'   => $server['ssl_days'] ?? null,
            'message'    => $message,
            'time'       => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'message' => $err];
        }
        if ($code >= 200 && $code < 400) {
            return ['ok' => true, 'message' => 'HTTP ' . $code];
        }

        return ['ok' => false, 'message' => 'HTTP ' . $code];
    }

    /**
     * 短信宝类 SMS 接口通知（默认对接 smsbao.com，地址可自定义）
     * 接口：{api}?u=账号&p=md5(密码)&m=手机号&c=内容
     * 支持国内/国外两条线路，地址均可在设置中修改，适配兼容短信宝标准的第三方接口
     */
    public static function sendSmsbao(PDO $db, string $type, array $server, string $message): array
    {
        $user   = trim(self::setting($db, 'smsbao_username'));
        $pwd    = self::setting($db, 'smsbao_password');
        $mobile = trim(self::setting($db, 'smsbao_mobile'));
        if ($user === '' || $pwd === '' || $mobile === '') {
            return ['ok' => false, 'message' => '短信宝账号/密码/接收手机号未配置'];
        }

        // 按所选线路取 API 地址（未配置时回退短信宝官方默认地址）
        $line    = self::setting($db, 'smsbao_api_line', 'domestic') === 'intl' ? 'intl' : 'domestic';
        $default = $line === 'intl' ? 'http://api.smsbao.com/sms' : 'https://u.smsbao.com/sms';
        $base    = trim(self::setting($db, 'smsbao_api_' . $line, '')) ?: $default;
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'API 地址不合法'];
        }

        $content = self::renderSmsTemplate($db, $type, $server, $message);
        // 单条短信 70 字上限（长短信自动拆分计费，这里截断防止超长被拒）
        $content = mb_substr($content, 0, 500);

        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query([
            'u' => $user,
            'p' => md5($pwd),
            'm' => $mobile,
            'c' => $content,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string)curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'message' => $err];
        }

        $codes = [
            '0'   => '发送成功',
            '-1'  => '账户余额不足',
            '-2'  => '账号或密码错误',
            '-3'  => '短信内容含敏感词',
            '-4'  => '短信内容超出长度限制',
            '-5'  => '一分钟内重复提交',
            '-6'  => '发送超时',
            '-7'  => '接口访问受限',
            '30'  => '手机号格式不正确',
            '40'  => '账号不存在',
            '41'  => '手机号码为空',
            '42'  => '短信内容为空',
            '43'  => '无可用签名',
        ];
        $body = trim($body);
        if ($body === '0') {
            return ['ok' => true, 'message' => '发送成功'];
        }

        return ['ok' => false, 'message' => $codes[$body] ?? ('返回码 ' . $body)];
    }

    /** 短信占位符映射（短信宝 / 腾讯云 / 阿里云共用） */
    private static function smsPlaceholders(PDO $db, array $server, string $message): array
    {
        return [
            '{site}'    => self::setting($db, 'site_name', '服务器探针'),
            '{name}'    => (string)($server['name'] ?? ''),
            '{type}'    => strtoupper((string)($server['type'] ?? '')),
            '{address}' => (string)($server['address'] ?? ''),
            '{message}' => $message,
            '{time}'    => date('Y-m-d H:i:s'),
        ];
    }

    /** 渲染短信模版（占位符：{site} {name} {type} {address} {message} {time}） */
    public static function renderSmsTemplate(PDO $db, string $type, array $server, string $message): string
    {
        $tpl = trim(self::setting($db, 'smsbao_template'));
        if ($tpl === '') {
            $tpl = '【{site}】节点预警：{name}，{message}';
        }

        return strtr($tpl, self::smsPlaceholders($db, $server, $message));
    }

    /**
     * 腾讯云短信（SendSms，TC3-HMAC-SHA256 签名）
     * 需配置：SecretId / SecretKey / SmsSdkAppId / 签名 / 模板ID / 模板参数 / 手机号
     * 模板参数用 || 分隔（对应模板 {1} {2} ...），每段可用占位符
     */
    public static function sendTencentSms(PDO $db, string $type, array $server, string $message): array
    {
        $secretId  = trim(self::setting($db, 'tencent_sms_secret_id'));
        $secretKey = self::setting($db, 'tencent_sms_secret_key');
        $appId     = trim(self::setting($db, 'tencent_sms_app_id'));
        $sign      = trim(self::setting($db, 'tencent_sms_sign'));
        $tplId     = trim(self::setting($db, 'tencent_sms_template_id'));
        $mobile    = trim(self::setting($db, 'tencent_sms_mobile'));
        if ($secretId === '' || $secretKey === '' || $appId === '' || $sign === '' || $tplId === '' || $mobile === '') {
            return ['ok' => false, 'message' => '腾讯云 SecretId/SecretKey/AppId/签名/模板ID/手机号未配置完整'];
        }

        // 手机号：腾讯云需 E.164 格式，国内自动补 +86；已带 + 的按原样（国际号）
        $phones = [];
        foreach (explode(',', $mobile) as $m) {
            $m = preg_replace('/\s+/', '', trim($m));
            if ($m === '') {
                continue;
            }
            $phones[] = $m[0] === '+' ? $m : '+86' . ltrim($m, '+');
        }
        if (!$phones) {
            return ['ok' => false, 'message' => '接收手机号为空'];
        }

        // 模板参数（|| 分隔，逐段替换占位符）
        $ph       = self::smsPlaceholders($db, $server, $message);
        $paramSet = [];
        $rawParam = trim(self::setting($db, 'tencent_sms_template_param'));
        if ($rawParam !== '') {
            foreach (explode('||', $rawParam) as $seg) {
                $seg = trim(strtr($seg, $ph));
                if ($seg === '') {
                    return ['ok' => false, 'message' => '腾讯云模板参数不能为空，请检查分隔符与占位符对应值'];
                }
                $paramSet[] = $seg;
            }
        }

        $host      = 'sms.tencentcloudapi.com';
        $action    = 'SendSms';
        $timestamp = time();
        $payload   = (string)json_encode([
            'SmsSdkAppId'    => $appId,
            'SignName'       => $sign,
            'TemplateId'     => $tplId,
            'TemplateParamSet' => $paramSet,
            'PhoneNumberSet' => $phones,
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization: ' . self::tencentTc3Authorization($secretId, $secretKey, 'sms', $host, $action, $payload, $timestamp),
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: 2021-01-11',
            'X-TC-Region: ap-guangzhou',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://' . $host,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string)curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['ok' => false, 'message' => $err];
        }

        $json = json_decode($body, true);
        $resp = is_array($json) ? ($json['Response'] ?? null) : null;
        if (!is_array($resp)) {
            return ['ok' => false, 'message' => '返回解析失败：' . mb_substr(trim($body), 0, 160)];
        }
        if (isset($resp['Error']['Code'])) {
            return ['ok' => false, 'message' => trim($resp['Error']['Code'] . ' ' . ($resp['Error']['Message'] ?? ''))];
        }
        $statuses = $resp['SendStatusSet'] ?? null;
        if (!is_array($statuses) || !$statuses) {
            return ['ok' => false, 'message' => '发送状态未知'];
        }
        $success = 0;
        $errors = [];
        foreach ($statuses as $status) {
            if (is_array($status) && ($status['Code'] ?? '') === 'Ok') {
                $success++;
            } else {
                $errors[] = is_array($status)
                    ? trim(($status['Code'] ?? '未知错误') . ' ' . ($status['Message'] ?? ''))
                    : '发送状态未知';
            }
        }
        if (count($statuses) !== count($phones)) {
            $errors[] = '返回状态数量与接收手机号数量不符';
        }
        if ($errors) {
            return ['ok' => false, 'message' => '成功 ' . $success . '/' . count($phones) . '：' . implode('；', array_unique($errors))];
        }

        return ['ok' => true, 'message' => '发送成功'];
    }

    /**
     * 阿里云短信（SendSms，RPC 风格 HMAC-SHA1 签名）
     * 需配置：AccessKeyId / AccessKeySecret / 签名 / 模板CODE / 模板参数(JSON) / 手机号
     * 模板参数为 JSON 串（键名对应模板 ${key}），值内可用占位符
     */
    public static function sendAliyunSms(PDO $db, string $type, array $server, string $message): array
    {
        $keyId     = trim(self::setting($db, 'aliyun_sms_key_id'));
        $keySecret = self::setting($db, 'aliyun_sms_key_secret');
        $sign      = trim(self::setting($db, 'aliyun_sms_sign'));
        $tplCode   = trim(self::setting($db, 'aliyun_sms_template_code'));
        $mobile    = trim(self::setting($db, 'aliyun_sms_mobile'));
        if ($keyId === '' || $keySecret === '' || $sign === '' || $tplCode === '' || $mobile === '') {
            return ['ok' => false, 'message' => '阿里云 AccessKeyId/Secret/签名/模板CODE/手机号未配置完整'];
        }

        // 手机号：阿里云国内号码不带 +86，多个逗号分隔；防御性去掉 +86/86 前缀
        $phones = [];
        foreach (explode(',', $mobile) as $m) {
            $m = preg_replace('/\D/', '', trim($m));
            if ($m === '') {
                continue;
            }
            if (strlen($m) === 13 && str_starts_with($m, '86')) {
                $m = substr($m, 2);
            }
            $phones[] = $m;
        }
        if (!$phones) {
            return ['ok' => false, 'message' => '接收手机号为空'];
        }

        // 模板参数（JSON）：先解析模板串，逐值替换占位符后重新编码，避免内容含引号破坏 JSON
        $ph        = self::smsPlaceholders($db, $server, $message);
        $paramJson = '';
        $rawParam  = trim(self::setting($db, 'aliyun_sms_template_param'));
        if ($rawParam !== '') {
            $decoded = json_decode($rawParam);
            if (!$decoded instanceof \stdClass) {
                return ['ok' => false, 'message' => '模板参数必须是 JSON 对象（应形如 {"code":"{name}"}）'];
            }
            foreach ($decoded as $k => $v) {
                if (!is_string($v) && !is_int($v) && !is_float($v)) {
                    return ['ok' => false, 'message' => '模板参数值只能是字符串或数字'];
                }
                $decoded->{$k} = strtr((string)$v, $ph);
            }
            $paramJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            if ($paramJson === false) {
                return ['ok' => false, 'message' => '模板参数编码失败，请检查占位符对应内容'];
            }
        }

        $params = [
            'AccessKeyId'      => $keyId,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => implode(',', $phones),
            'SignName'         => $sign,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $tplCode,
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];
        if ($paramJson !== '') {
            $params['TemplateParam'] = $paramJson;
        }
        $params['Signature'] = self::aliyunRpcSign($params, $keySecret, 'POST');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://dysmsapi.aliyuncs.com/',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string)curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['ok' => false, 'message' => $err];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'message' => '返回解析失败：' . mb_substr(trim($body), 0, 160)];
        }
        if (($json['Code'] ?? '') === 'OK') {
            return ['ok' => true, 'message' => '发送成功'];
        }

        return ['ok' => false, 'message' => trim(($json['Code'] ?? '未知错误') . ' ' . ($json['Message'] ?? ''))];
    }

    /**
     * Telegram 预警推送（Bot API sendMessage）
     */
    public static function sendTelegram(PDO $db, string $type, array $server, string $message): array
    {
        $token  = trim(self::setting($db, 'telegram_token'));
        $chatId = trim(self::setting($db, 'telegram_chat_id'));
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'message' => 'Telegram Bot Token / Chat ID 未配置'];
        }

        $title = match ($type) {
            'offline'  => '🔴 节点离线',
            'ssl'      => '🟠 SSL 证书到期',
            'resource' => '🟡 资源超限',
            default    => '🔔 测试消息',
        };
        $text = $title . "\n\n" . $message;

        $errs = [];
        foreach (array_filter(array_map('trim', explode(',', $chatId))) as $cid) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://api.telegram.org/bot' . $token . '/sendMessage',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $cid, 'text' => $text]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = (string)curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err !== '') {
                $errs[] = $err;
                continue;
            }
            $json = json_decode($body, true);
            if (!is_array($json) || empty($json['ok'])) {
                $errs[] = (string)($json['description'] ?? '接口返回异常');
            }
        }

        if ($errs) {
            return ['ok' => false, 'message' => implode('；', array_unique($errs))];
        }

        return ['ok' => true, 'message' => '发送成功'];
    }

    /* ---------------- 测试 ---------------- */

    public static function testSmtp(PDO $db): array
    {
        return self::sendSmtp($db, 'test', ['name' => '测试', 'type' => 'test', 'address' => '-'],
            "这是一封来自「服务器探针」的测试邮件。\n发送时间：" . date('Y-m-d H:i:s'));
    }

    public static function testWebhook(PDO $db): array
    {
        return self::sendWebhook($db, 'test', ['id' => 0, 'name' => '测试', 'type' => 'test', 'address' => '-', 'fail_count' => 0, 'ssl_days' => null],
            '这是一条来自「服务器探针」的 Webhook 测试消息。时间：' . date('Y-m-d H:i:s'));
    }

    public static function testSmsbao(PDO $db): array
    {
        return self::sendSmsbao($db, 'test', ['name' => '测试节点', 'type' => 'test', 'address' => '-'],
            '这是一条测试短信。时间：' . date('Y-m-d H:i:s'));
    }

    public static function testTencentSms(PDO $db): array
    {
        return self::sendTencentSms($db, 'test', ['name' => '测试节点', 'type' => 'test', 'address' => '-'],
            '这是一条测试短信。时间：' . date('Y-m-d H:i:s'));
    }

    public static function testAliyunSms(PDO $db): array
    {
        return self::sendAliyunSms($db, 'test', ['name' => '测试节点', 'type' => 'test', 'address' => '-'],
            '这是一条测试短信。时间：' . date('Y-m-d H:i:s'));
    }

    public static function testTelegram(PDO $db): array
    {
        return self::sendTelegram($db, 'test', ['name' => '测试节点', 'type' => 'test', 'address' => '-'],
            '这是一条来自「服务器探针」的 Telegram 测试消息。时间：' . date('Y-m-d H:i:s'));
    }

    /* ---------------- 工具 ---------------- */

    /** 腾讯云 TC3-HMAC-SHA256 签名，返回 Authorization 头值 */
    private static function tencentTc3Authorization(string $secretId, string $secretKey, string $service, string $host, string $action, string $payload, int $timestamp): string
    {
        $date             = gmdate('Y-m-d', $timestamp);
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\nx-tc-action:" . strtolower($action) . "\n";
        $signedHeaders    = 'content-type;host;x-tc-action';
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $payload);
        $credentialScope  = "{$date}/{$service}/tc3_request";
        $stringToSign     = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

        return "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }

    /** 阿里云 RPC 风格签名（HMAC-SHA1），返回 Signature 值（签名时不含 Signature 参数） */
    private static function aliyunRpcSign(array $params, string $accessKeySecret, string $method = 'POST'): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = self::aliyunEncode((string)$k) . '=' . self::aliyunEncode((string)$v);
        }
        $stringToSign = $method . '&' . self::aliyunEncode('/') . '&' . self::aliyunEncode(implode('&', $pairs));

        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    /** 阿里云 percentEncode（RFC3986：空格→%20、*→%2A、~ 不编码） */
    private static function aliyunEncode(string $str): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], urlencode($str));
    }

    /** 读取设置（独立缓存，避免与页面缓存冲突） */
    private static function setting(PDO $db, string $key, string $default = ''): string
    {
        static $rows = null;
        if ($rows === null) {
            $rows = [];
            foreach ($db->query('SELECT `key`, `value` FROM settings') as $r) {
                $rows[$r['key']] = $r['value'];
            }
        }

        return (string)($rows[$key] ?? $default);
    }
}
