<?php
/**
 * 定时探测 URL 触发接口（无需登录，凭密钥访问）
 *
 * 宝塔计划任务选择「访问URL」，每分钟请求：
 *   https://域名/admin/api/cron.php?key=密钥
 *
 * 密钥在 系统设置 → 运行环境 中查看，可重新生成。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

// 密钥校验（恒定时间比较，防时序攻击）
$key     = (string)($_GET['key'] ?? '');
$validKey = CronService::cronKey($db);
if ($key === '' || !hash_equals($validKey, $key)) {
    http_response_code(403);
    exit('密钥无效');
}

// 探测可能耗时较长，避免被 PHP/Nginx 超时中断
@set_time_limit(300);
ignore_user_abort(true);

// 文件锁防止并发重复执行（与 CLI 脚本共用同一把锁）
$lockFile = ADMIN_ROOT . '/data/cron.lock';
$lockFp   = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    http_response_code(200);
    exit(date('Y-m-d H:i:s') . " 上一次探测尚未结束，跳过本次执行");
}

try {
    $r = CronService::run($db);

    foreach ($r['lines'] as $line) {
        echo $line, "\n";
    }
    echo sprintf(
        "%s 完成：探测 %d 个节点，清理 %d 条过期日志",
        date('Y-m-d H:i:s'),
        $r['checked'],
        $r['cleaned']
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo '执行出错：' . $e->getMessage();
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
