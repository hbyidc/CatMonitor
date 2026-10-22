<?php
/**
 * 开放 API 文档（后台查阅）
 */
require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = 'API 文档';
$active    = 'api_doc';

$openApi        = open_api_credentials($db);
$openApiEnabled = get_setting($db, 'open_api_enabled', '1') === '1';
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$base   = $scheme . '://' . $host . '/admin/api/open.php';
$aq     = 'api_id=' . $openApi['id'] . '&api_key=' . $openApi['key'];
// 生成带真实凭证的示例 URL（输出时用 e() 转义 &）
$ex = fn (string $qs = ''): string => $base . '?' . $aq . ($qs !== '' ? '&' . $qs : '');

include __DIR__ . '/templates/header.php';
?>
<style>
.pre-doc{background:#f6f8fa;border:1px solid #eaecef;border-radius:6px;padding:10px 12px;font-size:12px;line-height:1.6;max-height:360px;overflow:auto;white-space:pre;margin:0;color:#24292f;}
.pre-doc code{background:none;color:inherit;padding:0;font-size:inherit;white-space:pre;}
.accordion-button:not(.collapsed){background:#f3f7ff;}
.accordion-button code{color:#d63384;font-size:13px;}
.doc-tbl th{white-space:nowrap;}
</style>

<div class="row">
  <div class="col-lg-12">

    <!-- 概述 -->
    <div class="card">
      <header class="card-header"><div class="card-title"><i class="mdi mdi-api me-1"></i>开放 API 文档</div></header>
      <div class="card-body">
        <?php if (!$openApiEnabled): ?>
          <div class="alert alert-warning py-2 mb-3">开放 API 当前已<strong>停用</strong>，请先在「系统设置 → 开放 API」启用后再调用。</div>
        <?php endif; ?>
        <p class="mb-3">统一入口，凭 <code>api_id</code> + <code>api_key</code> 鉴权，通过 <code>action</code> 参数区分功能。除 <code>probe</code> / <code>probe_all</code> 外均为只读，且<strong>任何 action 都不会返回登录凭证或通知渠道密钥</strong>。</p>
        <table class="table table-sm doc-tbl mb-3">
          <tbody>
            <tr><th style="width:120px;">接口地址</th><td><code><?php echo e($base); ?></code></td></tr>
            <tr><th>API ID</th><td><code><?php echo e($openApi['id']); ?></code></td></tr>
            <tr><th>API Key</th><td><code class="text-break"><?php echo e($openApi['key']); ?></code></td></tr>
            <tr><th>鉴权方式</th><td class="small">① Query 传 <code>api_id</code>+<code>api_key</code>；② 表单 POST；③ 请求头 <code>X-Api-Id</code> / <code>X-Api-Key</code>（三选一）</td></tr>
            <tr><th>返回结构</th><td class="small">统一 <code>{"code":0,"data":{…}}</code>；<code>code=0</code> 成功，<code>code=1</code> 失败（原因见 <code>data.message</code>）；鉴权失败返回 HTTP <code>401</code>。</td></tr>
          </tbody>
        </table>
        <a href="settings.php?tab=api" class="btn btn-sm btn-outline-primary"><i class="mdi mdi-cog-outline me-1"></i>前往开放 API 设置</a>
      </div>
    </div>

    <!-- action 一览 -->
    <div class="card">
      <header class="card-header"><div class="card-title">接口动作一览</div></header>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th style="width:130px;">action</th><th>用途</th><th>关键参数</th><th style="width:96px;">类型</th></tr></thead>
            <tbody>
              <tr><td><code>overview</code></td><td>全量分类 + 节点卡片（默认）</td><td class="small"><code>include_hidden</code> <code>with_resource</code> <code>with_history</code></td><td><span class="badge bg-success">只读</span></td></tr>
              <tr><td><code>probe_all</code></td><td>探测所有启用节点</td><td class="small text-muted">—</td><td><span class="badge bg-danger">触发探测</span></td></tr>
              <tr><td><code>probe</code></td><td>探测指定节点</td><td class="small"><code>server_id</code></td><td><span class="badge bg-danger">触发探测</span></td></tr>
              <tr><td><code>trend</code></td><td>性能趋势（CPU/内存/磁盘/负载/带宽）</td><td class="small"><code>server_id</code> <code>range</code></td><td><span class="badge bg-success">只读</span></td></tr>
              <tr><td><code>quality</code></td><td>历史质量（延迟/丢包/可用率）</td><td class="small"><code>server_id</code> <code>range</code></td><td><span class="badge bg-success">只读</span></td></tr>
              <tr><td><code>alerts</code></td><td>告警记录查询</td><td class="small"><code>type</code> <code>server_id</code> <code>since</code> <code>limit</code> <code>page</code></td><td><span class="badge bg-success">只读</span></td></tr>
              <tr><td><code>alert_config</code></td><td>告警规则阈值 + 渠道开关</td><td class="small text-muted">—</td><td><span class="badge bg-success">只读</span></td></tr>
              <tr><td><code>token</code></td><td>POST id+key 换取一次性登录令牌（供 SSO 登录）</td><td class="small"><code>ttl</code></td><td><span class="badge bg-info text-dark">签发令牌</span></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 详解 -->
    <div class="card">
      <header class="card-header"><div class="card-title">接口详解</div></header>
      <div class="card-body">
        <div class="accordion" id="apiAccordion">

          <!-- overview -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#d-overview"><code>overview</code><span class="ms-2 small text-muted">全量分类 + 节点卡片</span><span class="badge bg-success ms-2">只读</span></button></h2>
            <div id="d-overview" class="accordion-collapse collapse show" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <table class="table table-sm doc-tbl mb-2"><thead><tr><th style="width:150px;">参数</th><th>说明</th><th style="width:80px;">默认</th></tr></thead><tbody>
                  <tr><td><code>include_hidden</code></td><td>1=含隐藏/停用节点、隐藏分类、空分类（完整后台视角）</td><td>0</td></tr>
                  <tr><td><code>with_resource</code></td><td>0=不返回资源占用（CPU/内存/磁盘/负载/带宽）</td><td>1</td></tr>
                  <tr><td><code>with_history</code></td><td>1=额外返回按小时可用率数组 <code>history</code></td><td>0</td></tr>
                </tbody></table>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('include_hidden=1')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "site": "Catmonitor", "total": 15, "online": 14, "offline": 1, "group_count": 4,
    "groups": [{
      "id": 2, "name": "重要", "front_hidden": false, "total": 5, "online": 4,
      "servers": [{
        "id": 13, "name": "180.188.24.167", "type": "ping", "type_label": "PING",
        "group_id": 2, "group_name": "重要", "address": "180.188.24.167", "port": 0,
        "status": 1, "online": true, "latency": 29.12, "packet_loss": 0,
        "message": "PING 正常，收到 4/4…", "uptime": 98.96, "avg_latency": 29.15,
        "resource": null,   // SSH/宝塔且 with_resource=1 时：{cpu,mem,disk,load,net_rx,net_tx,disk_mount,…}
        "history": null     // with_history=1 时：[["09-13 05:00",100], …]
      }]
    }]
  }
}</code></pre>
              </div>
            </div>
          </div>

          <!-- probe_all -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d-probeall"><code>probe_all</code><span class="ms-2 small text-muted">探测所有启用节点</span><span class="badge bg-danger ms-2">触发探测</span></button></h2>
            <div id="d-probeall" class="accordion-collapse collapse" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <p class="small text-muted mb-2">立即探测所有 <code>enabled=1</code> 节点并落库；带并发锁（同一时刻仅允许一个任务），节点多时耗时较长。无参数。</p>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('action=probe_all')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "action": "probe_all", "checked": 15, "online": 14, "offline": 1,
    "finished_at": "2026-09-13 17:20:00",
    "results": [
      { "id": 18, "name": "211.101.247.189", "type": "bt",  "status": 1, "latency": 1182.8, "message": "宝塔面板正常…" },
      { "id": 9,  "name": "123", "type": "udp", "status": 1, "latency": 75.2,  "message": "UDP 包已发送…" }
    ]
  }
}</code></pre>
              </div>
            </div>
          </div>

          <!-- probe -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d-probe"><code>probe</code><span class="ms-2 small text-muted">探测指定节点</span><span class="badge bg-danger ms-2">触发探测</span></button></h2>
            <div id="d-probe" class="accordion-collapse collapse" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <table class="table table-sm doc-tbl mb-2"><thead><tr><th style="width:150px;">参数</th><th>说明</th><th style="width:80px;">必填</th></tr></thead><tbody>
                  <tr><td><code>server_id</code></td><td>节点 ID</td><td>是</td></tr>
                </tbody></table>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('action=probe&server_id=11')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "action": "probe", "id": 11, "name": "111.170.172.102", "type": "ping",
    "address": "111.170.172.102", "port": 0,
    "status": 1, "latency": 12.51, "message": "PING 正常，收到 4/4…",
    "duration": 52, "checked_at": "2026-09-13 17:13:09",
    "loss": 0, "ssl_days": null, "resource": null, "health": "healthy"
  }
}</code></pre>
              </div>
            </div>
          </div>

          <!-- trend -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d-trend"><code>trend</code><span class="ms-2 small text-muted">性能趋势</span><span class="badge bg-success ms-2">只读</span></button></h2>
            <div id="d-trend" class="accordion-collapse collapse" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <p class="small text-muted mb-2">仅 <strong>SSH / 宝塔</strong>类型节点；其他类型返回 <code>code=1</code>。数据来自长期监控表，超 300 点自动降采样。</p>
                <table class="table table-sm doc-tbl mb-2"><thead><tr><th style="width:150px;">参数</th><th>说明</th><th style="width:80px;">默认</th></tr></thead><tbody>
                  <tr><td><code>server_id</code></td><td>节点 ID（仅 SSH/宝塔）</td><td>必填</td></tr>
                  <tr><td><code>range</code></td><td><code>1h</code>/<code>12h</code>/<code>24h</code>/<code>3d</code>/<code>7d</code>/<code>30d</code></td><td>24h</td></tr>
                  <tr><td><code>start</code> <code>end</code></td><td>自定义时间窗（Unix 秒，跨度≥10 分钟，优先于 range）</td><td>—</td></tr>
                </tbody></table>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('action=trend&server_id=4&range=24h')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "action": "trend", "node": { "id": 4, "name": "23.141.84.5", "type": "ssh" },
    "range": "24h", "retain": 30, "start": 1789204440, "end": 1789290840,
    "points": [
      [1789290840, 0, 21.1, 17, 0, 868, 426]  // [时间戳, CPU%, 内存%, 磁盘%, 负载, 下行B/s, 上行B/s]
    ],
    "current": { "status": 1, "cpu": 0, "mem": 21.1, "disk": 17, "load": 0, "net_rx": 868, "net_tx": 426, "disk_mount": "/" }
  }
}</code></pre>
              </div>
            </div>
          </div>

          <!-- quality -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d-quality"><code>quality</code><span class="ms-2 small text-muted">历史质量</span><span class="badge bg-success ms-2">只读</span></button></h2>
            <div id="d-quality" class="accordion-collapse collapse" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <table class="table table-sm doc-tbl mb-2"><thead><tr><th style="width:150px;">参数</th><th>说明</th><th style="width:80px;">默认</th></tr></thead><tbody>
                  <tr><td><code>server_id</code></td><td>节点 ID</td><td>必填</td></tr>
                  <tr><td><code>range</code></td><td><code>1h</code>/<code>6h</code>/<code>24h</code>/<code>7d</code></td><td>24h</td></tr>
                </tbody></table>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('action=quality&server_id=13&range=24h')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "action": "quality", "node": { "id": 13, "name": "180.188.24.167", "type": "ping" },
    "range": "24h",
    "series": { "labels": ["05:00","06:00",…], "latency": [29.1,…], "loss": [0,…] },
    "bars": [ { "t": "09-13 17:00:00", "ok": true, "lat": 29.09, "loss": 0 } ],   // 最近 50 次探测
    "stats": {
      "last": { "ok": true, "lat": 29.09, "loss": 0, "msg": "PING 正常…", "t": "2026-09-13 17:13:09" },
      "h12":  { "n": 360, "uptime": 99.17, "avg_latency": 29.15, "avg_loss": 0.28 }
    }
  }
}</code></pre>
              </div>
            </div>
          </div>

          <!-- alerts -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d-alerts"><code>alerts</code><span class="ms-2 small text-muted">告警记录查询</span><span class="badge bg-success ms-2">只读</span></button></h2>
            <div id="d-alerts" class="accordion-collapse collapse" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <table class="table table-sm doc-tbl mb-2"><thead><tr><th style="width:150px;">参数</th><th>说明</th><th style="width:80px;">默认</th></tr></thead><tbody>
                  <tr><td><code>type</code></td><td><code>offline</code>/<code>ssl</code>/<code>resource</code>/<code>test</code>，留空=全部</td><td>全部</td></tr>
                  <tr><td><code>server_id</code></td><td>仅指定节点</td><td>—</td></tr>
                  <tr><td><code>since</code></td><td>起始时间戳（增量拉取：只返回此时刻之后的告警）</td><td>—</td></tr>
                  <tr><td><code>limit</code></td><td>每页条数（最大 200）</td><td>50</td></tr>
                  <tr><td><code>page</code></td><td>页码</td><td>1</td></tr>
                </tbody></table>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('action=alerts&type=offline&limit=20')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "action": "alerts", "total": 270, "page": 1, "limit": 20, "pages": 14,
    "filter": { "type": "offline", "server_id": null, "since": null },
    "list": [{
      "id": 275, "server_id": 9, "server_name": "123", "server_address": "frp-one.com",
      "alert_type": "offline", "alert_type_label": "离线预警",
      "message": "节点「123」（UDP frp-one.com）已连续 6 次检测失败…",
      "channels": "无可用通知渠道", "sent_at": 1789231503, "sent_at_text": "2026-09-13 00:45:03"
    }]
  }
}</code></pre>
              </div>
            </div>
          </div>

          <!-- alert_config -->
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d-alertconfig"><code>alert_config</code><span class="ms-2 small text-muted">告警规则 + 渠道开关</span><span class="badge bg-success ms-2">只读</span></button></h2>
            <div id="d-alertconfig" class="accordion-collapse collapse" data-bs-parent="#apiAccordion">
              <div class="accordion-body">
                <p class="small text-muted mb-2">返回当前告警规则阈值与通知渠道开关。短信接口为单选，<code>smsbao</code> / <code>tencent_sms</code> / <code>aliyun_sms</code> 最多一个为 true；关闭短信时均为 false。<strong>只含布尔开关与数值阈值，绝不返回任何密钥/凭证</strong>。无参数。</p>
                <div class="small text-muted mb-1">请求示例</div>
                <pre class="pre-doc mb-2"><code>curl "<?php echo e($ex('action=alert_config')); ?>"</code></pre>
                <div class="small text-muted mb-1">响应示例</div>
                <pre class="pre-doc mb-0"><code>{
  "code": 0,
  "data": {
    "action": "alert_config",
    "offline":  { "enabled": true, "threshold": 3 },
    "ssl":      { "enabled": true, "days": 30 },
    "resource": { "enabled": true, "cpu_threshold": 90, "mem_threshold": 90, "disk_threshold": 90 },
    "throttle": { "days": 1, "count": 3 },
    "time_window": { "start": "00:00", "end": "23:59" },
    "channels": { "smtp": false, "webhook": false, "smsbao": false, "tencent_sms": false, "aliyun_sms": false, "telegram": false }
  }
}</code></pre>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- 临时令牌登录（SSO） -->
    <div class="card">
      <header class="card-header"><div class="card-title"><i class="mdi mdi-login-variant me-1"></i>临时令牌登录（SSO）<span class="badge bg-warning text-dark ms-2">独立端点</span></div></header>
      <div class="card-body">
        <p class="mb-3">独立端点 <code>sso.php</code>（非 <code>open.php</code> 的 action）：免密登录后台，身份为<strong>主管理员</strong>，成功后 302 跳转后台。<strong>仅支持一次性令牌 token</strong>——避免 <code>api_key</code> 出现在地址栏 / Referer / 访问日志。</p>

        <h6 class="text-primary mb-2"><i class="mdi mdi-shield-key-outline me-1"></i>一次性令牌 token 登录</h6>
        <p class="small text-muted mb-2">两步：① <code>POST</code> <code>open.php</code>（<code>action=token</code>，密钥只在请求体）换取短期令牌；② 访问 <code>sso.php?token=…</code> 登录。令牌默认 120 秒有效、<strong>仅可用一次</strong>、库中只存哈希。</p>
        <div class="small text-muted mb-1">① 换取令牌</div>
        <pre class="pre-doc mb-2"><code>curl -X POST "<?php echo e($base); ?>" \
  -d "api_id=<?php echo e($openApi['id']); ?>" \
  -d "api_key=<?php echo e($openApi['key']); ?>" \
  -d "action=token"

# 响应
{
  "code": 0,
  "data": {
    "action": "token",
    "token": "8f3c1a…（64 位随机）",
    "expires_in": 120,
    "login_url": "<?php echo e($scheme . '://' . $host . '/admin/api/sso.php?token=8f3c1a…'); ?>"
  }
}</code></pre>
        <div class="small text-muted mb-1">② 用令牌登录（浏览器打开 login_url，或程序跟随 302）</div>
        <pre class="pre-doc mb-3"><code><?php echo e($scheme . '://' . $host . '/admin/api/sso.php?token=8f3c1a…'); ?></code></pre>

        <table class="table table-sm doc-tbl mb-3">
          <tbody>
            <tr><th style="width:120px;">端点地址</th><td><code><?php echo e($scheme . '://' . $host . '/admin/api/sso.php'); ?></code></td></tr>
            <tr><th>凭证参数</th><td class="small">仅 <code>token</code>：先经 <code>open.php</code> 的 <code>action=token</code>（密钥只在请求体）换取的一次性令牌。需「开放 API」与「临时令牌登录」两开关均开启。</td></tr>
            <tr><th>可选参数</th><td class="small"><code>redirect</code>：登录后跳转的后台页面文件名（仅允许 <code>[A-Za-z0-9_-]+.php</code>，如 <code>servers.php</code>；默认 <code>index.php</code>）。</td></tr>
            <tr><th>成功行为</th><td class="small">建立登录会话 → <code>302 Location: ../index.php</code>（或 <code>redirect</code> 指定页），写审计日志 <code>data/sso_login.log</code>（记 <code>via=token</code>）。</td></tr>
            <tr><th>失败行为</th><td class="small">跳 <code>../login.php?sso_error=…</code>：<code>disabled</code>(功能停用) / <code>invalid_token</code>(令牌无效、过期或已用)。</td></tr>
          </tbody>
        </table>

        <div class="alert alert-warning py-2 small mb-0">
          <i class="mdi mdi-alert-outline me-1"></i><strong>安全提示</strong>：换取令牌的 <code>api_key</code> 与令牌本身均等同后台登录权限，请长期保密；令牌虽短期一次性也不应公开分发。不用时可在「系统设置 → 开放 API」关闭临时令牌登录开关或重新生成凭证。
        </div>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/templates/footer.php';
