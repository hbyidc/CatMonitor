<?php
/**
 * 版本信息与授权端点常量
 *
 * 说明：
 *  - 本文件随代码一起发布，在线升级成功后会被更新包覆盖为新版本；
 *  - 运行时“当前生效版本”以 settings.app_version 为准，未设置时回退到 PROBE_VERSION；
 *  - 授权 / 更新相关常量仅服务于「在线更新」功能，检测失败不影响系统正常使用。
 */

// 当前程序版本号（语义化版本）
if (!defined('PROBE_VERSION')) {
    define('PROBE_VERSION', '0.1.0');
}

// 授权 / 更新服务端点
if (!defined('PROBE_AUTH_API')) {
    define('PROBE_AUTH_API', 'https://authn.hbyidc.com/api/index.php');
}

// 默认应用 ID（固定为 4，后台不可修改）
if (!defined('PROBE_APP_ID')) {
    define('PROBE_APP_ID', 4);
}
