<?php
/**
 * 引导文件：加载配置 / 公共库 / 初始化 Session 与数据库
 * 所有页面入口 require 本文件即可
 */

declare(strict_types=1);

// 生产环境建议改为：error_reporting(E_ALL); ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ADMIN_ROOT', __DIR__); // admin 目录

// 版本 / 授权端点常量（仅定义常量，不做任何授权检测，检测失败也不影响系统运行）
require ADMIN_ROOT . '/version.php';

// 输出缓冲：任何 PHP 警告/Notice 先入缓冲区，避免直接混入 JSON 接口响应导致前端 parsererror
// （json_out 会在输出前清空缓冲；页面请求则由 PHP 在脚本结束时自动刷出）
if (PHP_SAPI !== 'cli' && ob_get_level() === 0) {
    ob_start();
}

// ---- 配置：缺失时视为「未安装」，由安装向导生成 config.php（不致命退出）----
$configFile = ADMIN_ROOT . '/config.php';
$configured = is_file($configFile);
$config     = $configured
    ? require $configFile
    : [
        'db'                 => ['type' => 'sqlite', 'sqlite_path' => ADMIN_ROOT . '/data/probe.db'],
        'session_name'       => 'probe_admin_sid',
        'log_retention_days' => 7,
    ];
if (!$configured && !defined('PROBE_UNCONFIGURED')) {
    define('PROBE_UNCONFIGURED', true);
}

require ADMIN_ROOT . '/includes/functions.php';
require ADMIN_ROOT . '/includes/db.php';
require ADMIN_ROOT . '/includes/auth.php';
require ADMIN_ROOT . '/includes/probe.php';
require ADMIN_ROOT . '/includes/alert.php';
require ADMIN_ROOT . '/includes/cron.php';
require ADMIN_ROOT . '/includes/group.php';
require ADMIN_ROOT . '/includes/update.php'; // 在线更新 / 授权服务（仅加载类，bootstrap 不触发任何检测）

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // 会话保持 7 天：服务端存活时长与浏览器 Cookie 同步，每次访问自动续期
    $sessionLifetime = 7 * 86400;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_name($config['session_name']);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- 数据库连接：未配置或连接失败都不致命退出，统一交给安装向导处理 ----
$db      = null;
$dbError = '';
if ($configured) {
    try {
        $db = Db::connect($config['db']);
    } catch (PDOException $ex) {
        $dbError = $ex->getMessage();
    }
}

// 未配置 / 连接失败 / 未安装 → 强制进入安装向导（install.php 自身定义 PROBE_SKIP_INSTALL_CHECK 跳过）
if (!defined('PROBE_SKIP_INSTALL_CHECK')
    && (!$configured || $db === null || !Db::isInstalled($db))
) {
    if (PHP_SAPI === 'cli') {
        exit("系统尚未安装，请先访问 /admin/install.php 完成安装\n");
    }
    // 计算安装向导的绝对 URL（前台首页、后台各页、子目录 API 均能正确跳转）
    $docRoot   = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
    $adminRoot = str_replace('\\', '/', ADMIN_ROOT);
    $adminUrl  = ($docRoot !== '' && str_starts_with($adminRoot, $docRoot))
        ? rtrim(substr($adminRoot, strlen($docRoot)), '/')
        : '/admin';
    if ($adminUrl === '') {
        $adminUrl = '/admin';
    }
    header('Location: ' . $adminUrl . '/install.php');
    exit;
}

date_default_timezone_set('Asia/Shanghai');

// 预警模块自动建表/补列（兼容旧版本升级）；未配置/未连接时跳过
if ($db !== null && Db::isInstalled($db)) {
    AlertService::ensureSchema($db);
    GroupService::ensureSchema($db);
}
