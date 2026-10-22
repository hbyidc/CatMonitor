<?php
/**
 * 修改密码
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '修改密码';
$active    = 'edit_pwd';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPwd = (string)($_POST['old_password'] ?? '');
    $newPwd = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    $admin = current_admin($db);

    if (!$admin || !password_verify($oldPwd, $admin['password_hash'])) {
        flash_set('danger', '原密码错误');
    } elseif (strlen($newPwd) < 6) {
        flash_set('danger', '新密码长度不能少于 6 位');
    } elseif ($newPwd !== $confirm) {
        flash_set('danger', '两次输入的新密码不一致');
    } else {
        $stmt = $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPwd, PASSWORD_DEFAULT), (int)$admin['id']]);
        flash_set('success', '密码修改成功');
    }
    header('Location: edit_pwd.php');
    exit;
}

include __DIR__ . '/templates/header.php';
?>

<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <header class="card-header"><div class="card-title">修改密码</div></header>
      <div class="card-body">
        <form method="post">
          <?php echo csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label">原密码</label>
            <input type="password" name="old_password" class="form-control" required autocomplete="current-password">
          </div>
          <div class="mb-3">
            <label class="form-label">新密码</label>
            <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
          </div>
          <div class="mb-3">
            <label class="form-label">确认新密码</label>
            <input type="password" name="confirm" class="form-control" required autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary">保存</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/templates/footer.php';
