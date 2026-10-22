<?php
/**
 * 系统设置（基本设置 / 预警设置 / 运行环境）
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '系统设置';
$active    = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_basic';

    if ($action === 'save_basic') {
        $siteName  = trim((string)($_POST['site_name'] ?? ''));
        $retention = max(1, min(365, (int)($_POST['log_retention_days'] ?? 7)));
        $icpNo     = trim((string)($_POST['icp_no'] ?? ''));

        if ($siteName === '' || mb_strlen($siteName) > 50) {
            flash_set('danger', '站点名称不能为空且不超过 50 字');
        } else {
            set_setting($db, 'site_name', $siteName);
            set_setting($db, 'log_retention_days', (string)$retention);
            set_setting($db, 'icp_no', mb_substr($icpNo, 0, 100));
            flash_set('success', '基本设置已保存');
        }
        header('Location: settings.php?tab=basic');
        exit;
    }

    if ($action === 'save_update') {
        // 应用 ID 固定为 4：忽略前端提交，不允许修改
        $appId   = '4';
        $license = trim((string)($_POST['license_key'] ?? ''));
        set_setting($db, 'update_app_id', $appId);
        // 授权码留空表示保持不变；填写则更新，并清除旧 token 以便重新验证
        if ($license !== '') {
            set_setting($db, 'license_key', $license);
            set_setting($db, 'update_token', '');
            set_setting($db, 'update_token_expire', '0');
        }
        flash_set('success', '在线更新配置已保存');
        header('Location: settings.php?tab=update');
        exit;
    }

    if ($action === 'save_brand') {
        $uploadErr = '';
        // 图片上传：校验格式/大小后存入 uploads/ 目录，返回站点绝对路径
        $upload = function (string $field, array $exts) use (&$uploadErr) {
            $f = $_FILES[$field] ?? null;
            if (!$f || !isset($f['error']) || is_array($f['error'])) { return null; }
            if ($f['error'] === UPLOAD_ERR_NO_FILE) { return null; }
            if ($f['error'] !== UPLOAD_ERR_OK) { $uploadErr = '图片上传失败（错误码 ' . $f['error'] . '）'; return false; }
            if ($f['size'] > 2 * 1024 * 1024) { $uploadErr = '图片过大，请控制在 2MB 以内'; return false; }
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) { $uploadErr = '仅支持 ' . implode('/', $exts) . ' 格式'; return false; }
            $dir = dirname(__DIR__) . '/uploads';
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) { $uploadErr = '上传目录创建失败'; return false; }
            $name = $field . '_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { $uploadErr = '图片保存失败'; return false; }
            return '/uploads/' . $name;
        };
        // 替换设置值并清理旧上传文件
        $replace = function (string $key, string $newPath) use ($db) {
            $old = (string)get_setting($db, $key, '');
            if ($old !== '' && strpos($old, '/uploads/') === 0) {
                $file = dirname(__DIR__) . $old;
                if (is_file($file)) { @unlink($file); }
            }
            set_setting($db, $key, $newPath);
        };

        $imgExts  = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
        $fields   = [
            'favicon_file'    => ['site_favicon', ['ico', 'png', 'svg']],
            'admin_logo_file' => ['logo_admin', $imgExts],
            'front_logo_file' => ['logo_front', $imgExts],
        ];
        foreach ($fields as $field => [$key, $exts]) {
            $path = $upload($field, $exts);
            if ($path === false) { break; }
            if ($path !== null) {
                $replace($key, $path);
            } elseif (isset($_POST['remove_' . $key])) {
                $replace($key, ''); // 勾选「恢复默认」
            }
        }
        flash_set($uploadErr === '' ? 'success' : 'danger', $uploadErr === '' ? '站点标识已保存' : $uploadErr);
        header('Location: settings.php?tab=basic');
        exit;
    }

    if ($action === 'save_front') {
        $pairs = [
            'front_enabled'      => isset($_POST['front_enabled']) ? '1' : '0',
            'front_show_resource'=> isset($_POST['front_show_resource']) ? '1' : '0',
            'front_show_history' => isset($_POST['front_show_history']) ? '1' : '0',
            'front_history_hours' => (string)max(1, min(72, (int)($_POST['front_history_hours'] ?? 12))),
            'sspping_ping_only'  => isset($_POST['sspping_ping_only']) ? '1' : '0',
        ];
        foreach ($pairs as $k => $v) {
            set_setting($db, $k, $v);
        }
        flash_set('success', '前台页面设置已保存');
        header('Location: settings.php?tab=front');
        exit;
    }

    if ($action === 'save_alert_rules') {
        $pairs = [
            'alert_offline_enabled'   => isset($_POST['alert_offline_enabled']) ? '1' : '0',
            'alert_offline_threshold' => (string)max(1, min(100, (int)($_POST['alert_offline_threshold'] ?? 3))),
            'alert_ssl_enabled'       => isset($_POST['alert_ssl_enabled']) ? '1' : '0',
            'alert_ssl_days'          => (string)max(0, min(365, (int)($_POST['alert_ssl_days'] ?? 30))),
            'alert_resource_enabled'  => isset($_POST['alert_resource_enabled']) ? '1' : '0',
            'alert_cpu_threshold'     => (string)max(1, min(100, (int)($_POST['alert_cpu_threshold'] ?? 90))),
            'alert_mem_threshold'     => (string)max(1, min(100, (int)($_POST['alert_mem_threshold'] ?? 90))),
            'alert_disk_threshold'    => (string)max(1, min(100, (int)($_POST['alert_disk_threshold'] ?? 90))),
            'alert_limit_days'        => (string)max(1, min(30, (int)($_POST['alert_limit_days'] ?? 1))),
            'alert_limit_count'       => (string)max(1, min(100, (int)($_POST['alert_limit_count'] ?? 3))),
            'health_warn_threshold'   => (string)max(1, min(100, (int)($_POST['health_warn_threshold'] ?? 80))),
            'health_danger_threshold' => (string)max(1, min(100, (int)($_POST['health_danger_threshold'] ?? 95))),
            'health_loss_danger'      => (string)max(1, min(100, (int)($_POST['health_loss_danger'] ?? 50))),
            'uptime_strictness'       => in_array($_POST['uptime_strictness'] ?? '', ['ultra_strict', 'strict', 'balanced', 'lenient', 'ultra_lenient'], true) ? (string)$_POST['uptime_strictness'] : 'balanced',
            'uptime_yellow'           => (string)max(0, min(100, (float)($_POST['uptime_yellow'] ?? 98))),
            'uptime_red'              => (string)max(0, min(100, (float)($_POST['uptime_red'] ?? 90))),
            'uptime_loss_weight'      => (string)max(0, min(10, (float)($_POST['uptime_loss_weight'] ?? 3))),
            'uptime_lat_weight'       => (string)max(0, min(3, (float)($_POST['uptime_lat_weight'] ?? 1))),
            'alert_time_start'        => substr((string)($_POST['alert_time_start'] ?? '00:00'), 0, 5),
            'alert_time_end'          => substr((string)($_POST['alert_time_end'] ?? '23:59'), 0, 5),
        ];
        // 危险阈值不能低于告警阈值
        if ((int)$pairs['health_danger_threshold'] < (int)$pairs['health_warn_threshold']) {
            $pairs['health_danger_threshold'] = $pairs['health_warn_threshold'];
        }
        // 可用率红阈值不得高于黄阈值
        if ((float)$pairs['uptime_red'] > (float)$pairs['uptime_yellow']) {
            $pairs['uptime_red'] = $pairs['uptime_yellow'];
        }
        foreach ($pairs as $k => $v) {
            set_setting($db, $k, $v);
        }
        flash_set('success', '预警规则已保存');
        header('Location: settings.php?tab=alert');
        exit;
    }

    if ($action === 'save_notify') {
        $smsProvider = (string)($_POST['sms_provider'] ?? 'smsbao');
        $smsProviders = ['smsbao', 'tencent_sms', 'aliyun_sms'];
        if (!in_array($smsProvider, $smsProviders, true)) {
            flash_set('danger', '请选择有效的短信接口');
            header('Location: settings.php?tab=notify');
            exit;
        }
        $smsEnabled = isset($_POST['sms_enabled']);
        $pairs = [
            'sms_provider'           => $smsProvider,
            'smtp_enabled'            => isset($_POST['smtp_enabled']) ? '1' : '0',
            'smtp_host'               => trim((string)($_POST['smtp_host'] ?? '')),
            'smtp_port'               => (string)max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
            'smtp_encryption'         => in_array($_POST['smtp_encryption'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_encryption'] : 'ssl',
            'smtp_username'           => trim((string)($_POST['smtp_username'] ?? '')),
            'smtp_from'               => trim((string)($_POST['smtp_from'] ?? '')),
            'smtp_to'                 => trim((string)($_POST['smtp_to'] ?? '')),
            'webhook_enabled'         => isset($_POST['webhook_enabled']) ? '1' : '0',
            'webhook_url'             => trim((string)($_POST['webhook_url'] ?? '')),
            'webhook_token'           => trim((string)($_POST['webhook_token'] ?? '')),
            'smsbao_enabled'          => $smsEnabled && $smsProvider === 'smsbao' ? '1' : '0',
            'smsbao_username'         => trim((string)($_POST['smsbao_username'] ?? '')),
            'smsbao_mobile'           => trim((string)($_POST['smsbao_mobile'] ?? '')),
            'smsbao_template'         => trim((string)($_POST['smsbao_template'] ?? '')),
            'tencent_sms_enabled'        => $smsEnabled && $smsProvider === 'tencent_sms' ? '1' : '0',
            'tencent_sms_secret_id'      => trim((string)($_POST['tencent_sms_secret_id'] ?? '')),
            'tencent_sms_app_id'         => trim((string)($_POST['tencent_sms_app_id'] ?? '')),
            'tencent_sms_sign'           => trim((string)($_POST['tencent_sms_sign'] ?? '')),
            'tencent_sms_template_id'    => trim((string)($_POST['tencent_sms_template_id'] ?? '')),
            'tencent_sms_template_param' => trim((string)($_POST['tencent_sms_template_param'] ?? '')),
            'tencent_sms_mobile'         => trim((string)($_POST['tencent_sms_mobile'] ?? '')),
            'aliyun_sms_enabled'         => $smsEnabled && $smsProvider === 'aliyun_sms' ? '1' : '0',
            'aliyun_sms_key_id'          => trim((string)($_POST['aliyun_sms_key_id'] ?? '')),
            'aliyun_sms_sign'            => trim((string)($_POST['aliyun_sms_sign'] ?? '')),
            'aliyun_sms_template_code'   => trim((string)($_POST['aliyun_sms_template_code'] ?? '')),
            'aliyun_sms_template_param'  => trim((string)($_POST['aliyun_sms_template_param'] ?? '')),
            'aliyun_sms_mobile'          => trim((string)($_POST['aliyun_sms_mobile'] ?? '')),
            'telegram_enabled'        => isset($_POST['telegram_enabled']) ? '1' : '0',
            'telegram_token'          => trim((string)($_POST['telegram_token'] ?? '')),
            'telegram_chat_id'        => trim((string)($_POST['telegram_chat_id'] ?? '')),
        ];
        // SMTP 密码留空表示不修改
        if (trim((string)($_POST['smtp_password'] ?? '')) !== '') {
            $pairs['smtp_password'] = (string)$_POST['smtp_password'];
        }
        // 短信宝密码留空表示不修改
        if (trim((string)($_POST['smsbao_password'] ?? '')) !== '') {
            $pairs['smsbao_password'] = (string)$_POST['smsbao_password'];
        }
        // 腾讯云 SecretKey / 阿里云 AccessKeySecret 留空表示不修改（不回显、不覆盖）
        if (trim((string)($_POST['tencent_sms_secret_key'] ?? '')) !== '') {
            $pairs['tencent_sms_secret_key'] = (string)$_POST['tencent_sms_secret_key'];
        }
        if (trim((string)($_POST['aliyun_sms_key_secret'] ?? '')) !== '') {
            $pairs['aliyun_sms_key_secret'] = (string)$_POST['aliyun_sms_key_secret'];
        }
        // 短信 API：线路（国内/国外）+ 两条线路地址均可自定义，默认短信宝官方接口
        $pairs['smsbao_api_line'] = ($_POST['smsbao_api_line'] ?? 'domestic') === 'intl' ? 'intl' : 'domestic';
        $apiErr = '';
        foreach ([['smsbao_api_domestic', 'https://u.smsbao.com/sms', '国内'], ['smsbao_api_intl', 'http://api.smsbao.com/sms', '国外']] as [$key, $def, $label]) {
            if ($smsProvider !== 'smsbao') {
                continue;
            }
            $val = trim((string)($_POST[$key] ?? ''));
            if ($val === '') {
                $val = $def; // 留空恢复默认地址
            } elseif (!filter_var($val, FILTER_VALIDATE_URL)) {
                $apiErr = $label . ' API 地址不合法，请填写完整 URL（如 https://example.com/sms）';
                break;
            }
            $pairs[$key] = $val;
        }
        if ($apiErr !== '') {
            flash_set('danger', $apiErr);
            header('Location: settings.php?tab=notify');
            exit;
        }
        // 隐藏接口不会提交字段；保留其配置，只将通知开关关闭。
        foreach ($pairs as $k => $v) {
            foreach ($smsProviders as $provider) {
                if ($provider !== $smsProvider && str_starts_with($k, $provider . '_') && $k !== $provider . '_enabled') {
                    continue 2;
                }
            }
            set_setting($db, $k, $v);
        }
        flash_set('success', '通知方式已保存');
        header('Location: settings.php?tab=notify');
        exit;
    }

    if ($action === 'test_smtp') {
        $r = AlertService::testSmtp($db);
        flash_set($r['ok'] ? 'success' : 'danger', 'SMTP 测试：' . $r['message']);
        header('Location: settings.php?tab=notify');
        exit;
    }

    if ($action === 'test_webhook') {
        $r = AlertService::testWebhook($db);
        flash_set($r['ok'] ? 'success' : 'danger', 'Webhook 测试：' . $r['message']);
        header('Location: settings.php?tab=notify');
        exit;
    }

    if ($action === 'test_sms') {
        $sms = AlertService::smsConfig($db);
        if (($_POST['sms_provider'] ?? '') !== $sms['provider']) {
            flash_set('warning', '短信接口已切换，请先保存通知方式再测试');
        } else {
            $r = match ($sms['provider']) {
                'tencent_sms' => AlertService::testTencentSms($db),
                'aliyun_sms' => AlertService::testAliyunSms($db),
                default => AlertService::testSmsbao($db),
            };
            flash_set($r['ok'] ? 'success' : 'danger', '短信测试：' . $r['message']);
        }
        header('Location: settings.php?tab=notify');
        exit;
    }

    if ($action === 'test_telegram') {
        $r = AlertService::testTelegram($db);
        flash_set($r['ok'] ? 'success' : 'danger', 'Telegram 测试：' . $r['message']);
        header('Location: settings.php?tab=notify');
        exit;
    }

    if ($action === 'regen_cron_key') {
        CronService::cronKey($db, true);
        flash_set('success', 'URL 触发密钥已重新生成，请同步更新宝塔计划任务中的地址');
        header('Location: settings.php?tab=env');
        exit;
    }

    // 开放 API：保存启用开关
    if ($action === 'save_open_api') {
        set_setting($db, 'open_api_enabled', isset($_POST['open_api_enabled']) ? '1' : '0');
        flash_set('success', '开放 API 设置已保存');
        header('Location: settings.php?tab=api');
        exit;
    }

    // 开放 API：保存临时令牌登录（SSO）开关
    if ($action === 'save_sso') {
        set_setting($db, 'sso_login_enabled', isset($_POST['sso_login_enabled']) ? '1' : '0');
        flash_set('success', '临时令牌登录设置已保存');
        header('Location: settings.php?tab=api');
        exit;
    }

    // 开放 API：重新生成凭证（id + key）
    if ($action === 'regen_open_api') {
        open_api_credentials($db, true);
        flash_set('success', '开放 API 凭证已重新生成，旧凭证立即失效，请同步更新调用方');
        header('Location: settings.php?tab=api');
        exit;
    }
}

$siteName  = get_setting($db, 'site_name', 'Catmonitor');
$retention = (int)get_setting($db, 'log_retention_days', '7');
$icpNo     = get_setting($db, 'icp_no', '');
// 站点标识（favicon / 后台 Logo / 前台 Logo）
$brand = [
    'favicon'    => get_setting($db, 'site_favicon', ''),
    'admin_logo' => get_setting($db, 'logo_admin', ''),
    'front_logo' => get_setting($db, 'logo_front', ''),
];
// 前台页面设置
$front = [
    'enabled'      => get_setting($db, 'front_enabled', '1') === '1',
    'show_resource'=> get_setting($db, 'front_show_resource', '1') === '1',
    'show_history' => get_setting($db, 'front_show_history', '1') === '1',
    'history_hours' => max(1, min(72, (int)get_setting($db, 'front_history_hours', '12'))),
    'sspping_ping_only' => get_setting($db, 'sspping_ping_only', '0') === '1',
];
// 预警相关设置（带默认值）
$sms = AlertService::smsConfig($db);
$smsLabels = ['smsbao' => '短信宝', 'tencent_sms' => '腾讯云', 'aliyun_sms' => '阿里云'];
$alert = [
    'offline_enabled'   => get_setting($db, 'alert_offline_enabled', '1') === '1',
    'offline_threshold' => (int)get_setting($db, 'alert_offline_threshold', '3'),
    'ssl_enabled'       => get_setting($db, 'alert_ssl_enabled', '1') === '1',
    'ssl_days'          => (int)get_setting($db, 'alert_ssl_days', '30'),
    'resource_enabled'  => get_setting($db, 'alert_resource_enabled', '1') === '1',
    'cpu_threshold'     => (int)get_setting($db, 'alert_cpu_threshold', '90'),
    'mem_threshold'     => (int)get_setting($db, 'alert_mem_threshold', '90'),
    'disk_threshold'    => (int)get_setting($db, 'alert_disk_threshold', '90'),
    'limit_days'        => (int)get_setting($db, 'alert_limit_days', '1'),
    'limit_count'       => (int)get_setting($db, 'alert_limit_count', '3'),
    'health_warn'       => (int)get_setting($db, 'health_warn_threshold', '80'),
    'health_danger'     => (int)get_setting($db, 'health_danger_threshold', '95'),
    'health_loss'       => (int)get_setting($db, 'health_loss_danger', '50'),
    'up_strictness'     => get_setting($db, 'uptime_strictness', 'balanced'),
    'up_yellow'         => (float)get_setting($db, 'uptime_yellow', '98'),
    'up_red'            => (float)get_setting($db, 'uptime_red', '90'),
    'up_loss_weight'    => (float)get_setting($db, 'uptime_loss_weight', '3'),
    'up_lat_weight'     => (float)get_setting($db, 'uptime_lat_weight', '1'),
    'time_start'        => get_setting($db, 'alert_time_start', '00:00'),
    'time_end'          => get_setting($db, 'alert_time_end', '23:59'),
    'smtp_enabled'      => get_setting($db, 'smtp_enabled', '0') === '1',
    'smtp_host'         => get_setting($db, 'smtp_host'),
    'smtp_port'         => get_setting($db, 'smtp_port', '465'),
    'smtp_encryption'   => get_setting($db, 'smtp_encryption', 'ssl'),
    'smtp_username'     => get_setting($db, 'smtp_username'),
    'smtp_from'         => get_setting($db, 'smtp_from'),
    'smtp_to'           => get_setting($db, 'smtp_to'),
    'smtp_has_pwd'      => get_setting($db, 'smtp_password') !== '',
    'webhook_enabled'   => get_setting($db, 'webhook_enabled', '0') === '1',
    'webhook_url'       => get_setting($db, 'webhook_url'),
    'webhook_token'     => get_setting($db, 'webhook_token'),
    'smsbao_enabled'    => get_setting($db, 'smsbao_enabled', '0') === '1',
    'smsbao_username'   => get_setting($db, 'smsbao_username'),
    'smsbao_mobile'     => get_setting($db, 'smsbao_mobile'),
    'smsbao_template'   => get_setting($db, 'smsbao_template', ''),
    'smsbao_has_pwd'    => get_setting($db, 'smsbao_password') !== '',
    'smsbao_api_line'     => get_setting($db, 'smsbao_api_line', 'domestic'),
    'smsbao_api_domestic' => get_setting($db, 'smsbao_api_domestic', 'https://u.smsbao.com/sms'),
    'smsbao_api_intl'     => get_setting($db, 'smsbao_api_intl', 'http://api.smsbao.com/sms'),
    'tencent_sms_enabled'        => get_setting($db, 'tencent_sms_enabled', '0') === '1',
    'tencent_sms_secret_id'      => get_setting($db, 'tencent_sms_secret_id'),
    'tencent_sms_app_id'         => get_setting($db, 'tencent_sms_app_id'),
    'tencent_sms_sign'           => get_setting($db, 'tencent_sms_sign'),
    'tencent_sms_template_id'    => get_setting($db, 'tencent_sms_template_id'),
    'tencent_sms_template_param' => get_setting($db, 'tencent_sms_template_param'),
    'tencent_sms_mobile'         => get_setting($db, 'tencent_sms_mobile'),
    'tencent_sms_has_key'        => get_setting($db, 'tencent_sms_secret_key') !== '',
    'aliyun_sms_enabled'         => get_setting($db, 'aliyun_sms_enabled', '0') === '1',
    'aliyun_sms_key_id'          => get_setting($db, 'aliyun_sms_key_id'),
    'aliyun_sms_sign'            => get_setting($db, 'aliyun_sms_sign'),
    'aliyun_sms_template_code'   => get_setting($db, 'aliyun_sms_template_code'),
    'aliyun_sms_template_param'  => get_setting($db, 'aliyun_sms_template_param'),
    'aliyun_sms_mobile'          => get_setting($db, 'aliyun_sms_mobile'),
    'aliyun_sms_has_key'         => get_setting($db, 'aliyun_sms_key_secret') !== '',
    'telegram_enabled'  => get_setting($db, 'telegram_enabled', '0') === '1',
    'telegram_token'    => get_setting($db, 'telegram_token'),
    'telegram_chat_id'  => get_setting($db, 'telegram_chat_id'),
];

$phpInfo = [
    'PHP 版本'      => PHP_VERSION . (PHP_VERSION_ID >= 80000 ? '' : '（建议使用 PHP 8.0+）'),
    'cURL 扩展'     => extension_loaded('curl') ? '已安装' : '未安装（网站探测/Webhook 不可用）',
    'openssl 扩展'  => extension_loaded('openssl') ? '已安装' : '未安装（SSL 检测/SMTP 加密不可用）',
    'sockets 扩展'  => extension_loaded('sockets') ? '已安装（纯 PHP ICMP Ping 可用）' : '未安装（PING 需依赖 exec）',
    'ssh2 扩展'     => extension_loaded('ssh2') ? '已安装（SSH 登录验证/资源监控可用）' : '未安装（SSH 仅检测服务可用性，资源监控不可用）',
    'GD 扩展'       => extension_loaded('gd') ? '已安装' : '未安装（验证码不可用）',
    'exec 函数'     => (function_exists('exec') && stripos((string)ini_get('disable_functions'), 'exec') === false)
                        ? '可用' : '被禁用（不影响纯 PHP Ping，仅 IPv6/降级场景需要）',
    '数据库类型'    => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
];

// URL 触发地址（宝塔「访问URL」计划任务使用）
$cronKey = CronService::cronKey($db);
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$cronUrl = sprintf('%s://%s/admin/api/cron.php?key=%s', $scheme, $host, $cronKey);

// 前台状态页地址
$frontUrl = sprintf('%s://%s/', $scheme, $host);

// 开放 API 凭证与调用示例（第三方只读调用后台全量数据）
$openApi          = open_api_credentials($db);
$openApiEnabled   = get_setting($db, 'open_api_enabled', '1') === '1';
$openApiUrl       = sprintf('%s://%s/admin/api/open.php?api_id=%s&api_key=%s', $scheme, $host, $openApi['id'], $openApi['key']);
$openApiHiddenUrl = $openApiUrl . '&include_hidden=1';
// 临时令牌登录（SSO）：仅支持先换取一次性 token 再登录（主管理员）
$ssoEnabled       = get_setting($db, 'sso_login_enabled', '1') === '1';

// 在线更新 / 授权状态（授权失败不影响本页其它功能）
$upd = UpdateService::status($db);
// 应用 ID 固定为 4：历史配置若不为 4，进入本页时自动纠正
if ((int)$upd['app_id'] !== 4) {
    set_setting($db, 'update_app_id', '4');
    $upd = UpdateService::status($db);
}

// 当前激活的 Tab（保存后回跳定位）
$tab = in_array($_GET['tab'] ?? '', ['basic', 'front', 'alert', 'notify', 'env', 'api', 'update'], true) ? $_GET['tab'] : 'basic';
$tabPane = function (string $key) use ($tab): string {
    return 'tab-pane fade' . ($tab === $key ? ' show active' : '');
};
$tabBtn = function (string $key, string $label) use ($tab): string {
    return '<button class="nav-link' . ($tab === $key ? ' active' : '') . '" data-bs-toggle="tab" data-bs-target="#set-' . $key . '" type="button" role="tab">' . $label . '</button>';
};

include __DIR__ . '/templates/header.php';
?>

<div class="row">
  <div class="col-12">
    <div class="card settings-card">
      <header class="card-header pb-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
          <li class="nav-item"><?php echo $tabBtn('basic', '<i class="mdi mdi-cog-outline me-1"></i>基本设置'); ?></li>
          <li class="nav-item"><?php echo $tabBtn('front', '<i class="mdi mdi-monitor-shimmer me-1"></i>前台页面'); ?></li>
          <li class="nav-item"><?php echo $tabBtn('alert', '<i class="mdi mdi-bell-outline me-1"></i>预警规则'); ?></li>
          <li class="nav-item"><?php echo $tabBtn('notify', '<i class="mdi mdi-send-outline me-1"></i>通知方式'); ?></li>
          <li class="nav-item"><?php echo $tabBtn('env', '<i class="mdi mdi-server me-1"></i>运行环境'); ?></li>
          <li class="nav-item"><?php echo $tabBtn('api', '<i class="mdi mdi-api me-1"></i>开放 API'); ?></li>
          <li class="nav-item"><?php echo $tabBtn('update', '<i class="mdi mdi-cloud-download-outline me-1"></i>在线更新'); ?></li>
        </ul>
      </header>
      <div class="card-body tab-content settings-body">

        <!-- Tab：基本设置 -->
        <div class="<?php echo $tabPane('basic'); ?>" id="set-basic">
          <div class="row g-4">
          <div class="col-lg-6">
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_basic">
            <h6 class="text-primary"><i class="mdi mdi-cog-outline"></i>基本设置</h6>
            <div class="mb-3">
              <label class="form-label">站点名称</label>
              <input type="text" name="site_name" class="form-control" required maxlength="50" value="<?php echo e($siteName); ?>">
            </div>
            <div class="mb-4">
              <label class="form-label">日志保留天数</label>
              <input type="number" name="log_retention_days" class="form-control" min="1" max="365" value="<?php echo $retention; ?>">
              <div class="form-text">计划任务每次运行时自动清理超期日志</div>
            </div>
            <div class="mb-4">
              <label class="form-label">备案信息（ICP 备案号）</label>
              <input type="text" name="icp_no" class="form-control" maxlength="100" placeholder="如：冀ICP备12345678号-1" value="<?php echo e($icpNo); ?>">
              <div class="form-text">显示在前台状态页底部，点击跳转工信部备案查询；留空则不显示</div>
            </div>
            <button type="submit" class="btn btn-primary">保存设置</button>
          </form>

          </div>
          <div class="col-lg-6">
          <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_brand">
            <h6 class="text-primary"><i class="mdi mdi-image-outline"></i>站点标识（网站图标 / Logo）</h6>

            <div class="setting-item">
              <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="brand-preview"><img src="<?php echo e($brand['favicon'] !== '' ? $brand['favicon'] : '../favicon.ico'); ?>" alt="favicon"></div>
                <div class="flex-grow-1" style="min-width: 240px;">
                  <label class="form-label mb-1">网站图标（Favicon）</label>
                  <input type="file" name="favicon_file" class="form-control form-control-sm" accept=".ico,.png,.svg">
                  <div class="form-text">浏览器标签页图标，建议 32×32 的 ico/png；不选文件则保持不变</div>
                </div>
                <?php if ($brand['favicon'] !== ''): ?>
                  <div class="form-check small flex-none">
                    <input class="form-check-input" type="checkbox" name="remove_site_favicon" id="rm_favicon">
                    <label class="form-check-label" for="rm_favicon">恢复默认</label>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="setting-item">
              <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="brand-preview"><img src="<?php echo e($brand['admin_logo'] !== '' ? $brand['admin_logo'] : '../images/logo-sidebar.png'); ?>" alt="admin logo"></div>
                <div class="flex-grow-1" style="min-width: 240px;">
                  <label class="form-label mb-1">后台左上角 Logo</label>
                  <input type="file" name="admin_logo_file" class="form-control form-control-sm" accept=".png,.jpg,.jpeg,.gif,.webp,.svg">
                  <div class="form-text">管理后台侧边栏顶部 Logo，建议透明底 PNG，高度 40px 左右</div>
                </div>
                <?php if ($brand['admin_logo'] !== ''): ?>
                  <div class="form-check small flex-none">
                    <input class="form-check-input" type="checkbox" name="remove_logo_admin" id="rm_admin_logo">
                    <label class="form-check-label" for="rm_admin_logo">恢复默认</label>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="setting-item">
              <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="brand-preview">
                  <?php if ($brand['front_logo'] !== ''): ?>
                    <img src="<?php echo e($brand['front_logo']); ?>" alt="front logo">
                  <?php else: ?>
                    <i class="mdi mdi-cat" style="font-size:26px;color:#7c5cfc;"></i>
                  <?php endif; ?>
                </div>
                <div class="flex-grow-1" style="min-width: 240px;">
                  <label class="form-label mb-1">前台左上角 Logo</label>
                  <input type="file" name="front_logo_file" class="form-control form-control-sm" accept=".png,.jpg,.jpeg,.gif,.webp,.svg">
                  <div class="form-text">前台状态页品牌区图标，建议正方形（44×44 展示）；未设置时使用默认图标</div>
                </div>
                <?php if ($brand['front_logo'] !== ''): ?>
                  <div class="form-check small flex-none">
                    <input class="form-check-input" type="checkbox" name="remove_logo_front" id="rm_front_logo">
                    <label class="form-check-label" for="rm_front_logo">恢复默认</label>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <button type="submit" class="btn btn-primary">保存站点标识</button>
          </form>
          </div>
          </div>
        </div>

        <!-- Tab：前台页面 -->
        <div class="<?php echo $tabPane('front'); ?>" id="set-front">
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_front">

            <div class="setting-item">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <label class="form-label mb-0" for="front_enabled">启用前台状态页</label>
                  <div class="form-text">关闭后前台页面将无法访问</div>
                </div>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="front_enabled" id="front_enabled" <?php echo $front['enabled'] ? 'checked' : ''; ?>>
                </div>
              </div>
            </div>

            <div class="setting-item">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <label class="form-label mb-0" for="front_show_resource">显示 SSH 资源占用</label>
                  <div class="form-text">前台展示开启资源监控的 SSH 节点的 CPU / 内存 / 磁盘占用</div>
                </div>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="front_show_resource" id="front_show_resource" <?php echo $front['show_resource'] ? 'checked' : ''; ?>>
                </div>
              </div>
            </div>

            <div class="setting-item">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <label class="form-label mb-0" for="sspping_ping_only">SSH+PING 节点仅展示 PING 延迟</label>
                  <div class="form-text">开启后 SSH+PING 类型节点在首页与前台只显示 PING 延迟；关闭时同时显示 SSH 延迟与 PING 延迟</div>
                </div>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="sspping_ping_only" id="sspping_ping_only" <?php echo $front['sspping_ping_only'] ? 'checked' : ''; ?>>
                </div>
              </div>
            </div>

            <div class="setting-item">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <label class="form-label mb-0" for="front_show_history">显示历史网络轨迹</label>
                  <div class="form-text">前台展示每个节点近期的可用率轨迹条与平均延迟</div>
                </div>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="front_show_history" id="front_show_history" <?php echo $front['show_history'] ? 'checked' : ''; ?>>
                </div>
              </div>
              <div class="input-group input-group-sm" style="max-width: 260px;">
                <span class="input-group-text">轨迹时长</span>
                <input type="number" name="front_history_hours" class="form-control" min="1" max="72" value="<?php echo $front['history_hours']; ?>">
                <span class="input-group-text">小时</span>
              </div>
              <div class="form-text">按小时聚合显示最近 N 小时可用率，受日志保留天数限制（当前 <?php echo $retention; ?> 天）</div>
            </div>

            <div class="alert alert-info small">
              前台地址：<a href="<?php echo e($frontUrl); ?>" target="_blank"><?php echo e($frontUrl); ?></a><br>
              需要隐藏个别节点或整个分类？在 <a href="servers.php">服务器管理</a> / <a href="groups.php">节点分类</a> 中设置「前台隐藏」即可。
            </div>

            <button type="submit" class="btn btn-primary">保存前台设置</button>
          </form>
        </div>

        <!-- Tab：预警规则 -->
        <div class="<?php echo $tabPane('alert'); ?>" id="set-alert">
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_alert_rules">

          <h6 class="text-primary mb-3"><i class="mdi mdi-alert-circle-outline me-1"></i>预警规则</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="setting-item">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <label class="form-label mb-0" for="alert_offline_enabled">离线预警</label>
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="alert_offline_enabled" id="alert_offline_enabled" <?php echo $alert['offline_enabled'] ? 'checked' : ''; ?>>
                  </div>
                </div>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">连续失败</span>
                  <input type="number" name="alert_offline_threshold" class="form-control" min="1" max="100" value="<?php echo $alert['offline_threshold']; ?>">
                  <span class="input-group-text">次触发</span>
                </div>
                <div class="form-text">节点连续探测失败达到次数后发送离线预警</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="setting-item">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <label class="form-label mb-0" for="alert_ssl_enabled">SSL 到期预警</label>
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="alert_ssl_enabled" id="alert_ssl_enabled" <?php echo $alert['ssl_enabled'] ? 'checked' : ''; ?>>
                  </div>
                </div>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">剩余不足</span>
                  <input type="number" name="alert_ssl_days" class="form-control" min="0" max="365" value="<?php echo $alert['ssl_days']; ?>">
                  <span class="input-group-text">天触发</span>
                </div>
                <div class="form-text">HTTPS 节点证书即将到期时提前提醒</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="setting-item">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <label class="form-label mb-0" for="alert_resource_enabled">资源预警（SSH）</label>
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="alert_resource_enabled" id="alert_resource_enabled" <?php echo $alert['resource_enabled'] ? 'checked' : ''; ?>>
                  </div>
                </div>
                <div class="row g-2">
                  <div class="col-4">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text">CPU</span>
                      <input type="number" name="alert_cpu_threshold" class="form-control" min="1" max="100" value="<?php echo $alert['cpu_threshold']; ?>">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                  <div class="col-4">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text">内存</span>
                      <input type="number" name="alert_mem_threshold" class="form-control" min="1" max="100" value="<?php echo $alert['mem_threshold']; ?>">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                  <div class="col-4">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text">磁盘</span>
                      <input type="number" name="alert_disk_threshold" class="form-control" min="1" max="100" value="<?php echo $alert['disk_threshold']; ?>">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                </div>
                <div class="form-text">对开启资源监控的 SSH / 宝塔节点生效；任一数据盘超阈值即预警，消息列出挂载点</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="setting-item">
                <label class="form-label">预警时间窗口</label>
                <div class="input-group input-group-sm">
                  <input type="time" name="alert_time_start" class="form-control" value="<?php echo e($alert['time_start']); ?>">
                  <span class="input-group-text">至</span>
                  <input type="time" name="alert_time_end" class="form-control" value="<?php echo e($alert['time_end']); ?>">
                </div>
                <div class="form-text">其余时间不发送预警，支持跨天（如 22:00 至 08:00）</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="setting-item">
                <label class="form-label">频率限制（每节点每类预警）</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">每</span>
                  <input type="number" name="alert_limit_days" class="form-control" min="1" max="30" value="<?php echo $alert['limit_days']; ?>">
                  <span class="input-group-text">天最多</span>
                  <input type="number" name="alert_limit_count" class="form-control" min="1" max="100" value="<?php echo $alert['limit_count']; ?>">
                  <span class="input-group-text">次</span>
                </div>
                <div class="form-text">避免同一故障短时间内重复轰炸</div>
              </div>
            </div>
          </div>

          <h6 class="text-primary mb-3 mt-4"><i class="mdi mdi-heart-pulse-outline me-1"></i>健康状态判定（首页总览）</h6>
          <div class="row g-3">
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">资源占用 → 告警</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">≥</span>
                  <input type="number" name="health_warn_threshold" class="form-control" min="1" max="100" value="<?php echo $alert['health_warn']; ?>">
                  <span class="input-group-text">%</span>
                </div>
              </div>
            </div>
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">资源占用 → 危险</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">≥</span>
                  <input type="number" name="health_danger_threshold" class="form-control" min="1" max="100" value="<?php echo $alert['health_danger']; ?>">
                  <span class="input-group-text">%</span>
                </div>
              </div>
            </div>
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">PING 丢包 → 危险</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">≥</span>
                  <input type="number" name="health_loss_danger" class="form-control" min="1" max="100" value="<?php echo $alert['health_loss']; ?>">
                  <span class="input-group-text">%</span>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="form-text">资源指 SSH 节点的 CPU/负载/内存/磁盘；丢包大于 0 即告警，完全 ping 不通即离线</div>
            </div>
          </div>

          <h6 class="text-primary mb-3 mt-4"><i class="mdi mdi-speedometer me-1"></i>可用率评分（网络质量）</h6>
          <div class="row g-3">
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">评分松紧度</label>
                <select name="uptime_strictness" class="form-select form-select-sm">
                  <?php foreach (['ultra_strict' => '超严', 'strict' => '严', 'balanced' => '平衡', 'lenient' => '松', 'ultra_lenient' => '超松'] as $sv => $sl): ?>
                  <option value="<?php echo $sv; ?>" <?php echo $alert['up_strictness'] === $sv ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">延迟波动判定灵敏度：超严＝超最优延迟 1.1 倍即算波动且扣分最重，超松＝3 倍才算且扣分最轻</div>
              </div>
            </div>
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">可用率 → 绿色</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">≥</span>
                  <input type="number" name="uptime_yellow" class="form-control" min="0" max="100" step="0.1" value="<?php echo $alert['up_yellow']; ?>">
                  <span class="input-group-text">%</span>
                </div>
                <div class="form-text">低于此值显示黄色</div>
              </div>
            </div>
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">可用率 → 黄色</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">≥</span>
                  <input type="number" name="uptime_red" class="form-control" min="0" max="100" step="0.1" value="<?php echo $alert['up_red']; ?>">
                  <span class="input-group-text">%</span>
                </div>
                <div class="form-text">低于此值显示红色</div>
              </div>
            </div>
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">丢包掉分权重</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="uptime_loss_weight" class="form-control" min="0" max="10" step="0.5" value="<?php echo $alert['up_loss_weight']; ?>">
                  <span class="input-group-text">倍</span>
                </div>
                <div class="form-text">每 1% 丢包扣减该权重 % 的单次得分；0＝丢包不扣分，默认 3</div>
              </div>
            </div>
            <div class="col-md-6 col-xl-4">
              <div class="setting-item">
                <label class="form-label">波动掉分权重</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="uptime_lat_weight" class="form-control" min="0" max="3" step="0.1" value="<?php echo $alert['up_lat_weight']; ?>">
                  <span class="input-group-text">倍</span>
                </div>
                <div class="form-text">延迟波动扣分的倍率；0＝波动不扣分，1＝默认力度</div>
              </div>
            </div>
          </div>

            <div class="pt-2">
              <button type="submit" class="btn btn-primary">保存预警规则</button>
            </div>
          </form>
        </div>

        <!-- Tab：通知方式 -->
        <div class="<?php echo $tabPane('notify'); ?>" id="set-notify">
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_notify">

            <h6 class="text-primary mb-3"><i class="mdi mdi-email-outline me-1"></i>SMTP 邮件通知</h6>
          <div class="setting-item">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <label class="form-label mb-0" for="smtp_enabled">启用邮件通知</label>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtp_enabled" <?php echo $alert['smtp_enabled'] ? 'checked' : ''; ?>>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-5">
                <label class="form-label">SMTP 服务器</label>
                <input type="text" name="smtp_host" class="form-control form-control-sm" placeholder="smtp.qq.com" value="<?php echo e($alert['smtp_host']); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">端口</label>
                <input type="number" name="smtp_port" class="form-control form-control-sm" min="1" max="65535" value="<?php echo e($alert['smtp_port']); ?>">
              </div>
              <div class="col-md-5">
                <label class="form-label">加密方式</label>
                <select name="smtp_encryption" class="form-select form-select-sm">
                  <option value="ssl" <?php echo $alert['smtp_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL（端口 465）</option>
                  <option value="tls" <?php echo $alert['smtp_encryption'] === 'tls' ? 'selected' : ''; ?>>STARTTLS（端口 587）</option>
                  <option value="none" <?php echo $alert['smtp_encryption'] === 'none' ? 'selected' : ''; ?>>不加密（端口 25）</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">SMTP 用户名</label>
                <input type="text" name="smtp_username" class="form-control form-control-sm" value="<?php echo e($alert['smtp_username']); ?>" autocomplete="off">
              </div>
              <div class="col-md-6">
                <label class="form-label">SMTP 密码/授权码</label>
                <input type="password" name="smtp_password" class="form-control form-control-sm" autocomplete="new-password"
                       placeholder="<?php echo $alert['smtp_has_pwd'] ? '留空表示不修改' : ''; ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">发信人地址</label>
                <input type="email" name="smtp_from" class="form-control form-control-sm" placeholder="noreply@example.com" value="<?php echo e($alert['smtp_from']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">收件人（多个用逗号分隔）</label>
                <input type="text" name="smtp_to" class="form-control form-control-sm" placeholder="admin@example.com" value="<?php echo e($alert['smtp_to']); ?>">
              </div>
            </div>
          </div>

            <h6 class="text-primary mb-3 mt-4"><i class="mdi mdi-webhook me-1"></i>Webhook 通知</h6>
          <div class="setting-item">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <label class="form-label mb-0" for="webhook_enabled">启用 Webhook（POST JSON）</label>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="webhook_enabled" id="webhook_enabled" <?php echo $alert['webhook_enabled'] ? 'checked' : ''; ?>>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Webhook URL</label>
                <input type="url" name="webhook_url" class="form-control form-control-sm" placeholder="https://example.com/webhook" value="<?php echo e($alert['webhook_url']); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">密钥（可选，随 X-Probe-Token 头发送）</label>
                <input type="text" name="webhook_token" class="form-control form-control-sm" value="<?php echo e($alert['webhook_token']); ?>">
              </div>
            </div>
          </div>

            <h6 class="text-primary mb-3 mt-4"><i class="mdi mdi-message-text-outline me-1"></i>短信通知</h6>
          <div class="setting-item">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <label class="form-label mb-0" for="sms_enabled">启用短信通知</label>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="sms_enabled" id="sms_enabled" <?php echo $sms['enabled'] ? 'checked' : ''; ?>>
              </div>
            </div>
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label" for="sms_provider">短信接口</label>
                <select name="sms_provider" id="sms_provider" class="form-select form-select-sm" data-saved-provider="<?php echo e($sms['provider']); ?>" aria-describedby="sms-provider-help">
                  <?php foreach ($smsLabels as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $sms['provider'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 form-text" id="sms-provider-help">仅通过所选接口发送短信；切换后请保存，其他接口已保存的配置会保留。</div>
            </div>
            <fieldset data-sms-provider="smsbao" <?php echo $sms['provider'] !== 'smsbao' ? 'hidden disabled' : ''; ?>>
              <legend class="visually-hidden">短信宝配置</legend>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">API 接口线路</label>
                <select name="smsbao_api_line" class="form-select form-select-sm">
                  <option value="domestic" <?php echo $alert['smsbao_api_line'] === 'domestic' ? 'selected' : ''; ?>>国内线路</option>
                  <option value="intl" <?php echo $alert['smsbao_api_line'] === 'intl' ? 'selected' : ''; ?>>国外线路</option>
                </select>
                <div class="form-text">发送时使用对应线路的 API 地址</div>
              </div>
              <div class="col-12">
                <label class="form-label">国内 API 地址</label>
                <input type="url" name="smsbao_api_domestic" class="form-control form-control-sm" maxlength="255"
                       placeholder="https://u.smsbao.com/sms" value="<?php echo e($alert['smsbao_api_domestic']); ?>">
                <div class="form-text">默认短信宝国内接口，可改为任何兼容短信宝标准（u/p/m/c 参数）的地址；留空恢复默认</div>
              </div>
              <div class="col-12">
                <label class="form-label">国外 API 地址</label>
                <input type="url" name="smsbao_api_intl" class="form-control form-control-sm" maxlength="255"
                       placeholder="http://api.smsbao.com/sms" value="<?php echo e($alert['smsbao_api_intl']); ?>">
                <div class="form-text">默认短信宝国际接口；留空恢复默认</div>
              </div>
              <div class="col-md-4">
                <label class="form-label">短信宝账号</label>
                <input type="text" name="smsbao_username" class="form-control form-control-sm" placeholder="smsbao.com 注册账号" value="<?php echo e($alert['smsbao_username']); ?>" autocomplete="off">
              </div>
              <div class="col-md-4">
                <label class="form-label">短信宝密码</label>
                <input type="password" name="smsbao_password" class="form-control form-control-sm" autocomplete="new-password"
                       placeholder="<?php echo $alert['smsbao_has_pwd'] ? '留空表示不修改' : ''; ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">接收手机号（多个用逗号分隔）</label>
                <input type="text" name="smsbao_mobile" class="form-control form-control-sm" placeholder="13800138000,13900139000" value="<?php echo e($alert['smsbao_mobile']); ?>">
              </div>
              <div class="col-12">
                <label class="form-label">短信模版</label>
                <input type="text" name="smsbao_template" class="form-control form-control-sm" maxlength="255"
                       placeholder="【{site}】节点预警：{name}，{message}" value="<?php echo e($alert['smsbao_template']); ?>">
                <div class="form-text">留空使用默认模版；可用占位符：<code>{site}</code> 站点名、<code>{name}</code> 节点名、<code>{type}</code> 探测方式、<code>{address}</code> 地址、<code>{message}</code> 预警详情、<code>{time}</code> 时间；内容需含短信宝后台已报备的签名（如【xxx】）</div>
              </div>
            </div>
            </fieldset>

            <fieldset data-sms-provider="tencent_sms" <?php echo $sms['provider'] !== 'tencent_sms' ? 'hidden disabled' : ''; ?>>
              <legend class="visually-hidden">腾讯云配置</legend>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">SecretId</label>
                <input type="text" name="tencent_sms_secret_id" class="form-control form-control-sm" placeholder="腾讯云 API 密钥 SecretId" value="<?php echo e($alert['tencent_sms_secret_id']); ?>" autocomplete="off">
              </div>
              <div class="col-md-6">
                <label class="form-label">SecretKey</label>
                <input type="password" name="tencent_sms_secret_key" class="form-control form-control-sm" autocomplete="new-password" placeholder="<?php echo $alert['tencent_sms_has_key'] ? '留空表示不修改' : '腾讯云 API 密钥 SecretKey'; ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">短信应用 ID（SmsSdkAppId）</label>
                <input type="text" name="tencent_sms_app_id" class="form-control form-control-sm" placeholder="1400xxxxxx" value="<?php echo e($alert['tencent_sms_app_id']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">短信签名（SignName）</label>
                <input type="text" name="tencent_sms_sign" class="form-control form-control-sm" placeholder="腾讯云已审核通过的签名" value="<?php echo e($alert['tencent_sms_sign']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">模板 ID（TemplateId）</label>
                <input type="text" name="tencent_sms_template_id" class="form-control form-control-sm" placeholder="如 1234567" value="<?php echo e($alert['tencent_sms_template_id']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">接收手机号（多个用逗号分隔）</label>
                <input type="text" name="tencent_sms_mobile" class="form-control form-control-sm" placeholder="13800138000,13900139000" value="<?php echo e($alert['tencent_sms_mobile']); ?>">
              </div>
              <div class="col-12">
                <label class="form-label">模板参数（TemplateParamSet）</label>
                <input type="text" name="tencent_sms_template_param" class="form-control form-control-sm" maxlength="255" placeholder="{name}||{type}||{time}" value="<?php echo e($alert['tencent_sms_template_param']); ?>">
                <div class="form-text">用 <code>||</code> 分隔多个参数，依次对应模板中的 <code>{1}</code> <code>{2}</code> …；每个参数可用占位符 <code>{site}</code> <code>{name}</code> <code>{type}</code> <code>{address}</code> <code>{message}</code> <code>{time}</code>。腾讯云限制单个参数长度，<code>{message}</code> 可能过长请酌情；国内号码自动补 +86，国际号码请自带 + 区号</div>
              </div>
            </div>
            </fieldset>

            <fieldset data-sms-provider="aliyun_sms" <?php echo $sms['provider'] !== 'aliyun_sms' ? 'hidden disabled' : ''; ?>>
              <legend class="visually-hidden">阿里云配置</legend>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">AccessKey ID</label>
                <input type="text" name="aliyun_sms_key_id" class="form-control form-control-sm" placeholder="阿里云 AccessKey ID" value="<?php echo e($alert['aliyun_sms_key_id']); ?>" autocomplete="off">
              </div>
              <div class="col-md-6">
                <label class="form-label">AccessKey Secret</label>
                <input type="password" name="aliyun_sms_key_secret" class="form-control form-control-sm" autocomplete="new-password" placeholder="<?php echo $alert['aliyun_sms_has_key'] ? '留空表示不修改' : '阿里云 AccessKey Secret'; ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">短信签名（SignName）</label>
                <input type="text" name="aliyun_sms_sign" class="form-control form-control-sm" placeholder="阿里云已审核通过的签名" value="<?php echo e($alert['aliyun_sms_sign']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">模板 CODE（TemplateCode）</label>
                <input type="text" name="aliyun_sms_template_code" class="form-control form-control-sm" placeholder="如 SMS_123456789" value="<?php echo e($alert['aliyun_sms_template_code']); ?>">
              </div>
              <div class="col-12">
                <label class="form-label">接收手机号（多个用逗号分隔）</label>
                <input type="text" name="aliyun_sms_mobile" class="form-control form-control-sm" placeholder="13800138000,13900139000" value="<?php echo e($alert['aliyun_sms_mobile']); ?>">
              </div>
              <div class="col-12">
                <label class="form-label">模板参数（TemplateParam，JSON）</label>
                <input type="text" name="aliyun_sms_template_param" class="form-control form-control-sm" maxlength="255" placeholder='{"name":"{name}","time":"{time}"}' value="<?php echo e($alert['aliyun_sms_template_param']); ?>">
                <div class="form-text">填写 JSON，键名对应模板中的 <code>${name}</code>、<code>${time}</code> 等变量；值内可用占位符 <code>{site}</code> <code>{name}</code> <code>{type}</code> <code>{address}</code> <code>{message}</code> <code>{time}</code>。留空表示模板无变量</div>
              </div>
            </div>
            </fieldset>
          </div>

            <h6 class="text-primary mb-3 mt-4"><i class="mdi mdi-telegram me-1"></i>Telegram 推送</h6>
          <div class="setting-item">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <label class="form-label mb-0" for="telegram_enabled">启用 Telegram 推送</label>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="telegram_enabled" id="telegram_enabled" <?php echo $alert['telegram_enabled'] ? 'checked' : ''; ?>>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label">Bot Token</label>
                <input type="text" name="telegram_token" class="form-control form-control-sm" placeholder="通过 @BotFather 创建机器人获取" value="<?php echo e($alert['telegram_token']); ?>" autocomplete="off">
              </div>
              <div class="col-md-5">
                <label class="form-label">Chat ID（多个用逗号分隔）</label>
                <input type="text" name="telegram_chat_id" class="form-control form-control-sm" placeholder="如 123456789 或 @频道名" value="<?php echo e($alert['telegram_chat_id']); ?>">
              </div>
              <div class="col-12">
                <div class="form-text mb-0">服务器需能访问 api.telegram.org（国内服务器可能需要代理）；Chat ID 可通过 @userinfobot 获取，先给机器人发一条消息再推送</div>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap align-items-center pt-2">
            <button type="submit" class="btn btn-primary">保存通知方式</button>
            <button type="button" class="btn btn-outline-primary" onclick="notifyTest('test_smtp')"><i class="mdi mdi-send-check-outline me-1"></i>发送测试邮件</button>
            <button type="button" class="btn btn-outline-primary" onclick="notifyTest('test_webhook')"><i class="mdi mdi-send-check-outline me-1"></i>发送测试 Webhook</button>
            <button type="button" class="btn btn-outline-primary" onclick="notifyTest('test_sms')"><i class="mdi mdi-send-check-outline me-1"></i><span id="sms-test-label">测试<?php echo e($smsLabels[$sms['provider']]); ?>短信</span></button>
            <button type="button" class="btn btn-outline-primary" onclick="notifyTest('test_telegram')"><i class="mdi mdi-send-check-outline me-1"></i>发送测试 Telegram</button>
            <span class="text-muted small">测试使用已保存的配置，请先保存</span>
          </div>
          </form>
        </div>

        <!-- Tab：运行环境 -->
        <div class="<?php echo $tabPane('env'); ?>" id="set-env">
          <h6 class="text-primary mb-3"><i class="mdi mdi-language-php me-1"></i>PHP 环境</h6>
          <table class="table table-sm">
            <tbody>
              <?php foreach ($phpInfo as $k => $v): ?>
                <tr><th style="width: 150px;"><?php echo $k; ?></th><td><?php echo e($v); ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <h6 class="text-primary mb-3 mt-4"><i class="mdi mdi-clock-outline me-1"></i>定时探测计划任务</h6>
          <div class="alert alert-info small mb-0">
            需配置计划任务（每分钟执行一次），两种方式任选其一：<br>
            ① <b>Shell 脚本</b>：<code>/www/server/php/<?php echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION; ?>/bin/php <?php echo e(ADMIN_ROOT); ?>/cron/probe.php</code><br>
            ② <b>访问 URL</b>：<code id="cron-url"><?php echo e($cronUrl); ?></code>
            <button type="button" class="btn btn-xs btn-secondary ms-1" onclick="copyCronUrl()">复制</button>
            <form method="post" class="d-inline" onsubmit="return confirm('重新生成后旧地址立即失效，确定？');">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="regen_cron_key">
              <button type="submit" class="btn btn-xs btn-outline-secondary">重新生成密钥</button>
            </form>
          </div>

        </div>

        <!-- Tab：开放 API -->
        <div class="<?php echo $tabPane('api'); ?>" id="set-api">
          <form method="post" class="mb-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_open_api">
            <div class="d-flex justify-content-between align-items-center gap-2">
              <div>
                <label class="form-label mb-0" for="open_api_enabled">启用开放 API</label>
                <div class="form-text">供第三方调用后台全量数据、性能趋势、历史质量，并可触发探测（不含登录凭证）</div>
              </div>
              <div class="d-flex align-items-center gap-2 flex-none">
                <a href="api_doc.php" class="btn btn-sm btn-outline-primary"><i class="mdi mdi-book-open-outline me-1"></i>查看完整文档</a>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="open_api_enabled" id="open_api_enabled" <?php echo $openApiEnabled ? 'checked' : ''; ?>>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">保存</button>
              </div>
            </div>
          </form>

          <table class="table table-sm align-middle mb-3">
            <tbody>
              <tr>
                <th style="width: 92px;">API ID</th>
                <td><code id="open-api-id"><?php echo e($openApi['id']); ?></code></td>
                <td style="width: 60px;" class="text-end"><button type="button" class="btn btn-xs btn-outline-secondary" onclick="copyById('open-api-id')">复制</button></td>
              </tr>
              <tr>
                <th>API Key</th>
                <td><code id="open-api-key" class="text-break"><?php echo e($openApi['key']); ?></code></td>
                <td class="text-end"><button type="button" class="btn btn-xs btn-outline-secondary" onclick="copyById('open-api-key')">复制</button></td>
              </tr>
              <tr>
                <th>接口地址</th>
                <td colspan="2"><code class="text-break"><?php echo e($scheme); ?>://<?php echo e($host); ?>/admin/api/open.php</code></td>
              </tr>
              <tr>
                <th>调用示例</th>
                <td><code id="open-api-url" class="text-break"><?php echo e($openApiUrl); ?></code></td>
                <td class="text-end"><button type="button" class="btn btn-xs btn-outline-secondary" onclick="copyById('open-api-url')">复制</button></td>
              </tr>
              <tr>
                <th>含隐藏节点</th>
                <td><code id="open-api-hidden-url" class="text-break"><?php echo e($openApiHiddenUrl); ?></code></td>
                <td class="text-end"><button type="button" class="btn btn-xs btn-outline-secondary" onclick="copyById('open-api-hidden-url')">复制</button></td>
              </tr>
            </tbody>
          </table>

          <form method="post" class="mb-3" onsubmit="return confirm('重新生成后旧的 API ID / Key 立即失效，确定？');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="regen_open_api">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-refresh me-1"></i>重新生成凭证</button>
          </form>

          <!-- 临时令牌登录（SSO） -->
          <form method="post" class="mb-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_sso">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
              <div>
                <label class="form-label mb-0" for="sso_login_enabled">临时令牌登录（SSO）</label>
                <div class="form-text">仅支持先换取一次性临时 token 再登录，不再提供含 API Key 的直登链接</div>
              </div>
              <div class="d-flex align-items-center gap-2 flex-none">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="sso_login_enabled" id="sso_login_enabled" <?php echo $ssoEnabled ? 'checked' : ''; ?>>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">保存</button>
              </div>
            </div>
            <div class="alert alert-info py-2 small mb-0">
              <i class="mdi mdi-shield-key-outline me-1"></i><b>登录方式</b>：先 <code>POST open.php</code>（<code>action=token</code>，携带 API 凭证）换取短期一次性 token（默认 120 秒），再访问 <code>sso.php?token=…</code> 登录；token 用后即失效，避免 api_key 出现在 URL / 日志。详见 <a href="api_doc.php">API 文档</a>。<?php echo $ssoEnabled ? '' : ' <b>当前已停用。</b>'; ?>
            </div>
          </form>

          <div class="mb-2"><b>接口动作</b> <span class="text-muted small">通过 <code>action</code> 参数区分，默认 <code>overview</code></span></div>
          <table class="table table-sm align-middle mb-3">
            <thead>
              <tr><th style="width: 96px;">action</th><th>用途</th><th style="width: 250px;">关键参数</th></tr>
            </thead>
            <tbody>
              <tr><td><code>overview</code></td><td>全量分类 + 节点卡片（只读，默认）</td><td><code>include_hidden</code> <code>with_resource</code> <code>with_history</code></td></tr>
              <tr><td><code>probe_all</code></td><td>立即探测所有启用节点<span class="text-danger">（触发探测）</span></td><td class="text-muted">—</td></tr>
              <tr><td><code>probe</code></td><td>立即探测指定节点<span class="text-danger">（触发探测）</span></td><td><code>server_id</code></td></tr>
              <tr><td><code>trend</code></td><td>性能趋势：CPU / 内存 / 磁盘 / 负载 / 带宽时序（仅 SSH / 宝塔）</td><td><code>server_id</code> <code>range=1h|12h|24h|3d|7d|30d</code></td></tr>
              <tr><td><code>quality</code></td><td>历史质量：延迟 / 丢包 / 可用率时序 + 最近 50 次探测条</td><td><code>server_id</code> <code>range=1h|6h|24h|7d</code></td></tr>
              <tr><td><code>alerts</code></td><td>告警记录查询（离线 / SSL / 资源 / 测试）</td><td><code>type</code> <code>server_id</code> <code>since</code> <code>limit</code> <code>page</code></td></tr>
              <tr><td><code>alert_config</code></td><td>告警规则阈值 + 渠道开关（只读，不含凭证）</td><td class="text-muted">—</td></tr>
            </tbody>
          </table>

          <div class="text-muted small">
            <div class="mb-1"><b>鉴权</b>：Query 传 <code>api_id</code> + <code>api_key</code>，或请求头 <code>X-Api-Id</code> / <code>X-Api-Key</code>；所有 action 均需鉴权。</div>
            <div class="mb-1"><b>overview 参数</b>：<code>include_hidden=1</code> 含隐藏 / 停用节点与空分类；<code>with_resource=0</code> 不返回资源占用；<code>with_history=1</code> 返回按小时可用率数组。</div>
            <div class="mb-1"><b>示例</b>：<code class="text-break">…/open.php?api_id=…&amp;api_key=…&amp;action=quality&amp;server_id=4&amp;range=24h</code></div>
            <div><b>说明</b>：<code>overview</code> / <code>trend</code> / <code>quality</code> / <code>alerts</code> / <code>alert_config</code> 只读；<code>probe</code> / <code>probe_all</code> 会立即触发探测（<code>probe_all</code> 带并发锁，节点多时较慢）。均不返回登录凭证。</div>
          </div>
        </div>

        <!-- Tab：在线更新 -->
        <div class="<?php echo $tabPane('update'); ?>" id="set-update">
          <div>
            <h6 class="text-primary"><i class="mdi mdi-cellphone-link me-1"></i>在线更新</h6>

            <!-- 版本 / 运行环境：轻量文本 + 小图标，不用色块 -->
            <div class="d-flex flex-wrap align-items-center gap-3 small mb-2">
              <span class="text-muted">当前版本 <b class="text-body">v<?php echo e($upd['version']); ?></b></span>
              <?php if ($upd['version_name'] !== ''): ?><span class="text-muted">授权版本 <b class="text-body"><?php echo e($upd['version_name']); ?></b></span><?php endif; ?>
              <?php if ($upd['last_update_at'] !== ''): ?><span class="text-muted">上次更新 <?php echo e($upd['last_update_at']); ?></span><?php endif; ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3 small text-muted mb-2">
              <span><i class="mdi <?php echo $upd['curl_ext'] ? 'mdi-check-circle-outline text-success' : 'mdi-close-circle-outline text-danger'; ?> me-1"></i>cURL</span>
              <span><i class="mdi <?php echo $upd['zip_ext'] ? 'mdi-check-circle-outline text-success' : 'mdi-close-circle-outline text-danger'; ?> me-1"></i>Zip 扩展</span>
              <span><i class="mdi <?php echo $upd['root_writable'] ? 'mdi-check-circle-outline text-success' : 'mdi-close-circle-outline text-danger'; ?> me-1"></i>目录可写</span>
            </div>
            <p class="form-text mb-4">授权检测失败仅影响「在线升级」，不影响系统正常使用；升级逐版本执行：备份 → 数据库 SQL → 解压覆盖，任一步失败自动回滚。</p>

            <!-- 授权配置 -->
            <form method="post" class="mb-4">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="save_update">
              <h6 class="text-primary mb-3"><i class="mdi mdi-key-variant me-1"></i>授权配置</h6>
              <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">授权码（license_key）</label>
                <input type="text" name="license_key" class="form-control" autocomplete="off" placeholder="<?php echo $upd['license_set'] ? '已保存：' . e($upd['license_mask']) . '（留空则不修改）' : '请输入 27 位授权码'; ?>">
                <div class="form-text">当前域名：<code><?php echo e($upd['domain']); ?></code>　接口地址：<code class="text-break"><?php echo e($upd['api_base']); ?></code></div>
              </div>
              </div>
              <button type="submit" class="btn btn-primary">保存配置</button>
            </form>

            <!-- 授权状态 + 操作 -->
            <h6 class="text-primary mb-2"><i class="mdi mdi-shield-check-outline me-1"></i>授权与更新</h6>
            <div id="upd-auth" class="small text-muted mb-3">
              <?php if ($upd['license_status'] !== ''): ?>
                <i class="mdi mdi-check-circle text-success me-1"></i>已授权<?php echo $upd['version_name'] !== '' ? ' · 版本 ' . e($upd['version_name']) : ''; ?><?php echo $upd['expire_at'] !== '' ? ' · 到期 ' . e($upd['expire_at']) : ''; ?>
              <?php elseif ($upd['license_set']): ?>
                <i class="mdi mdi-circle-outline text-muted me-1"></i>已配置授权码，点击「验证授权」后即可检查更新。
              <?php else: ?>
                <i class="mdi mdi-circle-outline text-muted me-1"></i>尚未配置授权码；未授权不影响系统正常使用，仅无法在线升级。
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-query"><i class="mdi mdi-magnify me-1"></i>查询授权</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-verify"><i class="mdi mdi-check-decagram me-1"></i>验证授权</button>
              <button type="button" class="btn btn-sm btn-primary" id="btn-check"><i class="mdi mdi-cloud-download-outline me-1"></i>检查更新</button>
            </div>
            <div id="upd-result"></div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
function copyCronUrl() {
  var t = document.getElementById('cron-url').innerText;
  if (navigator.clipboard) { navigator.clipboard.writeText(t); probeNotify('已复制', 'success'); }
}
// 通用：复制指定元素的文本（开放 API 的 ID / Key / 示例地址）
function copyById(id) {
  var el = document.getElementById(id);
  if (!el) { return; }
  var t = (el.innerText || el.textContent || '').trim();
  if (navigator.clipboard) { navigator.clipboard.writeText(t); probeNotify('已复制', 'success'); }
}
// 短信接口切换：只显示并提交所选配置，切回时保留尚未提交的输入。
(function () {
  var select = document.getElementById('sms_provider');
  if (!select) { return; }
  function syncSmsProvider() {
    document.querySelectorAll('#set-notify [data-sms-provider]').forEach(function (panel) {
      var active = panel.getAttribute('data-sms-provider') === select.value;
      panel.hidden = !active;
      panel.disabled = !active;
    });
    document.getElementById('sms-test-label').textContent = '测试' + select.options[select.selectedIndex].text + '短信';
  }
  select.addEventListener('change', syncSmsProvider);
  syncSmsProvider();
})();
// 通知方式测试：临时替换 action 后提交（避免按钮自身 name/value 干扰）
function notifyTest(action) {
  var provider = document.getElementById('sms_provider');
  if (action === 'test_sms' && provider.value !== provider.dataset.savedProvider) {
    probeNotify('短信接口已切换，请先保存通知方式再测试', 'warning');
    return;
  }
  var f = document.querySelector('#set-notify form');
  var a = f.querySelector('[name=action]');
  var old = a.value;
  a.value = action;
  f.submit();
  a.value = old;
}
// Tab 切换同步 URL，刷新/分享后停留在当前 Tab
document.querySelectorAll('.settings-card .card-header-tabs [data-bs-toggle="tab"]').forEach(function (btn) {
  btn.addEventListener('shown.bs.tab', function () {
    var key = btn.getAttribute('data-bs-target').replace('#set-', '');
    if (history.replaceState) { history.replaceState(null, '', 'settings.php?tab=' + key); }
  });
});

// ===== 在线更新（授权查询 / 验证 / 检查更新 / 逐版本升级）=====
(function () {
  var $auth = document.getElementById('upd-auth');
  var $result = document.getElementById('upd-result');
  if (!$auth || !$result) { return; }

  function busy(btn, on, text) {
    if (!btn) { return; }
    if (on) { btn.dataset.oldHtml = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (text || '处理中'); }
    else { btn.disabled = false; if (btn.dataset.oldHtml) { btn.innerHTML = btn.dataset.oldHtml; } }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function post(action, extra) {
    var body = new URLSearchParams();
    body.append('action', action);
    if (extra) { Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); }); }
    return fetch('api/update.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok || j.code !== 0) { throw new Error((j.data && (j.data.message || j.data.msg)) || ('HTTP ' + r.status)); }
        return j.data || {};
      });
    });
  }
  function authHtml(ok, msg, extra) {
    var icon = ok ? '<i class="mdi mdi-check-circle text-success me-1"></i>' : '<i class="mdi mdi-close-circle text-danger me-1"></i>';
    return '<div>' + icon + '<span class="text-body">' + esc(msg || '') + '</span>' + (extra || '') + '</div>';
  }
  function licenseExtra(d) {
    var x = '';
    if (d.data && d.data.version_name) { x += '　版本：' + esc(d.data.version_name); }
    if (d.data && d.data.expire_at) { x += '　到期：' + esc(d.data.expire_at); }
    return x;
  }

  document.getElementById('btn-query').addEventListener('click', function () {
    var b = this; busy(b, true, '查询中');
    post('query').then(function (d) {
      busy(b, false);
      $auth.innerHTML = authHtml(d.ok, d.msg, licenseExtra(d));
    }).catch(function (e) { busy(b, false); $auth.innerHTML = authHtml(false, '请求失败：' + e.message); });
  });

  document.getElementById('btn-verify').addEventListener('click', function () {
    var b = this; busy(b, true, '验证中');
    post('verify').then(function (d) {
      busy(b, false);
      $auth.innerHTML = authHtml(d.ok, d.msg, licenseExtra(d));
      probeNotify(d.ok ? '授权验证通过' : (d.msg || '授权验证失败'), d.ok ? 'success' : 'danger');
    }).catch(function (e) { busy(b, false); $auth.innerHTML = authHtml(false, '请求失败：' + e.message); });
  });

  document.getElementById('btn-check').addEventListener('click', function () {
    var b = this; busy(b, true, '检查中');
    post('check').then(function (d) { busy(b, false); renderCheck(d); })
      .catch(function (e) { busy(b, false); $result.innerHTML = '<div class="small text-danger mb-2"><i class="mdi mdi-close-circle-outline me-1"></i>请求失败：' + esc(e.message) + '</div>'; });
  });

  function renderCheck(d) {
    var html = '';
    if (d.authorized === false && d.auth_msg) {
      html += '<div class="small text-muted mb-2"><i class="mdi mdi-alert-outline text-warning me-1"></i>' + esc(d.auth_msg) + '（不影响系统正常使用，仅无法在线升级）</div>';
    }
    if (!d.ok) {
      $result.innerHTML = html + '<div class="small text-danger mb-2"><i class="mdi mdi-close-circle-outline me-1"></i>' + esc(d.msg || '检查更新失败') + '</div>';
      return;
    }
    if (d.service_expired) {
      html += '<div class="small text-muted mb-2"><i class="mdi mdi-clock-alert-outline text-warning me-1"></i>更新服务已到期，可能无法下载新版本。</div>';
    }
    if (!d.has_update || !d.versions || d.versions.length === 0) {
      $result.innerHTML = html + '<div class="small text-muted mb-2"><i class="mdi mdi-check-circle-outline text-success me-1"></i>当前已是最新版本（v' + esc(d.current_version) + '）。</div>';
      return;
    }
    html += '<div class="mb-2 small text-muted">发现 ' + d.versions.length + ' 个可用更新，请按顺序逐个升级（当前 v' + esc(d.current_version) + '）：</div>';
    html += '<div class="list-group mb-2">';
    d.versions.forEach(function (v) {
      var ver = esc(v.version || '');
      html += '<div class="list-group-item">'
        + '<div class="d-flex justify-content-between align-items-start gap-2">'
        + '<div class="flex-grow-1">'
        + '<div class="mb-1"><b>v' + ver + '</b>'
        + (v.version_type ? ' <span class="text-muted small">' + esc(v.version_type) + '</span>' : '')
        + (v.update_type ? ' <span class="text-muted small">· ' + esc(v.update_type) + '</span>' : '')
        + (v.released_at ? ' <span class="text-muted small">' + esc(v.released_at) + '</span>' : '')
        + '</div>'
        + (v.content ? '<div class="small">' + esc(v.content) + '</div>' : '')
        + (v.db_upgrade ? '<div class="small text-muted mt-1"><i class="mdi mdi-database-outline text-warning me-1"></i>含数据库升级</div>' : '')
        + '</div>'
        + '<button type="button" class="btn btn-sm btn-primary flex-none btn-upgrade" data-ver="' + ver + '">升级到此版本</button>'
        + '</div>'
        + '<pre class="upd-log d-none mt-2 mb-0 small p-2" style="white-space:pre-wrap;"></pre>'
        + '<div class="upd-err d-none mt-2"></div>'
        + '</div>';
    });
    html += '</div>';
    $result.innerHTML = html;
    $result.querySelectorAll('.btn-upgrade').forEach(function (btn) {
      btn.addEventListener('click', function () { doUpgrade(btn); });
    });
  }

  // 把一行文本追加到升级日志控制台并自动滚动到底
  function appendLog(log, text) {
    if (!log) { return; }
    log.classList.remove('d-none');
    log.textContent += text + '\n';
    log.scrollTop = log.scrollHeight;
  }
  // 解析单个 SSE 帧：仅取以 data: 开头的行，忽略首包填充 / 注释行
  function parseSSEFrame(raw, onEvent) {
    var lines = String(raw).split('\n');
    var data = [];
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].indexOf('data:') === 0) { data.push(lines[i].slice(5).replace(/^ /, '')); }
    }
    if (!data.length) { return; }
    try { onEvent(JSON.parse(data.join('\n'))); } catch (e) { /* 忽略无法解析的帧 */ }
  }
  // 读取 SSE 流：支持流式逐帧回调；浏览器不支持 ReadableStream 时回退为一次性读取再解析
  function readSSE(res, onEvent) {
    if (!res.body || !res.body.getReader) {
      return res.text().then(function (t) {
        t.split('\n\n').forEach(function (frame) { if (frame.trim()) { parseSSEFrame(frame, onEvent); } });
      });
    }
    var reader = res.body.getReader();
    var decoder = new TextDecoder('utf-8');
    var buf = '';
    function pump() {
      return reader.read().then(function (r) {
        if (r.done) { if (buf.trim()) { parseSSEFrame(buf, onEvent); } return; }
        buf += decoder.decode(r.value, { stream: true });
        var idx;
        while ((idx = buf.indexOf('\n\n')) >= 0) {
          parseSSEFrame(buf.slice(0, idx), onEvent);
          buf = buf.slice(idx + 2);
        }
        return pump();
      });
    }
    return pump();
  }
  // 渲染「完整详细报错」面板：全量文本不截断 + 一键复制；用 textContent 防注入
  function showUpdErr(box, msg, detail) {
    if (!box) { return; }
    var d = detail || {};
    var lines = [];
    lines.push('错误信息：' + (msg || '未知错误'));
    if (d.stage) { lines.push('失败阶段：' + d.stage); }
    if (d.exception) { lines.push('异常类型：' + d.exception); }
    if (d.file) { lines.push('出错位置：' + d.file); }
    if (d.url) { lines.push('下载地址：' + d.url); }
    if (d.path) { lines.push('文件路径：' + d.path); }
    if (d.api_msg) { lines.push('接口返回：' + d.api_msg); }
    if (Array.isArray(d.sql_errors) && d.sql_errors.length) {
      lines.push('');
      lines.push('数据库错误明细（' + d.sql_errors.length + ' 条）：');
      d.sql_errors.forEach(function (e) {
        lines.push('— 第 ' + e.index + ' 条语句失败：' + (e.message || ''));
        lines.push('  SQL：' + (e.sql || ''));
        if (e.error_info) { lines.push('  errorInfo：' + JSON.stringify(e.error_info)); }
      });
    }
    if (d.trace) { lines.push(''); lines.push('调用栈：'); lines.push(d.trace); }
    box.classList.remove('d-none');
    box.innerHTML = '';
    var alert = document.createElement('div');
    alert.className = 'small mb-0';
    var head = document.createElement('div');
    head.className = 'd-flex justify-content-between align-items-center gap-2 mb-1';
    var title = document.createElement('b');
    title.className = 'text-danger';
    title.innerHTML = '<i class="mdi mdi-alert-octagon-outline me-1"></i>升级失败（完整报错）';
    var copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'btn btn-xs btn-outline-danger flex-none';
    copyBtn.textContent = '复制报错';
    head.appendChild(title);
    head.appendChild(copyBtn);
    var pre = document.createElement('pre');
    pre.className = 'mb-0 small p-2';
    pre.style.whiteSpace = 'pre-wrap';
    pre.style.wordBreak = 'break-word';
    pre.style.maxHeight = '300px';
    pre.style.overflowY = 'auto';
    pre.style.background = '#fff5f5';
    pre.style.border = '1px solid #fecaca';
    pre.style.borderRadius = '6px';
    pre.textContent = lines.join('\n');
    copyBtn.addEventListener('click', function () {
      if (navigator.clipboard) { navigator.clipboard.writeText(pre.textContent); probeNotify('已复制完整报错', 'success'); }
    });
    alert.appendChild(head);
    alert.appendChild(pre);
    box.appendChild(alert);
  }

  function doUpgrade(btn) {
    var ver = btn.getAttribute('data-ver');
    if (!confirm('确认升级到 v' + ver + '？\n\n系统将自动：备份原文件 → 执行数据库 SQL → 覆盖程序文件。\n升级期间请勿关闭页面，完成后建议刷新。')) { return; }
    var item = btn.closest('.list-group-item');
    var log = item ? item.querySelector('.upd-log') : null;
    var errBox = item ? item.querySelector('.upd-err') : null;
    var all = $result.querySelectorAll('.btn-upgrade');
    busy(btn, true, '升级中');
    all.forEach(function (b) { if (b !== btn) { b.disabled = true; } });
    if (log) { log.classList.remove('d-none'); log.textContent = ''; }
    if (errBox) { errBox.classList.add('d-none'); errBox.innerHTML = ''; }
    appendLog(log, '▶ 开始升级到 v' + ver + '（以下为实时过程）…');

    var finalRes = null;   // done 事件（最终结果）
    var lastError = null;  // 最近一次 error 事件（含完整明细）
    function onEvent(ev) {
      if (!ev || !ev.type) { return; }
      if (ev.type === 'step') { appendLog(log, '· ' + ev.text); }
      else if (ev.type === 'warn') { appendLog(log, '⚠ ' + (ev.text || ev.msg || '')); }
      else if (ev.type === 'error') { lastError = ev; appendLog(log, '✘ ' + (ev.msg || '错误')); }
      else if (ev.type === 'done') { finalRes = ev; }
    }
    function finish(err) {
      busy(btn, false);
      var ok = finalRes ? !!finalRes.ok : false;
      var detail = (finalRes && finalRes.detail && Object.keys(finalRes.detail).length ? finalRes.detail : null)
        || (lastError && lastError.detail) || (err ? { stage: 'network', message: (err.message || String(err)) } : null) || {};
      var msg = (finalRes && finalRes.msg) || (lastError && lastError.msg) || (err ? ('请求失败：' + (err.message || err)) : '升级失败（未收到完成信号）');
      if (ok) {
        appendLog(log, '✔ ' + (finalRes.msg || ('v' + ver + ' 升级成功')) + (finalRes.backup ? '（备份：' + finalRes.backup + '）' : ''));
        probeNotify('v' + ver + ' 升级成功', 'success');
        btn.textContent = '已升级';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-success');
        btn.disabled = true;
        setTimeout(function () { if (confirm('v' + ver + ' 升级成功，是否刷新页面以加载新版本？')) { location.reload(); } }, 600);
      } else {
        appendLog(log, '✘ 升级失败：' + msg);
        showUpdErr(errBox, msg, detail);
        probeNotify('升级失败：' + msg, 'danger');
        all.forEach(function (b) { b.disabled = false; });
      }
    }

    var body = new URLSearchParams();
    body.append('action', 'upgrade_stream');
    body.append('version', ver);
    body.append('type', 'update');
    fetch('api/update.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (res) {
      if (!res.ok) {
        return res.json().then(function (j) {
          throw new Error((j.data && (j.data.message || j.data.msg)) || ('HTTP ' + res.status));
        });
      }
      return readSSE(res, onEvent);
    }).then(function () {
      finish();
    }).catch(function (e) {
      appendLog(log, '✘ 请求异常：' + (e && e.message ? e.message : e));
      finish(e);
    });
  }
})();
</script>

<?php include __DIR__ . '/templates/footer.php';
