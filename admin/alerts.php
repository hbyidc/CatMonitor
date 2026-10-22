<?php
/**
 * 预警日志
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '预警日志';
$active    = 'alerts';

$typeFilter = (string)($_GET['type'] ?? '');
if (!in_array($typeFilter, ['offline', 'ssl', 'test'], true)) {
    $typeFilter = '';
}

$where  = [];
$params = [];
if ($typeFilter !== '') {
    $where[]  = 'a.alert_type = ?';
    $params[] = $typeFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT a.*, s.name AS server_name, s.address AS server_address
        FROM alerts a LEFT JOIN servers s ON s.id = a.server_id
        {$whereSql}
        ORDER BY a.id DESC";

[$rows, $total, $page, $pages] = paginate($db, $sql, $params, (int)($_GET['page'] ?? 1));

include __DIR__ . '/templates/header.php';
?>

<div class="row">
  <div class="col-lg-12">
    <div class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <div class="card-title"><i class="mdi mdi-bell-alert-outline me-1"></i> 预警日志</div>
        <form method="get" class="d-flex gap-2 mb-0">
          <select name="type" class="form-select form-select-sm" style="width:130px" onchange="this.form.submit()">
            <option value="">全部类型</option>
            <option value="offline" <?php echo $typeFilter === 'offline' ? 'selected' : ''; ?>>离线预警</option>
            <option value="ssl" <?php echo $typeFilter === 'ssl' ? 'selected' : ''; ?>>SSL 预警</option>
          </select>
          <?php if ($typeFilter !== ''): ?><a href="alerts.php" class="btn btn-light btn-sm">重置</a><?php endif; ?>
        </form>
      </header>
      <div class="card-body">

        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>#</th>
                <th>类型</th>
                <th>节点</th>
                <th>预警内容</th>
                <th>发送渠道</th>
                <th>发送时间</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">
                  <i class="mdi mdi-shield-check-outline fs-1 d-block mb-2 text-success"></i>暂无预警记录
                </td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><?php echo alert_type_badge($r['alert_type']); ?></td>
                  <td><?php echo e($r['server_name'] ?? ('已删除#' . $r['server_id'])); ?></td>
                  <td class="small" title="<?php echo e($r['message']); ?>"><?php echo e(mb_substr($r['message'], 0, 70)); ?></td>
                  <td class="small text-muted"><?php echo e($r['channels']); ?></td>
                  <td><?php echo time_text($r['sent_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-between flex-wrap mt-3">
          <?php echo pager_html($page, $pages, $total, 'alerts.php'); ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/templates/footer.php';
