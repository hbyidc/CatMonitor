<?php
/**
 * 前台状态页公开数据接口（免登录）
 * 仅输出前台可见节点（排除停用、节点级隐藏、分类级隐藏），不含任何敏感信息
 */

require dirname(__DIR__) . '/bootstrap.php';

header('Cache-Control: public, max-age=20');

// 前台总开关
if (get_setting($db, 'front_enabled', '1') !== '1') {
    json_out(['message' => '前台状态页已关闭'], 1);
}

$showResource = get_setting($db, 'front_show_resource', '1') === '1';
$showHistory  = get_setting($db, 'front_show_history', '1') === '1';
$ssppingPingOnly = get_setting($db, 'sspping_ping_only', '0') === '1';
$upCfg = uptime_scoring_config($db);
$retention    = max(1, (int)get_setting($db, 'log_retention_days', '7'));
$historyHours = min(max(1, (int)get_setting($db, 'front_history_hours', '12')), $retention * 24);

$now    = time();
$cutoff = $now - $historyHours * 3600;
$types  = probe_types();

// 可见分类（未隐藏）
$visibleGroups = [];
foreach ($db->query('SELECT * FROM `groups` WHERE front_hidden = 0 ORDER BY sort ASC, id ASC') as $g) {
    $visibleGroups[(int)$g['id']] = $g;
}

// 可见节点：启用 + 未隐藏 + 所属分类未隐藏（未分类节点正常展示）
$servers = [];
foreach ($db->query('SELECT * FROM servers WHERE enabled = 1 AND front_hidden = 0 ORDER BY sort_order ASC, id ASC') as $s) {
    $gid = (int)$s['group_id'];
    if ($gid > 0 && !isset($visibleGroups[$gid])) {
        continue; // 分类被整体隐藏
    }
    $servers[] = $s;
}

// 历史可用率：一次性拉取窗口内日志，按节点 + 小时桶存探测行（可用率含波动扣减）
$hourly = [];   // server_id => [hourBucket => rows[]] + '_rows'
$logs  = $db->prepare('SELECT server_id, status, latency, packet_loss, checked_at FROM logs WHERE checked_at >= ?');
$logs->execute([$cutoff]);
foreach ($logs as $r) {
    $sid = (int)$r['server_id'];
    $h   = (int)floor((int)$r['checked_at'] / 3600);
    $row = [(int)$r['status'], $r['latency'] !== null ? (float)$r['latency'] : null, $r['packet_loss'] !== null ? (float)$r['packet_loss'] : null];
    $hourly[$sid][$h][]    = $row;
    $hourly[$sid]['_rows'][] = $row;
}

// 小时轴（旧 → 新）
$hours = [];
for ($i = $historyHours - 1; $i >= 0; $i--) {
    $hours[] = (int)floor(($now - $i * 3600) / 3600);
}

$buildServer = function (array $s) use ($types, $hourly, $hours, $showResource, $showHistory, $ssppingPingOnly, $upCfg) {
    $sid  = (int)$s['id'];
    // SSH+PING 节点：仅展示 PING 模式时 latency 直接替换为 PING 延迟，否则额外输出 ping_latency 供前台双延迟展示
    $isSshPing = $s['type'] === 'ssh_ping';
    $pingLat   = ($isSshPing && $s['ping_latency'] !== null) ? round((float)$s['ping_latency'], 2) : null;
    $latency   = $s['last_latency'] !== null ? round((float)$s['last_latency'], 2) : null;
    if ($isSshPing && $ssppingPingOnly) {
        $latency = $pingLat;
        $pingLat = null;
    }
    $item = [
        'id'        => $sid,
        'name'      => $s['name'],
        'type'      => $s['type'],
        'type_label'=> $types[$s['type']]['label'] ?? strtoupper($s['type']),
        'status'    => $s['last_status'] === null ? null : (int)$s['last_status'],
        'latency'   => $latency,
        'ping_latency' => $pingLat,
        'checked_at'=> (int)$s['last_check_at'],
        'uptime'    => null,
        'avg_latency' => null,
        'history'   => null,
        'resource'  => null,
        'ssl_days'  => $s['type'] === 'http' ? $s['ssl_days'] : null,
    ];

    if ($showHistory && isset($hourly[$sid]['_rows'])) {
        $st = uptime_stats_from_rows($hourly[$sid]['_rows'], $upCfg);
        $item['uptime']      = $st['uptime'];
        $item['avg_latency'] = $st['avg_latency'];
        $bars = [];
        foreach ($hours as $h) {
            $label = date('m-d H:00', $h * 3600);
            $bars[] = isset($hourly[$sid][$h])
                ? [$label, uptime_stats_from_rows($hourly[$sid][$h], $upCfg)['uptime']]
                : [$label, null];
        }
        $item['history'] = $bars;
    }

    if ($showResource && in_array($s['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($s['ssh_monitor'] ?? 0) === 1 && $s['cpu_usage'] !== null) {
        $item['resource'] = [
            'cpu'   => $s['cpu_usage'] !== null ? round((float)$s['cpu_usage'], 1) : null,
            'mem'   => $s['mem_usage'] !== null ? round((float)$s['mem_usage'], 1) : null,
            'disk'  => $s['disk_usage'] !== null ? round((float)$s['disk_usage'], 1) : null,
            'load'  => $s['load_usage'] !== null ? round((float)$s['load_usage'], 2) : null,
            'net_rx'=> $s['net_rx_rate'] !== null ? (float)$s['net_rx_rate'] : null,
            'net_tx'=> $s['net_tx_rate'] !== null ? (float)$s['net_tx_rate'] : null,
        ];
    }

    return $item;
};

// 按分类组装（未分类节点归入「未分组」）
$out = [];
$online = 0;
foreach ($servers as $s) {
    if ((int)$s['last_status'] === 1) {
        $online++;
    }
    $gid = (int)$s['group_id'];
    $key = $gid > 0 ? $gid : 0;
    if (!isset($out[$key])) {
        $out[$key] = [
            'id'   => $gid,
            'name' => $gid > 0 ? $visibleGroups[$gid]['name'] : '未分组',
            'servers' => [],
        ];
    }
    $out[$key]['servers'][] = $buildServer($s);
}

json_out([
    'site'         => get_setting($db, 'site_name', 'Catmonitor'),
    'updated'      => $now,
    'total'        => count($servers),
    'online'       => $online,
    'show_resource'=> $showResource,
    'show_history' => $showHistory,
    'uptime_yellow'=> $upCfg['yellow'],
    'uptime_red'   => $upCfg['red'],
    'history_hours'=> $historyHours,
    'groups'       => array_values($out),
]);
