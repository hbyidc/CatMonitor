<?php
/**
 * 登录页
 */

require __DIR__ . '/bootstrap.php';
csrf_check_request();
header('Cache-Control: no-store');

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
// 临时令牌登录（SSO）失败回跳的原因提示
$ssoErrorMap = [
    'disabled'      => '临时令牌登录已停用，请在「系统设置 → 开放 API」中开启',
    'invalid_token' => '登录令牌无效、过期或已使用，请重新获取',
];
$ssoErr = (string)($_GET['sso_error'] ?? '');
if ($ssoErr !== '') {
    $error = $ssoErrorMap[$ssoErr] ?? '令牌登录失败，请重试或使用账号密码登录';
}
// 连续失败满 3 次后才要求验证码（防暴力破解，平时登录更便捷）
$failCount   = (int)($_SESSION['login_fails'] ?? 0);
$needCaptcha = $failCount >= 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $captcha  = strtolower(trim((string)($_POST['captcha'] ?? '')));

    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } elseif ($needCaptcha && ($captcha === '' || $captcha !== ($_SESSION['captcha'] ?? ''))) {
        $error = '验证码错误';
    } elseif (attempt_login($db, $username, $password)) {
        unset($_SESSION['login_fails']); // 登录成功清零失败计数
        header('Location: index.php');
        exit;
    } else {
        $error = '用户名或密码错误';
        $_SESSION['login_fails'] = $failCount + 1;
        $needCaptcha = ($failCount + 1) >= 3;
    }
    unset($_SESSION['captcha']);
}

$siteName = get_setting($db, 'site_name', 'Catmonitor');
$favicon  = get_setting($db, 'site_favicon', '');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 - <?php echo e($siteName); ?></title>
<link rel="icon" href="<?php echo e($favicon !== '' ? $favicon : '../favicon.ico'); ?>">
<link rel="stylesheet" href="../css/materialdesignicons.min.css">
<link rel="stylesheet" href="../css/bootstrap.min.css">
<link rel="stylesheet" href="../css/animate.min.css">
<link rel="stylesheet" href="../css/style.min.css">
<link rel="stylesheet" href="../css/probe.css">
</head>
<body class="login-page center-vh">
<!-- 背景光晕球 -->
<div class="login-orb login-orb-1" aria-hidden="true"></div>
<div class="login-orb login-orb-2" aria-hidden="true"></div>
<div class="login-orb login-orb-3" aria-hidden="true"></div>

<div class="login-wrap">
  <div class="login-card login-panel">
    <div class="login-brand-mark">
      <span class="logo-ico <?php echo $favicon !== '' ? 'logo-ico-img' : ''; ?>" aria-hidden="true">
        <?php if ($favicon !== ''): ?>
          <img src="<?php echo e($favicon); ?>" alt="">
        <?php else: ?>
          <i class="mdi mdi-cat"></i>
        <?php endif; ?>
      </span>
      <span class="brand-name"><?php echo e($siteName); ?></span>
    </div>
    <div class="panel-sub">登录监控控制台</div>
    <?php if ($error): ?>
      <div class="alert alert-danger py-2" role="alert">
        <i class="mdi mdi-alert-circle-outline" aria-hidden="true"></i><?php echo e($error); ?>
      </div>
    <?php endif; ?>
    <form action="login.php" method="post" autocomplete="off">
      <?php echo csrf_field(); ?>
      <div class="ly-field">
        <span class="field-ico mdi mdi-account-outline" aria-hidden="true"></span>
        <input type="text" name="username" class="form-control" placeholder="用户名" required autofocus
               value="<?php echo e($_POST['username'] ?? ''); ?>">
      </div>
      <div class="ly-field">
        <span class="field-ico mdi mdi-lock-outline" aria-hidden="true"></span>
        <input type="password" name="password" id="password" class="form-control" placeholder="密码" required>
        <button type="button" class="toggle-pwd" id="togglePwd" title="显示/隐藏密码" aria-label="显示或隐藏密码">
          <i class="mdi mdi-eye-outline" aria-hidden="true"></i>
        </button>
      </div>
      <?php if ($needCaptcha): ?>
      <div class="ly-field">
        <div class="row g-2">
          <div class="col">
            <div class="position-relative">
              <span class="field-ico mdi mdi-shield-check-outline" aria-hidden="true"></span>
              <input type="text" name="captcha" class="form-control" placeholder="验证码" required maxlength="6">
            </div>
          </div>
          <div class="col-4 d-flex align-items-center gap-1">
            <img src="captcha.php" id="captcha" class="captcha-img" onclick="refreshCaptcha()" title="点击刷新" alt="验证码">
            <button type="button" class="captcha-refresh" onclick="refreshCaptcha()" title="刷新验证码" aria-label="刷新验证码">
              <i class="mdi mdi-refresh" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="d-grid mt-4">
        <button class="login-submit" type="submit">登录</button>
      </div>
    </form>
    <div class="panel-foot">© <?php echo date('Y'); ?> <?php echo e($siteName); ?> · 仅限授权管理员访问</div>
  </div>
</div>
<script src="../js/jquery.min.js"></script>
<script src="../js/popper.min.js"></script>
<script src="../js/bootstrap.min.js"></script>
<script>
function refreshCaptcha() {
  var img = document.getElementById('captcha');
  if (img) { img.src = 'captcha.php?d=' + Math.random(); }
}
// 密码明文切换
$('#togglePwd').on('click', function () {
  var $input = $('#password');
  var show = $input.attr('type') === 'password';
  $input.attr('type', show ? 'text' : 'password');
  $(this).find('i').toggleClass('mdi-eye-outline mdi-eye-off-outline');
});
</script>
</body>
</html>
