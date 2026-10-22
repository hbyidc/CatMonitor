<?php
/**
 * 性能趋势页：SSH 节点长期监控的总趋势 + 单项趋势波浪图
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '性能趋势';
$active    = 'index';

$id = (int)($_GET['server_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM servers WHERE id = ?');
$stmt->execute([$id]);
$server = $stmt->fetch();

$valid = $server && in_array($server['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($server['metrics_retain_days'] ?? 0) > 0;

include __DIR__ . '/templates/header.php';
?>

<?php if (!$valid): ?>
<div class="card">
  <div class="card-body text-center text-muted py-5">
    <i class="mdi mdi-chart-line-variant fs-1 d-block mb-2"></i>
    <?php if (!$server): ?>节点不存在
    <?php elseif (!in_array($server['type'], ['ssh', 'ssh_ping', 'bt'], true)): ?>仅 SSH / SSH+PING / 宝塔面板节点支持性能趋势
    <?php else: ?>该节点未开启长期监控，请在 <a href="servers.php">服务器管理</a> 中编辑节点并选择保留时长<?php endif; ?>
    <div class="mt-3"><a class="btn btn-primary btn-sm" href="index.php"><i class="mdi mdi-arrow-left"></i> 返回监控总览</a></div>
  </div>
</div>
<?php else: ?>

<!-- 页头：节点信息 + 时间档位 -->
<div class="node-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3" style="background:#fff;border:1px solid #eaecf0;border-radius:12px;padding:12px 16px">
  <div class="d-flex align-items-center flex-wrap gap-2">
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="mdi mdi-arrow-left"></i> 返回</a>
    <div class="card-title mb-0"><i class="mdi mdi-chart-areaspline me-1"></i> 性能趋势 · <?php echo e($server['name']); ?></div>
    <span class="text-muted small"><?php echo e($server['address']); ?><?php echo (int)$server['port'] > 0 ? ':' . (int)$server['port'] : ''; ?></span>
    <span class="badge badge-soft-info">保留 <?php echo (int)$server['metrics_retain_days']; ?> 天数据</span>
  </div>
  <div class="btn-group btn-group-sm" id="range-btns">
    <button type="button" class="btn btn-outline-primary" data-range="1h">1 小时</button>
    <button type="button" class="btn btn-outline-primary" data-range="12h">12 小时</button>
    <button type="button" class="btn btn-primary" data-range="24h">24 小时</button>
    <button type="button" class="btn btn-outline-primary" data-range="3d">3 天</button>
    <button type="button" class="btn btn-outline-primary" data-range="7d">7 天</button>
    <?php if ((int)$server['metrics_retain_days'] >= 30): ?>
      <button type="button" class="btn btn-outline-primary" data-range="30d">30 天</button>
    <?php endif; ?>
  </div>
</div>

<!-- 当前值概览 -->
<div class="row g-3 g-xl-4 mb-3">
  <?php
  $cur = [
      ['cpu', 'CPU', $server['cpu_usage'], '%', '#4361ee', 'mdi-cpu-64-bit'],
      ['mem', '内存', $server['mem_usage'], '%', '#0ea5a4', 'mdi-memory'],
      ['disk', '磁盘 ' . ($server['disk_mount'] ?? '/'), $server['disk_usage'], '%', '#f59e0b', 'mdi-harddisk'],
      ['load', '负载', $server['load_usage'], '', '#8b5cf6', 'mdi-speedometer'],
      ['net', '实时带宽', null, '', '#12b76a', 'mdi-swap-vertical'],
  ];
  foreach ($cur as [$key, $label, $val, $unit, $color, $icon]): ?>
    <div class="col-6 col-xl">
      <div class="card h-100"><div class="card-body py-3 d-flex align-items-center gap-3">
        <i class="mdi <?php echo $icon; ?> fs-2" style="color:<?php echo $color; ?>"></i>
        <div>
          <div class="text-muted small"><?php echo e($label); ?></div>
          <div class="fw-bold" style="font-size:<?php echo $key === 'net' ? '15px' : '20px'; ?>" id="cur-<?php echo $key; ?>">
            <?php if ($key === 'net'): ?>
              <span style="color:#12b76a">↓<?php echo format_bps(($server['net_rx_rate'] ?? null) !== null ? (float)$server['net_rx_rate'] : null); ?></span>
              <span style="color:#4361ee" class="ms-1">↑<?php echo format_bps(($server['net_tx_rate'] ?? null) !== null ? (float)$server['net_tx_rate'] : null); ?></span>
            <?php else: ?>
              <?php echo $val !== null ? ($key === 'load' ? number_format((float)$val, 2) : round((float)$val, 1) . '%') : '-'; ?>
            <?php endif; ?>
          </div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3 metrics-disk-current">
  <header class="card-header"><div class="card-title"><i class="mdi mdi-harddisk me-1"></i>各磁盘当前占用</div></header>
  <div class="card-body">
    <div id="metrics-disk-list"><?php echo resource_disk_list_html(resource_disks($server), (int)get_setting($db, 'alert_disk_threshold', '90')); ?></div>
    <div class="small text-muted mt-2">按独立文件系统展示；数据盘历史从启用多盘采集后开始记录，缺测保留断点，旧单盘历史单独标注。</div>
  </div>
</div>

<!-- 总趋势 -->
<div class="card mb-3">
  <header class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="card-title"><i class="mdi mdi-chart-multiple me-1"></i> 总趋势（CPU / 内存 / 磁盘 / 负载）</div>
    <div class="d-flex align-items-center gap-2">
      <span class="text-muted small" id="win-label"></span>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-reset-zoom"><i class="mdi mdi-restore"></i> 重置</button>
    </div>
  </header>
  <div class="card-body">
    <div style="height:340px"><canvas id="chart-all" class="trend-canvas"></canvas></div>
    <div class="text-muted small text-center mt-3" id="empty-tip" style="display:none">
      <i class="mdi mdi-chart-line-variant me-1"></i>当前时间段暂无历史数据
    </div>
  </div>
</div>

<!-- 单项趋势 -->
<div class="row g-3 g-xl-4">
  <?php
  $single = [
      ['cpu', 'CPU 使用率趋势', '#4361ee'],
      ['mem', '内存使用率趋势', '#0ea5a4'],
      ['disk', '各磁盘占用趋势（按挂载点）', '#f59e0b'],
      ['load', '系统负载趋势', '#8b5cf6'],
      ['net', '网络带宽趋势', '#12b76a'],
  ];
  foreach ($single as [$key, $label, $color]): ?>
    <div class="col-12 col-xl-6">
      <div class="card h-100">
        <header class="card-header"><div class="card-title" style="font-size:14px"><span class="me-2" style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?php echo $color; ?>"></span><?php echo $label; ?></div></header>
        <div class="card-body"><div style="height:220px"><canvas id="chart-<?php echo $key; ?>" class="trend-canvas"></canvas></div></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
$pageScript = '';
if ($valid) {
    $pageScript = '<script>window.probeMetricsInit = { id: ' . (int)$server['id'] . ', retain: ' . (int)$server['metrics_retain_days'] . ' }; window.probeResT = [90,90,' . (int)get_setting($db, 'alert_disk_threshold', '90') . '];</script>'
        . '<script src="../js/chart.min.js"></script>'
        . <<<'JS'
<script>
$(function () {
  var serverId = window.probeMetricsInit.id;
  var retain = window.probeMetricsInit.retain;
  var charts = {};
  var colors = { cpu: '#4361ee', mem: '#0ea5a4', disk: '#f59e0b', load: '#8b5cf6' };
  var secMap = { '1h': 3600, '12h': 43200, '24h': 86400, '3d': 259200, '7d': 604800, '30d': 2592000 };
  // 每个图表独立维护时间窗口与数据：chartWins[id]=[s,e]，chartLast[id]=pts，hiStore[id]={pts,start,end}
  var chartWins = {}, chartLast = {}, hiStore = {};
  var chartDefs = {
    'chart-all':  { cols: [1, 2, 3, 4], yMax: 100, y1: true },
    'chart-cpu':  { cols: [1], yMax: 100 },
    'chart-mem':  { cols: [2], yMax: 100 },
    'chart-disk': { cols: [3], yMax: 100 },
    'chart-load': { cols: [4], yMax: null },
    'chart-net':  { cols: [5, 6], yMax: null, bps: true }
  };
  var colMeta = {
    1: { name: 'CPU', color: '#4361ee', bg: 'rgba(67,97,238,.12)' },
    2: { name: '内存', color: '#0ea5a4', bg: 'rgba(14,165,164,.12)' },
    3: { name: '磁盘', color: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
    4: { name: '负载', color: '#8b5cf6', bg: 'rgba(139,92,246,.12)' },
    5: { name: '下行', color: '#12b76a', bg: 'rgba(18,183,106,.12)' },
    6: { name: '上行', color: '#4361ee', bg: 'rgba(67,97,238,.12)' }
  };

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmtBps(b) {
    if (b == null) { return '-'; }
    if (b >= 1073741824) { return (b / 1073741824).toFixed(2) + ' GB/s'; }
    if (b >= 1048576) { return (b / 1048576).toFixed(2) + ' MB/s'; }
    if (b >= 1024) { return (b / 1024).toFixed(1) + ' KB/s'; }
    return Math.round(b) + ' B/s';
  }
  function fmtTime(t, multiDay) {
    var d = new Date(t * 1000);
    return multiDay
      ? (d.getMonth() + 1) + '/' + d.getDate() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
      : pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function makeChart(id, cfg) {
    if (charts[id]) { charts[id].destroy(); }
    charts[id] = new Chart(document.getElementById(id), cfg);
  }

  function areaDs(label, data, color, rgba, extra) {
    var ds = {
      label: label, data: data, borderColor: color, backgroundColor: rgba,
      fill: true, tension: .35, pointRadius: 0, borderWidth: 1.5
    };
    return $.extend(ds, extra || {});
  }

  function baseOpts(yMax, y1, s, e, bps, legendOn) {
    var multiDay = e - s > 86400;
    var scales = {
      // 线性时间轴：数据按真实时间戳定位，窗口固定，拖动时曲线不会被拉伸
      x: { type: 'linear', min: s, max: e, ticks: { maxTicksLimit: 10, maxRotation: 0, font: { size: 11 }, callback: function (v) { return fmtTime(v, multiDay); } }, grid: { display: false } },
      y: { min: 0, max: yMax, ticks: { font: { size: 11 }, callback: function (v) { return bps ? fmtBps(v) : (yMax === 100 ? v + '%' : v); } } }
    };
    if (y1) {
      scales.y1 = { position: 'right', min: 0, grid: { drawOnChartArea: false }, ticks: { font: { size: 11 } } };
    }
    return {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: y1 !== undefined || !!legendOn, position: 'top', labels: { boxWidth: 12, boxHeight: 12, font: { size: 12 } } },
        tooltip: { callbacks: {
          title: function (items) { return items.length ? fmtTime(items[0].parsed.x, multiDay) : ''; },
          label: bps ? function (item) { return item.dataset.label + ': ' + fmtBps(item.parsed.y); } : undefined
        } }
      },
      scales: scales
    };
  }

  // 渲染单个图表：首次创建，之后原地刷新（无动画，窗口不跳变）；空数据时保留图表只显示提示
  function renderChart(id, pts, s, e) {
    var def = chartDefs[id];
    if (!def) { return; } // 未知画布（如拖动回调迟到时 panId 已被清空），直接忽略
    chartWins[id] = [s, e];
    chartLast[id] = pts;
    if (id === 'chart-all') {
      $('#win-label').text(fmtTime(s, true) + ' ~ ' + fmtTime(e, e - s > 3600));
      $('#empty-tip').toggle(pts.length === 0);
    }

    // 数据点为 {x: 时间戳, y: 值}，配合线性时间轴按真实时间定位
    var series = def.cols.map(function (c) { return pts.map(function (p) { return { x: p[0], y: p[c] }; }); });
    var datasets;
    if (id === 'chart-disk') {
      var mounts = [];
      pts.forEach(function (p) {
        Object.keys(p[7] || {}).forEach(function (mount) { if (mounts.indexOf(mount) < 0) mounts.push(mount); });
      });
      mounts.sort(function (a, b) { return a === b ? 0 : (a === '/' ? -1 : (b === '/' ? 1 : a.localeCompare(b))); });
      var palette = ['#4361ee', '#0ea5a4', '#8b5cf6', '#e11d48', '#0891b2', '#65a30d', '#a16207'];
      datasets = mounts.map(function (mount) {
        var hash = 0;
        for (var k = 0; k < mount.length; k++) hash = (hash * 31 + mount.charCodeAt(k)) >>> 0;
        var color = mount === '/' ? '#f59e0b' : palette[hash % palette.length];
        return areaDs(mount, pts.map(function (p) {
          var values = p[7] || {};
          return { x: p[0], y: Object.prototype.hasOwnProperty.call(values, mount) ? values[mount] : null };
        }), color, 'transparent', { fill: false, spanGaps: false, tension: .1, pointRadius: pts.length === 1 ? 3 : 0 });
      });
    } else {
      datasets = def.cols.map(function (c, i) {
        var m = colMeta[c];
        var extra = (id === 'chart-all' && c === 4) ? { fill: false, borderDash: [4, 3], yAxisID: 'y1', backgroundColor: 'transparent' } : null;
        return areaDs(m.name, series[i], m.color, m.bg, extra);
      });
    }

    if (charts[id]) {
      // 原地更新数据与窗口，避免销毁重建
      var ch = charts[id];
      // 先清除悬停激活态：新数据更短时旧索引会让 tooltip 取到越界数据点报错
      ch.setActiveElements([]);
      if (ch.tooltip) { ch.tooltip.setActiveElements([], { x: 0, y: 0 }); }
      ch.options.scales.x.min = s;
      ch.options.scales.x.max = e;
      if (id === 'chart-disk') {
        var visibility = Object.create(null);
        ch.data.datasets.forEach(function (ds, i) { visibility[ds.label] = ch.isDatasetVisible(i); });
        datasets.forEach(function (ds) { ds.hidden = visibility[ds.label] === false; });
        ch.data.datasets = datasets;
      } else {
        series.forEach(function (arr, i) { ch.data.datasets[i].data = arr; });
      }
      ch.update('none');
      return;
    }

    // 首次创建
    makeChart(id, { type: 'line', data: { datasets: datasets }, options: baseOpts(def.yMax, def.y1, s, e, def.bps, id === 'chart-disk' || def.cols.length > 1) });
  }

  // 双层数据：fullPts 为整个保留期基线（平移用），hiStore[id] 为该图表窗口的高精度数据
  var fullPts = [];

  function sliceFor(id, s, e) {
    var hi = hiStore[id];
    if (hi && s >= hi.start && e <= hi.end) {
      var h = hi.pts.filter(function (p) { return p[0] >= s && p[0] <= e; });
      if (h.length >= 2) { return h; }
    }
    return fullPts.filter(function (p) { return p[0] >= s && p[0] <= e; });
  }

  // 请求单个图表窗口的高精度数据（带请求序号防竞态：旧请求迟到时丢弃，避免视图被拽回旧窗口）
  var reqSeq = {};
  function loadWindow(id, s, e) {
    var hi = hiStore[id];
    // 已有覆盖该窗口的高精度缓存且图表就在此窗口，无需重复请求
    if (hi && hi.start <= s && hi.end >= e && chartWins[id] && chartWins[id][0] === s && chartWins[id][1] === e) { return; }
    var seq = (reqSeq[id] || 0) + 1;
    reqSeq[id] = seq;
    $.getJSON('api/metrics.php', { server_id: serverId, start: s, end: e }, function (res) {
      if (reqSeq[id] !== seq) { return; } // 已有更新的请求，丢弃过期响应
      if (!res || res.code !== 0 || !res.data.ok) {
        probeNotify((res && res.data && res.data.message) || '获取趋势数据失败', 'danger');
        return;
      }
      hiStore[id] = { pts: res.data.points, start: s, end: e };
      // 兜底：响应为空但图表当前有数据时保留现有画面；仅当图表仍在请求的窗口时才重绘
      if (res.data.points.length === 0 && (chartLast[id] || []).length >= 2) { return; }
      if (chartWins[id] && chartWins[id][0] === s && chartWins[id][1] === e) {
        renderChart(id, res.data.points, s, e);
      }
    });
  }

  // 一次请求同步所有图表（时间档位按钮 / 重置用）
  function loadWindowAll(s, e) {
    var seq = (reqSeq.__all || 0) + 1;
    reqSeq.__all = seq;
    Object.keys(chartDefs).forEach(function (id) { reqSeq[id] = (reqSeq[id] || 0) + 1; });
    $.getJSON('api/metrics.php', { server_id: serverId, start: s, end: e }, function (res) {
      if (reqSeq.__all !== seq) { return; }
      if (!res || res.code !== 0 || !res.data.ok) {
        probeNotify((res && res.data && res.data.message) || '获取趋势数据失败', 'danger');
        return;
      }
      Object.keys(chartDefs).forEach(function (id) {
        hiStore[id] = { pts: res.data.points, start: s, end: e };
        renderChart(id, res.data.points, s, e);
      });
      $('#metrics-disk-list').html(window.probeDiskListHtml(res.data.current));
    });
  }

  // 加载整个保留期基线数据（供平移/缩放即时切片）
  function loadFull() {
    var nowS = Math.floor(Date.now() / 1000);
    $.getJSON('api/metrics.php', { server_id: serverId, start: nowS - retain * 86400, end: nowS }, function (res) {
      if (res && res.code === 0 && res.data.ok) { fullPts = res.data.points; }
    });
  }

  // 图表像素坐标 → 时间戳（线性时间轴直接换算，精确）
  function pxToTime(canvasId, px) {
    var c = charts[canvasId];
    if (!c) { return null; }
    var t = c.scales.x.getValueForPixel(px);
    return (t == null || isNaN(t)) ? null : t;
  }

  // 缩放单个图表窗口：本地数据即时重绘（流畅）+ 防抖请求高精度数据
  var detailTimers = {};
  function zoomTo(id, s, e) {
    var nowS = Math.floor(Date.now() / 1000);
    var minSpan = 600, maxSpan = retain * 86400;
    if (e - s > maxSpan) { e = s + maxSpan; }
    if (e - s < minSpan) { var c = (s + e) / 2; s = c - minSpan / 2; e = c + minSpan / 2; }
    if (e > nowS) { var d = e - nowS; s -= d; e = nowS; }
    var floor = nowS - retain * 86400;
    if (s < floor) { s = floor; if (e > nowS) { e = nowS; } }
    s = Math.floor(s);
    e = Math.ceil(e);
    setActiveRange(null);
    // 兜底：目标窗口至少要有 2 个数据点，否则本次缩放不生效
    var local = sliceFor(id, s, e);
    if (local.length < 2) { return; }
    renderChart(id, local, s, e);
    // 停止缩放后再请求高密度数据
    clearTimeout(detailTimers[id]);
    detailTimers[id] = setTimeout(function () { loadWindow(id, s, e); }, 300);
  }

  function setActiveRange(btn) {
    $('#range-btns button').removeClass('btn-primary').addClass('btn-outline-primary');
    if (btn) { $(btn).removeClass('btn-outline-primary').addClass('btn-primary'); }
  }

  // 时间档位按钮：所有图表同步重置
  $('#range-btns').on('click', 'button', function () {
    setActiveRange(this);
    var nowS = Math.floor(Date.now() / 1000);
    loadWindowAll(nowS - secMap[$(this).data('range')], nowS);
  });

  // 重置视图（所有图表回到 24 小时）
  $('#btn-reset-zoom').on('click', function () {
    var btn = $('#range-btns button[data-range="24h"]');
    setActiveRange(btn);
    var nowS = Math.floor(Date.now() / 1000);
    loadWindowAll(nowS - 86400, nowS);
  });

  // 滚轮缩放：仅缩放当前悬停的图表，以光标位置为中心（节流）
  var wheelEv = null, wheelBusy = false;
  $('.trend-canvas').on('wheel', function (e) {
    e.preventDefault();
    var rect = this.getBoundingClientRect();
    wheelEv = { id: this.id, x: e.originalEvent.clientX - rect.left, out: e.originalEvent.deltaY > 0 };
    if (wheelBusy) { return; }
    wheelBusy = true;
    setTimeout(function () {
      wheelBusy = false;
      var ev = wheelEv;
      wheelEv = null;
      if (!ev || !chartWins[ev.id]) { return; }
      var w = chartWins[ev.id];
      var factor = ev.out ? 1.3 : 1 / 1.3;
      var t = pxToTime(ev.id, ev.x);
      if (t == null) { t = (w[0] + w[1]) / 2; }
      zoomTo(ev.id, t - (t - w[0]) * factor, t + (w[1] - t) * factor);
    }, 120);
  });

  // 左右拖动平移：只移动当前图表的时间轴，其他图表不受影响（鼠标 + 触摸单指），rAF 节流
  var panId = null, panStartPlotX = null, panWin = null, panMoved = false, rafId = 0;
  var panPlotLeft = 0, panPlotW = 1;

  function clampWin(s, e) {
    var nowS = Math.floor(Date.now() / 1000);
    var floor = nowS - retain * 86400;
    if (s < floor) { e += floor - s; s = floor; }
    if (e > nowS) { s -= e - nowS; e = nowS; if (s < floor) { s = floor; } }
    return [s, e];
  }

  function panTo(clientX) {
    var span = panWin[1] - panWin[0];
    var dt = ((clientX - panPlotLeft) - panStartPlotX) / panPlotW * span;
    var w = clampWin(panWin[0] - dt, panWin[1] - dt);
    var s = Math.floor(w[0]), e = Math.ceil(w[1]);
    // 兜底：目标窗口至少要有 2 个数据点，否则停在数据边缘不继续移动
    var local = sliceFor(panId, s, e);
    if (local.length < 2) { return; }
    var cur = chartWins[panId];
    if (cur && s === cur[0] && e === cur[1]) { return; }
    if (!rafId) {
      // 提前捕获当前值：rAF 回调执行时可能已松手（panId 被置 null）
      var pid = panId, plocal = local, ps = s, pe = e;
      rafId = requestAnimationFrame(function () {
        rafId = 0;
        if (pid) { renderChart(pid, plocal, ps, pe); }
      });
    }
  }

  function startPan(id, clientX) {
    if (!chartWins[id] || !(chartLast[id] || []).length) { return; }
    // 以图表绘图区（去除坐标轴边距）做像素↔时间换算，保证拖动与缩放/数据位置完全一致
    var canvas = document.getElementById(id);
    var rect = canvas.getBoundingClientRect();
    var xs = charts[id] && charts[id].scales ? charts[id].scales.x : null;
    panPlotLeft = rect.left + (xs ? xs.left : 0);
    panPlotW = xs ? (xs.right - xs.left) : rect.width;
    panId = id;
    panStartPlotX = clientX - panPlotLeft;
    panWin = chartWins[id].slice();
    panMoved = false;
    // 拖动期间禁止页面选中/原生拖拽，避免带动其他内容
    document.body.classList.add('chart-panning');
    $('.trend-canvas').css('cursor', 'grabbing');
  }

  function movePan(clientX) {
    if (panStartPlotX === null) { return; }
    if (Math.abs((clientX - panPlotLeft) - panStartPlotX) > 3) { panMoved = true; }
    panTo(clientX);
  }

  function endPan() {
    if (panStartPlotX === null) { return; }
    var id = panId;
    panId = null;
    panStartPlotX = null;
    document.body.classList.remove('chart-panning');
    $('.trend-canvas').css('cursor', 'grab');
    if (panMoved && id && chartWins[id]) {
      // 平移停止后拉取该图表窗口的高精度数据（只更新这一张图）
      var s = chartWins[id][0], e = chartWins[id][1];
      setActiveRange(null);
      clearTimeout(detailTimers[id]);
      detailTimers[id] = setTimeout(function () { loadWindow(id, s, e); }, 250);
    }
  }

  $('.trend-canvas').on('mousedown', function (e) {
    if (e.button !== 0) { return; }
    startPan(this.id, e.originalEvent.clientX);
    e.preventDefault();
  });
  // 阻止浏览器原生拖拽（避免拖动时带动页面元素）
  $('.trend-canvas').on('dragstart', function (e) { e.preventDefault(); });
  $(document).on('mousemove.metrics', function (e) { movePan(e.originalEvent.clientX); });
  $(document).on('mouseup.metrics', endPan);

  // 触摸单指平移（只动当前图表）
  $('.trend-canvas').each(function () {
    var el = this;
    el.addEventListener('touchstart', function (e) {
      if (e.touches.length === 1) { startPan(el.id, e.touches[0].clientX); }
    }, { passive: true });
    el.addEventListener('touchmove', function (e) {
      if (panStartPlotX !== null && e.touches.length === 1) {
        e.preventDefault();
        movePan(e.touches[0].clientX);
      }
    }, { passive: false });
    el.addEventListener('touchend', endPan);
  });

  // 初始化：基线数据 + 默认 24 小时（所有图表同步）
  setActiveRange($('#range-btns button[data-range="24h"]'));
  var nowS = Math.floor(Date.now() / 1000);
  loadFull();
  loadWindowAll(nowS - 86400, nowS);
});
</script>
JS;
}
include __DIR__ . '/templates/footer.php';
