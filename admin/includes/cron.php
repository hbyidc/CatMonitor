<?php
/**
 * 定时探测核心逻辑（CLI 脚本与 URL 触发共用）
 */

declare(strict_types=1);

class CronService
{
    /**
     * 执行一轮探测 + 清理超期日志
     *
     * @return array{checked:int, cleaned:int, lines:string[]}
     */
    public static function run(PDO $db): array
    {
        $now     = time();
        $servers = $db->query('SELECT * FROM servers WHERE enabled = 1 ORDER BY id ASC')->fetchAll();
        $lines   = [];

        $checked = 0;
        foreach ($servers as $server) {
            $interval = max(10, (int)$server['check_interval']);
            if ((int)$server['last_check_at'] + $interval > $now) {
                continue; // 未到检测时间
            }
            $result = ProbeService::checkServer($db, $server);
            $checked++;
            $lines[] = sprintf(
                "[%s] #%d %s(%s) => %s %.2fms %s",
                date('H:i:s'),
                $server['id'],
                $server['name'],
                strtoupper($server['type']),
                $result['status'] ? 'ONLINE ' : 'OFFLINE',
                $result['latency'],
                $result['message']
            );
        }

        // 清理超期日志
        $retention = max(1, (int)get_setting($db, 'log_retention_days', '7'));
        $del = $db->prepare('DELETE FROM logs WHERE checked_at < ?');
        $del->execute([$now - $retention * 86400]);

        return [
            'checked' => $checked,
            'cleaned' => $del->rowCount(),
            'lines'   => $lines,
        ];
    }

    /** 获取/生成 URL 触发密钥 */
    public static function cronKey(PDO $db, bool $regenerate = false): string
    {
        $key = get_setting($db, 'cron_key');
        if ($key === '' || $regenerate) {
            $key = bin2hex(random_bytes(16));
            set_setting($db, 'cron_key', $key);
        }

        return $key;
    }
}
