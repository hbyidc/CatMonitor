<?php
/**
 * 节点网络质量详情接口（需登录）
 *   GET id    节点 ID
 *   GET range 时间范围：1h / 6h / 24h / 7d（默认 24h）
 * 返回：节点信息、可用率条（最近 50 次探测）、统计卡（含网络质量打分）、延迟/丢包时序
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();

$id = max(0, (int)($_GET['id'] ?? 0));
$st = $db->prepare('SELECT id, name, type, address, port, enabled FROM servers WHERE id = ?');
$st->execute([$id]);
$s = $st->fetch();
if (!$s) {
    json_out(['message' => '节点不存在'], 1);
}

$rangeMap = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];
$range = (string)($_GET['range'] ?? '24h');
if (!isset($rangeMap[$range])) {
    $range = '24h';
}
$span = $rangeMap[$range];
$now  = time();

/* ---------------- 时序数据（图表用） ---------------- */
$q = $db->prepare('SELECT status, latency, packet_loss, checked_at FROM logs WHERE server_id = ? AND checked_at >= ? ORDER BY checked_at ASC');
$q->execute([$id, $now - $span]);
$points = $q->fetchAll();

$fmt = $range === '7d' ? 'm-d H:i' : 'H:i';

$labels = [];
$lat    = [];
$loss   = [];
if (count($points) > 360) {
    // 点数过多时按时间桶聚合（桶内取平均），控制图表渲染压力
    $bucketSize = (int)ceil($span / 360);
    $buckets = [];
    foreach ($points as $p) {
        $b = (int)floor((int)$p['checked_at'] / $bucketSize);
        if (!isset($buckets[$b])) {
            $buckets[$b] = ['t' => $b * $bucketSize, 'lat' => 0.0, 'latn' => 0, 'loss' => 0.0, 'lossn' => 0];
        }
        if ($p['latency'] !== null) {
            $buckets[$b]['lat'] += (float)$p['latency'];
            $buckets[$b]['latn']++;
        }
        if ($p['packet_loss'] !== null) {
            $buckets[$b]['loss'] += (float)$p['packet_loss'];
            $buckets[$b]['lossn']++;
        }
    }
    ksort($buckets);
    foreach ($buckets as $b) {
        $labels[] = date($fmt, $b['t']);
        $lat[]    = $b['latn'] > 0 ? round($b['lat'] / $b['latn'], 2) : null;
        $loss[]   = $b['lossn'] > 0 ? round($b['loss'] / $b['lossn'], 2) : null;
    }
} else {
    foreach ($points as $p) {
        $labels[] = date($fmt, (int)$p['checked_at']);
        $lat[]    = $p['latency'] !== null ? round((float)$p['latency'], 2) : null;
        $loss[]   = $p['packet_loss'] !== null ? round((float)$p['packet_loss'], 2) : null;
    }
}

/* ---------------- 可用率条（最近 50 次探测，旧 → 新） ---------------- */
$q = $db->prepare('SELECT status, latency, packet_loss, checked_at FROM logs WHERE server_id = ? ORDER BY checked_at DESC LIMIT 50');
$q->execute([$id]);
$bars = [];
foreach ($q->fetchAll() as $p) {
    $bars[] = [
        't'    => date('m-d H:i:s', (int)$p['checked_at']),
        'ok'   => (int)$p['status'] === 1,
        'lat'  => $p['latency'] !== null ? round((float)$p['latency'], 2) : null,
        'loss' => $p['packet_loss'] !== null ? round((float)$p['packet_loss'], 1) : null,
    ];
}
$bars = array_reverse($bars);

/* ---------------- 统计（跟随所选档位，波动扣减后可用率，松紧/权重后台可设） ---------------- */
$upCfg = uptime_scoring_config($db);
$stat = function (int $since) use ($db, $id, $upCfg): array {
    $q = $db->prepare('SELECT status, latency, packet_loss FROM logs WHERE server_id = ? AND checked_at >= ?');
    $q->execute([$id, $since]);
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $rows[] = [(int)$r['status'], $r['latency'] !== null ? (float)$r['latency'] : null, $r['packet_loss'] !== null ? (float)$r['packet_loss'] : null];
    }
    return uptime_stats_from_rows($rows, $upCfg);
};

$sCur = $stat($now - $span);
$rangeLabels = ['1h' => '1 小时', '6h' => '6 小时', '24h' => '24 小时', '7d' => '7 天'];

// 最近一次探测
$last = null;
$q = $db->prepare('SELECT status, latency, packet_loss, message, checked_at FROM logs WHERE server_id = ? ORDER BY checked_at DESC LIMIT 1');
$q->execute([$id]);
if ($r = $q->fetch()) {
    $last = [
        'ok'    => (int)$r['status'] === 1,
        'lat'   => $r['latency'] !== null ? round((float)$r['latency'], 2) : null,
        'loss'  => $r['packet_loss'] !== null ? round((float)$r['packet_loss'], 1) : null,
        'msg'   => (string)$r['message'],
        't'     => date('Y-m-d H:i:s', (int)$r['checked_at']),
    ];
}

json_out([
    'node' => [
        'id'    => (int)$s['id'],
        'name'  => $s['name'],
        'type'  => $s['type'],
        'target'=> $s['address'] . ((int)$s['port'] > 0 && $s['type'] !== 'http' ? ':' . (int)$s['port'] : ''),
    ],
    'range'  => $range,
    'series' => ['labels' => $labels, 'latency' => $lat, 'loss' => $loss],
    'bars'   => $bars,
    'stats'  => [
        'last'        => $last,
        'cur'         => $sCur,
        'range_label' => $rangeLabels[$range],
        'up_t'        => [$upCfg['yellow'], $upCfg['red']],
    ],
]);
