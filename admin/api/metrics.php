<?php
/**
 * 长期监控历史趋势接口
 *   GET server_id=4&range=24h → 返回时间序列数据（降采样至约 300 点）
 *   range: 1h / 12h / 24h / 3d / 7d / 30d
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();

$id    = (int)($_GET['server_id'] ?? 0);
$ranges = ['1h' => 3600, '12h' => 43200, '24h' => 86400, '3d' => 259200, '7d' => 604800, '30d' => 2592000];
$range = (string)($_GET['range'] ?? '24h');
$sec   = $ranges[$range] ?? 86400;
// 自定义时间窗口（缩放/拖选用，优先于 range），限定在保留期内
$start = (int)($_GET['start'] ?? 0);
$end   = (int)($_GET['end'] ?? 0);

$stmt = $db->prepare('SELECT id, name, address, type, metrics_retain_days, cpu_usage, mem_usage, disk_usage, load_usage, mem_used, mem_total, disk_used, disk_total, disk_mount, disks_json, net_rx_rate, net_tx_rate, last_status, last_check_at FROM servers WHERE id = ?');
$stmt->execute([$id]);
$server = $stmt->fetch();
if (!$server || !in_array($server['type'], ['ssh', 'ssh_ping', 'bt'], true)) {
    json_out(['ok' => false, 'message' => '节点不存在或非 SSH/宝塔面板类型'], 1);
}

$now = time();
if ($start > 0 && $end > $start && $end - $start >= 600) {
    $start = max($start, $now - (int)$server['metrics_retain_days'] * 86400);
    $end   = min($end, $now + 60);
    $range = 'custom';
} else {
    $start = $now - $sec;
    $end   = $now;
}

$rows = $db->prepare('SELECT checked_at, cpu, mem, disk, `load`, net_rx, net_tx, disks_json FROM server_metrics
                      WHERE server_id = ? AND checked_at >= ? AND checked_at <= ? ORDER BY checked_at ASC');
$rows->execute([$id, $start, $end]);
$rows = $rows->fetchAll();

// 降采样：超过 300 点时按时间桶取平均，保证图表流畅
$points = [];
$count  = count($rows);
if ($count > 300) {
    $bucketSize = (int)ceil($count / 300);
    foreach (array_chunk($rows, $bucketSize) as $chunk) {
        $t = $cpu = $mem = $disk = $load = $rx = $tx = 0;
        $n = 0;
        foreach ($chunk as $r) {
            $t += (int)$r['checked_at'];
            $cpu += (float)$r['cpu'];
            $mem += (float)$r['mem'];
            $disk += (float)$r['disk'];
            $load += (float)($r['load'] ?? 0);
            $rx += (float)($r['net_rx'] ?? 0);
            $tx += (float)($r['net_tx'] ?? 0);
            $n++;
        }
        $points[] = [
            (int)round($t / $n),
            round($cpu / $n, 1), round($mem / $n, 1), round($disk / $n, 1), round($load / $n, 2),
            (int)round($rx / $n), (int)round($tx / $n),
        ];
    }
} else {
    foreach ($rows as $r) {
        $points[] = [(int)$r['checked_at'], round((float)$r['cpu'], 1), round((float)$r['mem'], 1), round((float)$r['disk'], 1), round((float)($r['load'] ?? 0), 2), (int)($r['net_rx'] ?? 0), (int)($r['net_tx'] ?? 0)];
    }
}

// 第 8 项为本时间桶的「挂载点 => 使用率」映射，前 7 项保持兼容。
$diskBuckets = disk_history_buckets($rows, max(1, (int)ceil($count / 300)));
foreach ($points as $i => &$point) $point[] = $diskBuckets[$i];
unset($point);

json_out([
    'ok'      => true,
    'name'    => $server['name'],
    'address' => $server['address'],
    'retain'  => (int)$server['metrics_retain_days'],
    'range'   => $range,
    'start'   => (int)$start,
    'end'     => (int)$end,
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
        'disk_used'  => $server['disk_used'], 'disk_total' => $server['disk_total'],
        'disk_mount' => $server['disk_mount'],
        'disks'      => resource_disks($server),
        'checked_at' => (int)$server['last_check_at'],
    ],
]);
