<?php
/**
 * 监控总览（仪表盘）
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '监控总览';
$active    = 'index';

$servers = $db->query('SELECT * FROM servers ORDER BY sort_order ASC, id ASC')->fetchAll();

$total  = count($servers);
$online = count(array_filter($servers, fn($s) => $s['last_status'] === 1));
$offline = count(array_filter($servers, fn($s) => $s['last_status'] === 0));
$todayChecks = (int)$db->query('SELECT COUNT(*) AS c FROM logs WHERE checked_at >= ' . strtotime(date('Y-m-d')))
    ->fetch()['c'];

$theme = probe_theme_data($db);
$groups   = GroupService::all($db);
$groupMap = GroupService::map($db);

// 健康状态统计（在线/健康/告警/危险/离线），阈值可在系统设置中调整
[$healthWarn, $healthDanger, $healthLossDanger] = health_thresholds($db);
// SSH+PING 节点是否仅展示 PING 延迟（后台前台设置中配置）
$ssppingPingOnly = get_setting($db, 'sspping_ping_only', '0') === '1';
$healthCounts = ['healthy' => 0, 'warning' => 0, 'danger' => 0, 'offline' => 0];
foreach ($servers as $s) {
    $h = server_health($s, $healthWarn, $healthDanger, $healthLossDanger);
    if ($h === 'offline') {
        $healthCounts['offline']++;
    } else {
        $healthCounts[$h]++;
    }
}

// 资源占用展示/超阈标红用的阈值
$resThresholds = [
    (int)get_setting($db, 'alert_cpu_threshold', '90'),
    (int)get_setting($db, 'alert_mem_threshold', '90'),
    (int)get_setting($db, 'alert_disk_threshold', '90'),
];

// 12h 波动扣减后可用率（节点卡片徽章），评分松紧/权重/颜色阈值后台可设
$upCfg = uptime_scoring_config($db);
$uptimeMap = [];
$rowsByServer = [];
foreach ($db->query('SELECT server_id, status, latency, packet_loss FROM logs WHERE checked_at >= ' . (time() - 43200)) as $qr) {
    $rowsByServer[(int)$qr['server_id']][] = [(int)$qr['status'], $qr['latency'] !== null ? (float)$qr['latency'] : null, $qr['packet_loss'] !== null ? (float)$qr['packet_loss'] : null];
}
foreach ($rowsByServer as $sid => $rows) {
    $uptimeMap[$sid] = uptime_stats_from_rows($rows, $upCfg);
}

// 按分类归组节点：分类按创建顺序、未分类殿后；组内 SSH 资源监控节点优先（占大格居左）
$byGroup = [];
foreach ($servers as $s) {
    $byGroup[(int)($s['group_id'] ?? 0)][] = $s;
}
$groupOrder = [];
foreach ($groups as $g) {
    if (isset($byGroup[(int)$g['id']])) {
        $groupOrder[] = (int)$g['id'];
    }
}
foreach (array_keys($byGroup) as $gid) {
    if (!in_array($gid, $groupOrder, true)) {
        $groupOrder[] = $gid;
    }
}
// 应用用户拖动保存的排序，新增分类追加到尾部
$savedOrder = array_values(array_filter(
    explode(',', (string)get_setting($db, 'group_order', '')),
    fn($v) => $v !== '' && ctype_digit($v)
));
if ($savedOrder) {
    usort($groupOrder, function ($a, $b) use ($savedOrder) {
        $pa = array_search((string)$a, $savedOrder, true);
        $pb = array_search((string)$b, $savedOrder, true);
        $pa = $pa === false ? PHP_INT_MAX : $pa;
        $pb = $pb === false ? PHP_INT_MAX : $pb;
        return $pa <=> $pb;
    });
}
foreach ($byGroup as &$groupList) {
    usort($groupList, function ($a, $b) {
        // 用户拖动保存的顺序优先；未拖动过（均为 0）时 SSH 资源监控节点居前占大格
        $so = (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);
        if ($so !== 0) {
            return $so;
        }
        $big = fn($x) => in_array($x['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($x['ssh_monitor'] ?? 0) === 1 ? 1 : 0;
        return ($big($b) <=> $big($a)) ?: ((int)$a['id'] <=> (int)$b['id']);
    });
}
unset($groupList);

include __DIR__ . '/templates/header.php';
?>

<?php if ($theme['offline'] > 0): ?>
<div class="alert probe-banner d-flex align-items-center" role="alert">
  <i class="mdi mdi-alert-octagon-outline fs-3 me-3"></i>
  <div>
    <span class="banner-title">当前有 <?php echo $theme['offline']; ?> 个节点离线：</span>
    <?php foreach ($theme['offlineNodes'] as $i => $n): ?>
      <?php echo $i > 0 ? '、' : ''; ?><a href="logs.php?server_id=<?php echo (int)$n['id']; ?>&down=1"><?php echo e($n['name']); ?></a>
    <?php endforeach; ?>
    <span class="text-muted small ms-2">请及时排查，可前往 <a href="logs.php?down=1">探测日志</a> 查看异常记录</span>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 g-xl-4">
  <!-- 服务器总览（浅色统计面板） -->
  <div class="col-xl-4">
    <div class="card probe-overview h-100">
      <header class="card-header d-flex justify-content-between align-items-center">
        <div class="card-title"><i class="mdi mdi-view-dashboard-outline me-1"></i> 服务器总览</div>
        <span class="small text-muted">今日探测 <?php echo $todayChecks; ?> 次</span>
      </header>
      <div class="card-body pt-3">
        <div class="ov-head"><span>服务器总数</span><strong><?php echo $total; ?></strong></div>
        <?php
        $ovRows = [
            ['online', '在线', $healthCounts['healthy'] + $healthCounts['warning'] + $healthCounts['danger'], '#4361ee'],
            ['healthy', '健康', $healthCounts['healthy'], '#12b76a'],
            ['warning', '告警', $healthCounts['warning'], '#f79009'],
            ['danger', '危险', $healthCounts['danger'], '#f04438'],
            ['offline', '离线', $healthCounts['offline'], '#667085'],
        ];
        foreach ($ovRows as [$ovKey, $ovLabel, $ovNum, $ovColor]):
            $ovPct = $total > 0 ? (int)round($ovNum / $total * 100) : 0;
        ?>
          <div class="ov-row" data-ov="<?php echo $ovKey; ?>">
            <span class="dot" style="background:<?php echo $ovColor; ?>"></span>
            <span class="lbl"><?php echo $ovLabel; ?></span>
            <span class="cnt" style="color:<?php echo $ovColor; ?>"><?php echo $ovNum; ?>台 (<?php echo $ovPct; ?>%)</span>
          </div>
          <div class="ov-bar"><i style="width:<?php echo $ovPct; ?>%;background:<?php echo $ovColor; ?>"></i></div>
        <?php endforeach; ?>
        <div class="ov-foot">资源 ≥<?php echo $healthWarn; ?>% 告警 / ≥<?php echo $healthDanger; ?>% 危险 · PING 丢包 ≥<?php echo $healthLossDanger; ?>% 危险</div>
      </div>
    </div>
  </div>

  <!-- 预警中心 -->
  <div class="col-xl-8">
    <div class="card h-100">
      <header class="card-header d-flex justify-content-between align-items-center">
        <div class="card-title"><i class="mdi mdi-bell-alert-outline me-1"></i> 预警中心</div>
        <div class="d-flex align-items-center gap-3">
          <a href="alerts.php" class="btn btn-outline-primary btn-sm"><i class="mdi mdi-history me-1"></i>全部日志</a>
          <a href="settings.php?tab=alert" class="small">预警设置</a>
        </div>
      </header>
      <div class="card-body pt-3">
        <div class="row g-3 mb-4">
          <div class="col-4">
            <div class="alert-summary-item <?php echo $theme['alertToday'] > 0 ? 'sum-danger' : 'sum-success'; ?>">
              <div class="num num-danger"><?php echo $theme['alertToday']; ?></div>
              <div class="lbl">今日预警</div>
            </div>
          </div>
          <div class="col-4">
            <div class="alert-summary-item <?php echo $theme['offline'] > 0 ? 'sum-danger' : 'sum-success'; ?>">
              <div class="num <?php echo $theme['offline'] > 0 ? 'num-danger' : 'num-success'; ?>"><?php echo $theme['offline']; ?></div>
              <div class="lbl">离线节点</div>
            </div>
          </div>
          <div class="col-4">
            <div class="alert-summary-item <?php echo $theme['sslSoon'] > 0 ? 'sum-warning' : 'sum-success'; ?>">
              <div class="num <?php echo $theme['sslSoon'] > 0 ? 'num-warning' : 'num-success'; ?>"><?php echo $theme['sslSoon']; ?></div>
              <div class="lbl">SSL 将到期</div>
            </div>
          </div>
        </div>

        <div class="small text-muted mb-2">最近预警</div>
        <?php if (!$theme['alerts']): ?>
          <div class="text-center text-muted py-4">
            <i class="mdi mdi-shield-check-outline fs-1 d-block mb-1 text-success"></i>
            暂无预警记录，运行正常
          </div>
        <?php else: ?>
          <div class="probe-feed">
            <?php foreach (array_slice($theme['alerts'], 0, 4) as $a): ?>
              <div class="alert-feed-item">
                <?php echo alert_type_badge($a['alert_type']); ?>
                <div class="feed-body">
                  <div class="feed-title" title="<?php echo e(($a['server_name'] ? $a['server_name'] . '：' : '') . $a['message']); ?>"><?php echo e(($a['server_name'] ? $a['server_name'] . '：' : '') . $a['message']); ?></div>
                </div>
                <div class="feed-time"><?php echo time_text($a['sent_at']); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 节点状态区头：与分类模块融为一体 -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-4 mb-3" id="node-toolbar">
  <div class="card-title mb-0" style="margin-left: 12px;"><i class="mdi mdi-monitor-dashboard me-1"></i> 节点状态</div>
  <div class="d-flex gap-2 align-items-center flex-wrap node-toolbar-actions">
    <select id="group-filter" class="form-select form-select-sm" style="width:140px">
      <option value="">全部分类</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?php echo (int)$g['id']; ?>"><?php echo e($g['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <select id="refresh-interval" class="form-select form-select-sm" style="width:112px" title="自动刷新间隔">
      <option value="10">10 秒刷新</option>
      <option value="30">30 秒刷新</option>
      <option value="60">60 秒刷新</option>
      <option value="120">120 秒刷新</option>
      <option value="0">关闭自动刷新</option>
    </select>
    <button type="button" class="btn btn-light btn-sm" id="btn-refresh"><i class="mdi mdi-refresh"></i> 刷新</button>
    <span id="refresh-countdown" class="refresh-ring" title="自动刷新倒计时"><span class="ring-inner"><b id="refresh-sec"></b></span></span>
    <button type="button" class="btn btn-primary btn-sm" id="btn-check-all"><i class="mdi mdi-radar"></i> 立即探测全部</button>
    <a href="servers.php" class="btn btn-success btn-sm"><i class="mdi mdi-plus"></i> 添加节点</a>
  </div>
</div>

<!-- 分类模块：一个分类一个大模块，行内自适应排几个 -->
<div class="row g-3 g-xl-4" id="group-area">
  <?php if (!$servers): ?>
    <div class="col-12">
      <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="mdi mdi-server-off-outline fs-1 d-block mb-2"></i>
        暂无监控节点，请先 <a href="servers.php">添加服务器</a>
      </div></div>
    </div>
  <?php endif; ?>
  <?php foreach ($groupOrder as $gid):
      $groupList = $byGroup[$gid];
      $gName   = $gid > 0 ? ($groupMap[$gid] ?? '未知分类') : '未分类';
      $gTotal  = count($groupList);
      $gOnline = count(array_filter($groupList, fn($s) => $s['last_status'] === 1));
  ?>
    <div class="col-12 col-xl-6 group-col" data-group="<?php echo $gid; ?>">
      <div class="card group-card h-100">
        <header class="card-header d-flex justify-content-between align-items-center">
          <div class="card-title d-flex align-items-center mb-0">
            <i class="mdi mdi-drag drag-handle" title="按住拖动排序"></i>
            <i class="mdi mdi-folder-outline me-1 text-primary"></i> <?php echo e($gName); ?>
          </div>
          <span class="badge badge-soft-<?php echo $gOnline === $gTotal ? 'success' : ($gOnline > 0 ? 'warning' : 'danger'); ?>"><?php echo $gOnline; ?>/<?php echo $gTotal; ?> 在线</span>
        </header>
        <div class="card-body">
          <div class="node-grid">
            <?php foreach ($groupList as $s):
                $isBig = in_array($s['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($s['ssh_monitor'] ?? 0) === 1;
                // 健康值徽章：掉线 → 红色故障 0%；在线 → 波动扣减后可用率；无数据 → 灰
                $hq = $uptimeMap[(int)$s['id']] ?? null;
                if ((int)$s['last_status'] === 0) {
                    $healthChip = '<span class="health-chip" style="color:#f04438;background:rgba(240,68,56,.1)" title="节点离线，12h 可用率按 0% 计">故障 0%</span>';
                } elseif ($hq && $hq['uptime'] !== null) {
                    $uc = uptime_color((float)$hq['uptime'], $upCfg['yellow'], $upCfg['red']);
                    $healthChip = '<span class="health-chip" style="color:' . $uc . ';background:' . $uc . '1a" title="近12h 可用率（延迟超基线/丢包已扣减）' . ($hq['avg_latency'] !== null ? ' · 平均延迟 ' . $hq['avg_latency'] . 'ms' : '') . '">可用率 ' . $hq['uptime'] . '%</span>';
                } else {
                    $healthChip = '<span class="health-chip" style="color:#98a2b3;background:rgba(152,162,179,.12)" title="12h 内暂无探测数据">暂无数据</span>';
                }
                $tileClass = 'node-tile' . ($isBig ? ' node-big' : '') . ($s['last_status'] === 0 ? ' row-offline' : '');
            ?>
              <div class="<?php echo $tileClass; ?>" data-id="<?php echo (int)$s['id']; ?>" data-group="<?php echo $gid; ?>" data-health="<?php echo server_health($s, $healthWarn, $healthDanger, $healthLossDanger); ?>">
                <div class="node-head d-flex justify-content-between align-items-start gap-2">
                  <div class="text-truncate">
                    <i class="mdi mdi-drag node-handle" title="按住拖动排序 / 拖到其他分类"></i><strong><?php echo e($s['name']); ?></strong>
                    <span class="badge badge-soft-info ms-1"><?php echo e(probe_types()[$s['type']]['label'] ?? strtoupper($s['type'])); ?></span>
                    <?php echo $healthChip; ?>
                    <?php if (!$s['enabled']): ?><span class="badge badge-soft-secondary ms-1">已停用</span><?php endif; ?>
                  </div>
                  <div class="td-status flex-none"><?php echo status_badge($s['last_status']); ?></div>
                </div>
                <div class="node-addr small text-muted text-truncate" title="<?php echo e($s['address']); ?>">
                  <i class="mdi mdi-link-variant me-1"></i><?php echo e($s['address']); ?><?php echo (int)$s['port'] > 0 ? ':' . (int)$s['port'] : ''; ?>
                </div>
                <?php if ($isBig): ?>
                  <div class="td-resource"><?php echo resource_gauges_html($s, ...$resThresholds); ?></div>
                <?php else: ?>
                  <div class="td-message small text-muted text-truncate" title="<?php echo e($s['last_message']); ?>"><span class="msg-text"><?php echo e(mb_substr($s['last_message'], 0, 40)); ?></span></div>
                <?php endif; ?>
                <div class="node-foot d-flex justify-content-between align-items-center mt-auto pt-1">
                  <span class="small text-muted"><span class="td-latency"><?php echo e(node_latency_text($s, $ssppingPingOnly)); ?></span><span class="mx-1">·</span><span class="td-time"><?php echo time_text($s['last_check_at']); ?></span></span>
                  <div class="d-flex gap-1">
                    <a class="btn btn-outline-secondary btn-sm" href="network.php?id=<?php echo (int)$s['id']; ?>"><i class="mdi mdi-pulse"></i> 质量</a>
                    <?php if ($isBig && (int)($s['metrics_retain_days'] ?? 0) > 0): ?>
                      <a class="btn btn-outline-secondary btn-sm" href="metrics.php?server_id=<?php echo (int)$s['id']; ?>"><i class="mdi mdi-chart-areaspline"></i> 趋势</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-check-one" data-id="<?php echo (int)$s['id']; ?>">探测</button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php
$pageScript = '<script src="../js/chart.min.js"></script>'
    . <<<'JS'
<script>
$(function () {
  var badgeOn  = '<span class="badge badge-soft-success"><span class="status-dot dot-success"></span>在线</span>';
  var badgeOff = '<span class="badge badge-soft-danger"><span class="status-dot dot-danger"></span>离线</span>';
  var badgeNil = '<span class="badge badge-soft-secondary"><span class="status-dot dot-muted"></span>未检测</span>';

  // 环形图骨架（首次探测前容器内还没有 .resource-gauges 时先插入）
  var gaugeSkeleton = '<div class="resource-gauges" data-cpu="" data-mem="" data-disk="">'
    + '<div class="gauge-item"><div class="gauge-wrap"><canvas width="72" height="72"></canvas><span class="gauge-val">-</span></div><span class="gauge-label">CPU</span></div>'
    + '<div class="gauge-item"><div class="gauge-wrap"><canvas width="72" height="72"></canvas><span class="gauge-val">-</span></div><span class="gauge-label">内存</span></div>'
    + '<div class="gauge-item"><div class="gauge-wrap"><canvas width="72" height="72"></canvas><span class="gauge-val">-</span></div><span class="gauge-label">磁盘</span></div>'
    + '</div>';

  function updateResource($tr, res) {
    var $rc = $tr.find('.td-resource');
    if (!$rc.length) { return; }
    if (!$rc.find('.resource-gauges').length) { $rc.html(gaugeSkeleton); }
    window.probeUpdateGauges($rc, res);
  }

  // 健康值徽章实时更新：掉线 → 红色故障 0%；在线 → 波动扣减后可用率
  function updateHealthChip($tr, isOffline, uptime, avgLat) {
    var $chip = $tr.find('.health-chip');
    if (!$chip.length) { return; }
    if (isOffline) {
      $chip.text('故障 0%').attr('style', 'color:#f04438;background:rgba(240,68,56,.1)').attr('title', '节点离线，12h 可用率按 0% 计');
    } else if (uptime !== null && uptime !== undefined) {
      var upT = window.probeUptimeT || [98, 90];
      var c = uptime >= upT[0] ? '#12b76a' : (uptime >= upT[1] ? '#f79009' : '#f04438');
      $chip.text('可用率 ' + uptime + '%')
        .attr('style', 'color:' + c + ';background:' + c + '1a')
        .attr('title', '近12h 可用率（延迟超基线/丢包已扣减）' + (avgLat != null ? ' · 平均延迟 ' + avgLat + 'ms' : ''));
    }
  }

  // 总览面板（深色统计）增量/全量更新
  var ovCounts = window.probeOvInit.counts;
  function ovApply() {
    var map = {
      online: (ovCounts.healthy || 0) + (ovCounts.warning || 0) + (ovCounts.danger || 0),
      healthy: ovCounts.healthy || 0, warning: ovCounts.warning || 0,
      danger: ovCounts.danger || 0, offline: ovCounts.offline || 0
    };
    var total = window.probeOvInit.total;
    $('.probe-overview [data-ov]').each(function () {
      var num = map[$(this).data('ov')] || 0;
      var pct = total > 0 ? Math.round(num / total * 100) : 0;
      $(this).find('.cnt').text(num + '台 (' + pct + '%)');
      $(this).next('.ov-bar').children('i').css('width', pct + '%');
    });
  }
  function ovShift(from, to) {
    if (from && ovCounts[from] !== undefined) { ovCounts[from]--; }
    if (to && ovCounts[to] !== undefined) { ovCounts[to]++; }
    ovApply();
  }
  function ovRecount(list) {
    ovCounts = { healthy: 0, warning: 0, danger: 0, offline: 0 };
    $.each(list, function (i, r) {
      if (r.last_status == 1) { ovCounts[r.health] = (ovCounts[r.health] || 0) + 1; }
      else if (r.last_status == 0) { ovCounts.offline++; }
    });
    ovApply();
  }

  function checkOne(id, $btn) {
    if ($btn) { $btn.prop('disabled', true).text('探测中...'); }
    $.post('api/probe.php', { action: 'check', server_id: id }, function (res) {
      if (res.code === 0) {
        var r = res.data;
        var $tr = $('#group-area .node-tile[data-id="' + id + '"]');
        $tr.find('.td-status').html(r.status == 1 ? badgeOn : badgeOff);
        $tr.toggleClass('row-offline', r.status != 1);
        updateHealthChip($tr, r.status != 1, null, null);
        $tr.find('.td-latency').text(r.latency_text || (r.latency + ' ms'));
        $tr.find('.td-message').attr('title', r.message).find('.msg-text').text(r.message.length > 24 ? r.message.substr(0, 24) : r.message);
        $tr.find('.td-time').text(r.checked_at);
        if (r.resource) { updateResource($tr, r.resource); }
        var oldH = $tr.data('health');
        if (r.health && oldH !== r.health) { ovShift(oldH, r.health); $tr.attr('data-health', r.health); }
      } else {
        probeNotify(res.data.message || '探测失败', 'danger');
      }
      if ($btn) { $btn.prop('disabled', false).text('探测'); }
    });
  }

  $('#group-area').on('click', '.btn-check-one', function () {
    checkOne($(this).data('id'), $(this));
  });

  $('#btn-check-all').on('click', function () {
    var $btn = $(this).prop('disabled', true);
    $.post('api/probe.php', { action: 'check_all' }, function (res) {
      $btn.prop('disabled', false);
      if (res.code === 0) {
        location.reload();
      } else {
        probeNotify(res.data.message || '探测失败', 'danger');
      }
    });
  });

  $('#btn-refresh').on('click', function () { location.reload(); });

  // 按分类筛选（直接显隐整个分类模块）
  $('#group-filter').on('change', function () {
    var v = $(this).val();
    $('#group-area .group-col').each(function () {
      if (v === '') { $(this).show(); }
      else { $(this).toggle(String($(this).data('group')) === String(v)); }
    });
  });

  // 通用长按拖动排序：鼠标按住把手直接拖，触摸长按 350ms 触发；
  // containerSel 传入时支持跨容器拖放（拖入空容器自动追加到末尾）
  function initDragSort(areaSel, itemSel, handleSel, sameScope, onSave, containerSel) {
    var area = document.querySelector(areaSel);
    if (!area) { return; }
    var dragEl = null, timer = null;

    function pointOf(ev) { return ev.touches && ev.touches.length ? ev.touches[0] : ev; }

    function onStart(e) {
      var handle = e.target.closest ? e.target.closest(handleSel) : null;
      if (!handle) { return; }
      var el = handle.closest(itemSel);
      if (e.type === 'touchstart') {
        timer = setTimeout(function () { begin(el); }, 350);
      } else {
        e.preventDefault();
        begin(el);
      }

      function begin(item) {
        dragEl = item;
        item.classList.add('dragging');
        document.body.classList.add('drag-sorting');
        if (navigator.vibrate) { navigator.vibrate(20); }
      }

      function onMove(ev) {
        if (!dragEl) {
          // 触摸长按完成前移动视为取消
          if (timer) { clearTimeout(timer); timer = null; unbind(); }
          return;
        }
        ev.preventDefault();
        var p = pointOf(ev);
        var target = null;
        area.querySelectorAll(itemSel).forEach(function (c) {
          if (c === dragEl || !sameScope(c, dragEl)) { return; }
          var r = c.getBoundingClientRect();
          if (p.clientX >= r.left && p.clientX <= r.right && p.clientY >= r.top && p.clientY <= r.bottom) { target = c; }
        });
        // 指针下方的容器（跨分类拖入提示/落入）
        var overCont = null;
        if (containerSel) {
          area.querySelectorAll(containerSel).forEach(function (ct) {
            var r = ct.getBoundingClientRect();
            var hit = p.clientX >= r.left && p.clientX <= r.right && p.clientY >= r.top && p.clientY <= r.bottom;
            var canDrop = hit && !ct.contains(dragEl);
            ct.classList.toggle('drop-over', canDrop);
            if (canDrop) { overCont = ct; }
          });
        }
        if (target) {
          var list = Array.prototype.slice.call(area.querySelectorAll(itemSel))
            .filter(function (c) { return c === dragEl || sameScope(c, dragEl); });
          if (list.indexOf(dragEl) < list.indexOf(target)) { target.after(dragEl); }
          else { target.before(dragEl); }
        } else if (overCont) {
          overCont.appendChild(dragEl);
        }
      }

      function onEnd() {
        if (timer) { clearTimeout(timer); timer = null; }
        if (containerSel) {
          area.querySelectorAll(containerSel).forEach(function (ct) { ct.classList.remove('drop-over'); });
        }
        if (dragEl) {
          dragEl.classList.remove('dragging');
          document.body.classList.remove('drag-sorting');
          onSave(dragEl);
          dragEl = null;
        }
        unbind();
      }

      function unbind() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onEnd);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onEnd);
        document.removeEventListener('touchcancel', onEnd);
      }

      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onEnd);
      document.addEventListener('touchmove', onMove, { passive: false });
      document.addEventListener('touchend', onEnd);
      document.addEventListener('touchcancel', onEnd);
    }

    area.addEventListener('mousedown', onStart);
    area.addEventListener('touchstart', onStart, { passive: false });
  }

  // 分类模块排序（整个模块互换）
  initDragSort('#group-area', '.group-col', '.drag-handle',
    function () { return true; },
    function () {
      var order = [];
      document.querySelectorAll('#group-area .group-col').forEach(function (c) { order.push(c.getAttribute('data-group')); });
      $.post('api/group_order.php', { order: order.join(',') });
    });

  // 节点卡片排序 + 跨分类拖动（按住卡片拖入其他分类模块即可快速归类）
  initDragSort('#group-area', '.node-tile', '.node-handle',
    function () { return true; },
    function (el) {
      var col = el.closest('.group-col');
      var gid = col ? col.getAttribute('data-group') : '0';
      var grid = el.closest('.node-grid');
      var ids = [];
      grid.querySelectorAll('.node-tile').forEach(function (t) {
        ids.push(t.getAttribute('data-id'));
        t.setAttribute('data-group', gid);
      });
      $.post('api/node_order.php', { ids: ids.join(','), group: gid });
      refreshGroupBadges();
    },
    '.node-grid');

  // 拖放后重算各分类头部「x/y 在线」徽章
  function refreshGroupBadges() {
    $('#group-area .group-col').each(function () {
      var $col = $(this);
      var $tiles = $col.find('.node-tile');
      var total = $tiles.length;
      var online = $tiles.find('.td-status .dot-success').length;
      var cls = total === 0 ? 'secondary' : (online === total ? 'success' : (online > 0 ? 'warning' : 'danger'));
      $col.find('.card-header .badge').attr('class', 'badge badge-soft-' + cls).text(online + '/' + total + ' 在线');
    });
  }

  window.probeRenderGauges(document);

  // 自动刷新：间隔可配置（记忆在本地）+ rAF 驱动扇形圆环平滑消退，到点拉取最新状态
  var REFRESH_KEY = 'probe_refresh_interval';
  var refreshSec = parseInt(localStorage.getItem(REFRESH_KEY), 10);
  if ([10, 30, 60, 120, 0].indexOf(refreshSec) === -1) { refreshSec = 30; }
  var ringEl = document.getElementById('refresh-countdown');
  var secEl  = document.getElementById('refresh-sec');
  var deadline = refreshSec > 0 ? Date.now() + refreshSec * 1000 : 0;
  var lastSecShown = -1;

  function resetCountdown() {
    deadline = refreshSec > 0 ? Date.now() + refreshSec * 1000 : 0;
    lastSecShown = -1;
  }

  function tickRing() {
    if (refreshSec > 0) {
      var remainMs = deadline - Date.now();
      if (remainMs <= 0) {
        pollStatus();
        ringEl.classList.remove('flash'); void ringEl.offsetWidth; ringEl.classList.add('flash');
        resetCountdown();
        remainMs = refreshSec * 1000;
      }
      // 毫秒级占比，扇形平滑消退
      var pct = Math.max(0, Math.min(100, remainMs / (refreshSec * 1000) * 100));
      ringEl.style.background = 'conic-gradient(#4361ee ' + pct + '%, #e4e7ec ' + pct + '%)';
      var sec = Math.max(1, Math.ceil(remainMs / 1000));
      if (sec !== lastSecShown) {
        lastSecShown = sec;
        secEl.textContent = sec;
        ringEl.classList.add('tick'); // 数字切换小弹跳
        setTimeout(function () { ringEl.classList.remove('tick'); }, 200);
      }
    }
    requestAnimationFrame(tickRing);
  }

  $('#refresh-interval').val(String(refreshSec)).on('change', function () {
    refreshSec = parseInt($(this).val(), 10);
    localStorage.setItem(REFRESH_KEY, String(refreshSec));
    resetCountdown();
    ringEl.style.display = refreshSec > 0 ? '' : 'none'; // 关闭自动刷新时隐藏
  });
  ringEl.style.display = refreshSec > 0 ? '' : 'none';
  if (refreshSec > 0) { secEl.textContent = refreshSec; } // 首屏直接显示设定值，避免闪现默认 30

  function pollStatus() {
    $.getJSON('api/probe.php', { action: 'status' }, function (res) {
      if (res.code !== 0) { return; }
      $.each(res.data.list, function (i, r) {
        var $tr = $('#group-area .node-tile[data-id="' + r.id + '"]');
        if (!$tr.length) { return; }
        var badge = r.last_status == 1 ? badgeOn : (r.last_status == 0 ? badgeOff : badgeNil);
        $tr.find('.td-status').html(badge);
        $tr.toggleClass('row-offline', r.last_status == 0);
        updateHealthChip($tr, r.last_status == 0, r.uptime24, r.avg_latency24);
        $tr.find('.td-latency').text(r.latency_text || (r.last_latency != null ? r.last_latency + ' ms' : '-'));
        if (r.ssh_monitor == 1) {
          updateResource($tr, {
            cpu: r.cpu_usage, mem: r.mem_usage, disk: r.disk_usage,
            disk_mount: r.disk_mount, disk_used: r.disk_used, disk_total: r.disk_total, disks: r.disks,
            mem_used: r.mem_used, mem_total: r.mem_total, load_hist: r.load_history,
            swap_used: r.swap_used, swap_total: r.swap_total,
            net_rx: r.net_rx_rate, net_tx: r.net_tx_rate
          });
        }
        if (r.last_check_at) { $tr.find('.td-time').text(r.last_check_at); }
        $tr.attr('data-health', r.health);
      });
      ovRecount(res.data.list);
    });
  }

  requestAnimationFrame(tickRing);
});
</script>
JS;
// nowdoc 内不解析 PHP，阈值需单独注入
echo '<script>window.probeResT = ' . json_encode($resThresholds) . ';</script>';
echo '<script>window.probeUptimeT = ' . json_encode([$upCfg['yellow'], $upCfg['red']]) . ';</script>';
echo '<script>window.probeOvInit = ' . json_encode(['total' => $total, 'counts' => $healthCounts]) . ';</script>';
include __DIR__ . '/templates/footer.php';
