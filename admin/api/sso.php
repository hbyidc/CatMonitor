<?php
/**
 * 开放 API 临时令牌登录（SSO）：凭一次性 token 换取后台登录会话并跳转。
 *
 * 用法：
 *   ① 先 POST open.php（action=token，携带 api_id+api_key）换取短期一次性 token；
 *   ② 再访问 /admin/api/sso.php?token=xxx[&redirect=servers.php] 登录。
 *   不再支持 api_id+api_key 直接登录（避免长期密钥出现在 URL / Referer / 访问日志）。
 *
 * 安全：
 *   - token 经 sso_consume_token 校验并消费（一次性、短期、库中仅存 sha256 哈希，天然防重放）；
 *   - 额外受「系统设置 → 开放 API → 临时令牌登录」开关 sso_login_enabled 控制；
 *   - 含凭证，一律 no-store / no-cache 禁止中间缓存；
 *   - redirect 仅允许 admin 目录内 [A-Za-z0-9_-]+\.php 文件名，杜绝开放重定向 / 路径穿越；
 *   - 登录为主管理员（admins 表 id 最小者），写 last_login_at 并追加审计日志 data/sso_login.log。
 */

require dirname(__DIR__) . '/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// 目标跳转（防开放重定向：仅允许同目录 .php 文件名，默认 index.php）
$redirect = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '');
$target   = 'index.php';
if ($redirect !== '' && preg_match('/^[A-Za-z0-9_\-]+\.php$/', $redirect)) {
    $target = $redirect;
}

// 已登录：直接进后台，避免重复建会话
if (is_logged_in()) {
    header('Location: ../' . $target);
    exit;
}

// SSO 失败：统一回登录页并带原因标记（login.php 负责展示中文提示）
$deny = static function (string $reason): void {
    header('Location: ../login.php?sso_error=' . rawurlencode($reason));
    exit;
};

// 临时令牌登录开关
if (get_setting($db, 'sso_login_enabled', '1') !== '1') {
    $deny('disabled');
}

// 登录身份获取：仅支持一次性令牌 token（先经 open.php action=token 换取，避免 api_key 出现在 URL / 日志）
$loginVia   = 'token';
$tokenParam = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($tokenParam === '') {
    $deny('invalid_token');
}
$admin = sso_consume_token($db, $tokenParam); // 命中即消费（一次性）；无效 / 过期 / 已用返回 null
if (!$admin) {
    $deny('invalid_token');
}

// 建立会话（与 attempt_login 保持一致）
session_regenerate_id(true);
unset($_SESSION['csrf_token']);
csrf_token();
$_SESSION['admin_id']   = (int)$admin['id'];
$_SESSION['admin_name'] = (string)$admin['username'];
unset($_SESSION['login_fails'], $_SESSION['captcha']);

$now = time();
$db->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([$now, (int)$admin['id']]);

// 审计日志（追加，含时间 / IP / UA / 管理员）
$line = sprintf(
    "[%s] SSO login via=%s admin_id=%d username=%s ip=%s ua=%s\n",
    date('Y-m-d H:i:s', $now),
    $loginVia,
    (int)$admin['id'],
    (string)$admin['username'],
    (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200)
);
@file_put_contents(ADMIN_ROOT . '/data/sso_login.log', $line, FILE_APPEND | LOCK_EX);

header('Location: ../' . $target);
exit;
