<?php
/**
 * 退出登录
 */

require __DIR__ . '/bootstrap.php';
require_login();
require_post_request();

logout();
header('Location: login.php');
exit;
