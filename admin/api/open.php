<?php
/**
 * 开放 API（第三方调用）统一入口：凭 api_id + api_key 鉴权，通过 action 参数区分功能。
 * 供外部大屏 / 客户端 / 聚合站调用；除探测外均为只读，绝不返回任何登录凭证。
 *
 * 鉴权：需 api_id + api_key 同时匹配（后台「系统设置 → 开放 API」中生成 / 查看 / 停用）
 *   三种传参方式任选其一：
 *     ① Query： GET  /admin/api/open.php?api_id=xxx&api_key=yyy
 *     ② 表单： POST  api_id=xxx & api_key=yyy
 *     ③ 请求头：      X-Api-Id: xxx   /   X-Api-Key: yyy
 *
 * action（默认 overview）：
 *   overview   全量分类 + 节点卡片（只读，不触发探测）
 *   probe_all  立即探测所有启用节点（写操作，耗时，带并发锁）
 *   probe      立即探测指定节点              参数：server_id
 *   trend      指定节点性能趋势(CPU/内存/磁盘/负载/带宽时序)  参数：server_id, range=1h|12h|24h|3d|7d|30d（仅 SSH/宝塔）
 *   quality    指定节点历史质量(延迟/丢包/可用率时序+统计)    参数：server_id, range=1h|6h|24h|7d
  *   alerts     告警记录查询(离线/SSL/资源/测试)  参数：type, server_id, since, limit, page
  *   alert_config 告警规则阈值 + 渠道开关(只读，不含任何凭证)
 *   token      POST id+key 换取一次性登录令牌  参数：ttl(可选，默认120s，范围30~600)；返回 token + login_url（供 sso.php?token= 登录）
 *
 * overview 可选参数：
 *   include_hidden = 1  含隐藏/停用节点、隐藏分类、空分类（完整后台视角）；默认 0 仅前台可见
 *   with_resource  = 0  不返回资源占用；默认 1
 *   with_history   = 1  额外返回按小时可用率数组 history；默认 0
 *
 * 安全：绝不返回 username / password；含密钥请求一律 no-store 禁止中间缓存。
 */

require dirname(__DIR__) . '/bootstrap.php';

// 允许跨域调用（第三方前端 / 服务端）；预检请求直接放行
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Id, X-Api-Key');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- 鉴权 ----
$apiId  = (string)($_GET['api_id']  ?? $_POST['api_id']  ?? $_SERVER['HTTP_X_API_ID']  ?? '');
$apiKey = (string)($_GET['api_key'] ?? $_POST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
if (!open_api_verify($db, $apiId, $apiKey)) {
    http_response_code(401);
    json_out(['message' => 'API 凭证无效，或开放 API 已停用'], 1);
}

// ---- 通用参数助手 ----
$param  = static fn (string $k, string $default = ''): string => (string)($_GET[$k] ?? $_POST[$k] ?? $default);
$sid    = static fn (): int => (int)($param('server_id') !== '' ? $param('server_id') : $param('id', '0'));
$action = $param('action', 'overview');

// 命中以下任一动作后 json_out() 会直接 exit；都未命中则继续走文件末尾的 overview 逻辑。

// ===== action=probe_all：探测所有启用节点（写操作，耗时；文件锁防并发重复触发）=====
if ($action === 'probe_all') {
    $targets = $db->query('SELECT * FROM servers WHERE enabled = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
    @set_time_limit(max(120, count($targets) * 15));
    ignore_user_abort(true);

    $lockFile = ADMIN_ROOT . '/data/open_probe.lock';
    $fp = @fopen($lockFile, 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        json_out(['message' => '已有探测任务正在进行，请稍后重试'], 1);
    }

    $results = [];
    $online  = 0;
    try {
        foreach ($targets as $server) {
            try {
                $r = ProbeService::checkServer($db, $server);
            } catch (Throwable $e) {
                $r = ['status' => false, 'latency' => 0.0, 'message' => '采集异常：' . $e->getMessage()];
            }
            $ok = !empty($r['status']);
            if ($ok) { $online++; }
            $results[] = [
                'id'      => (int)$server['id'],
                'name'    => $server['name'],
                'type'    => $server['type'],
                'status'  => $ok ? 1 : 0,
                'latency' => round((float)($r['latency'] ?? 0), 2),
                'message' => (string)($r['message'] ?? ''),
            ];
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    json_out([
        'action'      => 'probe_all',
        'checked'     => count($results),
        'online'      => $online,
        'offline'     => count($results) - $online,
        'finished_at' => date('Y-m-d H:i:s'),
        'results'     => $results,
    ]);
}

// ===== action=probe：探测指定节点（server_id）=====
if ($action === 'probe') {
    $id   = $sid();
    $stmt = $db->prepare('SELECT * FROM servers WHERE id = ?');
    $stmt->execute([$id]);
    $server = $stmt->fetch();
    if (!$server) {
        json_out(['message' => '节点不存在'], 1);
    }

    @set_time_limit(max(60, (int)$server['timeout'] + 30));
    ignore_user_abort(true);
    [$hw, $hd, $hld] = health_thresholds($db);

    $t0 = microtime(true);
    try {
        $r = ProbeService::checkServer($db, $server);
    } catch (Throwable $e) {
        $r = ['status' => false, 'latency' => 0.0, 'message' => '采集异常：' . $e->getMessage()];
    }
    $duration = (int)round((microtime(true) - $t0) * 1000);

    $stmt->execute([$id]);
    $after = $stmt->fetch();

    json_out([
        'action'     => 'probe',
        'id'         => $id,
        'name'       => $server['name'],
        'type'       => $server['type'],
        'address'    => $server['address'],
        'port'       => (int)$server['port'],
        'status'     => !empty($r['status']) ? 1 : 0,
        'latency'    => round((float)($r['latency'] ?? 0), 2),
        'message'    => (string)($r['message'] ?? ''),
        'duration'   => $duration,
        'checked_at' => date('Y-m-d H:i:s'),
        'loss'       => $r['loss'] ?? null,
        'ssl_days'   => $r['ssl_days'] ?? null,
        'resource'   => $r['resource'] ?? null,
        'health'     => server_health($after ?: $server, $hw, $hd, $hld),
    ]);
}

// ===== action=trend：指定节点性能趋势（server_metrics，仅 SSH/宝塔）=====
if ($action === 'trend') {
    $id     = $sid();
    $ranges = ['1h' => 3600, '12h' => 43200, '24h' => 86400, '3d' => 259200, '7d' => 604800, '30d' => 2592000];
    $range  = $param('range', '24h');
    $sec    = $ranges[$range] ?? 86400;

    $stmt = $db->prepare('SELECT id, name, address, type, metrics_retain_days, cpu_usage, mem_usage, disk_usage, load_usage, mem_used, mem_total, swap_used, swap_total, disk_used, disk_total, disk_mount, net_rx_rate, net_tx_rate, last_status, last_check_at FROM servers WHERE id = ?');
    $stmt->execute([$id]);
    $server = $stmt->fetch();
    if (!$server) {
        json_out(['message' => '节点不存在'], 1);
    }
    if (!in_array($server['type'], ['ssh', 'ssh_ping', 'bt'], true)) {
        json_out(['message' => '该节点不支持性能趋势（仅 SSH / 宝塔面板类型）'], 1);
    }

    $now   = time();
    $start = (int)$param('start', '0');
    $end   = (int)$param('end', '0');
    if ($start > 0 && $end > $start && $end - $start >= 600) {
        $start = max($start, $now - (int)$server['metrics_retain_days'] * 86400);
        $end   = min($end, $now + 60);
        $range = 'custom';
    } else {
        $start = $now - $sec;
        $end   = $now;
    }

    $q = $db->prepare('SELECT checked_at, cpu, mem, disk, `load`, net_rx, net_tx FROM server_metrics WHERE server_id = ? AND checked_at >= ? AND checked_at <= ? ORDER BY checked_at ASC');
    $q->execute([$id, $start, $end]);
    $rows = $q->fetchAll();

    // 降采样：超过 300 点按块取平均
    $points = [];
    $count  = count($rows);
    if ($count > 300) {
        $bucketSize = (int)ceil($count / 300);
        foreach (array_chunk($rows, $bucketSize) as $chunk) {
            $t = $cpu = $mem = $disk = $load = $rx = $tx = 0;
            $n = 0;
            foreach ($chunk as $r) {
                $t += (int)$r['checked_at']; $cpu += (float)$r['cpu']; $mem += (float)$r['mem'];
                $disk += (float)$r['disk']; $load += (float)($r['load'] ?? 0);
                $rx += (float)($r['net_rx'] ?? 0); $tx += (float)($r['net_tx'] ?? 0); $n++;
            }
            $points[] = [(int)round($t / $n), round($cpu / $n, 1), round($mem / $n, 1), round($disk / $n, 1), round($load / $n, 2), (int)round($rx / $n), (int)round($tx / $n)];
        }
    } else {
        foreach ($rows as $r) {
            $points[] = [(int)$r['checked_at'], round((float)$r['cpu'], 1), round((float)$r['mem'], 1), round((float)$r['disk'], 1), round((float)($r['load'] ?? 0), 2), (int)($r['net_rx'] ?? 0), (int)($r['net_tx'] ?? 0)];
        }
    }

    json_out([
        'action'  => 'trend',
        'node'    => ['id' => $id, 'name' => $server['name'], 'type' => $server['type'], 'address' => $server['address']],
        'range'   => $range,
        'start'   => $start,
        'end'     => $end,
        'retain'  => (int)$server['metrics_retain_days'],
        'points'  => $points, // [时间戳, CPU%, 内存%, 磁盘%, 负载, 下行 B/s, 上行 B/s]
        'current' => [
            'status'     => (int)$server['last_status'],
            'cpu'        => $server['cpu_usage'] !== null ? round((float)$server['cpu_usage'], 1) : null,
            'mem'        => $server['mem_usage'] !== null ? round((float)$server['mem_usage'], 1) : null,
            'disk'       => $server['disk_usage'] !== null ? round((float)$server['disk_usage'], 1) : null,
            'load'       => $server['load_usage'] !== null ? round((float)$server['load_usage'], 2) : null,
            'net_rx'     => $server['net_rx_rate'] !== null ? (float)$server['net_rx_rate'] : null,
            'net_tx'     => $server['net_tx_rate'] !== null ? (float)$server['net_tx_rate'] : null,
            'mem_used'   => $server['mem_used'], 'mem_total' => $server['mem_total'],
            'swap_used'  => $server['swap_used'], 'swap_total' => $server['swap_total'],
            'disk_used'  => $server['disk_used'], 'disk_total' => $server['disk_total'],
            'disk_mount' => $server['disk_mount'],
            'checked_at' => (int)$server['last_check_at'],
        ],
    ]);
}

// ===== action=quality：指定节点历史质量（logs：延迟/丢包时序 + 最近50次可用率条 + 统计）=====
if ($action === 'quality') {
    $id       = $sid();
    $rangeMap = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];
    $range    = $param('range', '24h');
    if (!isset($rangeMap[$range])) { $range = '24h'; }
    $span = $rangeMap[$range];
    $now  = time();

    $st = $db->prepare('SELECT id, name, type, address, port FROM servers WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) {
        json_out(['message' => '节点不存在'], 1);
    }

    // 延迟 / 丢包时序（点数过多时按时间桶聚合）
    $q = $db->prepare('SELECT status, latency, packet_loss, checked_at FROM logs WHERE server_id = ? AND checked_at >= ? ORDER BY checked_at ASC');
    $q->execute([$id, $now - $span]);
    $pts = $q->fetchAll();
    $fmt = $range === '7d' ? 'm-d H:i' : 'H:i';
    $labels = []; $lat = []; $loss = [];
    if (count($pts) > 360) {
        $bucketSize = (int)ceil($span / 360);
        $buckets = [];
        foreach ($pts as $p) {
            $b = (int)floor((int)$p['checked_at'] / $bucketSize);
            if (!isset($buckets[$b])) { $buckets[$b] = ['t' => $b * $bucketSize, 'lat' => 0.0, 'latn' => 0, 'loss' => 0.0, 'lossn' => 0]; }
            if ($p['latency'] !== null) { $buckets[$b]['lat'] += (float)$p['latency']; $buckets[$b]['latn']++; }
            if ($p['packet_loss'] !== null) { $buckets[$b]['loss'] += (float)$p['packet_loss']; $buckets[$b]['lossn']++; }
        }
        ksort($buckets);
        foreach ($buckets as $b) {
            $labels[] = date($fmt, $b['t']);
            $lat[]    = $b['latn'] > 0 ? round($b['lat'] / $b['latn'], 2) : null;
            $loss[]   = $b['lossn'] > 0 ? round($b['loss'] / $b['lossn'], 2) : null;
        }
    } else {
        foreach ($pts as $p) {
            $labels[] = date($fmt, (int)$p['checked_at']);
            $lat[]    = $p['latency'] !== null ? round((float)$p['latency'], 2) : null;
            $loss[]   = $p['packet_loss'] !== null ? round((float)$p['packet_loss'], 2) : null;
        }
    }

    // 最近 50 次探测可用率条（旧 → 新）
    $q = $db->prepare('SELECT status, latency, packet_loss, checked_at FROM logs WHERE server_id = ? ORDER BY checked_at DESC LIMIT 50');
    $q->execute([$id]);
    $bars = [];
    foreach ($q->fetchAll() as $p) {
        $bars[] = ['t' => date('m-d H:i:s', (int)$p['checked_at']), 'ok' => (int)$p['status'] === 1, 'lat' => $p['latency'] !== null ? round((float)$p['latency'], 2) : null, 'loss' => $p['packet_loss'] !== null ? round((float)$p['packet_loss'], 1) : null];
    }
    $bars = array_reverse($bars);

    // 12h 波动扣减后可用率（评分松紧/权重后台可设）
    $upCfg = uptime_scoring_config($db);
    $q = $db->prepare('SELECT status, latency, packet_loss FROM logs WHERE server_id = ? AND checked_at >= ?');
    $q->execute([$id, $now - 43200]);
    $rows12 = [];
    foreach ($q->fetchAll() as $r) {
        $rows12[] = [(int)$r['status'], $r['latency'] !== null ? (float)$r['latency'] : null, $r['packet_loss'] !== null ? (float)$r['packet_loss'] : null];
    }
    $h12 = uptime_stats_from_rows($rows12, $upCfg);

    // 最近一次探测
    $last = null;
    $q = $db->prepare('SELECT status, latency, packet_loss, message, checked_at FROM logs WHERE server_id = ? ORDER BY checked_at DESC LIMIT 1');
    $q->execute([$id]);
    if ($r = $q->fetch()) {
        $last = ['ok' => (int)$r['status'] === 1, 'lat' => $r['latency'] !== null ? round((float)$r['latency'], 2) : null, 'loss' => $r['packet_loss'] !== null ? round((float)$r['packet_loss'], 1) : null, 'msg' => (string)$r['message'], 't' => date('Y-m-d H:i:s', (int)$r['checked_at'])];
    }

    json_out([
        'action' => 'quality',
        'node'   => ['id' => (int)$s['id'], 'name' => $s['name'], 'type' => $s['type'], 'target' => $s['address'] . ((int)$s['port'] > 0 && $s['type'] !== 'http' ? ':' . (int)$s['port'] : '')],
        'range'  => $range,
        'series' => ['labels' => $labels, 'latency' => $lat, 'loss' => $loss],
        'bars'   => $bars,
        'stats'  => ['last' => $last, 'h12' => $h12],
    ]);
}

// ===== action=alerts：告警记录查询（alerts 表 JOIN servers，只读）=====
if ($action === 'alerts') {
    $type     = $param('type');                    // offline | ssl | resource | test | 空=全部
    $serverId = (int)$param('server_id', '0');
    $since    = (int)$param('since', '0');          // 仅返回该时间戳之后的告警（可选）
    $limit    = min(200, max(1, (int)$param('limit', '50')));
    $page     = max(1, (int)$param('page', '1'));
    $offset   = ($page - 1) * $limit;

    $where = [];
    $bind  = [];
    if (in_array($type, ['offline', 'ssl', 'resource', 'test'], true)) {
        $where[] = 'a.alert_type = ?';
        $bind[]  = $type;
    }
    if ($serverId > 0) {
        $where[] = 'a.server_id = ?';
        $bind[]  = $serverId;
    }
    if ($since > 0) {
        $where[] = 'a.sent_at >= ?';
        $bind[]  = $since;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cq = $db->prepare("SELECT COUNT(*) AS c FROM alerts a {$whereSql}");
    $cq->execute($bind);
    $total = (int)$cq->fetch()['c'];

    // LIMIT / OFFSET 已强制为 int，内联安全（避免部分 PDO 对 LIMIT ? 绑定为字符串）
    $lq = $db->prepare("SELECT a.*, s.name AS server_name, s.address AS server_address
                        FROM alerts a LEFT JOIN servers s ON s.id = a.server_id
                        {$whereSql}
                        ORDER BY a.id DESC
                        LIMIT {$limit} OFFSET {$offset}");
    $lq->execute($bind);

    $typeLabels = ['offline' => '离线预警', 'ssl' => 'SSL 预警', 'resource' => '资源超限', 'test' => '测试消息'];
    $list = [];
    foreach ($lq->fetchAll() as $r) {
        $list[] = [
            'id'               => (int)$r['id'],
            'server_id'        => (int)$r['server_id'],
            'server_name'      => $r['server_name'] !== null ? (string)$r['server_name'] : ('已删除#' . (int)$r['server_id']),
            'server_address'   => $r['server_address'] !== null ? (string)$r['server_address'] : '',
            'alert_type'       => (string)$r['alert_type'],
            'alert_type_label' => $typeLabels[$r['alert_type']] ?? (string)$r['alert_type'],
            'message'          => (string)$r['message'],
            'channels'         => (string)$r['channels'],
            'sent_at'          => (int)$r['sent_at'],
            'sent_at_text'     => $r['sent_at'] ? date('Y-m-d H:i:s', (int)$r['sent_at']) : '',
        ];
    }

    json_out([
        'action' => 'alerts',
        'filter' => ['type' => $type !== '' ? $type : null, 'server_id' => $serverId ?: null, 'since' => $since ?: null],
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'pages'  => (int)ceil($total / $limit),
        'list'   => $list,
    ]);
}

// ===== action=alert_config：告警规则阈值 + 渠道开关（只读 settings，绝不返回任何凭证）=====
if ($action === 'alert_config') {
    $on = static fn (string $k, string $d = '0'): bool => get_setting($db, $k, $d) === '1';
    $iv = static fn (string $k, string $d): int => (int)get_setting($db, $k, $d);
    $sms = AlertService::smsConfig($db);

    json_out([
        'action'   => 'alert_config',
        'offline'  => ['enabled' => $on('alert_offline_enabled', '1'), 'threshold' => $iv('alert_offline_threshold', '3')],
        'ssl'      => ['enabled' => $on('alert_ssl_enabled', '1'), 'days' => $iv('alert_ssl_days', '30')],
        'resource' => [
            'enabled'        => $on('alert_resource_enabled', '1'),
            'cpu_threshold'  => $iv('alert_cpu_threshold', '90'),
            'mem_threshold'  => $iv('alert_mem_threshold', '90'),
            'disk_threshold' => $iv('alert_disk_threshold', '90'),
        ],
        'throttle'    => ['days' => $iv('alert_limit_days', '1'), 'count' => $iv('alert_limit_count', '3')],
        'time_window' => ['start' => get_setting($db, 'alert_time_start', '00:00'), 'end' => get_setting($db, 'alert_time_end', '23:59')],
        'channels'    => [
            'smtp'        => $on('smtp_enabled'),
            'webhook'     => $on('webhook_enabled'),
            'smsbao'      => $sms['enabled'] && $sms['provider'] === 'smsbao',
            'tencent_sms' => $sms['enabled'] && $sms['provider'] === 'tencent_sms',
            'aliyun_sms'  => $sms['enabled'] && $sms['provider'] === 'aliyun_sms',
            'telegram'    => $on('telegram_enabled'),
        ],
    ]);
}

// ===== action=token：POST id+key 换取一次性登录令牌（再凭 sso.php?token= 登录，避免 api_key 进 URL）=====
if ($action === 'token') {
    $ttl = (int)$param('ttl', '120');
    $t   = sso_issue_token($db, $ttl);
    if (!$t) {
        json_out(['message' => '临时令牌登录未启用，或系统尚无管理员'], 1);
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    json_out([
        'action'     => 'token',
        'token'      => $t['token'],
        'expires_in' => $t['expires_in'],
        'login_url'  => $scheme . '://' . $host . '/admin/api/sso.php?token=' . $t['token'],
    ]);
}

// ---- overview 参数解析 ----
$truthy = static fn ($v) => in_array((string)$v, ['1', 'true', 'yes', 'on'], true);
$falsy  = static fn ($v) => in_array((string)$v, ['0', 'false', 'no', 'off'], true);

$includeHidden = $truthy($_GET['include_hidden'] ?? $_POST['include_hidden'] ?? '0');
$withResource  = !$falsy($_GET['with_resource']  ?? $_POST['with_resource']  ?? '1');
$withHistory   = $truthy($_GET['with_history']   ?? $_POST['with_history']   ?? '0');

$now       = time();
$types     = probe_types();
$retention = max(1, (int)get_setting($db, 'log_retention_days', '7'));
$histHours = min(max(1, (int)get_setting($db, 'front_history_hours', '12')), $retention * 24);
$cutoff    = $now - $histHours * 3600;

// ---- 分类 ----
// include_hidden：取全部分类（含隐藏）；否则仅未隐藏分类
$groups = [];   // gid => row（已按 sort 排序）
$gSql = $includeHidden
    ? 'SELECT * FROM `groups` ORDER BY sort ASC, id ASC'
    : 'SELECT * FROM `groups` WHERE front_hidden = 0 ORDER BY sort ASC, id ASC';
foreach ($db->query($gSql) as $g) {
    $groups[(int)$g['id']] = $g;
}
// 未隐藏分类集合（用于标注节点所属分类是否被隐藏）
$visibleGroups = [];
foreach ($db->query('SELECT id FROM `groups` WHERE front_hidden = 0') as $g) {
    $visibleGroups[(int)$g['id']] = true;
}

// ---- 节点 ----
$sSql = $includeHidden
    ? 'SELECT * FROM servers ORDER BY sort_order ASC, id ASC'
    : 'SELECT * FROM servers WHERE enabled = 1 AND front_hidden = 0 ORDER BY sort_order ASC, id ASC';
$servers = [];
foreach ($db->query($sSql) as $s) {
    $gid = (int)$s['group_id'];
    if (!$includeHidden && $gid > 0 && !isset($visibleGroups[$gid])) {
        continue; // 所属分类被整体隐藏
    }
    $servers[] = $s;
}

// ---- 历史日志：窗口内一次性拉取，按节点 + 小时桶存探测行（可用率含波动扣减）----
$hourly = [];   // sid => [hourBucket => rows[]] + '_rows'
$logs = $db->prepare('SELECT server_id, status, latency, packet_loss, checked_at FROM logs WHERE checked_at >= ?');
$logs->execute([$cutoff]);
foreach ($logs as $r) {
    $sid = (int)$r['server_id'];
    $h   = (int)floor((int)$r['checked_at'] / 3600);
    $row = [(int)$r['status'], $r['latency'] !== null ? (float)$r['latency'] : null, $r['packet_loss'] !== null ? (float)$r['packet_loss'] : null];
    $hourly[$sid][$h][]      = $row;
    $hourly[$sid]['_rows'][] = $row;
}
$hours = [];
for ($i = $histHours - 1; $i >= 0; $i--) {
    $hours[] = (int)floor(($now - $i * 3600) / 3600);
}

// ---- 组装单个节点（后台视角，剔除 username / password 等凭证）----
$upCfg = uptime_scoring_config($db);
$buildServer = static function (array $s) use ($types, $hourly, $hours, $withResource, $withHistory, $groups, $visibleGroups, $upCfg) {
    $sid    = (int)$s['id'];
    $gid    = (int)$s['group_id'];
    $status = $s['last_status'] === null ? null : (int)$s['last_status'];

    $item = [
        'id'              => $sid,
        'name'            => $s['name'],
        'type'            => $s['type'],
        'type_label'      => $types[$s['type']]['label'] ?? strtoupper($s['type']),
        'group_id'        => $gid,
        'group_name'      => $gid > 0 ? ($groups[$gid]['name'] ?? '') : '未分组',
        'address'         => $s['address'],
        'port'            => (int)$s['port'],
        'enabled'         => (int)$s['enabled'] === 1,
        'front_hidden'    => (int)$s['front_hidden'] === 1,
        'group_hidden'    => $gid > 0 && !isset($visibleGroups[$gid]),
        'status'          => $status,
        'online'          => $status === 1,
        'latency'         => $s['last_latency'] !== null ? round((float)$s['last_latency'], 2) : null,
        'ping_latency'    => ($s['type'] ?? '') === 'ssh_ping' && ($s['ping_latency'] ?? null) !== null ? round((float)$s['ping_latency'], 2) : null,
        'packet_loss'     => $s['packet_loss'] !== null ? round((float)$s['packet_loss'], 2) : null,
        'message'         => (string)($s['last_message'] ?? ''),
        'fail_count'      => (int)($s['fail_count'] ?? 0),
        'checked_at'      => (int)$s['last_check_at'],
        'checked_at_text' => (int)$s['last_check_at'] > 0 ? date('Y-m-d H:i:s', (int)$s['last_check_at']) : null,
        'ssl_days'        => $s['type'] === 'http' && $s['ssl_days'] !== null ? (int)$s['ssl_days'] : null,
        'remark'          => (string)($s['remark'] ?? ''),
        'uptime'          => null,
        'avg_latency'     => null,
        'resource'        => null,
        'history'         => null,
    ];

    // 可用率 / 平均延迟（窗口内）
    if (isset($hourly[$sid]['_rows'])) {
        $st = uptime_stats_from_rows($hourly[$sid]['_rows'], $upCfg);
        $item['uptime']      = $st['uptime'];
        $item['avg_latency'] = $st['avg_latency'];
        if ($withHistory) {
            $bars = [];
            foreach ($hours as $h) {
                $label  = date('m-d H:00', $h * 3600);
                $bars[] = isset($hourly[$sid][$h])
                    ? [$label, uptime_stats_from_rows($hourly[$sid][$h], $upCfg)['uptime']]
                    : [$label, null];
            }
            $item['history'] = $bars;
        }
    }

    // 资源占用（仅开启资源监控的 SSH / 宝塔节点）
    if ($withResource && in_array($s['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($s['ssh_monitor'] ?? 0) === 1 && $s['cpu_usage'] !== null) {
        $item['resource'] = [
            'cpu'        => $s['cpu_usage']   !== null ? round((float)$s['cpu_usage'], 1)   : null,
            'mem'        => $s['mem_usage']   !== null ? round((float)$s['mem_usage'], 1)   : null,
            'disk'       => $s['disk_usage']  !== null ? round((float)$s['disk_usage'], 1)  : null,
            'load'       => $s['load_usage']  !== null ? round((float)$s['load_usage'], 2)  : null,
            'net_rx'     => $s['net_rx_rate'] !== null ? (float)$s['net_rx_rate'] : null,
            'net_tx'     => $s['net_tx_rate'] !== null ? (float)$s['net_tx_rate'] : null,
            'disk_mount' => $s['disk_mount'] ?: null,
            'disk_used'  => $s['disk_used']   !== null ? (int)$s['disk_used']  : null,
            'disk_total' => $s['disk_total']  !== null ? (int)$s['disk_total'] : null,
        ];
    }

    return $item;
};

// ---- 按分类聚合 ----
$byGroup = [];   // gid => group bucket
$online = 0;
$offline = 0;
foreach ($servers as $s) {
    $st = $s['last_status'] === null ? null : (int)$s['last_status'];
    if ($st === 1) { $online++; } else { $offline++; }

    $gid = (int)$s['group_id'];
    if (!isset($byGroup[$gid])) {
        $byGroup[$gid] = [
            'id'           => $gid,
            'name'         => $gid > 0 ? ($groups[$gid]['name'] ?? '未分组') : '未分组',
            'front_hidden' => $gid > 0 && (int)($groups[$gid]['front_hidden'] ?? 0) === 1,
            'total'        => 0,
            'online'       => 0,
            'servers'      => [],
        ];
    }
    $item = $buildServer($s);
    $byGroup[$gid]['servers'][] = $item;
    $byGroup[$gid]['total']++;
    if ($item['online']) { $byGroup[$gid]['online']++; }
}

// ---- 分类输出排序：按 groups.sort；include_hidden 时连空分类一并输出 ----
$groupList = [];
foreach ($groups as $gid => $g) {
    if (isset($byGroup[$gid])) {
        $groupList[] = $byGroup[$gid];
    } elseif ($includeHidden) {
        $groupList[] = [
            'id'           => $gid,
            'name'         => $g['name'],
            'front_hidden' => (int)$g['front_hidden'] === 1,
            'total'        => 0,
            'online'       => 0,
            'servers'      => [],
        ];
    }
}
// 孤儿分类（节点指向的分类已不在 groups 中，理论上少见）兜底补齐
foreach ($byGroup as $gid => $bucket) {
    if ($gid !== 0 && !isset($groups[$gid])) {
        $groupList[] = $bucket;
    }
}
// 未分组置底
if (isset($byGroup[0])) {
    $groupList[] = $byGroup[0];
}

json_out([
    'site'           => get_setting($db, 'site_name', 'Catmonitor'),
    'updated'        => $now,
    'updated_text'   => date('Y-m-d H:i:s', $now),
    'include_hidden' => $includeHidden,
    'with_resource'  => $withResource,
    'with_history'   => $withHistory,
    'history_hours'  => $histHours,
    'total'          => count($servers),
    'online'         => $online,
    'offline'        => $offline,
    'group_count'    => count($groupList),
    'groups'         => $groupList,
]);
