<?php
/**
 * 探测日志
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '探测日志';
$active    = 'logs';

$serverId = (int)($_GET['server_id'] ?? 0);
$onlyDown = (int)($_GET['down'] ?? 0);

$servers = $db->query('SELECT id, name FROM servers ORDER BY id ASC')->fetchAll();

$where  = [];
$params = [];
if ($serverId > 0) {
    $where[]    = 'l.server_id = ?';
    $params[]   = $serverId;
}
if ($onlyDown) {
    $where[] = 'l.status = 0';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT l.*, s.name AS server_name, s.type AS server_type
        FROM logs l LEFT JOIN servers s ON s.id = l.server_id
        {$whereSql}
        ORDER BY l.id DESC";

[$rows, $total, $page, $pages] = paginate($db, $sql, $params, (int)($_GET['page'] ?? 1));

include __DIR__ . '/templates/header.php';
?>

<div class="row">
  <div class="col-lg-12">
    <div class="card">
      <header class="card-header">
        <div class="card-title">探测日志</div>
      </header>
      <div class="card-body">
        <form method="get" class="row g-2 mb-3">
          <div class="col-auto">
            <select name="server_id" class="form-select form-select-sm">
              <option value="0">全部节点</option>
              <?php foreach ($servers as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo $serverId === (int)$s['id'] ? 'selected' : ''; ?>>
                  <?php echo e($s['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <select name="down" class="form-select form-select-sm">
              <option value="0">全部结果</option>
              <option value="1" <?php echo $onlyDown ? 'selected' : ''; ?>>仅异常（离线）</option>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">筛选</button>
            <a href="logs.php" class="btn btn-secondary btn-sm">重置</a>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>#</th>
                <th>节点</th>
                <th>结果</th>
                <th>延迟</th>
                <th>信息</th>
                <th>检测时间</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">暂无探测日志</td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><?php echo e($r['server_name'] ?? ('已删除#' . $r['server_id'])); ?></td>
                  <td><?php echo status_badge($r['status'] ? 1 : 0); ?></td>
                  <td><?php echo latency_text($r['latency']); ?></td>
                  <td class="small text-muted" title="<?php echo e($r['message']); ?>"><?php echo e(mb_substr($r['message'], 0, 60)); ?></td>
                  <td><?php echo time_text($r['checked_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-between flex-wrap mt-3">
          <?php echo pager_html($page, $pages, $total, 'logs.php'); ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/templates/footer.php';
