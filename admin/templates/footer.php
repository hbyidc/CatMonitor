      </div>
    </main>
    <!--End 页面主要内容-->
  </div>
</div>

<script src="../js/jquery.min.js"></script>
<script>
// 只为同源写请求附带 CSRF 令牌，禁止向外部地址泄露会话令牌。
jQuery.ajaxPrefilter(function (options, originalOptions, xhr) {
  var method = String(options.type || 'GET').toUpperCase();
  var target;
  try { target = new URL(options.url, document.baseURI); } catch (e) { return; }
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta && target.origin === location.origin && !/^(GET|HEAD|OPTIONS)$/.test(method)) {
    xhr.setRequestHeader('X-CSRF-Token', meta.content);
  }
});
</script>
<script src="../js/popper.min.js"></script>
<script src="../js/bootstrap.min.js"></script>
<script src="../js/perfect-scrollbar.min.js"></script>
<script src="../js/jquery.cookie.min.js"></script>
<script src="../js/jquery-confirm/jquery-confirm.min.js"></script>
<script src="../js/main.min.js"></script>

<!-- 轻量 Toast 通知 -->
<div id="probe-toast" class="probe-toast"></div>
<script>
window.probeNotify = function (msg, type) {
  var $t = $('#probe-toast');
  $t.attr('class', 'probe-toast probe-toast-' + (type || 'info')).text(msg);
  requestAnimationFrame(function () { $t.addClass('show'); });
  clearTimeout($t.data('timer'));
  $t.data('timer', setTimeout(function () { $t.removeClass('show'); }, 2600));
};
</script>
<!-- SSH 资源环形图共享渲染（需页面自行引入 chart.min.js，未引入时降级为纯文本百分比） -->
<script>
window.probeGaugeColor = function (v, t) {
  if (v >= t) return '#f04438';
  if (v >= t * 0.8) return '#f79009';
  return '#12b76a';
};
window.probeDiskListHtml = function (res) {
  var disks = Array.isArray(res.disks) ? res.disks : (res.disk == null ? [] : [{ mount: res.disk_mount || '/', pct: res.disk, used: res.disk_used, total: res.disk_total }]);
  var limit = window.probeDiskLimit || 0;
  if (limit > 0) {
    var root = [], data = [];
    disks.forEach(function (d) { (d.mount === '/' ? root : data).push(d); });
    data.sort(function (a, b) { return (b.pct == null ? -1 : Number(b.pct)) - (a.pct == null ? -1 : Number(a.pct)); });
    disks = root.concat(data.slice(0, limit));
  }
  var $list = $('<div class="disk-list" aria-label="各磁盘使用情况"></div>');
  var threshold = (window.probeResT || [90, 90, 90])[2];
  function kb(v) {
    if (v >= 1073741824) return (v / 1073741824).toFixed(1) + ' TB';
    if (v >= 1048576) return (v / 1048576).toFixed(1) + ' GB';
    if (v >= 1024) return (v / 1024).toFixed(1) + ' MB';
    return Math.round(v) + ' KB';
  }
  disks.forEach(function (d) {
    var v = d.pct == null || !isFinite(Number(d.pct)) ? null : Math.max(0, Math.min(100, Number(d.pct)));
    var $row = $('<div class="disk-row"></div>');
    var $head = $('<div class="disk-row-head"></div>').appendTo($row);
    $('<span class="disk-mount"></span>').text(d.mount).attr('title', d.mount).appendTo($head);
    $('<span></span>').text(v == null ? '-' : Math.round(v * 10) / 10 + '%').appendTo($head);
    $('<div class="disk-capacity"></div>').text(d.used != null && d.total != null ? kb(d.used) + ' / ' + kb(d.total) : '容量未知').appendTo($row);
    var $track = $('<div class="disk-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"></div>').attr('aria-label', '磁盘 ' + d.mount).appendTo($row);
    if (v == null) $track.attr('aria-valuetext', '暂无数据'); else $track.attr('aria-valuenow', v);
    $('<i></i>').css({ width: (v || 0) + '%', background: v == null ? '#d0d5dd' : window.probeGaugeColor(v, threshold) }).appendTo($track);
    $list.append($row);
  });
  if (!disks.length) $list.append('<div class="small text-muted">暂无磁盘数据</div>');
  return $list.prop('outerHTML');
};
window.probeRenderGauges = function (scope) {
  if (!window.Chart) return;
  var t = window.probeResT || [90, 90, 90];
  var gaugeIdx = { cpu: 0, mem: 1, disk: 2, swap: 1 };
  var $set = $(scope || document);
  $set = $set.filter('.resource-gauges').add($set.find('.resource-gauges'));
  $set.each(function () {
    var $g = $(this);
    var vals = [$g.attr('data-cpu'), $g.attr('data-mem'), $g.attr('data-disk')];
    $g.find('.gauge-wrap canvas').each(function (i) {
      // 优先按 data-gauge 取值（swap 环插在内存环之后，索引会错位），无标记时退回按顺序
      var name = $(this).attr('data-gauge');
      var raw = name ? $g.attr('data-' + name) : (vals[i] === undefined ? '' : vals[i]);
      var th = t[name ? gaugeIdx[name] : i] || 90;
      var v = raw === '' || raw === null || raw === undefined ? null : parseFloat(raw);
      var seg = v === null ? 0 : Math.min(100, Math.max(0, v));
      var color = v === null ? '#d0d5dd' : window.probeGaugeColor(v, th);
      if (this.__chart) { this.__chart.destroy(); }
      this.__chart = new Chart(this, {
        type: 'doughnut',
        data: { datasets: [{ data: [seg, 100 - seg], backgroundColor: [color, '#eef0f4'], borderWidth: 0 }] },
        options: { responsive: false, cutout: '66%', animation: { duration: 350 },
          plugins: { legend: { display: false }, tooltip: { enabled: false } } }
      });
    });
  });
};
window.probeUpdateGauges = function (container, res) {
  var $g = $(container);
  if (!$g.hasClass('resource-gauges')) { $g = $g.find('.resource-gauges').first(); }
  if (!$g.length || !res) return;
  var fmt = function (v) { return String(Math.round(v * 10) / 10); };
  var fbps = function (b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB/s';
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB/s';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB/s';
    return Math.round(b) + ' B/s';
  };
  var fkb = function (kb) {
    if (kb >= 1073741824) return (kb / 1073741824).toFixed(1) + ' TB';
    if (kb >= 1048576) return (kb / 1048576).toFixed(1) + ' GB';
    if (kb >= 1024) return (kb / 1024).toFixed(1) + ' MB';
    return kb + ' KB';
  };
  $g.attr('data-cpu', res.cpu == null ? '' : res.cpu);
  $g.attr('data-mem', res.mem == null ? '' : res.mem);
  $g.attr('data-disk', res.disk == null ? '' : res.disk);
  var $items = $g.find('.gauge-item');
  $items.eq(0).find('.gauge-val').text(res.cpu == null ? '-' : fmt(res.cpu) + '%');
  $items.eq(1).find('.gauge-val').not('.gauge-swap-val').text(res.mem == null ? '-' : fmt(res.mem) + '%');
  $items.eq(2).find('.gauge-val').text(res.disk == null ? '-' : fmt(res.disk) + '%');
  $g.attr('data-mount', res.disk_mount || '');
  $items.eq(2).find('.gauge-label').text('磁盘 ' + (res.disk_mount || '-'));
  $g.attr('data-used', res.disk_used == null ? '' : res.disk_used).attr('data-total', res.disk_total == null ? '' : res.disk_total);
  var $d = $items.eq(2).find('.gauge-disk');
  if (!$d.length) { $d = $('<div class="gauge-disk"></div>').appendTo(ensureInfo($items.eq(2))); }
  $d.text(res.disk_used != null && res.disk_total != null ? fkb(res.disk_used) + ' / ' + fkb(res.disk_total) : '');
  $items.eq(2).addClass('gauge-disk-group').children('.disk-list').remove();
  $items.eq(2).append(window.probeDiskListHtml(res));
  // 保证标签容器存在（骨架插入的场景）
  function ensureInfo($item) {
    var $info = $item.find('.gauge-info');
    if (!$info.length) {
      $info = $('<div class="gauge-info"></div>').append($item.children('.gauge-label')).appendTo($item);
    }
    return $info;
  }
  // CPU 副信息：负载历史（优先用传入的整体，否则滚动追加保留最近 3 个）
  var hist = null;
  if (res.load_hist != null) {
    hist = String(res.load_hist).split(',').filter(function (v) { return v !== ''; });
  } else if (res.load != null) {
    hist = String($g.attr('data-loadhist') || '').split(',').filter(function (v) { return v !== ''; });
    hist.push(String(Math.round(res.load * 100) / 100));
    if (hist.length > 3) { hist = hist.slice(-3); }
  }
  if (hist && hist.length) {
    $g.attr('data-loadhist', hist.join(','));
    var $cs = $items.eq(0).find('.gauge-sub');
    if (!$cs.length) { $cs = $('<div class="gauge-sub"></div>').appendTo(ensureInfo($items.eq(0))); }
    $cs.text('负载 ' + hist.join(' / '));
  }
  // 内存副信息：当前占用/总容量；有 Swap 时分两行（内存 + Swap）
  var hasSwap = res.swap_total != null && res.swap_total > 0 && res.swap_used != null;
  if (res.mem_used != null && res.mem_total != null) {
    var $info1 = ensureInfo($items.eq(1));
    var $ms = $info1.find('.gauge-sub').not('.gauge-swap').first();
    if (!$ms.length) {
      $ms = $('<div class="gauge-sub"></div>');
      var $lb = $info1.find('.gauge-label');
      if ($lb.length) { $ms.insertAfter($lb.first()); } else { $ms.prependTo($info1); }
    }
    $ms.text((hasSwap ? '内存 ' : '') + fkb(res.mem_used) + ' / ' + fkb(res.mem_total));
  }
  var $sw = $items.eq(1).find('.gauge-swap');
  if (hasSwap) {
    var $info2 = ensureInfo($items.eq(1));
    if (!$sw.length) { $sw = $('<div class="gauge-sub gauge-swap"></div>').appendTo($info2); }
    $sw.text('Swap ' + fkb(res.swap_used) + ' / ' + fkb(res.swap_total));
  } else {
    $sw.remove();
  }
  // Swap 扇形圈：有 Swap 时叠在内存环右下角（小号角标），无 Swap 时移除
  var $memItem = $items.eq(1);
  var swapPct = null;
  if (hasSwap) {
    swapPct = res.swap != null ? res.swap : Math.round(res.swap_used / res.swap_total * 1000) / 10;
  }
  var $swRing = $memItem.find('.gauge-swap-ring');
  if (hasSwap) {
    if (!$swRing.length) {
      $swRing = $('<div class="gauge-wrap gauge-swap-ring"><canvas width="36" height="36" data-gauge="swap"></canvas>'
        + '<span class="gauge-val gauge-swap-val">-</span></div>');
      $memItem.find('.gauge-wrap').first().append($swRing);
    }
    $swRing.find('.gauge-swap-val').text(swapPct == null ? '-' : fmt(swapPct) + '%');
    $g.attr('data-swap', swapPct == null ? '' : swapPct);
  } else {
    $swRing.remove();
    $g.attr('data-swap', '');
  }
  // 实时带宽：下行/上行速率（容器不存在时按结构补建）
  if (res.net_rx != null || res.net_tx != null) {
    var $net = $g.find('.gauge-net');
    if (!$net.length) {
      $net = $('<div class="gauge-item gauge-net"><div class="gauge-net-box">'
        + '<b class="net-down"><i class="mdi mdi-arrow-down-bold-circle-outline"></i><span class="net-down-val">-</span></b>'
        + '<b class="net-up"><i class="mdi mdi-arrow-up-bold-circle-outline"></i><span class="net-up-val">-</span></b>'
        + '</div><div class="gauge-info"><span class="gauge-label">实时带宽</span></div></div>').appendTo($g);
    }
    if (res.net_rx != null) { $net.find('.net-down-val').text(fbps(res.net_rx)); }
    if (res.net_tx != null) { $net.find('.net-up-val').text(fbps(res.net_tx)); }
  }
  window.probeRenderGauges($g);
};
</script>
<?php if (!empty($pageScript)) {
    echo $pageScript;
} ?>
</body>
</html>
