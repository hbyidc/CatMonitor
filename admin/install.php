<?php
/**
 * 安装向导（四步）：环境检查 → 数据库配置 → 管理员账号 → 完成
 *
 * 首次访问任意页面（前台/后台）都会被 bootstrap 强制跳转到本向导；
 * 未生成 config.php 或未建表前，系统不可用。
 */

declare(strict_types=1);

define('PROBE_SKIP_INSTALL_CHECK', true);

require __DIR__ . '/bootstrap.php';
csrf_check_request();
header('Cache-Control: no-store');

$configFile = ADMIN_ROOT . '/config.php';
// $configured / $db / $dbError 由 bootstrap 提供

$errors = [];
$notice = '';

/* ---------------- 环境需求检查 ---------------- */
$pdoDrivers = PDO::getAvailableDrivers();
$ext        = static fn(string $n): bool => extension_loaded($n);
$extTxt     = static fn(string $n): string => extension_loaded($n) ? '已启用' : '未启用';
$disabledFn = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$execOk     = function_exists('exec') && !in_array('exec', $disabledFn, true);
// 每项：[名称, 当前值, 是否满足, 是否必需, 说明]
$reqGroups = [
    ['运行环境', 'mdi-language-php', [
        ['PHP 版本 ≥ 8.0', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>='), true, '支持 8.0 ~ 8.4'],
        ['PDO 扩展', $extTxt('pdo'), $ext('pdo'), true, '数据库访问层'],
        ['PDO 驱动 (sqlite/mysql)', $pdoDrivers ? implode(', ', $pdoDrivers) : '无', $pdoDrivers !== [], true, '至少需要其中一种'],
    ]],
    ['PHP 扩展', 'mdi-puzzle-outline', [
        ['cURL 扩展', $extTxt('curl'), $ext('curl'), true, 'HTTP 探测 / 预警推送'],
        ['mbstring 扩展', $extTxt('mbstring'), $ext('mbstring'), true, '多字节字符处理'],
        ['OpenSSL 扩展', $extTxt('openssl'), $ext('openssl'), false, '短信签名 / SSL 证书检测'],
        ['Zip 扩展', $extTxt('zip'), $ext('zip'), false, '在线更新'],
        ['Sockets 扩展', $extTxt('sockets'), $ext('sockets'), false, '原生 ICMP PING（无需 exec）'],
        ['SSH2 扩展', $extTxt('ssh2'), $ext('ssh2'), false, 'SSH 认证检测与资源采集'],
        ['exec 函数', $execOk ? '可用' : '已禁用', $execOk, false, '系统 ping 命令降级方案'],
    ]],
    ['目录权限', 'mdi-folder-lock-outline', [
        ['admin 目录可写', is_writable(ADMIN_ROOT) ? '可写' : '不可写', is_writable(ADMIN_ROOT), true, '生成 config.php'],
        ['admin/data 目录可写', is_writable(ADMIN_ROOT . '/data') ? '可写' : '不可写', is_writable(ADMIN_ROOT . '/data'), true, 'SQLite / 缓存 / 更新包'],
    ]],
];
$reqs = array_merge(...array_map(static fn(array $g): array => $g[2], $reqGroups));
$fatal = false;
$reqPass = $reqBad = $reqWarn = 0;
foreach ($reqs as $r) {
    if ($r[2]) { $reqPass++; } elseif ($r[3]) { $reqBad++; $fatal = true; } else { $reqWarn++; }
}
$serverInfo = [
    ['mdi-linux', '操作系统', PHP_OS_FAMILY . (function_exists('php_uname') ? ' ' . php_uname('r') : '')],
    ['mdi-server-network', 'Web 服务', (string)($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI)],
    ['mdi-memory', '内存限制', (string)ini_get('memory_limit')],
    ['mdi-timer-outline', '最大执行时间', ((int)ini_get('max_execution_time')) === 0 ? '不限' : ini_get('max_execution_time') . ' 秒'],
    ['mdi-clock-outline', '时区', date_default_timezone_get()],
    ['mdi-upload-outline', '上传限制', (string)ini_get('upload_max_filesize')],
];

$installed = ($db !== null) && Db::isInstalled($db);

/* ---------------- 步骤判定 ---------------- */
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 0);
if ($installed) {
    $step = 4;
} else {
    if ($step === 4) { $step = 0; }
    if ($step < 1 || $step > 3) {
        $step = !$configured ? 1 : ($db === null ? 2 : 3);
    }
    if ($step === 3 && (!$configured || $db === null)) { $step = 2; }
    if ($step === 2 && $fatal) { $step = 1; }
}

/* ---------------- POST：步骤 2 —— 生成 config.php ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2 && !$configured) {
    $type   = (($_POST['db_type'] ?? 'sqlite') === 'mysql') ? 'mysql' : 'sqlite';
    $host   = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $port   = (int)($_POST['db_port'] ?? 3306);
    $dbname = trim((string)($_POST['db_name'] ?? ''));
    $user   = trim((string)($_POST['db_user'] ?? ''));
    $pass   = (string)($_POST['db_pass'] ?? '');

    if ($type === 'mysql' && ($host === '' || $dbname === '' || $user === '')) {
        $errors[] = 'MySQL 需填写主机、数据库名与用户名';
    }
    if ($port < 1 || $port > 65535) {
        $errors[] = '数据库端口需在 1-65535 之间';
    }
    if (!is_writable(ADMIN_ROOT)) {
        $errors[] = 'admin 目录不可写，无法生成 config.php，请修正目录权限';
    }

    if (!$errors) {
        $testCfg = [
            'type'        => $type,
            'sqlite_path' => ADMIN_ROOT . '/data/probe.db',
            'mysql'       => [
                'host' => $host, 'port' => $port, 'dbname' => $dbname,
                'username' => $user, 'password' => $pass, 'charset' => 'utf8mb4',
            ],
        ];
        try {
            $testDb = Db::connect($testCfg);

            $eType = var_export($type, true);
            $eHost = var_export($host, true);
            $eName = var_export($dbname, true);
            $eUser = var_export($user, true);
            $ePass = var_export($pass, true);
            $ePort = (string)$port;
            $content = <<<PHP
<?php
/**
 * 服务器探针系统 - 全局配置（由安装向导生成）
 * PHP >= 8.0
 */

return [
    'db' => [
        'type'        => {$eType},
        'sqlite_path' => __DIR__ . '/data/probe.db',
        'mysql'       => [
            'host'     => {$eHost},
            'port'     => {$ePort},
            'dbname'   => {$eName},
            'username' => {$eUser},
            'password' => {$ePass},
            'charset'  => 'utf8mb4',
        ],
    ],

    'session_name'       => 'probe_admin_sid',
    'log_retention_days' => 7,
];

PHP;
            if (file_put_contents($configFile, $content, LOCK_EX) === false) {
                $errors[] = '写入 config.php 失败，请检查 admin 目录权限';
            } else {
                @chmod($configFile, 0640);
                $db         = $testDb;
                $configured = true;
                $step       = 3;
                $notice     = '数据库配置已保存，请设置管理员账号';
            }
        } catch (PDOException $ex) {
            $errors[] = '数据库连接失败：' . $ex->getMessage();
        }
    }
}

/* ---------------- POST：步骤 3 —— 建表 + 管理员 + 默认设置 ---------------- */
// 仅响应步骤 3 表单本身的提交（步骤 2 保存成功后 $step 会切到 3，不能顺带触发）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3 && (int)($_POST['step'] ?? 0) === 3 && $configured && $db !== null && !Db::isInstalled($db)) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');
    $siteName = trim((string)($_POST['site_name'] ?? 'CatMonitor'));

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors[] = '用户名需为 3-20 位字母、数字或下划线';
    }
    if (strlen($password) < 6) {
        $errors[] = '密码长度不能少于 6 位';
    }
    if ($password !== $confirm) {
        $errors[] = '两次输入的密码不一致';
    }
    if ($siteName === '' || mb_strlen($siteName) > 50) {
        $siteName = 'CatMonitor';
    }

    if (!$errors) {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk     = $driver === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $tables = [
            "CREATE TABLE IF NOT EXISTS admins (
                id {$pk},
                username VARCHAR(50) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                last_login_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS servers (
                id {$pk},
                name VARCHAR(100) NOT NULL,
                type VARCHAR(10) NOT NULL DEFAULT 'ping',
                address VARCHAR(255) NOT NULL,
                port INTEGER DEFAULT 0,
                username VARCHAR(100) DEFAULT '',
                password VARCHAR(255) DEFAULT '',
                check_interval INTEGER DEFAULT 60,
                timeout INTEGER DEFAULT 5,
                enabled INTEGER DEFAULT 1,
                last_status INTEGER DEFAULT NULL,
                last_latency REAL DEFAULT NULL,
                last_message VARCHAR(255) DEFAULT '',
                last_check_at INTEGER DEFAULT 0,
                fail_count INTEGER DEFAULT 0,
                ssl_days INTEGER DEFAULT NULL,
                ssh_monitor INTEGER DEFAULT 0,
                cpu_usage FLOAT DEFAULT NULL,
                mem_usage FLOAT DEFAULT NULL,
                disk_usage FLOAT DEFAULT NULL,
                disk_mount VARCHAR(50) DEFAULT NULL,
                disk_used BIGINT DEFAULT NULL,
                disk_total BIGINT DEFAULT NULL,
                disks_json TEXT DEFAULT NULL,
                packet_loss FLOAT DEFAULT NULL,
                load_usage FLOAT DEFAULT NULL,
                group_id INTEGER DEFAULT 0,
                front_hidden INTEGER DEFAULT 0,
                remark VARCHAR(255) DEFAULT '',
                created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS logs (
                id {$pk},
                server_id INTEGER NOT NULL,
                status INTEGER NOT NULL DEFAULT 0,
                latency REAL DEFAULT 0,
                message VARCHAR(255) DEFAULT '',
                checked_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `value` TEXT
            )",
            "CREATE TABLE IF NOT EXISTS alerts (
                id {$pk},
                server_id INTEGER NOT NULL,
                alert_type VARCHAR(20) NOT NULL,
                message VARCHAR(500) DEFAULT '',
                channels VARCHAR(100) DEFAULT '',
                sent_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS `groups` (
                id {$pk},
                name VARCHAR(50) NOT NULL,
                sort INTEGER DEFAULT 0,
                front_hidden INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT 0
            )",
        ];

        try {
            foreach ($tables as $sql) {
                $db->exec($sql);
            }
            try {
                $db->exec('CREATE INDEX IF NOT EXISTS idx_logs_server_time ON logs (server_id, checked_at)');
            } catch (PDOException) {
                // MySQL 已存在索引时忽略
            }

            $stmt = $db->prepare('INSERT INTO admins (username, password_hash, last_login_at) VALUES (?, ?, 0)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

            set_setting($db, 'site_name', $siteName);
            set_setting($db, 'log_retention_days', (string)($config['log_retention_days'] ?? 7));
            // 记录安装时的程序版本（在线更新以此为基线检查新版本）
            set_setting($db, 'app_version', defined('PROBE_VERSION') ? PROBE_VERSION : '0.1.0');
            // 预警默认设置
            foreach ([
                'alert_offline_enabled' => '1', 'alert_offline_threshold' => '3',
                'alert_ssl_enabled' => '1', 'alert_ssl_days' => '30',
                'alert_resource_enabled' => '1',
                'alert_limit_days' => '1', 'alert_limit_count' => '3',
                'alert_time_start' => '00:00', 'alert_time_end' => '23:59',
                'smtp_enabled' => '0', 'smtp_port' => '465', 'smtp_encryption' => 'ssl',
                'webhook_enabled' => '0', 'smsbao_enabled' => '0',
                'tencent_sms_enabled' => '0', 'aliyun_sms_enabled' => '0', 'telegram_enabled' => '0',
                'sms_provider' => 'smsbao',
            ] as $k => $v) {
                set_setting($db, $k, $v);
            }

            $installed = true;
            $step      = 4;
            $notice    = '安装成功！管理员账号：' . $username;
        } catch (PDOException $ex) {
            $errors[] = '安装失败：' . $ex->getMessage();
        }
    }
}

$steps = [
    1 => ['环境检查', 'mdi-clipboard-check-outline'],
    2 => ['数据库配置', 'mdi-database-cog-outline'],
    3 => ['管理员账号', 'mdi-account-key-outline'],
    4 => ['安装完成', 'mdi-flag-checkered'],
];
$version  = defined('PROBE_VERSION') ? PROBE_VERSION : '0.1.0';
$cronCmd  = '/www/server/php/' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/bin/php ' . ADMIN_ROOT . '/cron/probe.php';
$postType = ($_POST['db_type'] ?? '') === 'mysql' ? 'mysql' : (in_array('sqlite', $pdoDrivers, true) ? 'sqlite' : 'mysql');
$old      = static fn(string $k, string $def = ''): string => e((string)($_POST[$k] ?? $def));
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 - CatMonitor</title>
<link rel="stylesheet" href="../css/materialdesignicons.min.css">
<link rel="stylesheet" href="../css/bootstrap.min.css">
<link rel="stylesheet" href="../css/style.min.css">
<style>
  .inst-wrap { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px 12px; }
  .inst-card { width: 100%; max-width: 680px; }
  .inst-head { display: flex; align-items: center; gap: 12px; }
  .inst-logo { width: 40px; height: 40px; border-radius: 10px; background: rgba(67,97,238,.1); color: #4361ee; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
  .inst-head .t { font-size: 15px; font-weight: 600; color: #344054; line-height: 1.3; }
  .inst-head .d { font-size: 12px; color: #98a2b3; }
  .inst-ver { margin-left: auto; font-size: 11px; color: #4361ee; background: rgba(67,97,238,.08); border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
  .inst-steps { display: flex; gap: 6px; margin-bottom: 18px; }
  .inst-steps .s { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px; text-align: center; font-size: 12px; color: #98a2b3; padding-bottom: 8px; border-bottom: 2px solid #eef0f4; }
  .inst-steps .s .n { width: 30px; height: 30px; border-radius: 50%; background: #f2f4f7; display: flex; align-items: center; justify-content: center; font-size: 16px; transition: .2s; }
  .inst-steps .s.active { color: #4361ee; border-bottom-color: #4361ee; font-weight: 600; }
  .inst-steps .s.active .n { background: #4361ee; color: #fff; box-shadow: 0 0 0 4px rgba(67,97,238,.12); }
  .inst-steps .s.done { color: #12b76a; border-bottom-color: #12b76a; }
  .inst-steps .s.done .n { background: rgba(18,183,106,.12); color: #12b76a; }
  .req-ok { color: #12b76a; } .req-bad { color: #f04438; } .req-warn { color: #f79009; }
  .inst-sec { font-size: 13px; font-weight: 600; color: #4361ee; margin: 18px 0 8px; }
  .inst-sec:first-child { margin-top: 0; }
  .inst-feats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
  .inst-feat { border: 1px solid #eef0f4; border-radius: 8px; padding: 10px 8px; text-align: center; }
  .inst-feat i { font-size: 22px; color: #4361ee; }
  .inst-feat b { display: block; font-size: 12px; color: #344054; margin-top: 2px; }
  .inst-feat small { display: block; font-size: 11px; color: #98a2b3; line-height: 1.4; }
  .inst-sum { display: flex; align-items: center; gap: 12px; background: #f9fafb; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
  .inst-sum .progress { flex: 1; height: 6px; }
  .inst-sum .lbl { font-size: 12px; color: #667085; white-space: nowrap; }
  .inst-info { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .inst-info > div { background: #f9fafb; border-radius: 8px; padding: 8px 10px; min-width: 0; }
  .inst-info small { display: block; font-size: 11px; color: #98a2b3; }
  .inst-info span { display: block; font-size: 12px; color: #344054; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .inst-req td { vertical-align: middle; }
  .inst-req .desc { display: block; font-size: 11px; color: #98a2b3; }
  .db-opt { position: relative; display: block; margin: 0; }
  .db-opt input { position: absolute; opacity: 0; pointer-events: none; }
  .db-opt-body { display: flex; gap: 10px; align-items: flex-start; height: 100%; border: 1px solid #e4e7ec; border-radius: 10px; padding: 12px; cursor: pointer; transition: .15s; }
  .db-opt-body i { font-size: 24px; color: #98a2b3; line-height: 1; }
  .db-opt-body b { display: block; font-size: 13px; color: #344054; }
  .db-opt-body small { display: block; font-size: 11px; color: #98a2b3; margin-top: 2px; }
  .db-opt input:checked + .db-opt-body { border-color: #4361ee; background: rgba(67,97,238,.04); box-shadow: 0 0 0 3px rgba(67,97,238,.1); }
  .db-opt input:checked + .db-opt-body i { color: #4361ee; }
  .db-opt input:disabled + .db-opt-body { opacity: .5; cursor: not-allowed; }
  .pwd-meter { display: flex; gap: 4px; margin-top: 6px; }
  .pwd-meter span { flex: 1; height: 4px; border-radius: 2px; background: #eef0f4; transition: .2s; }
  .inst-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
  .inst-chips code { background: #fff; border: 1px solid #e4e7ec; border-radius: 4px; padding: 1px 6px; font-size: 11px; color: #475467; }
  .inst-next { list-style: none; padding: 0; margin: 0; }
  .inst-next li { display: flex; gap: 10px; align-items: flex-start; padding: 10px 0; border-top: 1px dashed #eef0f4; }
  .inst-next li:first-child { border-top: 0; }
  .inst-next .ic { width: 30px; height: 30px; border-radius: 8px; background: rgba(67,97,238,.08); color: #4361ee; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
  .inst-next b { font-size: 13px; color: #344054; }
  .inst-next p { font-size: 12px; color: #98a2b3; margin: 2px 0 0; }
  .inst-next code { font-size: 11px; word-break: break-all; }
  .inst-foot { font-size: 12px; color: #98a2b3; margin-top: 14px; text-align: center; }
  @media (max-width: 575.98px) {
    .inst-feats { grid-template-columns: repeat(2, 1fr); }
    .inst-info { grid-template-columns: repeat(2, 1fr); }
    .inst-steps .s { font-size: 11px; }
    .inst-ver { display: none; }
  }
</style>
</head>
<body style="background:#f5f7fb;">
<div class="inst-wrap">
  <div class="card inst-card">
    <div class="card-header">
      <div class="inst-head w-100">
        <div class="inst-logo"><i class="mdi mdi-shield-crown-outline"></i></div>
        <div>
          <div class="t">CatMonitor 安装向导</div>
          <div class="d">服务器探针监控系统 · 只需几步即可完成部署</div>
        </div>
        <span class="inst-ver">V<?php echo e($version); ?></span>
      </div>
    </div>
    <div class="card-body">
      <div class="inst-steps">
        <?php foreach ($steps as $i => [$label, $icon]): ?>
          <div class="s <?php echo $i === $step ? 'active' : ($i < $step ? 'done' : ''); ?>">
            <span class="n"><i class="mdi <?php echo $i < $step ? 'mdi-check' : $icon; ?>"></i></span>
            <span><?php echo $i . '. ' . e($label); ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($notice): ?><div class="alert alert-success"><?php echo e($notice); ?></div><?php endif; ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

      <?php if ($step === 1): ?>
        <div class="inst-feats">
          <div class="inst-feat"><i class="mdi mdi-radar"></i><b>多协议探测</b><small>PING / TCP / UDP<br>HTTP / SSH</small></div>
          <div class="inst-feat"><i class="mdi mdi-chart-donut"></i><b>资源监控</b><small>CPU / 内存 / Swap<br>磁盘 / 负载</small></div>
          <div class="inst-feat"><i class="mdi mdi-bell-ring-outline"></i><b>多渠道预警</b><small>邮件 / Webhook<br>短信 / Telegram</small></div>
          <div class="inst-feat"><i class="mdi mdi-earth"></i><b>公开状态页</b><small>可用率 / 质量历史<br>分组展示</small></div>
        </div>

        <?php $reqTotal = count($reqs); $reqPct = $reqTotal ? (int)round($reqPass / $reqTotal * 100) : 0; ?>
        <div class="inst-sum">
          <i class="mdi <?php echo $fatal ? 'mdi-close-circle req-bad' : ($reqWarn ? 'mdi-alert-circle req-warn' : 'mdi-check-circle req-ok'); ?>" style="font-size:22px;"></i>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between mb-1">
              <span class="lbl"><b class="text-dark"><?php echo $fatal ? '环境不满足安装条件' : ($reqWarn ? '可以安装，部分可选功能受限' : '环境检测全部通过'); ?></b></span>
              <span class="lbl">通过 <?php echo $reqPass; ?>/<?php echo $reqTotal; ?></span>
            </div>
            <div class="progress"><div class="progress-bar <?php echo $fatal ? 'bg-danger' : ($reqWarn ? 'bg-warning' : 'bg-success'); ?>" style="width:<?php echo $reqPct; ?>%"></div></div>
          </div>
        </div>

        <div class="inst-sec"><i class="mdi mdi-server-outline me-1"></i>服务器信息</div>
        <div class="inst-info">
          <?php foreach ($serverInfo as [$ic, $k, $v]): ?>
            <div title="<?php echo e($v); ?>"><small><i class="mdi <?php echo $ic; ?> me-1"></i><?php echo e($k); ?></small><span><?php echo e($v !== '' ? $v : '-'); ?></span></div>
          <?php endforeach; ?>
        </div>

        <?php foreach ($reqGroups as [$gName, $gIcon, $items]): ?>
          <div class="inst-sec"><i class="mdi <?php echo $gIcon; ?> me-1"></i><?php echo e($gName); ?></div>
          <table class="table table-sm inst-req mb-0">
            <tbody>
              <?php foreach ($items as [$label, $val, $ok, $required, $desc]): ?>
                <tr>
                  <td><?php echo e($label); ?><?php echo $required ? '' : ' <span class="text-muted small">(可选)</span>'; ?><span class="desc"><?php echo e($desc); ?></span></td>
                  <td class="text-end"><?php echo e((string)$val); ?></td>
                  <td class="text-end" style="width:50px;">
                    <?php if ($ok): ?><i class="mdi mdi-check-circle req-ok"></i>
                    <?php elseif ($required): ?><i class="mdi mdi-close-circle req-bad"></i>
                    <?php else: ?><i class="mdi mdi-alert-circle req-warn"></i><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endforeach; ?>

        <?php if (!$ext('sockets') && !$execOk): ?>
          <div class="alert alert-warning small mt-3 mb-0">Sockets 扩展与 exec 函数均不可用，<b>PING 类探测将无法工作</b>，建议至少启用其中一项。</div>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3">
          <a class="btn btn-outline-secondary" href="?step=1"><i class="mdi mdi-refresh me-1"></i>重新检测</a>
          <?php if ($fatal): ?>
            <a class="btn btn-primary flex-grow-1 disabled">存在 <?php echo $reqBad; ?> 项必需条件未满足，无法继续</a>
          <?php else: ?>
            <a class="btn btn-primary flex-grow-1" href="?step=2">环境正常，下一步：配置数据库<i class="mdi mdi-arrow-right ms-1"></i></a>
          <?php endif; ?>
        </div>

      <?php elseif ($step === 2): ?>
        <?php if ($configured && $db === null): ?>
          <div class="alert alert-danger">已存在 config.php，但数据库连接失败：<br><code><?php echo e($dbError); ?></code></div>
          <div class="text-muted small mb-3">请修正 <code>admin/config.php</code> 中的数据库信息；如需重新配置，请删除该文件后刷新本页。</div>
          <a class="btn btn-outline-secondary w-100" href="?step=2">刷新重试</a>
        <?php elseif ($configured): ?>
          <div class="alert alert-info">数据库已配置，可直接进入下一步。</div>
          <a class="btn btn-primary w-100" href="?step=3">下一步：设置管理员</a>
        <?php else: ?>
          <form method="post" class="inst-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="step" value="2">
            <div class="inst-sec"><i class="mdi mdi-database-outline me-1"></i>选择数据库类型</div>
            <div class="row g-2 mb-3">
              <?php foreach ([
                  ['sqlite', 'mdi-feather', 'SQLite', '零配置，适合预览 / 小型部署'],
                  ['mysql', 'mdi-database', 'MySQL / MariaDB', '适合节点较多、长期保存监控数据'],
              ] as [$dv, $di, $dn, $dd]): $avail = in_array($dv, $pdoDrivers, true); ?>
                <div class="col-sm-6">
                  <label class="db-opt h-100">
                    <input type="radio" name="db_type" value="<?php echo $dv; ?>" <?php echo $postType === $dv ? 'checked' : ''; ?> <?php echo $avail ? '' : 'disabled'; ?>>
                    <span class="db-opt-body"><i class="mdi <?php echo $di; ?>"></i><span><b><?php echo $dn; ?></b><small><?php echo $avail ? e($dd) : '当前环境未安装 pdo_' . $dv . ' 驱动'; ?></small></span></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <div id="sqlite-box" class="alert alert-light border small mb-3" style="<?php echo $postType === 'mysql' ? 'display:none;' : ''; ?>">
              <i class="mdi mdi-information-outline me-1 text-primary"></i>无需填写任何信息，数据文件将保存在 <code>admin/data/probe.db</code>，建议在 Web 服务器中禁止外网直接访问 <code>admin/data/</code> 目录。
            </div>
            <div id="mysql-box" style="<?php echo $postType === 'mysql' ? '' : 'display:none;'; ?>">
              <div class="row g-2 mb-2">
                <div class="col-8"><label class="form-label">主机</label><input type="text" name="db_host" class="form-control" value="<?php echo $old('db_host', '127.0.0.1'); ?>"></div>
                <div class="col-4"><label class="form-label">端口</label><input type="number" name="db_port" class="form-control" value="<?php echo $old('db_port', '3306'); ?>" min="1" max="65535"></div>
                <div class="col-12"><label class="form-label">数据库名</label><input type="text" name="db_name" class="form-control" placeholder="catmonitor" value="<?php echo $old('db_name'); ?>"></div>
                <div class="col-6"><label class="form-label">用户名</label><input type="text" name="db_user" class="form-control" autocomplete="off" value="<?php echo $old('db_user'); ?>"></div>
                <div class="col-6">
                  <label class="form-label">密码</label>
                  <div class="input-group">
                    <input type="password" name="db_pass" class="form-control" autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary pwd-eye" tabindex="-1"><i class="mdi mdi-eye-outline"></i></button>
                  </div>
                </div>
              </div>
              <div class="form-text mb-3"><i class="mdi mdi-lightbulb-on-outline me-1"></i>宝塔面板：<b>数据库 → 添加数据库</b>，字符集选 utf8mb4，创建后将信息填入此处。</div>
            </div>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary" href="?step=1"><i class="mdi mdi-arrow-left me-1"></i>上一步</a>
              <button type="submit" class="btn btn-primary flex-grow-1">连接并保存配置</button>
            </div>
          </form>
        <?php endif; ?>

      <?php elseif ($step === 3): ?>
        <form method="post" class="inst-form">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="step" value="3">
          <div class="inst-sec"><i class="mdi mdi-web me-1"></i>站点信息</div>
          <div class="mb-3">
            <label class="form-label">站点名称</label>
            <input type="text" name="site_name" class="form-control" value="<?php echo $old('site_name', 'CatMonitor'); ?>" maxlength="50">
            <div class="form-text">显示在前台状态页与后台标题，安装后可在系统设置中修改</div>
          </div>

          <div class="inst-sec"><i class="mdi mdi-account-circle-outline me-1"></i>管理员账号</div>
          <div class="mb-3">
            <label class="form-label">管理员用户名</label>
            <div class="input-group">
              <span class="input-group-text"><i class="mdi mdi-account-outline"></i></span>
              <input type="text" name="username" class="form-control" required pattern="[a-zA-Z0-9_]{3,20}" value="<?php echo $old('username'); ?>" placeholder="admin" autocomplete="off">
            </div>
            <div class="form-text">3-20 位字母、数字或下划线</div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label class="form-label">管理员密码</label>
              <div class="input-group">
                <input type="password" name="password" id="inst-pwd" class="form-control" required minlength="6" autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary pwd-eye" tabindex="-1"><i class="mdi mdi-eye-outline"></i></button>
              </div>
              <div class="pwd-meter" id="pwd-meter"><span></span><span></span><span></span><span></span></div>
              <div class="form-text" id="pwd-tip">至少 6 位，建议混合大小写字母、数字与符号</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label">确认密码</label>
              <div class="input-group">
                <input type="password" name="confirm" id="inst-confirm" class="form-control" required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary pwd-eye" tabindex="-1"><i class="mdi mdi-eye-outline"></i></button>
              </div>
              <div class="form-text" id="confirm-tip">&nbsp;</div>
            </div>
          </div>

          <div class="alert alert-light border small mb-3">
            <div><i class="mdi mdi-database-plus-outline me-1 text-primary"></i>将在 <b><?php echo e(strtoupper((string)$db?->getAttribute(PDO::ATTR_DRIVER_NAME))); ?></b> 中创建以下数据表：</div>
            <div class="inst-chips"><?php foreach (['admins', 'servers', 'logs', 'settings', 'alerts', 'groups'] as $tb): ?><code><?php echo $tb; ?></code><?php endforeach; ?></div>
            <div class="mt-2 text-muted">并写入默认配置：离线 / SSL / 资源预警默认开启，探测日志保留 <?php echo (int)($config['log_retention_days'] ?? 7); ?> 天。</div>
          </div>
          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="?step=2"><i class="mdi mdi-arrow-left me-1"></i>上一步</a>
            <button type="submit" class="btn btn-primary flex-grow-1"><i class="mdi mdi-rocket-launch-outline me-1"></i>开始安装</button>
          </div>
        </form>

      <?php else: /* step 4 */ ?>
        <div class="text-center py-3">
          <i class="mdi mdi-check-decagram" style="font-size:56px;color:#12b76a;"></i>
          <h5 class="mt-2 mb-1">安装完成</h5>
          <p class="text-muted small mb-3">系统版本 V<?php echo e($version); ?> 已就绪<?php echo isset($username) && $username !== '' ? '，管理员账号：<b class="text-dark">' . e($username) . '</b>' : ''; ?>。</p>
          <a class="btn btn-primary" href="login.php"><i class="mdi mdi-login me-1"></i>前往后台登录</a>
          <a class="btn btn-outline-secondary" href="../index.php" target="_blank"><i class="mdi mdi-earth me-1"></i>查看前台状态页</a>
        </div>

        <div class="inst-sec"><i class="mdi mdi-format-list-checks me-1"></i>接下来</div>
        <ul class="inst-next">
          <li>
            <span class="ic"><i class="mdi mdi-clock-outline"></i></span>
            <div class="flex-grow-1">
              <b>配置计划任务（必需）</b>
              <p>宝塔面板 → 计划任务 → Shell 脚本，执行周期 <b>每分钟</b>，脚本内容：</p>
              <div class="d-flex align-items-center gap-2 mt-1">
                <code id="cron-cmd" class="flex-grow-1"><?php echo e($cronCmd); ?></code>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="cron-copy">复制</button>
              </div>
            </div>
          </li>
          <li>
            <span class="ic"><i class="mdi mdi-server-plus"></i></span>
            <div><b>添加监控节点</b><p>登录后台 → 服务器管理，支持 PING / TCP / UDP / HTTP / SSH 等多种探测方式。</p></div>
          </li>
          <li>
            <span class="ic"><i class="mdi mdi-bell-cog-outline"></i></span>
            <div><b>配置预警渠道</b><p>系统设置 → 通知方式，可接入邮件、Webhook、短信与 Telegram；预警规则可调整阈值与静默时段。</p></div>
          </li>
          <li>
            <span class="ic"><i class="mdi mdi-palette-outline"></i></span>
            <div><b>个性化前台</b><p>系统设置 → 基本设置 / 前台页面，可修改站点名称、网站图标与 Logo；分组管理中可调整前台展示。</p></div>
          </li>
        </ul>
        <div class="alert alert-warning small mt-3 mb-0"><i class="mdi mdi-shield-alert-outline me-1"></i>安全提示：如需重新安装，请删除 <code>admin/config.php</code>（SQLite 另需删除 <code>admin/data/probe.db</code>）后刷新本页。</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="inst-foot">CatMonitor V<?php echo e($version); ?> · 服务器探针监控系统 · PHP <?php echo e(PHP_VERSION); ?></div>
</div>
<script>
(function () {
  // 数据库类型切换
  document.querySelectorAll('input[name="db_type"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var my = this.value === 'mysql';
      document.getElementById('mysql-box').style.display = my ? '' : 'none';
      document.getElementById('sqlite-box').style.display = my ? 'none' : '';
    });
  });
  // 密码显隐
  document.querySelectorAll('.pwd-eye').forEach(function (b) {
    b.addEventListener('click', function () {
      var ipt = this.parentNode.querySelector('input'), show = ipt.type === 'password';
      ipt.type = show ? 'text' : 'password';
      this.querySelector('i').className = 'mdi ' + (show ? 'mdi-eye-off-outline' : 'mdi-eye-outline');
    });
  });
  // 密码强度与一致性
  var pwd = document.getElementById('inst-pwd'), cfm = document.getElementById('inst-confirm');
  if (pwd) {
    var bars = document.querySelectorAll('#pwd-meter span'), tip = document.getElementById('pwd-tip'), ctip = document.getElementById('confirm-tip');
    var lv = [['#f04438', '弱'], ['#f79009', '一般'], ['#4361ee', '较强'], ['#12b76a', '强']];
    var check = function () {
      var v = pwd.value, s = 0;
      if (v.length >= 6) s++;
      if (v.length >= 10) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
      if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
      if (v.length < 6) s = v ? 1 : 0;
      bars.forEach(function (b, i) { b.style.background = i < s ? lv[s - 1][0] : ''; });
      tip.innerHTML = !v ? '至少 6 位，建议混合大小写字母、数字与符号'
        : (v.length < 6 ? '<span class="req-bad">长度不足 6 位</span>' : '密码强度：<b style="color:' + lv[s - 1][0] + '">' + lv[s - 1][1] + '</b>');
      if (cfm.value) {
        var same = cfm.value === v;
        ctip.innerHTML = same ? '<span class="req-ok"><i class="mdi mdi-check"></i> 两次输入一致</span>' : '<span class="req-bad"><i class="mdi mdi-close"></i> 两次输入不一致</span>';
        cfm.setCustomValidity(same ? '' : '两次输入的密码不一致');
      } else {
        ctip.innerHTML = '&nbsp;';
        cfm.setCustomValidity('');
      }
    };
    pwd.addEventListener('input', check);
    cfm.addEventListener('input', check);
  }
  // 提交中状态，防止重复提交
  document.querySelectorAll('.inst-form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var btn = f.querySelector('button[type=submit]');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>正在处理，请稍候…';
    });
  });
  // 复制计划任务命令
  var cp = document.getElementById('cron-copy');
  if (cp) {
    cp.addEventListener('click', function () {
      var txt = document.getElementById('cron-cmd').textContent, done = function () { cp.textContent = '已复制'; setTimeout(function () { cp.textContent = '复制'; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(txt).then(done); return; }
      var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(ta);
    });
  }
})();
</script>
</body>
</html>
