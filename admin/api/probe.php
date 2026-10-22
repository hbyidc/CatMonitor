<?php
/**
 * 探测 AJAX 接口
 *   action=status    获取所有节点当前状态
 *   action=check     立即探测单个节点（server_id）
 *   action=check_all 立即探测全部启用节点
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($action !== 'status') {
    require_post_request(true);
}
[$healthWarn, $healthDanger, $healthLossDanger] = health_thresholds($db);
$ssppingPingOnly = get_setting($db, 'sspping_ping_only', '0') === '1';
$upCfg = uptime_scoring_config($db);

switch ($action) {
    case 'status':
        $rows = $db->query('SELECT id, name, type, last_status, last_latency, ping_latency, last_check_at, ssh_monitor, cpu_usage, mem_usage, mem_used, mem_total, swap_used, swap_total, disk_usage, disk_mount, disk_used, disk_total, disks_json, net_rx_rate, net_tx_rate, packet_loss, load_usage, load_history FROM servers ORDER BY id ASC')->fetchAll();
        // 12h 波动扣减后可用率（一次拉取窗口日志，按节点计算）
        $rowsByServer = [];
        foreach ($db->query('SELECT server_id, status, latency, packet_loss FROM logs WHERE checked_at >= ' . (time() - 43200)) as $qr) {
            $rowsByServer[(int)$qr['server_id']][] = [(int)$qr['status'], $qr['latency'] !== null ? (float)$qr['latency'] : null, $qr['packet_loss'] !== null ? (float)$qr['packet_loss'] : null];
        }
        foreach ($rows as &$r) {
            $r['last_check_at'] = $r['last_check_at'] ? date('Y-m-d H:i:s', (int)$r['last_check_at']) : '';
            $r['health'] = server_health($r, $healthWarn, $healthDanger, $healthLossDanger);
            $r['disks'] = resource_disks($r);
            unset($r['disks_json']);
            $st = isset($rowsByServer[(int)$r['id']]) ? uptime_stats_from_rows($rowsByServer[(int)$r['id']], $upCfg) : null;
            $r['uptime24']       = $st['uptime'] ?? null;
            $r['avg_latency24']  = $st['avg_latency'] ?? null;
            $r['latency_text']   = node_latency_text($r, $ssppingPingOnly);
        }
        unset($r);
        json_out(['list' => $rows]);

    case 'check':
        $id   = (int)($_POST['server_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            json_out(['message' => '节点不存在'], 1);
        }

        // 快速采集可能耗时较久（SSH 登录 + 1 秒采样 / 宝塔多次 API 请求）
        set_time_limit(max(60, (int)$server['timeout'] + 30));
        $t0 = microtime(true);
        try {
            $result = ProbeService::checkServer($db, $server);
        } catch (Throwable $e) {
            // 采集过程抛出的异常原文直接回传前端展示，便于排查配置问题
            $result = ['status' => false, 'latency' => 0.0, 'message' => '采集异常：' . $e->getMessage()];
        }
        $duration = (int)round((microtime(true) - $t0) * 1000);
        // 重新读取节点行（探测已落库），计算最新健康状态
        $stmt->execute([$id]);
        $after = $stmt->fetch();
        json_out([
            'status'     => $result['status'] ? 1 : 0,
            'latency'    => round($result['latency'], 2),
            'ping_latency' => $result['ping_latency'] ?? null,
            'latency_text' => node_latency_text([
                'type' => $server['type'],
                'last_latency' => $result['latency'],
                'ping_latency' => $result['ping_latency'] ?? null,
            ], $ssppingPingOnly),
            'message'    => $result['message'],
            'checked_at' => date('Y-m-d H:i:s'),
            'duration'   => $duration,
            'resource'   => $result['resource'] ?? null,
            'loss'       => $result['loss'] ?? null,
            'ssl_days'   => $result['ssl_days'] ?? null,
            'name'       => $server['name'],
            'type'       => $server['type'],
            'address'    => $server['address'],
            'port'       => (int)$server['port'],
            'health'     => server_health($after ?: $server, $healthWarn, $healthDanger, $healthLossDanger),
        ]);

    case 'check_all':
        $servers = $db->query('SELECT * FROM servers WHERE enabled = 1 ORDER BY id ASC')->fetchAll();
        set_time_limit(max(120, count($servers) * 15));

        $checked = 0;
        foreach ($servers as $server) {
            ProbeService::checkServer($db, $server);
            $checked++;
        }
        json_out(['message' => "完成 {$checked} 个节点探测", 'checked' => $checked]);

    default:
        json_out(['message' => '未知操作'], 1);
}
