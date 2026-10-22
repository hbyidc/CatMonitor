<?php
/**
 * 在线更新 AJAX 接口（均需登录）
 *
 *   action=status   当前版本 + 授权状态（供页面初始化）
 *   action=query    授权查询（只读，快速判断是否正版）
 *   action=verify   用已保存授权码做完整验证并缓存 token
 *   action=check    检查可用更新（返回版本列表）
 *   action=upgrade  升级单个版本（version 必填，逐版本调用，一次性返回汇总 JSON）
 *   action=upgrade_stream  同 upgrade，但以 SSE 流式「实时」推送每一步进度与失败完整明细
 *
 * 约定：HTTP 传输层统一 json_out（code=0）；业务成败看返回体中的 ok 字段。
 * upgrade_stream 例外：Content-Type 为 text/event-stream，每条事件形如 data:{json}\n\n。
 * 授权 / 网络失败只返回 ok=false，绝不影响系统其它功能。
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
if (!in_array($action, ['status', 'query'], true)) {
    require_post_request(true);
}

switch ($action) {
    case 'status':
        json_out(UpdateService::status($db));
        break;

    case 'query':
        $res = UpdateService::query($db);
        json_out([
            'ok'   => $res['code'] === 1,
            'msg'  => $res['msg'] !== '' ? $res['msg'] : ($res['code'] === 1 ? '正版授权' : '授权异常'),
            'data' => $res['data'],
        ]);
        break;

    case 'verify':
        // 允许表单临时携带授权码（先保存再验证）；否则用已保存的授权码
        $posted = trim((string)($_POST['license_key'] ?? ''));
        if ($posted !== '') {
            set_setting($db, 'license_key', $posted);
        }
        $tk = UpdateService::token($db, true);
        json_out([
            'ok'   => $tk['ok'],
            'msg'  => $tk['ok'] ? ($tk['msg'] !== '' ? $tk['msg'] : '授权验证通过') : $tk['msg'],
            'data' => $tk['data'],
        ]);
        break;

    case 'check':
        // token 可选：无授权时也尝试检查（升级阶段才会强制校验授权）
        $tk    = UpdateService::token($db);
        $token = $tk['ok'] ? $tk['token'] : '';
        $res   = UpdateService::checkUpdate($db, $token);
        if ($res['code'] !== 1) {
            json_out([
                'ok'         => false,
                'authorized' => $tk['ok'],
                'auth_msg'   => $tk['ok'] ? '' : $tk['msg'],
                'msg'        => $res['msg'] !== '' ? $res['msg'] : '检查更新失败',
                'data'       => $res['data'],
            ]);
        }
        json_out([
            'ok'              => true,
            'authorized'      => $tk['ok'],
            'auth_msg'        => $tk['ok'] ? '' : $tk['msg'],
            'msg'             => $res['msg'],
            'has_update'      => (bool)($res['data']['has_update'] ?? false),
            'versions'        => is_array($res['data']['versions'] ?? null) ? $res['data']['versions'] : [],
            'service_expired' => (bool)($res['data']['service_expired'] ?? false),
            'current_version' => UpdateService::currentVersion($db),
        ]);
        break;

    case 'upgrade':
        $version = trim((string)($_POST['version'] ?? ($_GET['version'] ?? '')));
        if ($version === '') {
            json_out(['ok' => false, 'msg' => '缺少目标版本号']);
        }
        $type = ((string)($_POST['type'] ?? 'update')) === 'full' ? 'full' : 'update';
        // 升级可能耗时较长（下载 + 解压 + SQL），放宽执行时限
        @set_time_limit(600);
        $res = UpdateService::applyUpgrade($db, $version, $type);
        json_out($res);
        break;

    case 'upgrade_stream':
        // 实时流式升级（SSE）：把每一步进度与失败完整明细逐条推送给前端展示
        $version = trim((string)($_POST['version'] ?? ($_GET['version'] ?? '')));
        $type    = ((string)($_POST['type'] ?? 'update')) === 'full' ? 'full' : 'update';

        // 关闭错误直显，避免中途 PHP 警告混入 SSE 流导致前端解析失败（错误一律改由流事件承载）
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        // 丢弃 bootstrap 的输出缓冲与此前任何杂散输出，改为逐块即时刷出
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no'); // 关闭 nginx 代理缓冲，保证实时推送
            header('Connection: keep-alive');
        }
        $sse = static function (array $ev): void {
            echo 'data: ' . json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        };
        // 首包填充 2KB，击穿部分代理 / 网关的初始缓冲，确保后续事件即时到达
        echo str_repeat(' ', 2048) . "\n";
        @flush();

        // 致命错误（不可被 catch）也尽量以事件回传完整报错，而非静默断流
        register_shutdown_function(static function () use ($sse): void {
            $e = error_get_last();
            if ($e && in_array((int)$e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $sse(['type' => 'error', 'msg' => '致命错误：' . $e['message'], 'detail' => ['stage' => 'fatal', 'file' => $e['file'] . ':' . $e['line']], 'backup' => '']);
                $sse(['type' => 'done', 'ok' => false, 'version' => '', 'msg' => '致命错误：' . $e['message'], 'backup' => '', 'detail' => ['stage' => 'fatal']]);
                $sse(['type' => 'end']);
            }
        });

        // 释放会话锁：升级为长请求，避免阻塞页面其它 AJAX（登录鉴权已在文件顶部完成）
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(600);

        if (!$db instanceof PDO) {
            $sse(['type' => 'error', 'msg' => '数据库未就绪，无法升级', 'detail' => ['stage' => 'db'], 'backup' => '']);
            $sse(['type' => 'done', 'ok' => false, 'version' => '', 'msg' => '数据库未就绪，无法升级', 'backup' => '', 'detail' => ['stage' => 'db']]);
            $sse(['type' => 'end']);
            exit;
        }
        if ($version === '') {
            $sse(['type' => 'error', 'msg' => '缺少目标版本号', 'detail' => [], 'backup' => '']);
            $sse(['type' => 'done', 'ok' => false, 'version' => '', 'msg' => '缺少目标版本号', 'backup' => '', 'detail' => []]);
            $sse(['type' => 'end']);
            exit;
        }

        // 传入 $sse 作为 emit 回调：applyUpgrade 内部每一步 / 每次失败都会即时推送
        $res = UpdateService::applyUpgrade($db, $version, $type, $sse);
        $sse([
            'type'    => 'done',
            'ok'      => (bool)($res['ok'] ?? false),
            'version' => (string)($res['version'] ?? $version),
            'msg'     => (string)($res['msg'] ?? ''),
            'backup'  => (string)($res['backup'] ?? ''),
            'detail'  => is_array($res['detail'] ?? null) ? $res['detail'] : [],
        ]);
        $sse(['type' => 'end']);
        exit;

    default:
        json_out(['ok' => false, 'msg' => '未知操作'], 400);
}
