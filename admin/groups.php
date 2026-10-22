<?php
/**
 * 节点分类管理：增删改 + 绑定节点统计
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '节点分类';
$active    = 'groups';

/* ---------- POST 操作处理 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort'] ?? 0);
        $frontHidden = isset($_POST['front_hidden']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 50) {
            flash_set('danger', '分类名称不能为空且不超过 50 字');
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) AS c FROM `groups` WHERE name = ?');
            $stmt->execute([$name]);
            if ((int)$stmt->fetch()['c'] > 0) {
                flash_set('danger', '分类名称已存在');
            } else {
                $stmt = $db->prepare('INSERT INTO `groups` (name, sort, front_hidden, created_at) VALUES (?, ?, ?, ' . time() . ')');
                $stmt->execute([$name, $sort, $frontHidden]);
                flash_set('success', '分类已添加');
            }
        }
        header('Location: groups.php');
        exit;
    }

    if ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort'] ?? 0);
        $frontHidden = isset($_POST['front_hidden']) ? 1 : 0;

        if ($id <= 0 || $name === '' || mb_strlen($name) > 50) {
            flash_set('danger', '分类名称不能为空且不超过 50 字');
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) AS c FROM `groups` WHERE name = ? AND id <> ?');
            $stmt->execute([$name, $id]);
            if ((int)$stmt->fetch()['c'] > 0) {
                flash_set('danger', '分类名称已存在');
            } else {
                $db->prepare('UPDATE `groups` SET name = ?, sort = ?, front_hidden = ? WHERE id = ?')->execute([$name, $sort, $frontHidden, $id]);
                flash_set('success', '分类已更新');
            }
        }
        header('Location: groups.php');
        exit;
    }

    if ($action === 'toggle_front') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE `groups` SET front_hidden = 1 - front_hidden WHERE id = ?')->execute([$id]);
        }
        header('Location: groups.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // 该分类下的节点改为未分类
            $db->prepare('UPDATE servers SET group_id = 0 WHERE group_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM `groups` WHERE id = ?')->execute([$id]);
            flash_set('success', '分类已删除，其下节点已改为未分类');
        }
        header('Location: groups.php');
        exit;
    }
}

/* ---------- 列表（含节点统计） ---------- */
$groups = GroupService::all($db);
$counts = [];
foreach ($db->query('SELECT group_id, COUNT(*) AS c FROM servers GROUP BY group_id') as $r) {
    $counts[(int)$r['group_id']] = (int)$r['c'];
}
$ungrouped = $counts[0] ?? 0;

include __DIR__ . '/templates/header.php';
?>

<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <header class="card-header">
        <div class="card-title"><i class="mdi mdi-tag-multiple-outline me-1"></i> 分类列表</div>
      </header>
      <div class="card-body pt-2">
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>名称 / 排序</th>
                <th style="width:110px">节点数</th>
                <th style="width:110px">前台</th>
                <th style="width:240px">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$groups): ?>
                <tr><td colspan="5" class="text-center text-muted py-5">
                  <i class="mdi mdi-tag-off-outline fs-1 d-block mb-2"></i>暂无分类，请在右侧添加
                </td></tr>
              <?php endif; ?>
              <?php foreach ($groups as $g): ?>
                <tr>
                  <td><?php echo (int)$g['id']; ?></td>
                  <td>
                    <form method="post" class="d-flex gap-2 form-update">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                      <input type="text" name="name" class="form-control form-control-sm" style="max-width:220px"
                             required maxlength="50" value="<?php echo e($g['name']); ?>">
                      <input type="number" name="sort" class="form-control form-control-sm" style="width:80px"
                             value="<?php echo (int)$g['sort']; ?>" title="排序，数字越小越靠前">
                      <input type="hidden" name="front_hidden" value="<?php echo (int)($g['front_hidden'] ?? 0); ?>">
                      <button type="submit" class="btn btn-outline-primary btn-sm">保存</button>
                    </form>
                  </td>
                  <td>
                    <a href="servers.php?group=<?php echo (int)$g['id']; ?>" class="badge badge-soft-primary text-decoration-none">
                      <?php echo $counts[(int)$g['id']] ?? 0; ?> 个节点
                    </a>
                  </td>
                  <td>
                    <form method="post" class="d-inline" title="前台状态页显示/隐藏该分类下所有节点">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="toggle_front">
                      <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                      <button type="submit" class="btn btn-sm <?php echo (int)($g['front_hidden'] ?? 0) === 1 ? 'btn-outline-secondary' : 'btn-outline-primary'; ?>">
                        <?php echo (int)($g['front_hidden'] ?? 0) === 1 ? '已隐藏' : '显示中'; ?>
                      </button>
                    </form>
                  </td>
                  <td class="text-nowrap">
                    <a href="servers.php?group=<?php echo (int)$g['id']; ?>" class="btn btn-outline-secondary btn-sm">查看节点</a>
                    <form method="post" class="d-inline form-delete" data-name="<?php echo e($g['name']); ?>" data-count="<?php echo $counts[(int)$g['id']] ?? 0; ?>">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="small text-muted mt-2">未分类节点：<?php echo $ungrouped; ?> 个</div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <header class="card-header">
        <div class="card-title"><i class="mdi mdi-plus-circle-outline me-1"></i> 添加分类</div>
      </header>
      <div class="card-body">
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="add">
          <div class="mb-3">
            <label class="form-label">分类名称 <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="50" placeholder="如：生产环境 / 测试环境">
          </div>
          <div class="mb-3">
            <label class="form-label">排序</label>
            <input type="number" name="sort" class="form-control" value="0">
            <div class="form-text">数字越小越靠前</div>
          </div>
          <div class="mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="front_hidden" id="add-front-hidden" value="1">
              <label class="form-check-label" for="add-front-hidden">前台隐藏（该分类不在前台状态页展示）</label>
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="mdi mdi-plus me-1"></i>添加分类</button>
        </form>
        <div class="alert alert-info small mt-3 mb-0">
          添加分类后，可在「服务器管理」中将节点绑定到对应分类，监控总览与服务器列表支持按分类筛选。
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$pageScript = <<<'JS'
<script>
$(function () {
  $('.form-delete').on('submit', function (e) {
    var name = $(this).data('name');
    var count = parseInt($(this).data('count') || 0, 10);
    var msg = '确定删除分类「' + name + '」吗？';
    if (count > 0) { msg += '\n该分类下有 ' + count + ' 个节点，删除后将改为未分类。'; }
    if (!confirm(msg)) { e.preventDefault(); }
  });
});
</script>
JS;
include __DIR__ . '/templates/footer.php';
