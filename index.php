<?php
/**
 * 前台公开状态页（免登录）
 * 数据来自 admin/api/public_status.php，显示项由后台「系统设置 - 前台页面」控制
 */
require __DIR__ . '/admin/bootstrap.php';

$frontSite = get_setting($db, 'site_name', 'Catmonitor');
$frontOn   = get_setting($db, 'front_enabled', '1') === '1';
$frontFavicon = get_setting($db, 'site_favicon', '');
$frontLogo    = get_setting($db, 'logo_front', '');
$icpNo        = get_setting($db, 'icp_no', '');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($frontSite); ?> - 服务状态</title>
<link rel="icon" href="<?php echo e($frontFavicon !== '' ? $frontFavicon : 'favicon.ico'); ?>">
<link rel="stylesheet" href="css/materialdesignicons.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: "PingFang SC", "Microsoft YaHei", "Helvetica Neue", Arial, system-ui, sans-serif;
  background: #fff;
  color: #344054;
  min-height: 100vh;
  overflow-x: hidden;
}
/* ---------- 不规则光晕球 ---------- */
.orb { position: fixed; z-index: 0; pointer-events: none; filter: blur(90px); }
.orb-blue {
  width: 560px; height: 560px; top: -190px; right: -140px;
  background: radial-gradient(circle at 35% 35%, rgba(96, 165, 250, .5), rgba(67, 97, 238, .16) 70%);
  border-radius: 62% 38% 55% 45% / 48% 60% 40% 52%;
}
.orb-purple {
  width: 480px; height: 480px; top: 34%; left: -180px;
  background: radial-gradient(circle at 55% 45%, rgba(147, 112, 255, .4), rgba(124, 92, 252, .12) 72%);
  border-radius: 45% 55% 42% 58% / 55% 42% 58% 45%;
}
.orb-pink {
  width: 520px; height: 520px; bottom: -200px; right: 8%;
  background: radial-gradient(circle at 45% 40%, rgba(244, 114, 182, .34), rgba(251, 146, 200, .1) 70%);
  border-radius: 55% 45% 60% 40% / 50% 55% 45% 50%;
}

.wrap { position: relative; z-index: 1; max-width: 1240px; margin: 0 auto; padding: 48px 24px 60px; }

/* ---------- 头部 ---------- */
.page-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 10px; }
.brand { display: flex; align-items: center; gap: 12px; }
.brand .logo {
  width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 22px;
  background: linear-gradient(135deg, #4361ee, #9370ff 60%, #f472b6);
  border-radius: 14px;
  box-shadow: 0 8px 20px rgba(124, 92, 252, .3);
}
.brand .name { font-size: 22px; font-weight: 700; color: #1d2939; }
.brand .logo.logo-custom { background: none; box-shadow: none; }
.brand .logo img { width: 100%; height: 100%; object-fit: contain; border-radius: 12px; }
.brand .sub { font-size: 12.5px; color: #98a2b3; margin-top: 1px; }
.head-right { display: flex; align-items: center; gap: 10px; }
.pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12.5px; font-weight: 600;
  padding: 6px 14px; border-radius: 999px;
  background: rgba(255, 255, 255, .7);
  border: 1px solid rgba(226, 232, 240, .9);
  backdrop-filter: blur(12px);
}
.pill .dot { width: 8px; height: 8px; border-radius: 50%; }
.pill i { font-size: 14px; color: #7c5cfc; }
.pill.ok .dot { background: #12b76a; box-shadow: 0 0 0 4px rgba(18, 183, 106, .15); }
.pill.warn .dot { background: #f04438; box-shadow: 0 0 0 4px rgba(240, 68, 56, .15); }
.pill.muted .dot { background: #98a2b3; }
#updated-time { font-size: 12px; color: #98a2b3; }

/* ---------- 分组卡片 ---------- */
.group-card {
  background: rgba(255, 255, 255, .72);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid rgba(226, 232, 240, .9);
  border-radius: 18px;
  box-shadow: 0 16px 48px rgba(99, 102, 241, .08);
  margin-top: 26px;
  overflow: hidden;
}
.group-title {
  display: flex; align-items: center; gap: 8px;
  padding: 16px 22px;
  font-size: 14px; font-weight: 700; color: #1d2939;
  border-bottom: 1px solid #f2f4f7;
}
.group-title i { color: #7c5cfc; font-size: 17px; }
.group-title .count { font-weight: 400; font-size: 12px; color: #98a2b3; margin-left: auto; }

/* ---------- 分类 Tab ---------- */
.group-tabs { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 26px; }
.group-tab {
  display: inline-flex; align-items: center; gap: 7px;
  font-size: 13px; font-weight: 600; color: #475467;
  padding: 8px 18px; border-radius: 999px; cursor: pointer; user-select: none;
  background: rgba(255, 255, 255, .7);
  border: 1px solid rgba(226, 232, 240, .9);
  backdrop-filter: blur(12px);
  transition: all .18s ease;
}
.group-tab:hover { border-color: #c7d2fe; color: #4361ee; }
.group-tab.active {
  color: #2563eb; border-color: rgba(147, 197, 253, .55);
  background: linear-gradient(135deg, #e0f2fe, #dbeafe 55%, #e0e7ff);
  box-shadow: 0 4px 12px rgba(59, 130, 246, .14);
}
.group-tab .gcnt {
  font-size: 11px; font-weight: 700; opacity: .72;
  background: rgba(120, 130, 150, .12); border-radius: 999px; padding: 1px 7px;
}
.group-tab.active .gcnt { background: rgba(37, 99, 235, .12); opacity: 1; }

/* ---------- 离线故障提示 ---------- */
.outage-banner {
  display: flex; align-items: center; gap: 12px;
  margin-top: 26px; padding: 14px 20px;
  background: rgba(254, 243, 242, .85);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(240, 68, 56, .22);
  border-radius: 14px;
  color: #b42318; font-size: 13.5px;
  box-shadow: 0 8px 24px rgba(240, 68, 56, .1);
}
.outage-banner i { font-size: 22px; color: #f04438; flex: 0 0 auto; }
.outage-banner b { font-weight: 700; }

.node { padding: 16px 22px; border-bottom: 1px solid #f6f7fa; }
.node:last-child { border-bottom: none; }
.node-row1 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 10px; }
.status-dot.on { background: #12b76a; box-shadow: 0 0 0 4px rgba(18, 183, 106, .14); }
.status-dot.off { background: #f04438; box-shadow: 0 0 0 4px rgba(240, 68, 56, .14); }
.status-dot.none { background: #cbd5e1; }
.node-name { font-size: 14.5px; font-weight: 600; color: #1d2939; }
.type-tag {
  font-size: 11px; font-weight: 600; color: #6172f3;
  background: rgba(97, 114, 243, .1);
  padding: 2px 8px; border-radius: 6px;
}
.node-meta { margin-left: auto; display: flex; align-items: baseline; gap: 12px; font-size: 12px; color: #98a2b3; }
.node-meta .lat { font-size: 14px; font-weight: 600; color: #475467; }

/* 历史轨迹条 */
.hist-row { display: flex; align-items: center; gap: 12px; margin-top: 12px; }
.hist-bars { display: flex; gap: 3px; flex: 1; }
.hist-bar { flex: 1; height: 26px; border-radius: 4px; background: #eef0f4; min-width: 4px; transition: transform .12s; }
.hist-bar:hover { transform: scaleY(1.15); }
.hist-bar.g { background: #12b76a; }
.hist-bar.y { background: #f79009; }
.hist-bar.r { background: #f04438; }
.hist-stat { flex: 0 0 auto; text-align: right; font-size: 12px; color: #98a2b3; line-height: 1.5; }
.hist-stat b { font-size: 14px; color: #1d2939; }

/* 资源占用 */
.res-row { display: flex; gap: 14px; margin-top: 12px; flex-wrap: wrap; }
.res-item { flex: 1; min-width: 140px; }
.res-label { display: flex; justify-content: space-between; font-size: 11.5px; color: #98a2b3; margin-bottom: 5px; }
.res-label b { color: #475467; }
.res-track { height: 6px; border-radius: 99px; background: #eef0f4; overflow: hidden; }
.res-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #4361ee, #7c5cfc); transition: width .4s; }
.res-fill.hot { background: linear-gradient(90deg, #f79009, #f04438); }
/* 实时带宽：下行/上行速率（居左显示在标题下方） */
.res-net .res-label { margin-bottom: 3px; }
.res-net-val { display: flex; gap: 10px; font-size: 12px; font-weight: 600; color: #475467; }
.res-net .net-down { color: #12b76a; }
.res-net .net-up { color: #4361ee; }
.res-net-val i { font-size: 12px; margin-right: 2px; vertical-align: -1px; }

/* ---------- 其他 ---------- */
.empty-tip { text-align: center; color: #98a2b3; padding: 80px 0; font-size: 14px; }
.empty-tip i { font-size: 40px; display: block; margin-bottom: 12px; color: #cbd5e1; }
.page-foot { text-align: center; margin-top: 44px; font-size: 12px; color: #b6bec9; }
.page-foot .mdi { color: #f472b6; }
.page-foot .icp { margin-top: 8px; }
.page-foot .icp a { color: #98a2b3; text-decoration: none; }
.page-foot .icp a:hover { color: #7c5cfc; }
@media (max-width: 575.98px) {
  .wrap { padding: 30px 14px 48px; }
  .node { padding: 14px 16px; }
  .group-title { padding: 14px 16px; }
  .hist-stat { display: none; }
}
</style>
</head>
<body>
<div class="orb orb-blue" aria-hidden="true"></div>
<div class="orb orb-purple" aria-hidden="true"></div>
<div class="orb orb-pink" aria-hidden="true"></div>

<div class="wrap">
  <header class="page-head">
    <div class="brand">
      <?php if ($frontLogo !== ''): ?>
        <span class="logo logo-custom"><img src="<?php echo e($frontLogo); ?>" alt="<?php echo e($frontSite); ?>"></span>
      <?php else: ?>
        <span class="logo mdi mdi-cat" aria-hidden="true"></span>
      <?php endif; ?>
      <div>
        <div class="name" id="site-name"><?php echo e($frontSite); ?></div>
        <div class="sub">服务运行状态 · 实时更新</div>
      </div>
    </div>
    <div class="head-right">
      <span class="pill" id="now-clock" title="当前时间"><i class="mdi mdi-clock-outline" aria-hidden="true"></i><span id="now-time-text">--</span></span>
      <span class="pill muted" id="status-pill"><span class="dot"></span>加载中…</span>
      <span id="updated-time"></span>
    </div>
  </header>

  <nav class="group-tabs" id="group-tabs" aria-label="分类切换"></nav>

  <div class="outage-banner" id="outage-banner" style="display:none">
    <i class="mdi mdi-alert-octagon-outline" aria-hidden="true"></i>
    <div id="outage-text"></div>
  </div>

  <main id="app">
    <div class="empty-tip"><i class="mdi mdi-loading mdi-spin"></i>正在加载监控数据…</div>
  </main>

  <footer class="page-foot">
    <i class="mdi mdi-heart" aria-hidden="true"></i> Powered by <?php echo e($frontSite); ?> · 数据每分钟自动刷新
    <?php if ($icpNo !== ''): ?>
      <div class="icp"><a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener"><?php echo e($icpNo); ?></a></div>
    <?php endif; ?>
  </footer>
</div>

<script>
var API = 'admin/api/public_status.php';
var UP_YELLOW = 98, UP_RED = 90;   // 可用率颜色阈值（后台可设，随接口下发）
var activeGroup = ''; // 当前选中分类，空串 = 全部

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function fmtTime(ts) {
  if (!ts) { return '未检测'; }
  var d = new Date(ts * 1000);
  var p = function (n) { return (n < 10 ? '0' : '') + n; };
  return p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}
function barClass(rate) {
  if (rate === null) { return ''; }
  if (rate >= UP_YELLOW) { return 'g'; }
  if (rate >= UP_RED) { return 'y'; }
  return 'r';
}
function fmtBps(b) {
  if (b == null) { return '-'; }
  if (b >= 1073741824) { return (b / 1073741824).toFixed(2) + ' GB/s'; }
  if (b >= 1048576) { return (b / 1048576).toFixed(2) + ' MB/s'; }
  if (b >= 1024) { return (b / 1024).toFixed(1) + ' KB/s'; }
  return Math.round(b) + ' B/s';
}

function renderServer(s, showHistory, showResource, hours) {
  var dot = s.status === 1 ? 'on' : (s.status === 0 ? 'off' : 'none');
  var html = '<div class="node">';
  html += '<div class="node-row1">';
  html += '<span class="status-dot ' + dot + '"></span>';
  html += '<span class="node-name">' + esc(s.name) + '</span>';
  html += '<span class="type-tag">' + esc(s.type_label) + '</span>';
  html += '<span class="node-meta">';
  if (s.status === 1 && s.latency !== null) {
    // SSH+PING 节点双延迟展示（后台设置仅 PING 时 ping_latency 为 null，只显单延迟）
    var latTxt = (s.type === 'ssh_ping' && s.ping_latency != null)
      ? 'SSH ' + s.latency + ' · PING ' + s.ping_latency + ' ms'
      : s.latency + ' ms';
    html += '<span class="lat">' + latTxt + '</span>';
  }
  if (s.type === 'http' && s.ssl_days !== null && s.ssl_days <= 30) {
    html += '<span style="color:#f79009">SSL 剩 ' + s.ssl_days + ' 天</span>';
  }
  html += '<span>' + fmtTime(s.checked_at) + '</span></span></div>';

  if (showHistory && s.history) {
    html += '<div class="hist-row"><div class="hist-bars">';
    s.history.forEach(function (b) {
      var title = b[0] + (b[1] === null ? ' 无数据' : ' 可用率 ' + b[1] + '%');
      html += '<div class="hist-bar ' + barClass(b[1]) + '" title="' + esc(title) + '"></div>';
    });
    html += '</div><div class="hist-stat">';
    if (s.uptime !== null) {
      html += '<b>' + s.uptime + '%</b> 近' + hours + 'h 可用率<br>平均延迟 ' + s.avg_latency + ' ms';
    } else {
      html += '暂无历史数据';
    }
    html += '</div></div>';
  }

  if (showResource && s.resource) {
    var r = s.resource;
    html += '<div class="res-row">';
    [['CPU', r.cpu], ['内存', r.mem], ['磁盘', r.disk]].forEach(function (it) {
      if (it[1] === null) { return; }
      html += '<div class="res-item"><div class="res-label"><span>' + it[0] + '</span><b>' + it[1] + '%</b></div>'
            + '<div class="res-track"><div class="res-fill' + (it[1] >= 90 ? ' hot' : '') + '" style="width:' + it[1] + '%"></div></div></div>';
    });
    if (r.net_rx != null || r.net_tx != null) {
      html += '<div class="res-item res-net"><div class="res-label"><span>实时带宽</span></div>'
            + '<div class="res-net-val"><span class="net-down"><i class="mdi mdi-arrow-down-bold-circle-outline"></i>' + fmtBps(r.net_rx) + '</span>'
            + '<span class="net-up"><i class="mdi mdi-arrow-up-bold-circle-outline"></i>' + fmtBps(r.net_tx) + '</span></div></div>';
    }
    html += '</div>';
  }

  html += '</div>';
  return html;
}

function renderTabs(groups, totalNodes) {
  var bar = document.getElementById('group-tabs');
  if (groups.length < 2) { bar.innerHTML = ''; return; } // 仅一个分类时无需 Tab
  var html = '<span class="group-tab' + (activeGroup === '' ? ' active' : '') + '" data-gid="">全部<span class="gcnt">' + totalNodes + '</span></span>';
  groups.forEach(function (g) {
    html += '<span class="group-tab' + (String(activeGroup) === String(g.id) ? ' active' : '') + '" data-gid="' + g.id + '">' + esc(g.name) + '<span class="gcnt">' + g.servers.length + '</span></span>';
  });
  bar.innerHTML = html;
}
function applyGroupFilter() {
  document.querySelectorAll('#app .group-card').forEach(function (c) {
    c.style.display = (activeGroup === '' || c.getAttribute('data-gid') === String(activeGroup)) ? '' : 'none';
  });
}
document.getElementById('group-tabs').addEventListener('click', function (e) {
  var t = e.target.closest('.group-tab');
  if (!t) { return; }
  activeGroup = t.getAttribute('data-gid');
  document.querySelectorAll('.group-tab').forEach(function (x) { x.classList.toggle('active', x === t); });
  applyGroupFilter();
});

function render(data) {
  if (data.uptime_yellow != null) { UP_YELLOW = data.uptime_yellow; }
  if (data.uptime_red != null) { UP_RED = data.uptime_red; }
  document.title = data.site + ' - 服务状态';
  document.getElementById('site-name').innerText = data.site;

  var pill = document.getElementById('status-pill');
  if (data.online === data.total) {
    pill.className = 'pill ok';
    pill.innerHTML = '<span class="dot"></span>全部运行正常';
  } else {
    pill.className = 'pill warn';
    pill.innerHTML = '<span class="dot"></span>' + (data.total - data.online) + ' 个节点异常';
  }
  document.getElementById('updated-time').innerText = '更新于 ' + fmtTime(data.updated);

  // 离线故障提示框
  var outage = document.getElementById('outage-banner');
  var down = data.total - data.online;
  if (down > 0) {
    document.getElementById('outage-text').innerHTML =
      '<b>当前有 ' + down + ' 个节点离线</b>，我们将尽快处理此故障';
    outage.style.display = '';
  } else {
    outage.style.display = 'none';
  }

  var app = document.getElementById('app');
  if (!data.groups.length) {
    document.getElementById('group-tabs').innerHTML = '';
    app.innerHTML = '<div class="empty-tip"><i class="mdi mdi-monitor-off"></i>暂无公开的监控节点</div>';
    return;
  }
  // 选中分类已不存在时回退到「全部」
  if (activeGroup !== '' && !data.groups.some(function (g) { return String(g.id) === String(activeGroup); })) {
    activeGroup = '';
  }
  renderTabs(data.groups, data.total);
  var html = '';
  data.groups.forEach(function (g) {
    html += '<div class="group-card" data-gid="' + g.id + '">';
    html += '<div class="group-title"><i class="mdi mdi-folder-outline"></i>' + esc(g.name)
          + '<span class="count">' + g.servers.length + ' 个节点</span></div>';
    g.servers.forEach(function (s) {
      html += renderServer(s, data.show_history, data.show_resource, data.history_hours);
    });
    html += '</div>';
  });
  app.innerHTML = html;
  applyGroupFilter();
}

function load() {
  fetch(API + '?t=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res.code === 0) { render(res.data); }
      else {
        document.getElementById('app').innerHTML =
          '<div class="empty-tip"><i class="mdi mdi-eye-off-outline"></i>' + esc(res.data.message || '页面暂不可用') + '</div>';
      }
    })
    .catch(function () {
      document.getElementById('app').innerHTML =
        '<div class="empty-tip"><i class="mdi mdi-wifi-off"></i>数据加载失败，正在重试…</div>';
    });
}
load();
setInterval(load, 60000);

/* 实时当前时间 */
function tickClock() {
  var d = new Date();
  var p = function (n) { return (n < 10 ? '0' : '') + n; };
  document.getElementById('now-time-text').innerText =
    d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' '
    + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}
tickClock();
setInterval(tickClock, 1000);
</script>
</body>
</html>
