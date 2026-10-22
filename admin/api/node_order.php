<?php
/**
 * 节点卡片排序保存接口（同分类内排序 / 跨分类拖动后调用）
 *   POST ids   = "4,1,6,7"（目标分类内节点 id 按新顺序逗号分隔）
 *   POST group = 目标分类 id（可选，传入时把这批节点划入该分类，0 代表未分类）
 */

require dirname(__DIR__) . '/bootstrap.php';
require_login_api();
require_post_request(true);

$raw = trim((string)($_POST['ids'] ?? ''));
$ids = array_values(array_filter(explode(',', $raw), fn($v) => $v !== '' && ctype_digit($v)));

$stmt = $db->prepare('UPDATE servers SET sort_order = ? WHERE id = ?');
foreach ($ids as $i => $id) {
    $stmt->execute([$i + 1, (int)$id]);
}

// 跨分类拖动：目标网格内所有节点划入新分类（幂等）
if (isset($_POST['group']) && ctype_digit((string)$_POST['group']) && $ids) {
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $db->prepare('UPDATE servers SET group_id = ? WHERE id IN (' . $in . ')');
    $st2->execute(array_merge([(int)$_POST['group']], array_map('intval', $ids)));
}
json_out(['ok' => true, 'count' => count($ids)]);
