<?php
/**
 * 登录认证
 */

/** 是否已登录 */
function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

/** 未登录跳转登录页（HTML 页面用） */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    csrf_check_request();
}

/** API 场景下的登录校验（返回 JSON 401） */
function require_login_api(): void
{
    if (!is_logged_in()) {
        json_out(['message' => '未登录或登录已过期'], 401);
    }
    csrf_check_request(true);
}

/** 当前管理员信息 */
function current_admin(PDO $db): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);

    return $stmt->fetch() ?: null;
}

/** 账号密码校验 */
function attempt_login(PDO $db, string $username, string $password): bool
{
    $stmt = $db->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    csrf_token();
    $_SESSION['admin_id']   = (int)$admin['id'];
    $_SESSION['admin_name'] = $admin['username'];

    $upd = $db->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?');
    $upd->execute([time(), (int)$admin['id']]);

    return true;
}

/** 退出登录 */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
