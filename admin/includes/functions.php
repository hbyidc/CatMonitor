<?php
/**
 * 公共函数库
 */

/** HTML 转义输出 */
function e(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/** 会话级 CSRF 令牌；跨请求稳定，登录身份变化后重新生成。 */
function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) !== 64) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** 普通表单也携带令牌，不依赖 JavaScript。 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** 不创建新令牌；避免未建立会话的请求被误判为合法。 */
function csrf_valid(): bool
{
    $expected = $_SESSION['csrf_token'] ?? null;
    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);

    return is_string($expected) && strlen($expected) === 64
        && is_string($received) && hash_equals($expected, $received);
}

/** 会话鉴权的写请求统一检查；外部 API / cron 使用独立密钥，不经过此入口。 */
function csrf_check_request(bool $json = false): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (csrf_valid()) {
        return;
    }
    $msg = '安全令牌无效或会话已过期，请刷新页面后重试';
    http_response_code(403);
    header('Cache-Control: no-store');
    if ($json || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        json_out(['message' => $msg, 'msg' => $msg, 'ok' => false], 403);
    }
    header('Content-Type: text/html; charset=utf-8');
    exit(e($msg));
}

/** 写操作禁止以 GET 触发，防止链接或预加载绕过 CSRF 检查。 */
function require_post_request(bool $json = false): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check_request($json);
        return;
    }
    http_response_code(405);
    header('Allow: POST');
    if ($json) {
        json_out(['message' => '此操作仅支持 POST 请求', 'msg' => '此操作仅支持 POST 请求', 'ok' => false], 405);
    }
    header('Content-Type: text/html; charset=utf-8');
    exit('此操作仅支持 POST 请求');
}

/** Flash 消息（写入） */
function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/** Flash 消息（读取并清除） */
function flash_get(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $f;
    }

    return null;
}

/** 输出 JSON 并结束 */
function json_out(array $data, int $code = 0)
{
    // 丢弃此前产生的任何输出（PHP 警告 / Notice 等），确保响应体为纯 JSON，避免前端 parsererror
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['code' => $code, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 读取设置项 */
function get_setting(PDO $db, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt  = $db->query('SELECT `key`, `value` FROM settings');
        foreach ($stmt as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }

    return $cache[$key] ?? $default;
}

/** 写入设置项 */
function set_setting(PDO $db, string $key, string $value): void
{
    $sql = match ($db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
        'mysql' => 'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
                    ON DUPLICATE KEY UPDATE `value` = :v2',
        default => 'INSERT OR REPLACE INTO settings (`key`, `value`) VALUES (:k, :v)',
    };
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':k', $key);
    $stmt->bindValue(':v', $value);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt->bindValue(':v2', $value);
    }
    $stmt->execute();
}

/**
 * 获取/生成开放 API 凭证（api_id + api_key）
 * 与 cron 密钥同机制：存于 settings，惰性生成，可强制重新生成
 */
function open_api_credentials(PDO $db, bool $regenerate = false): array
{
    $id  = get_setting($db, 'open_api_id');
    $key = get_setting($db, 'open_api_key');
    if ($regenerate || $id === '' || $key === '') {
        $id  = 'ak_' . bin2hex(random_bytes(6));   // 便于识别的短 ID
        $key = bin2hex(random_bytes(24));          // 48 位十六进制密钥
        set_setting($db, 'open_api_id', $id);
        set_setting($db, 'open_api_key', $key);
    }

    return ['id' => $id, 'key' => $key];
}

/** 校验开放 API 凭证：需已启用且 id/key 均匹配（恒定时间比较，防时序攻击） */
function open_api_verify(PDO $db, string $id, string $key): bool
{
    if (get_setting($db, 'open_api_enabled', '1') !== '1') {
        return false;
    }
    $c = open_api_credentials($db);

    return $id !== '' && $key !== ''
        && hash_equals($c['id'], $id)
        && hash_equals($c['key'], $key);
}

/**
 * 临时令牌登录（SSO）一次性令牌：用 id+key（POST）换取短期 token，再凭 token 登录。
 * 相比 URL 直接携带 api_key，token 短期(默认120s)、一次性、库中仅存 sha256 哈希，
 * 泄露风险低且随开关可吊销，避免 api_key 出现在地址栏 / Referer / 访问日志。
 */

/** SSO 令牌表（惰性建表） */
function sso_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pk     = $driver === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec("CREATE TABLE IF NOT EXISTS sso_tokens (
        id {$pk},
        token_hash CHAR(64) NOT NULL,
        admin_id INTEGER NOT NULL,
        ip VARCHAR(45) DEFAULT '',
        expires_at INTEGER NOT NULL,
        used INTEGER DEFAULT 0,
        created_at INTEGER DEFAULT 0
    )");
    $done = true;
}

/**
 * 签发一次性登录令牌（身份为主管理员 admins.id 最小者）
 *
 * @return array{token:string, admin_id:int, expires_at:int, expires_in:int}|null 未启用 / 无管理员时返回 null
 */
function sso_issue_token(PDO $db, int $ttl = 120): ?array
{
    if (get_setting($db, 'sso_login_enabled', '1') !== '1') {
        return null;
    }
    $admin = $db->query('SELECT id FROM admins ORDER BY id ASC LIMIT 1')->fetch();
    if (!$admin) {
        return null;
    }
    sso_ensure_schema($db);

    $now = time();
    $ttl = max(30, min(600, $ttl)); // 限定 30s ~ 10min
    // 顺带清理：已过期的、以及已用超过 1 小时的旧记录
    $db->prepare('DELETE FROM sso_tokens WHERE expires_at < ? OR (used = 1 AND created_at < ?)')
       ->execute([$now, $now - 3600]);

    $token = bin2hex(random_bytes(32));
    $exp   = $now + $ttl;
    $db->prepare('INSERT INTO sso_tokens (token_hash, admin_id, ip, expires_at, used, created_at) VALUES (?, ?, ?, ?, 0, ?)')
       ->execute([hash('sha256', $token), (int)$admin['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''), $exp, $now]);

    return ['token' => $token, 'admin_id' => (int)$admin['id'], 'expires_at' => $exp, 'expires_in' => $ttl];
}

/**
 * 校验并消费（一次性）登录令牌：命中即标记 used，防重放
 *
 * @return array{id:int, username:string}|null 无效 / 过期 / 已用时返回 null
 */
function sso_consume_token(PDO $db, string $token): ?array
{
    if ($token === '' || get_setting($db, 'sso_login_enabled', '1') !== '1') {
        return null;
    }
    sso_ensure_schema($db);

    $stmt = $db->prepare('SELECT id, admin_id FROM sso_tokens WHERE token_hash = ? AND used = 0 AND expires_at >= ? LIMIT 1');
    $stmt->execute([hash('sha256', $token), time()]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $db->prepare('UPDATE sso_tokens SET used = 1 WHERE id = ?')->execute([(int)$row['id']]);

    $q = $db->prepare('SELECT id, username FROM admins WHERE id = ?');
    $q->execute([(int)$row['admin_id']]);

    return $q->fetch() ?: null;
}

/** 探测类型定义：标签 + 默认端口 */
function probe_types(): array
{
    return [
        'ping' => ['label' => 'PING', 'port' => 0],
        'tcp'  => ['label' => 'TCP', 'port' => 80],
        'udp'  => ['label' => 'UDP', 'port' => 53],
        'ssh'  => ['label' => 'SSH', 'port' => 22],
        'ssh_ping' => ['label' => 'SSH+PING', 'port' => 22],
        'http' => ['label' => '网站', 'port' => 0],
        'bt'   => ['label' => '宝塔面板', 'port' => 0],
    ];
}

/** 状态徽章 HTML（软色 + 呼吸圆点） */
function status_badge(?int $status): string
{
    return match ($status) {
        1       => '<span class="badge badge-soft-success"><span class="status-dot dot-success"></span>在线</span>',
        0       => '<span class="badge badge-soft-danger"><span class="status-dot dot-danger"></span>离线</span>',
        default => '<span class="badge badge-soft-secondary"><span class="status-dot dot-muted"></span>未检测</span>',
    };
}

/** 预警类型徽章 */
function alert_type_badge(string $type): string
{
    return match ($type) {
        'offline'  => '<span class="badge badge-soft-danger"><i class="mdi mdi-lan-disconnect me-1"></i>离线预警</span>',
        'ssl'      => '<span class="badge badge-soft-warning"><i class="mdi mdi-certificate-outline me-1"></i>SSL 到期</span>',
        'resource' => '<span class="badge badge-soft-warning"><i class="mdi mdi-gauge me-1"></i>资源超限</span>',
        default    => '<span class="badge badge-soft-info">' . e($type) . '</span>',
    };
}

/**
 * 读取健康状态阈值配置（资源告警%/危险%、PING 丢包危险%）
 */
function health_thresholds(PDO $db): array
{
    $warn   = max(1, min(100, (int)get_setting($db, 'health_warn_threshold', '80')));
    $danger = max($warn, min(100, (int)get_setting($db, 'health_danger_threshold', '95')));
    $loss   = max(1, min(100, (int)get_setting($db, 'health_loss_danger', '50')));

    return [$warn, $danger, $loss];
}

/**
 * 节点健康状态判定：offline / healthy / warning / danger
 * - 离线（last_status=0）→ offline
 * - SSH 资源监控节点：CPU/负载/内存/磁盘任一项 ≥ $danger → danger，≥ $warn → warning
 * - PING 节点：丢包率 ≥ $lossDanger → danger，> 0 → warning（完全 ping 不通探测即为离线）
 * - 其余在线节点 → healthy
 */
function server_health(array $s, int $warn = 80, int $danger = 95, int $lossDanger = 50): string
{
    if ((int)($s['last_status'] ?? 0) !== 1) {
        return 'offline';
    }

    if ($s['type'] === 'ping') {
        $loss = (float)($s['packet_loss'] ?? 0);
        if ($loss >= $lossDanger) {
            return 'danger';
        }

        return $loss > 0 ? 'warning' : 'healthy';
    }

    if (in_array($s['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($s['ssh_monitor'] ?? 0) === 1) {
        $worst = 0.0;
        foreach (['cpu_usage', 'mem_usage', 'disk_usage', 'load_usage'] as $k) {
            if (($s[$k] ?? null) !== null) {
                $worst = max($worst, (float)$s[$k]);
            }
        }
        foreach (resource_disks($s) as $disk) {
            if ($disk['pct'] !== null) $worst = max($worst, $disk['pct']);
        }
        if ($worst >= $danger) {
            return 'danger';
        }

        return $worst >= $warn ? 'warning' : 'healthy';
    }

    return 'healthy';
}

/**
 * KB 容量格式化（df -P 输出单位为 KB）
 */
function format_kb(?float $kb): string
{
    if ($kb === null) {
        return '-';
    }
    if ($kb >= 1073741824) {
        return round($kb / 1073741824, 1) . ' TB';
    }
    if ($kb >= 1048576) {
        return round($kb / 1048576, 1) . ' GB';
    }
    if ($kb >= 1024) {
        return round($kb / 1024, 1) . ' MB';
    }

    return round($kb, 0) . ' KB';
}

/**
 * 百分比文本（去掉多余的 0，如 27.0 -> 27，5.6 -> 5.6）
 */
function pct_text(?float $v): string
{
    if ($v === null) {
        return '-';
    }

    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

/**
 * 实时带宽速率格式化（B/s → 人类可读）
 */
function format_bps(?float $bps): string
{
    if ($bps === null) {
        return '-';
    }
    if ($bps >= 1073741824) {
        return round($bps / 1073741824, 2) . ' GB/s';
    }
    if ($bps >= 1048576) {
        return round($bps / 1048576, 2) . ' MB/s';
    }
    if ($bps >= 1024) {
        return round($bps / 1024, 1) . ' KB/s';
    }

    return round($bps) . ' B/s';
}

/** 统一多盘快照格式，过滤伪文件系统并按设备去重。 */
function normalize_resource_disks(array $items): array
{
    $valid = [];
    $pseudo = ['tmpfs', 'devtmpfs', 'overlay', 'aufs', 'squashfs', 'proc', 'sysfs', 'devpts', 'ramfs', 'rootfs', 'cgroup', 'cgroup2', 'debugfs', 'securityfs', 'pstore', 'tracefs', 'nsfs', 'autofs', 'mqueue', 'hugetlbfs', 'fusectl', 'configfs'];
    foreach ($items as $d) {
        if (!is_array($d)) continue;
        $mount = (string)($d['mount'] ?? '');
        $device = (string)($d['device'] ?? '');
        $fs = strtolower((string)($d['fs'] ?? ''));
        if ($mount === '' || $mount[0] !== '/' || in_array($fs, $pseudo, true)
            || in_array(rtrim($mount, '/'), ['/boot', '/boot/efi'], true)
            || in_array($device, $pseudo, true) || preg_match('~^/dev/(loop|ram)\d+~', $device)
            || preg_match('~^/(proc|sys|dev)(/|$)~', $mount)) continue;
        $pct = $d['pct'] ?? null;
        $total = $d['total'] ?? null;
        $used = $d['used'] ?? null;
        if ($total !== null && (!is_numeric($total) || (float)$total <= 0)) continue;
        $valid[] = [
            'mount' => $mount, 'device' => $device, 'fs' => $fs,
            'pct' => is_numeric($pct) ? round(max(0, min(100, (float)$pct)), 1) : null,
            'used' => is_numeric($used) ? max(0, (int)$used) : null,
            'total' => is_numeric($total) ? (int)$total : null,
        ];
    }
    // 根分区优先，其余按挂载点排序；相同设备的绑定挂载只保留一项。
    usort($valid, static function ($a, $b) {
        if ($a['mount'] === $b['mount']) return 0;
        if ($a['mount'] === '/') return -1;
        if ($b['mount'] === '/') return 1;
        return strnatcmp($a['mount'], $b['mount']);
    });
    $out = $devices = $mounts = [];
    foreach ($valid as $d) {
        if (isset($mounts[$d['mount']]) || ($d['device'] !== '' && isset($devices[$d['device']]))) continue;
        $mounts[$d['mount']] = true;
        if ($d['device'] !== '') $devices[$d['device']] = true;
        $out[] = $d;
    }
    return $out;
}

/** 读取新快照；仅旧记录回退单盘，空数组表示本次未采到磁盘。 */
function resource_disks(array $data): array
{
    if (isset($data['disks']) && is_array($data['disks'])) return normalize_resource_disks($data['disks']);
    if (isset($data['disks_json'])) {
        $items = json_decode((string)$data['disks_json'], true);
        if (is_array($items)) return normalize_resource_disks($items);
    }
    $pct = $data['disk_usage'] ?? $data['disk'] ?? null;
    if ($pct === null) return [];
    return normalize_resource_disks([[
        'mount' => $data['disk_mount'] ?? '/', 'pct' => $pct,
        'used' => $data['disk_used'] ?? null, 'total' => $data['disk_total'] ?? null,
    ]]);
}

/** 服务器管理页专用：多盘时只保留系统盘 + 占用率最高的 N 个数据盘（$dataLimit<=0 不筛选）。 */
function resource_disks_top(array $disks, int $dataLimit = 2): array
{
    if ($dataLimit <= 0) return $disks;
    $root = $data = [];
    foreach ($disks as $d) {
        if (($d['mount'] ?? '') === '/') $root[] = $d; else $data[] = $d;
    }
    usort($data, static fn($a, $b) => ($b['pct'] ?? -1.0) <=> ($a['pct'] ?? -1.0));
    return array_merge($root, array_slice($data, 0, $dataLimit));
}

/** 与常规趋势使用相同分桶；缺测不填零，旧记录单独标识，不冒充数据盘历史。 */
function disk_history_buckets(array $rows, int $bucketSize): array
{
    $out = [];
    foreach (array_chunk($rows, max(1, $bucketSize)) as $chunk) {
        $sum = $count = [];
        foreach ($chunk as $r) {
            $items = isset($r['disks_json']) ? resource_disks($r) : [];
            if (!isset($r['disks_json']) && $r['disk'] !== null) {
                $items = [['mount' => '主盘（旧记录）', 'pct' => (float)$r['disk']]];
            }
            foreach ($items as $d) {
                if ($d['pct'] === null) continue;
                $key = $d['mount'];
                $sum[$key] = ($sum[$key] ?? 0) + $d['pct'];
                $count[$key] = ($count[$key] ?? 0) + 1;
            }
        }
        $point = [];
        foreach ($sum as $mount => $value) $point[$mount] = round($value / $count[$mount], 1);
        $out[] = (object)$point;
    }
    return $out;
}

/** 各挂载点进度条：容量单位与采集统一为 KB。 */
function resource_disk_list_html(array $disks, int $threshold = 90): string
{
    $html = '<div class="disk-list" aria-label="各磁盘使用情况">';
    foreach ($disks as $d) {
        $v = $d['pct'];
        $color = $v === null ? '#d0d5dd' : ($v >= $threshold ? '#f04438' : ($v >= $threshold * 0.8 ? '#f79009' : '#12b76a'));
        $label = $v === null ? '-' : pct_text($v) . '%';
        $capacity = $d['used'] !== null && $d['total'] !== null ? format_kb($d['used']) . ' / ' . format_kb($d['total']) : '容量未知';
        $html .= '<div class="disk-row"><div class="disk-row-head"><span class="disk-mount" title="' . e($d['mount']) . '">' . e($d['mount']) . '</span><span>' . $label . '</span></div>'
            . '<div class="disk-capacity">' . $capacity . '</div><div class="disk-track" role="progressbar" aria-label="' . e('磁盘 ' . $d['mount']) . '" aria-valuemin="0" aria-valuemax="100"'
            . ($v === null ? ' aria-valuetext="暂无数据"' : ' aria-valuenow="' . $v . '"') . '><i style="width:' . ($v ?? 0) . '%;background:' . $color . '"></i></div></div>';
    }
    return $html . ($disks ? '' : '<div class="small text-muted">暂无磁盘数据</div>') . '</div>';
}

/**
 * SSH 资源环形图容器（CPU / 内存 / 磁盘三个 doughnut + 实时带宽，绘制由共享 JS 完成）
 * 磁盘环下方显示挂载点及 已用/总容量；未开启监控或无数据时返回提示文本
 */
function resource_gauges_html(array $s, int $cpuT, int $memT, int $diskT, int $diskDataLimit = 0): string
{
    if ((int)($s['ssh_monitor'] ?? 0) !== 1) {
        return '';
    }
    $hasNet = ($s['net_rx_rate'] ?? null) !== null || ($s['net_tx_rate'] ?? null) !== null;
    if (($s['cpu_usage'] ?? null) === null && ($s['mem_usage'] ?? null) === null && ($s['disk_usage'] ?? null) === null && !$hasNet) {
        return '<div class="small text-muted mt-1">待采集资源数据</div>';
    }

    $cpu  = ($s['cpu_usage'] ?? null) === null ? '' : (float)$s['cpu_usage'];
    $mem  = ($s['mem_usage'] ?? null) === null ? '' : (float)$s['mem_usage'];
    $disk = ($s['disk_usage'] ?? null) === null ? '' : (float)$s['disk_usage'];
    $mount = e($s['disk_mount'] ?? '/');
    $val = function ($v): string {
        return $v === '' ? '-' : pct_text((float)$v) . '%';
    };

    // CPU 副信息：最近 3 个负载值；内存副信息：当前占用/总容量（有 Swap 时分两行展示）
    $loadHist = array_values(array_filter(explode(',', (string)($s['load_history'] ?? '')), fn($v) => $v !== ''));
    $cpuSub = $loadHist ? '<div class="gauge-sub">负载 ' . implode(' / ', $loadHist) . '</div>' : '';
    $hasSwap = (int)($s['swap_total'] ?? 0) > 0 && ($s['swap_used'] ?? null) !== null;
    $swapPct = $hasSwap ? round((float)$s['swap_used'] / (float)$s['swap_total'] * 100, 1) : '';
    $memSub = (($s['mem_used'] ?? null) !== null && ($s['mem_total'] ?? null) !== null)
        ? '<div class="gauge-sub">' . ($hasSwap ? '内存 ' : '') . format_kb((float)$s['mem_used']) . ' / ' . format_kb((float)$s['mem_total']) . '</div>'
        : '';
    if ($hasSwap) {
        $memSub .= '<div class="gauge-sub gauge-swap">Swap ' . format_kb((float)$s['swap_used']) . ' / ' . format_kb((float)$s['swap_total']) . '</div>';
    }
    // Swap 扇形圈：有 Swap 时叠在内存环右下角（小号角标）
    $swapRing = $hasSwap
        ? '<div class="gauge-wrap gauge-swap-ring"><canvas width="36" height="36" data-gauge="swap"></canvas>'
            . '<span class="gauge-val gauge-swap-val">' . $val($swapPct) . '</span></div>'
        : '';

    $diskInfo = '';
    if (($s['disk_used'] ?? null) !== null && ($s['disk_total'] ?? null) !== null) {
        $diskInfo = '<div class="gauge-disk">' . format_kb((float)$s['disk_used']) . ' / ' . format_kb((float)$s['disk_total']) . '</div>';
    }

    // 实时带宽：下行/上行速率（非百分比，不用环形图）
    $netHtml = '';
    if ($hasNet) {
        $netHtml = '<div class="gauge-item gauge-net"><div class="gauge-net-box">'
            . '<b class="net-down"><i class="mdi mdi-arrow-down-bold-circle-outline"></i><span class="net-down-val">' . format_bps(($s['net_rx_rate'] ?? null) !== null ? (float)$s['net_rx_rate'] : null) . '</span></b>'
            . '<b class="net-up"><i class="mdi mdi-arrow-up-bold-circle-outline"></i><span class="net-up-val">' . format_bps(($s['net_tx_rate'] ?? null) !== null ? (float)$s['net_tx_rate'] : null) . '</span></b>'
            . '</div><div class="gauge-info"><span class="gauge-label">实时带宽</span></div></div>';
    }

    // 服务器管理页多盘时只保留系统盘 + 占用率最高的若干数据盘（$diskDataLimit>0 生效）
    $diskList = $diskDataLimit > 0 ? resource_disks_top(resource_disks($s), $diskDataLimit) : resource_disks($s);

    return '<div class="resource-gauges"'
        . ' data-cpu="' . ($cpu === '' ? '' : $cpu) . '"'
        . ' data-mem="' . ($mem === '' ? '' : $mem) . '"'
        . ' data-swap="' . ($swapPct === '' ? '' : $swapPct) . '"'
        . ' data-disk="' . ($disk === '' ? '' : $disk) . '"'
        . ' data-mount="' . $mount . '"'
        . ' data-used="' . ($s['disk_used'] ?? '') . '"'
        . ' data-total="' . ($s['disk_total'] ?? '') . '"'
        . ' data-loadhist="' . implode(',', $loadHist) . '">'
        . '<div class="gauge-item"><div class="gauge-wrap"><canvas width="72" height="72" data-gauge="cpu"></canvas>'
        . '<span class="gauge-val">' . $val($cpu) . '</span></div>'
        . '<div class="gauge-info"><span class="gauge-label">CPU</span>' . $cpuSub . '</div></div>'
        . '<div class="gauge-item"><div class="gauge-wrap"><canvas width="72" height="72" data-gauge="mem"></canvas>'
        . '<span class="gauge-val">' . $val($mem) . '</span>' . $swapRing . '</div>'
        . '<div class="gauge-info"><span class="gauge-label">内存</span>' . $memSub . '</div></div>'
        . '<div class="gauge-item gauge-disk-group"><div class="gauge-wrap"><canvas width="72" height="72" data-gauge="disk"></canvas>'
        . '<span class="gauge-val">' . $val($disk) . '</span></div>'
        . '<div class="gauge-info"><span class="gauge-label">磁盘 ' . $mount . '</span>' . $diskInfo . '</div>'
        . resource_disk_list_html($diskList, $diskT) . '</div>'
        . $netHtml
        . '</div>';
}

/**
 * 预警/监控汇总数据（顶栏铃铛 / 侧边栏角标 / 仪表盘共用）
 *
 * @return array{alertToday:int, alerts:array, offline:int, offlineNodes:array, sslSoon:int}
 */
function probe_theme_data(PDO $db): array
{
    $todayStart = strtotime(date('Y-m-d'));

    $alertToday = (int)$db->query('SELECT COUNT(*) AS c FROM alerts WHERE sent_at >= ' . $todayStart)
        ->fetch()['c'];

    // 最近预警：每个节点只取最新一条（同一节点不重复展示），铃铛下拉/首页预警中心共用
    $alerts = $db->query(
        'SELECT a.alert_type, a.message, a.sent_at, s.name AS server_name
         FROM alerts a
         JOIN (SELECT MAX(id) AS mid FROM alerts GROUP BY server_id) t ON t.mid = a.id
         LEFT JOIN servers s ON s.id = a.server_id
         ORDER BY a.id DESC LIMIT 5'
    )->fetchAll();

    $offlineNodes = $db->query(
        'SELECT id, name FROM servers WHERE enabled = 1 AND last_status = 0 ORDER BY id ASC'
    )->fetchAll();

    $sslThreshold = max(0, (int)get_setting($db, 'alert_ssl_days', '30'));
    $sslSoon = (int)$db->query(
        'SELECT COUNT(*) AS c FROM servers WHERE enabled = 1 AND ssl_days IS NOT NULL AND ssl_days <= ' . $sslThreshold
    )->fetch()['c'];

    return [
        'alertToday'   => $alertToday,
        'alerts'       => $alerts,
        'offline'      => count($offlineNodes),
        'offlineNodes' => $offlineNodes,
        'sslSoon'      => $sslSoon,
    ];
}

/** 延迟格式化 */
function latency_text($ms): string
{
    if ($ms === null || $ms === '') {
        return '-';
    }

    return round((float)$ms, 2) . ' ms';
}

/**
 * 节点延迟展示文本：SSH+PING 节点默认同时展示两种延迟；
 * 后台设置 sspping_ping_only=1 时仅展示 PING 延迟
 */
function node_latency_text(array $s, bool $pingOnly = false): string
{
    if (($s['type'] ?? '') !== 'ssh_ping') {
        return latency_text($s['last_latency'] ?? null);
    }
    $ping = $s['ping_latency'] ?? null;
    $pingTxt = ($ping === null || $ping === '') ? '-' : round((float)$ping, 2) . ' ms';
    if ($pingOnly) {
        return 'PING ' . $pingTxt;
    }

    return 'SSH ' . latency_text($s['last_latency'] ?? null) . ' · PING ' . $pingTxt;
}

/** 时间戳格式化（0/null 返回 -） */
function time_text($ts): string
{
    $ts = (int)$ts;

    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
}

/** 简单分页，返回 [rows, total, page, pages] */
function paginate(PDO $db, string $sql, array $params, int $page, int $perPage = 20): array
{
    $page    = max(1, $page);
    $countFn = function (string $sql): string {
        return 'SELECT COUNT(*) AS c FROM (' . $sql . ') t';
    };
    $stmt = $db->prepare($countFn($sql));
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['c'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);

    $stmt = $db->prepare($sql . ' LIMIT ' . $perPage . ' OFFSET ' . ($page - 1) * $perPage);
    $stmt->execute($params);

    return [$stmt->fetchAll(), $total, $page, $pages];
}

/** 分页条 HTML（保留当前查询参数） */
function pager_html(int $page, int $pages, int $total, string $base = ''): string
{
    if ($pages <= 1) {
        return '<p class="text-muted small mb-0">共 ' . $total . ' 条记录</p>';
    }
    $query = $_GET;
    $make  = function (int $p) use ($query, $base) {
        $query['page'] = $p;

        return ($base ?: '') . '?' . http_build_query($query);
    };

    $html = '<nav><ul class="pagination mb-0">';
    $html .= '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . e($make(max(1, $page - 1))) . '">上一页</a></li>';
    for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
        $html .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '"><a class="page-link" href="' . e($make($i)) . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '"><a class="page-link" href="' . e($make(min($pages, $page + 1))) . '">下一页</a></li>';
    $html .= '</ul></nav>';
    $html .= '<p class="text-muted small mb-0 mt-2">共 ' . $total . ' 条记录，' . $pages . ' 页</p>';

    return $html;
}

/** 校验主机名/IP（防止命令注入） */
function is_valid_host(string $host): bool
{
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    // 域名 / IPv4 / IPv6（粗略）
    return (bool)preg_match('/^[a-zA-Z0-9.\-:_\[\]]+$/', $host);
}

/** 校验 URL */
function is_valid_url(string $url): bool
{
    return (bool)filter_var($url, FILTER_VALIDATE_URL)
        && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'));
}

/**
 * 由探测行计算「波动扣减后可用率」等统计
 * 规则：离线记 0；在线基础满分，丢包按丢包率 3 倍扣减（重扣）；
 * 延迟基线取 P10，超出基线 1.5 倍以内视为正常波动不扣，超过部分按超出比例减半扣（轻扣）
 * @param array $rows 每行 [status, latency, loss]
 * @return array [n, uptime, avg_latency, avg_loss]
 */
function uptime_stats_from_rows(array $rows, ?array $cfg = null): array
{
    $cfg = $cfg ?? uptime_scoring_defaults();
    $n = count($rows);
    if ($n <= 0) {
        return ['n' => 0, 'uptime' => null, 'avg_latency' => null, 'avg_loss' => null];
    }
    $latSum = 0.0;
    $latN   = 0;
    $lats   = [];
    $lossSum = 0.0;
    $lossN  = 0;
    foreach ($rows as [$st, $lat, $loss]) {
        if ((int)$st === 1 && $lat !== null) { $latSum += $lat; $latN++; $lats[] = $lat; }
        if ($loss !== null) { $lossSum += $loss; $lossN++; }
    }
    // 延迟基线：取第 10 百分位（最低稍高一点），比均值更苛刻
    $base = 0.0;
    if ($latN > 0) {
        sort($lats);
        $base = $lats[(int)floor(0.1 * ($latN - 1))];
    }

    $credit = 0.0;
    $thresh = $base * (float)($cfg['k'] ?? 1.5);          // 波动判定线：基线 × 松紧倍数
    $strength = (float)($cfg['strength'] ?? 1.0);          // 松紧档位扣减强度
    $latW  = (float)($cfg['lat_w'] ?? 1.0);                // 波动扣减权重（用户可设）
    $lossW = (float)($cfg['loss_w'] ?? 3.0);               // 丢包扣减权重（用户可设）
    foreach ($rows as [$st, $lat, $loss]) {
        if ((int)$st !== 1) { continue; }
        $c = 1.0;
        if ($lat !== null && $thresh > 0 && $lat > $thresh) {
            // 延迟超基线才算波动：超出比例减半为基准扣减，再乘强度与权重
            $raw = (1 - $thresh / $lat) / 2;
            $c *= max(0.0, 1 - $raw * $strength * $latW);
        }
        if ($loss !== null) {
            $c *= max(0.0, 1 - $loss * $lossW / 100); // 丢包：每 1% 丢包扣 loss_w%
        }
        $credit += $c;
    }

    return [
        'n'           => $n,
        'uptime'      => round($credit / $n * 100, 2),
        'avg_latency' => $latN > 0 ? round($latSum / $latN, 2) : null,
        'avg_loss'    => $lossN > 0 ? round($lossSum / $lossN, 2) : null,
    ];
}

/** 可用率评分默认参数（平衡档，与历史行为一致） */
function uptime_scoring_defaults(): array
{
    return ['k' => 1.5, 'strength' => 1.0, 'loss_w' => 3.0, 'lat_w' => 1.0, 'yellow' => 98.0, 'red' => 90.0];
}

/**
 * 可用率评分配置：松紧档位 / 丢包与波动扣减权重 / 颜色阈值（后台预警规则可设）
 * 松紧档位决定延迟波动判定倍数 k 与扣减强度 strength，档位越低越容易判为波动且扣分越重
 */
function uptime_scoring_config(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $levels = [
        'ultra_strict'  => ['k' => 1.1,  'strength' => 2.0],
        'strict'        => ['k' => 1.25, 'strength' => 1.5],
        'balanced'      => ['k' => 1.5,  'strength' => 1.0],
        'lenient'       => ['k' => 2.0,  'strength' => 0.6],
        'ultra_lenient' => ['k' => 3.0,  'strength' => 0.3],
    ];
    $lv = $levels[get_setting($db, 'uptime_strictness', 'balanced')] ?? $levels['balanced'];
    $yellow = max(0.0, min(100.0, (float)get_setting($db, 'uptime_yellow', '98')));
    $red    = max(0.0, min(100.0, (float)get_setting($db, 'uptime_red', '90')));
    if ($red > $yellow) {
        $red = $yellow;   // 红阈值不得高于黄阈值
    }
    return $cache = $lv + [
        'loss_w' => max(0.0, min(10.0, (float)get_setting($db, 'uptime_loss_weight', '3'))),
        'lat_w'  => max(0.0, min(3.0, (float)get_setting($db, 'uptime_lat_weight', '1'))),
        'yellow' => $yellow,
        'red'    => $red,
    ];
}

/** 可用率颜色：≥黄阈值 绿 / ≥红阈值 橙 / 其余红（阈值后台可设） */
function uptime_color(float $uptime, float $yellow = 98, float $red = 90): string
{
    if ($uptime >= $yellow) { return '#12b76a'; }
    if ($uptime >= $red) { return '#f79009'; }
    return '#f04438';
}
