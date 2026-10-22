<?php
/**
 * 布局头部
 * 页面需先定义：$pageTitle（标题）、$active（当前菜单：index/servers/logs/settings/edit_pwd）
 */
$siteName   = get_setting($db, 'site_name', 'Catmonitor');
$favicon    = get_setting($db, 'site_favicon', '');
$adminName  = $_SESSION['admin_name'] ?? '管理员';
$flash      = flash_get();
$theme      = probe_theme_data($db); // 预警汇总：铃铛 / 侧边栏角标 / 页面共用
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
<title><?php echo e($pageTitle ?? '首页'); ?> - <?php echo e($siteName); ?></title>
<link rel="icon" href="<?php echo e($favicon !== '' ? $favicon : '../favicon.ico'); ?>">
<link rel="stylesheet" href="../css/materialdesignicons.min.css">
<link rel="stylesheet" href="../css/bootstrap.min.css">
<link rel="stylesheet" href="../css/animate.min.css">
<link rel="stylesheet" href="../css/style.min.css">
<link rel="stylesheet" href="../js/jquery-confirm/jquery-confirm.min.css">
<link rel="stylesheet" href="../css/probe.css?v=<?php echo @filemtime(__DIR__ . '/../../css/probe.css') ?: 1; ?>">
</head>
<body>
<div class="lyear-layout-web">
  <div class="lyear-layout-container">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!--头部信息-->
    <header class="lyear-layout-header">
      <nav class="navbar">
        <div class="navbar-left d-flex align-items-center">
          <div class="lyear-aside-toggler">
            <span class="lyear-toggler-bar"></span>
            <span class="lyear-toggler-bar"></span>
            <span class="lyear-toggler-bar"></span>
          </div>
          <span class="page-heading d-none d-md-inline"><?php echo e($pageTitle ?? '首页'); ?></span>
        </div>
        <ul class="navbar-right d-flex align-items-center">
          <!-- 前台状态页入口 -->
          <li class="me-2 d-none d-md-block">
            <a href="../" target="_blank" class="topbar-bell" title="查看前台状态页">
              <i class="mdi mdi-open-in-new"></i>
            </a>
          </li>
          <!-- 预警铃铛 -->
          <li class="dropdown me-2">
            <a href="javascript:void(0)" data-bs-toggle="dropdown" class="topbar-bell" title="预警通知">
              <i class="mdi mdi-bell-outline"></i>
              <?php if ($theme['alertToday'] > 0): ?>
                <span class="badge-count"><?php echo $theme['alertToday'] > 99 ? '99+' : (int)$theme['alertToday']; ?></span>
              <?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-end alert-feed">
              <div class="alert-feed-header d-flex justify-content-between align-items-center">
                <strong>预警通知</strong>
                <a href="alerts.php">查看全部 <i class="mdi mdi-chevron-right"></i></a>
              </div>
              <?php if (!$theme['alerts']): ?>
                <div class="alert-feed-empty"><i class="mdi mdi-check-circle-outline fs-3 d-block mb-1 text-success"></i>暂无预警，一切正常</div>
              <?php endif; ?>
              <?php foreach ($theme['alerts'] as $a): ?>
                <a class="alert-feed-item" href="alerts.php">
                  <?php echo alert_type_badge($a['alert_type']); ?>
                  <div class="small mt-1 text-truncate"><?php echo e(($a['server_name'] ? $a['server_name'] . '：' : '') . $a['message']); ?></div>
                  <div class="alert-item-time"><?php echo time_text($a['sent_at']); ?></div>
                </a>
              <?php endforeach; ?>
            </div>
          </li>
          <li class="dropdown">
            <a href="javascript:void(0)" data-bs-toggle="dropdown" class="dropdown-toggle">
              <img class="avatar-md rounded-circle" src="<?php echo e($favicon !== '' ? $favicon : '../favicon.ico'); ?>" alt="<?php echo e($adminName); ?>">
              <span style="margin-left: 10px;"><?php echo e($adminName); ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="edit_pwd.php"><i class="mdi mdi-lock-outline"></i><span>修改密码</span></a></li>
              <li class="dropdown-divider"></li>
              <li><form method="post" action="logout.php" class="mb-0">
                <?php echo csrf_field(); ?>
                <button type="submit" class="dropdown-item"><i class="mdi mdi-logout-variant"></i><span>退出登录</span></button>
              </form></li>
            </ul>
          </li>
        </ul>
      </nav>
    </header>
    <!--End 头部信息-->

    <!--页面主要内容-->
    <main class="lyear-layout-content">
      <div class="container-fluid">
        <?php if ($flash): ?>
          <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
            <?php echo e($flash['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
