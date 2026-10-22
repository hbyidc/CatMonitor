<?php
/**
 * 定时探测脚本（CLI）
 *
 * 建议每分钟执行一次，宝塔计划任务示例（Shell 脚本类型）：
 *   /www/server/php/81/bin/php /www/wwwroot/你的站点目录/admin/cron/probe.php
 *   （81 换成站点实际使用的 PHP 版本，如 PHP 8.4 为 /www/server/php/84/bin/php）
 *
 * 也可以使用宝塔「访问URL」类型计划任务，请求 admin/api/cron.php?key=密钥，
 * 两者共用同一把文件锁，不会重复执行。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('本脚本仅允许命令行执行');
}

require dirname(__DIR__) . '/bootstrap.php';

// 文件锁防止并发重复执行
$lockFile = ADMIN_ROOT . '/data/cron.lock';
$lockFp   = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " 上一次探测尚未结束，跳过本次执行\n";
    exit(0);
}

try {
    $r = CronService::run($db);

    foreach ($r['lines'] as $line) {
        echo $line, "\n";
    }
    echo sprintf(
        "%s 完成：探测 %d 个节点，清理 %d 条过期日志\n",
        date('Y-m-d H:i:s'),
        $r['checked'],
        $r['cleaned']
    );
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
