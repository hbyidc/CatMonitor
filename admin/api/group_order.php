<?php
/**
 * 分类模块排序保存接口
 *   POST order = "1,3,0,5"（分类 id 逗号分隔，0 代表未分类）
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();
require_post_request(true);

$raw = trim((string)($_POST['order'] ?? ''));
$ids = array_values(array_filter(explode(',', $raw), fn($v) => $v !== '' && ctype_digit($v)));

set_setting($db, 'group_order', implode(',', $ids));
json_out(['ok' => true]);
