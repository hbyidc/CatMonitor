<?php
/**
 * 左侧导航
 */
$menu = [
    'index'    => ['icon' => 'mdi-home-city-outline',        'title' => '监控总览',   'url' => 'index.php'],
    'servers'  => ['icon' => 'mdi-server-network',           'title' => '服务器管理', 'url' => 'servers.php'],
    'groups'   => ['icon' => 'mdi-tag-multiple-outline',     'title' => '节点分类',   'url' => 'groups.php'],
    'logs'     => ['icon' => 'mdi-history',                  'title' => '探测日志',   'url' => 'logs.php'],
    'alerts'   => ['icon' => 'mdi-bell-alert-outline',       'title' => '预警日志',   'url' => 'alerts.php'],
    'settings' => ['icon' => 'mdi-cog-outline',              'title' => '系统设置',   'url' => 'settings.php'],
    'api_doc'  => ['icon' => 'mdi-api',                      'title' => 'API 文档',   'url' => 'api_doc.php'],
];
$active = $active ?? 'index';
$theme  = $theme ?? probe_theme_data($db); // header 已计算则复用
$siteName  = $siteName ?? get_setting($db, 'site_name', 'Catmonitor');
$adminLogo = get_setting($db, 'logo_admin', '');
?>
<!--左侧导航-->
<aside class="lyear-layout-sidebar">
  <div id="logo" class="sidebar-header">
    <a href="index.php"><img src="<?php echo e($adminLogo !== '' ? $adminLogo : '../images/logo-sidebar.png'); ?>" title="<?php echo e($siteName); ?>" alt="<?php echo e($siteName); ?>"></a>
  </div>
  <div class="lyear-layout-sidebar-info lyear-scroll">
    <nav class="sidebar-main">
      <ul class="nav-drawer">
        <?php foreach ($menu as $key => $item): ?>
          <li class="nav-item <?php echo $active === $key ? 'active' : ''; ?>">
            <a href="<?php echo $item['url']; ?>">
              <i class="mdi <?php echo $item['icon']; ?>"></i>
              <span><?php echo $item['title']; ?></span>
              <?php if ($key === 'alerts' && $theme['alertToday'] > 0): ?>
                <span class="menu-badge"><?php echo $theme['alertToday'] > 99 ? '99+' : (int)$theme['alertToday']; ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="sidebar-footer">
      <p class="copyright">
        <span>CatMonitor监控系统</span>
      </p>
    </div>
  </div>
</aside>
<!--End 左侧导航-->
