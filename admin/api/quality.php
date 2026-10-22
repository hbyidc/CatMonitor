<?php
/**
 * 节点质量历史接口（需登录）：逐小时可用率色块，与前台质量历史同款数据
 *   GET sid    节点 ID
 *   GET hours  回溯小时数，默认 24（1~168）
 * 返回：bars=[[label, uptime|null]…]、uptime、avg_latency、up_t=[黄阈值, 红阈值]
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();

$sid   = max(0, (int)($_GET['sid'] ?? 0));
$hours = min(168, max(1, (int)($_GET['hours'] ?? 24)));

$st = $db->prepare('SELECT id FROM servers WHERE id = ?');
$st->execute([$sid]);
if (!$st->fetch()) {
    json_out(['message' => '节点不存在'], 1);
}

$now    = time();
$cutoff = $now - $hours * 3600;
$q = $db->prepare('SELECT status, latency, packet_loss, checked_at FROM logs WHERE server_id = ? AND checked_at >= ? ORDER BY checked_at ASC');
$q->execute([$sid, $cutoff]);

// 按小时桶分组（与前台开放接口一致的聚合口径）
$hourly = [];
$all    = [];
foreach ($q as $r) {
    $h   = (int)floor((int)$r['checked_at'] / 3600);
    $row = [(int)$r['status'], $r['latency'] !== null ? (float)$r['latency'] : null, $r['packet_loss'] !== null ? (float)$r['packet_loss'] : null];
    $hourly[$h][] = $row;
    $all[]        = $row;
}

$upCfg = uptime_scoring_config($db);
$bars  = [];
for ($i = $hours - 1; $i >= 0; $i--) {
    $h     = (int)floor(($now - $i * 3600) / 3600);
    $label = date('m-d H:00', $h * 3600);
    $bars[] = isset($hourly[$h])
        ? [$label, uptime_stats_from_rows($hourly[$h], $upCfg)['uptime']]
        : [$label, null];
}

$stat = $all ? uptime_stats_from_rows($all, $upCfg) : null;

json_out([
    'sid'         => $sid,
    'hours'       => $hours,
    'bars'        => $bars,
    'uptime'      => $stat['uptime'] ?? null,
    'avg_latency' => $stat['avg_latency'] ?? null,
    'up_t'        => [$upCfg['yellow'], $upCfg['red']],
]);
