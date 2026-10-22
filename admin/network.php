<?php
/**
 * 网络质量页：单节点的可用率条 + 延迟/丢包图表 + 网络质量打分（Uptime Kuma 风格）
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '网络质量';
$active    = 'index';

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM servers WHERE id = ?');
$stmt->execute([$id]);
$server = $stmt->fetch();

include __DIR__ . '/templates/header.php';
?>

<?php if (!$server): ?>
<div class="card">
  <div class="card-body text-center text-muted py-5">
    <i class="mdi mdi-chart-line-variant fs-1 d-block mb-2"></i>
    节点不存在
    <div class="mt-3"><a class="btn btn-primary btn-sm" href="index.php"><i class="mdi mdi-arrow-left"></i> 返回监控总览</a></div>
  </div>
</div>
<?php else: ?>

<!-- 页头：节点信息 + 时间档位 -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3" style="background:#fff;border:1px solid #eaecf0;border-radius:12px;padding:12px 16px">
  <div class="d-flex align-items-center flex-wrap gap-2">
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="mdi mdi-arrow-left"></i> 返回</a>
    <div class="card-title mb-0"><i class="mdi mdi-pulse me-1"></i> 网络质量 · <?php echo e($server['name']); ?></div>
    <span class="badge badge-soft-info"><?php echo e(strtoupper((string)$server['type'])); ?></span>
    <span class="text-muted small"><?php echo e((string)$server['address']); ?><?php echo (int)$server['port'] > 0 && $server['type'] !== 'http' ? ':' . (int)$server['port'] : ''; ?></span>
  </div>
  <div class="btn-group btn-group-sm" id="range-btns">
    <button type="button" class="btn btn-outline-primary" data-range="1h">1 小时</button>
    <button type="button" class="btn btn-outline-primary" data-range="6h">6 小时</button>
    <button type="button" class="btn btn-primary" data-range="24h">24 小时</button>
    <button type="button" class="btn btn-outline-primary" data-range="7d">7 天</button>
  </div>
</div>

<!-- 可用率条（最近 50 次探测） -->
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex align-items-center gap-3">
      <div class="d-flex align-items-end gap-1 flex-grow-1 flex-wrap" id="uptime-bars" style="min-height:44px"></div>
      <div id="status-pill" class="badge rounded-pill fs-6 px-4 py-3" style="background:#12b76a">-</div>
    </div>
    <div class="d-flex justify-content-between text-muted small mt-2">
      <span>最近 50 次探测（悬停查看单次延迟 / 丢包）</span>
      <span id="bars-time"></span>
    </div>
    <div class="text-muted small text-center py-3" id="bars-empty" style="display:none">
      <i class="mdi mdi-chart-line-variant me-1"></i>暂无探测记录
    </div>
  </div>
</div>

<!-- 质量历史（逐小时可用率色块，与前台同款） -->
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
      <div class="fw-bold"><i class="mdi mdi-chart-box-outline me-1 text-primary"></i>质量历史<span class="text-muted small fw-normal ms-2">逐小时可用率，悬停查看具体数值（1/6/24 小时档展示近 24 小时，7 天档展示近 7 天）</span></div>
      <div class="text-muted small" id="hist-stat"></div>
    </div>
    <div class="qk-hist-bars" id="hist-bars"><span class="text-muted small">加载中…</span></div>
  </div>
</div>

<!-- 统计卡 -->
<div class="row g-3 g-xl-4 mb-3">
  <div class="col-6 col-xl-2">
    <div class="card h-100"><div class="card-body py-3 text-center">
      <div class="text-muted small mb-1">可用率（<span class="st-range">24 小时</span>）</div>
      <div class="fw-bold" style="font-size:26px" id="st-up24">-</div>
      <span class="text-muted small" id="st-up24-sub">波动扣减后</span>
    </div></div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100"><div class="card-body py-3 text-center">
      <div class="text-muted small mb-1">当前延迟</div>
      <div class="fw-bold" style="font-size:26px" id="st-lat">-</div>
      <span class="text-muted small" id="st-lat-sub">-</span>
    </div></div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100"><div class="card-body py-3 text-center">
      <div class="text-muted small mb-1">平均延迟（<span class="st-range">24 小时</span>）</div>
      <div class="fw-bold" style="font-size:26px" id="st-avg-lat">-</div>
      <span class="text-muted small">ms</span>
    </div></div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100"><div class="card-body py-3 text-center">
      <div class="text-muted small mb-1">丢包率（<span class="st-range">24 小时</span>）</div>
      <div class="fw-bold" style="font-size:26px" id="st-loss">-</div>
      <span class="text-muted small">平均 %</span>
    </div></div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100"><div class="card-body py-3 text-center">
      <div class="text-muted small mb-1">探测次数（<span class="st-range">24 小时</span>）</div>
      <div class="fw-bold" style="font-size:26px" id="st-count">-</div>
      <span class="text-muted small">次</span>
    </div></div>
  </div>
</div>

<!-- 延迟 / 丢包图表 -->
<div class="card mb-3">
  <header class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="card-title"><i class="mdi mdi-chart-line me-1"></i> 响应时间与丢包率</div>
    <div class="d-flex align-items-center gap-3 text-muted small">
      <span><span class="me-1" style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#4361ee"></span>延迟 ms</span>
      <span><span class="me-1" style="display:inline-block;width:10px;height:10px;border-radius:3px;background:rgba(240,68,56,.6)"></span>丢包 %</span>
    </div>
  </header>
  <div class="card-body">
    <div style="height:320px"><canvas id="chart-net"></canvas></div>
    <div class="text-muted small text-center mt-3" id="chart-empty" style="display:none">
      <i class="mdi mdi-chart-line-variant me-1"></i>当前时间段暂无历史数据
    </div>
  </div>
</div>

<?php endif; ?>

<?php
$pageScript = '';
if ($server) {
    $pageScript = '<script>window.probeNetworkInit = { id: ' . (int)$server['id'] . ' };</script>'
        . '<script src="../js/chart.min.js"></script>'
        . <<<'JS'
<script>
$(function () {
  var serverId = window.probeNetworkInit.id;
  var chart = null;
  var curRange = '24h';

  function fmtMs(v) { return v === null || v === undefined ? '-' : v + ' ms'; }
  function fmtPct(v) { return v === null || v === undefined ? '-' : v + '%'; }

  function render(data) {
    var st = data.stats;

    /* ---- 可用率条 ---- */
    var $bars = $('#uptime-bars').empty();
    $('#bars-empty').toggle(data.bars.length === 0);
    (data.bars || []).forEach(function (b) {
      var tip = b.t + '　' + (b.ok ? '正常' : '失败') + (b.lat !== null ? '　延迟 ' + b.lat + 'ms' : '') + (b.loss !== null ? '　丢包 ' + b.loss + '%' : '');
      $('<span>').attr('title', tip).css({
        width: '6px', height: '26px', borderRadius: '3px', display: 'inline-block',
        background: b.ok ? '#12b76a' : '#f04438'
      }).appendTo($bars);
    });
    if (data.bars.length) {
      $('#bars-time').text(data.bars[0].t + ' ~ ' + data.bars[data.bars.length - 1].t);
    } else {
      $('#bars-time').text('');
    }

    /* ---- 状态徽章 ---- */
    var last = st.last;
    var $pill = $('#status-pill');
    if (last && last.ok) {
      $pill.text('Up').css('background', '#12b76a');
    } else if (last) {
      $pill.text('Down').css('background', '#f04438');
    } else {
      $pill.text('-').css('background', '#98a2b3');
    }

    /* ---- 统计卡（跟随所选时间档位） ---- */
    var cur = st.cur || { n: 0, uptime: null, avg_latency: null, avg_loss: null };
    $('.st-range').text(st.range_label || '');
    $('#st-lat').text(last ? fmtMs(last.lat) : '-');
    $('#st-lat-sub').text(last ? (last.ok ? '正常 · ' + last.t : '失败 · ' + last.t) : '-');
    $('#st-avg-lat').text(cur.avg_latency !== null ? cur.avg_latency : '-');
    var upT = st.up_t || [98, 90];
    var upColor = cur.uptime === null ? '#98a2b3' : (cur.uptime >= upT[0] ? '#12b76a' : (cur.uptime >= upT[1] ? '#f79009' : '#f04438'));
    $('#st-up24').text(cur.uptime !== null ? cur.uptime + '%' : '-').css('color', upColor);
    $('#st-up24-sub').text('波动扣减后 · ' + cur.n + ' 次');
    $('#st-loss').text(cur.avg_loss !== null ? cur.avg_loss : '-');
    $('#st-count').text(cur.n);

    /* ---- 图表 ---- */
    var hasLoss = (data.series.loss || []).some(function (v) { return v !== null; });
    $('#chart-empty').toggle(data.series.labels.length === 0);
    var datasets = [{
      label: '延迟 ms', type: 'line', data: data.series.latency, yAxisID: 'y',
      borderColor: '#4361ee', backgroundColor: 'rgba(67,97,238,.12)',
      fill: true, tension: .3, pointRadius: 0, borderWidth: 1.5
    }];
    if (hasLoss) {
      datasets.push({
        label: '丢包 %', type: 'bar', data: data.series.loss, yAxisID: 'y1',
        backgroundColor: 'rgba(240,68,56,.55)', borderRadius: 2, barPercentage: .6, categoryPercentage: .9
      });
    }
    var opts = {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { maxTicksLimit: 10, maxRotation: 0, font: { size: 11 } }, grid: { display: false } },
        y: { min: 0, ticks: { font: { size: 11 }, callback: function (v) { return v + ' ms'; } } },
        y1: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, ticks: { font: { size: 11 }, callback: function (v) { return v + '%'; } } }
      }
    };
    if (chart) {
      chart.data.labels = data.series.labels;
      chart.data.datasets = datasets;
      chart.update('none');
    } else {
      chart = new Chart(document.getElementById('chart-net'), { type: 'line', data: { labels: data.series.labels, datasets: datasets }, options: opts });
    }
  }

  function load(range) {
    curRange = range || curRange;
    $.getJSON('api/network.php', { id: serverId, range: curRange }, function (res) {
      if (res && res.code === 0) { render(res.data); }
    });
    loadHist(curRange);
  }

  /* ---- 质量历史色块：逐小时可用率，颜色按后台设置的红黄阈值 ---- */
  function loadHist(range) {
    var hours = range === '7d' ? 168 : 24;
    $.getJSON('api/quality.php', { sid: serverId, hours: hours }, function (res) {
      var d = res && res.code === 0 ? res.data : null;
      var $box = $('#hist-bars').empty();
      if (!d || !d.bars || !d.bars.length) {
        $box.html('<span class="text-muted small">暂无历史数据</span>');
        $('#hist-stat').text('');
        return;
      }
      var upT = d.up_t || [98, 90];
      d.bars.forEach(function (b) {
        var cls = b[1] === null ? '' : (b[1] >= upT[0] ? 'g' : (b[1] >= upT[1] ? 'y' : 'r'));
        $('<div>').addClass('qk-hist-bar ' + cls)
          .attr('title', b[0] + (b[1] === null ? '　无数据' : '　可用率 ' + b[1] + '%'))
          .appendTo($box);
      });
      $('#hist-stat').html(d.uptime !== null
        ? '近 ' + d.hours + ' 小时可用率 <b class="text-dark">' + d.uptime + '%</b> · 平均延迟 ' + (d.avg_latency === null ? '-' : d.avg_latency + ' ms')
        : '暂无统计');
    });
  }

  $('#range-btns').on('click', 'button', function () {
    $('#range-btns button').removeClass('btn-primary').addClass('btn-outline-primary');
    $(this).removeClass('btn-outline-primary').addClass('btn-primary');
    load($(this).data('range'));
  });

  load('24h');
  setInterval(function () { load(); }, 60000);
});
</script>
JS;
}
include __DIR__ . '/templates/footer.php';
