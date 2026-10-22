<?php
/**
 * 服务器管理：监控节点增删改查
 */

require __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = '服务器管理';
$active    = 'servers';
$types     = probe_types();

/* ---------- POST 操作处理 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $type     = (string)($_POST['type'] ?? 'ping');
        $address  = trim((string)($_POST['address'] ?? ''));
        $port     = (int)($_POST['port'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $interval = max(10, (int)($_POST['check_interval'] ?? 60));
        $timeout  = max(1, min(60, (int)($_POST['timeout'] ?? 5)));
        $groupId  = max(0, (int)($_POST['group_id'] ?? 0));
        $enabled  = isset($_POST['enabled']) ? 1 : 0;
        // 前台隐藏：隐藏后不在前台状态页展示
        $frontHidden = isset($_POST['front_hidden']) ? 1 : 0;
        $remark   = trim((string)($_POST['remark'] ?? ''));
        // 资源采集开关：SSH / 宝塔面板节点均按表单开关（列表内也可快速切换）
        $sshMonitor = in_array($type, ['ssh', 'ssh_ping', 'bt'], true) && isset($_POST['ssh_monitor']) ? 1 : 0;
        // 长期监控保留天数（仅资源监控开启时有效）
        $retainDays = $sshMonitor ? (int)($_POST['metrics_retain_days'] ?? 0) : 0;
        if (!in_array($retainDays, [0, 1, 3, 7, 30], true)) {
            $retainDays = 0;
        }

        $err = '';
        if ($name === '' || mb_strlen($name) > 100) {
            $err = '请填写节点名称（100 字以内）';
        } elseif (!isset($types[$type])) {
            $err = '探测方式不合法';
        } elseif ($type === 'http' || $type === 'bt') {
            if (!is_valid_url($address)) {
                $err = $type === 'bt'
                    ? '宝塔面板地址请填写完整 URL，如 http://1.2.3.4:8888'
                    : '网站探测请填写完整 URL，如 https://www.example.com';
            }
        } else {
            if (!is_valid_host($address)) {
                $err = '主机名/IP 不合法';
            } elseif (!in_array($type, ['ping'], true) && ($port < 1 || $port > 65535)) {
                $err = '请填写有效的端口号（1-65535）';
            }
        }
        // 分类必须存在
        if (!$err && $groupId > 0 && !isset(GroupService::map($db)[$groupId])) {
            $err = '所选分类不存在';
        }
        // SSH 资源监控需要账号密码（编辑时密码留空视为沿用已有密码）；宝塔类型走下方密钥校验
        if (!$err && in_array($type, ['ssh', 'ssh_ping'], true) && $sshMonitor) {
            $hasPwd = $password !== '';
            if (!$hasPwd && $id > 0) {
                $stmt = $db->prepare('SELECT password FROM servers WHERE id = ?');
                $stmt->execute([$id]);
                $hasPwd = ($stmt->fetch()['password'] ?? '') !== '';
            }
            if ($username === '' || !$hasPwd) {
                $err = '开启资源监控需要填写 SSH 用户名和密码';
            } elseif (!extension_loaded('ssh2')) {
                $err = '资源监控需要安装 ssh2 扩展（见系统设置 - 运行环境）';
            }
        }

        // 宝塔面板必须填写接口密钥（编辑时留空视为沿用已保存密钥）
        if (!$err && $type === 'bt') {
            $hasKey = $password !== '';
            if (!$hasKey && $id > 0) {
                $stmt = $db->prepare('SELECT password FROM servers WHERE id = ?');
                $stmt->execute([$id]);
                $hasKey = ($stmt->fetch()['password'] ?? '') !== '';
            }
            if (!$hasKey) {
                $err = '请填写宝塔面板接口密钥（面板设置 → API 接口）';
            }
        }

        if (!$err) {
            $fields = [
                ':name' => $name, ':type' => $type, ':address' => $address, ':port' => in_array($type, ['http', 'bt'], true) ? 0 : $port,
                ':username' => $username, ':password' => $password, ':interval' => $interval,
                ':timeout' => $timeout, ':enabled' => $enabled, ':front_hidden' => $frontHidden,
                ':remark' => $remark, ':group_id' => $groupId,
                ':ssh_monitor' => $sshMonitor, ':metrics_retain' => $retainDays,
            ];
            if ($id > 0) {
                // 编辑：密码留空表示不修改
                if ($password === '') {
                    $sql = 'UPDATE servers SET name=:name, type=:type, address=:address, port=:port, username=:username,
                            check_interval=:interval, timeout=:timeout, enabled=:enabled, front_hidden=:front_hidden, remark=:remark, group_id=:group_id,
                            ssh_monitor=:ssh_monitor, metrics_retain_days=:metrics_retain WHERE id=:id';
                    unset($fields[':password']);
                    $fields[':id'] = $id;
                } else {
                    $sql = 'UPDATE servers SET name=:name, type=:type, address=:address, port=:port, username=:username,
                            password=:password, check_interval=:interval, timeout=:timeout, enabled=:enabled, front_hidden=:front_hidden, remark=:remark, group_id=:group_id,
                            ssh_monitor=:ssh_monitor, metrics_retain_days=:metrics_retain WHERE id=:id';
                    $fields[':id'] = $id;
                }
                $db->prepare($sql)->execute($fields);
                flash_set('success', '节点已更新');
            } else {
                $sql = 'INSERT INTO servers (name, type, address, port, username, password, check_interval, timeout, enabled, front_hidden, group_id, ssh_monitor, metrics_retain_days, remark, created_at)
                        VALUES (:name, :type, :address, :port, :username, :password, :interval, :timeout, :enabled, :front_hidden, :group_id, :ssh_monitor, :metrics_retain, :remark, ' . time() . ')';
                $db->prepare($sql)->execute($fields);
                flash_set('success', '节点已添加');
            }
        } else {
            flash_set('danger', $err);
        }
        header('Location: servers.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM logs WHERE server_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM server_metrics WHERE server_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM servers WHERE id = ?')->execute([$id]);
        flash_set('success', '节点已删除');
        header('Location: servers.php');
        exit;
    }

    /* ---------- 列表内快速开关：监控启用 / 资源采集 / 前台显示 ----------
     * AJAX 请求（列表开关）直接返回 JSON 即时生效；普通表单提交仍走跳转 */
    if (in_array($action, ['toggle', 'toggle_front', 'toggle_monitor'], true)) {
        $ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $back = isset($_POST['group']) && $_POST['group'] !== '' ? '?group=' . (int)$_POST['group'] : '';
        $reply = static function (bool $ok, string $msg, array $extra = []) use ($ajax, $back) {
            if ($ajax) {
                json_out(array_merge(['ok' => $ok, 'message' => $msg], $extra), $ok ? 0 : 1);
            }
            flash_set($ok ? 'success' : 'danger', $msg);
            header('Location: servers.php' . $back);
            exit;
        };

        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $srv  = $stmt->fetch();
        if (!$srv) {
            $reply(false, '节点不存在');
        }

        if ($action === 'toggle_monitor') {
            // 资源采集开关：仅 SSH / SSH+PING / 宝塔面板节点支持
            if (!in_array($srv['type'], ['ssh', 'ssh_ping', 'bt'], true)) {
                $reply(false, '仅 SSH / 宝塔面板节点支持资源采集');
            }
            $on = (int)($srv['ssh_monitor'] ?? 0) === 1 ? 0 : 1;
            if ($on === 1 && in_array($srv['type'], ['ssh', 'ssh_ping'], true)) {
                if (trim((string)$srv['username']) === '' || (string)$srv['password'] === '') {
                    $reply(false, '开启资源采集需先在「编辑」中填写 SSH 用户名和密码');
                }
                if (!extension_loaded('ssh2')) {
                    $reply(false, '资源采集需要安装 ssh2 扩展（见系统设置 → 运行环境）');
                }
            }
            if ($on === 1 && $srv['type'] === 'bt' && (string)$srv['password'] === '') {
                $reply(false, '开启资源采集需先在「编辑」中填写宝塔面板接口密钥');
            }
            // 长期监控保留天数不随开关清零，重新开启后延续原配置
            $db->prepare('UPDATE servers SET ssh_monitor = ? WHERE id = ?')->execute([$on, $id]);
            $reply(true, $on === 1 ? '已开启资源采集，下次探测生效' : '已关闭资源采集', ['ssh_monitor' => $on]);
        }

        if ($action === 'toggle_front') {
            $val = (int)($srv['front_hidden'] ?? 0) === 1 ? 0 : 1;
            $db->prepare('UPDATE servers SET front_hidden = ? WHERE id = ?')->execute([$val, $id]);
            $reply(true, $val === 1 ? '已在前台隐藏该节点' : '已在前台显示该节点', ['front_hidden' => $val]);
        }

        // toggle：监控启用/停用（停用后不再定时探测，也不触发预警）
        $val = (int)$srv['enabled'] === 1 ? 0 : 1;
        $db->prepare('UPDATE servers SET enabled = ? WHERE id = ?')->execute([$val, $id]);
        $reply(true, $val === 1 ? '已开启监控' : '已停用监控', ['enabled' => $val]);
    }

    if ($action === 'move') {
        // 组内上移/下移：与前台展示口径一致（sort_order ASC, id ASC），移动后重写整组序号
        $id   = (int)($_POST['id'] ?? 0);
        $dir  = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $back = isset($_POST['group']) && $_POST['group'] !== '' ? '?group=' . (int)$_POST['group'] : '';
        $stmt = $db->prepare('SELECT group_id FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $gid = $row ? (int)$row['group_id'] : null;
        if ($gid !== null) {
            $list = $gid === 0
                ? $db->query('SELECT id FROM servers WHERE group_id = 0 OR group_id IS NULL ORDER BY sort_order ASC, id ASC')->fetchAll()
                : $db->prepare('SELECT id FROM servers WHERE group_id = ? ORDER BY sort_order ASC, id ASC');
            if ($gid !== 0) {
                $list->execute([$gid]);
                $list = $list->fetchAll();
            }
            $ids = array_map(fn($r) => (int)$r['id'], $list);
            $idx = array_search($id, $ids, true);
            $ni  = $idx === false ? false : $idx + $dir;
            if ($ni !== false && $ni >= 0 && $ni < count($ids)) {
                [$ids[$idx], $ids[$ni]] = [$ids[$ni], $ids[$idx]];
                $up = $db->prepare('UPDATE servers SET sort_order = ? WHERE id = ?');
                foreach ($ids as $i => $sid) {
                    $up->execute([$i + 1, $sid]);
                }
            }
        }
        header('Location: servers.php' . $back);
        exit;
    }
}

/* ---------- 列表（排序口径与前台一致：分类排序 → 组内 sort_order → id） ---------- */
$groups   = GroupService::all($db);
$groupMap = GroupService::map($db);
$groupFilter = (int)($_GET['group'] ?? 0);

// 资源占用展示用的阈值
$resThresholds = [
    (int)get_setting($db, 'alert_cpu_threshold', '90'),
    (int)get_setting($db, 'alert_mem_threshold', '90'),
    (int)get_setting($db, 'alert_disk_threshold', '90'),
];

$allServers = $db->query('SELECT * FROM servers ORDER BY id ASC')->fetchAll();
$groupOrder = [];
foreach ($groups as $i => $g) {
    $groupOrder[(int)$g['id']] = $i + 1;
}
usort($allServers, function ($a, $b) use ($groupOrder) {
    $ga = $groupOrder[(int)($a['group_id'] ?? 0)] ?? 0;
    $gb = $groupOrder[(int)($b['group_id'] ?? 0)] ?? 0;
    if ($ga !== $gb) {
        return $ga <=> $gb;
    }
    $sa = (int)($a['sort_order'] ?? 0);
    $sb = (int)($b['sort_order'] ?? 0);

    return $sa !== $sb ? $sa <=> $sb : ((int)$a['id'] <=> (int)$b['id']);
});

// 各分类节点数（Tab 角标）
$groupCounts = ['-1' => 0];
foreach ($allServers as $s) {
    $gid = (int)($s['group_id'] ?? 0);
    if ($gid === 0) {
        $groupCounts['-1']++;
    } else {
        $groupCounts[$gid] = ($groupCounts[$gid] ?? 0) + 1;
    }
}

if ($groupFilter > 0) {
    $servers = array_values(array_filter($allServers, fn($s) => (int)($s['group_id'] ?? 0) === $groupFilter));
} elseif ($groupFilter === -1) {
    $servers = array_values(array_filter($allServers, fn($s) => (int)($s['group_id'] ?? 0) === 0));
} else {
    $servers = $allServers;
}

// 当前视图内每个节点的组内位置（控制上移/下移按钮可用性）
$canUp = $canDown = [];
$groupRows = [];
foreach ($servers as $s) {
    $groupRows[(int)($s['group_id'] ?? 0)][] = (int)$s['id'];
}
foreach ($groupRows as $ids) {
    foreach ($ids as $i => $sid) {
        $canUp[$sid]   = $i > 0;
        $canDown[$sid] = $i < count($ids) - 1;
    }
}

include __DIR__ . '/templates/header.php';
?>

<div class="row">
  <div class="col-lg-12">
    <div class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <div class="card-title"><i class="mdi mdi-server-network me-1"></i> 服务器管理</div>
        <div class="d-flex gap-2 align-items-center">
          <a href="groups.php" class="btn btn-light btn-sm"><i class="mdi mdi-tag-multiple-outline"></i> 分类管理</a>
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#serverModal"
                  data-id="0"><i class="mdi mdi-plus"></i> 添加节点</button>
        </div>
      </header>
      <!-- 前台同款分类 Tab -->
      <nav class="srv-tabs">
        <a class="srv-tab <?php echo $groupFilter === 0 ? 'active' : ''; ?>" href="servers.php?group=0">
          全部 <span class="tcnt"><?php echo count($allServers); ?></span>
        </a>
        <a class="srv-tab <?php echo $groupFilter === -1 ? 'active' : ''; ?>" href="servers.php?group=-1">
          未分类 <span class="tcnt"><?php echo (int)($groupCounts['-1'] ?? 0); ?></span>
        </a>
        <?php foreach ($groups as $g): ?>
          <a class="srv-tab <?php echo $groupFilter === (int)$g['id'] ? 'active' : ''; ?>" href="servers.php?group=<?php echo (int)$g['id']; ?>">
            <?php echo e($g['name']); ?> <span class="tcnt"><?php echo (int)($groupCounts[$g['id']] ?? 0); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>#</th>
                <th>名称</th>
                <th>分类</th>
                <th>方式</th>
                <th>目标地址</th>
                <th>间隔/超时</th>
                <th>状态</th>
                <th>监控</th>
                <th>资源采集</th>
                <th>前台</th>
                <th>最近检测</th>
                <th>排序</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$servers): ?>
                <tr><td colspan="13" class="text-center text-muted py-4">暂无监控节点</td></tr>
              <?php endif; ?>
              <?php foreach ($servers as $s): ?>
                <tr data-sid="<?php echo (int)$s['id']; ?>">
                  <td><?php echo (int)$s['id']; ?></td>
                  <td><strong><?php echo e($s['name']); ?></strong>
                    <?php if ($s['remark'] !== ''): ?><div class="text-muted small"><?php echo e($s['remark']); ?></div><?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int)$s['group_id'] > 0 && isset($groupMap[(int)$s['group_id']])): ?>
                      <span class="badge badge-soft-primary"><?php echo e($groupMap[(int)$s['group_id']]); ?></span>
                    <?php else: ?>
                      <span class="badge badge-soft-secondary">未分类</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge badge-soft-info"><?php echo e($types[$s['type']]['label'] ?? strtoupper($s['type'])); ?></span></td>
                  <td>
                    <?php echo e($s['address']); ?><?php echo (int)$s['port'] > 0 ? ':' . (int)$s['port'] : ''; ?>
                    <?php if (in_array($s['type'], ['ssh', 'ssh_ping'], true) && $s['username'] !== ''): ?>
                      <div class="text-muted small">用户：<?php echo e($s['username']); ?></div>
                    <?php endif; ?>
                    <div class="res-slot"><?php echo resource_gauges_html($s, ...$resThresholds, diskDataLimit: 2); ?></div>
                  </td>
                  <td><?php echo (int)$s['check_interval']; ?>s / <?php echo (int)$s['timeout']; ?>s</td>
                  <td class="td-status"><?php echo status_badge($s['last_status']); ?></td>
                  <td>
                    <div class="form-check form-switch switch-cell">
                      <input class="form-check-input tk-enabled" type="checkbox" role="switch" data-id="<?php echo (int)$s['id']; ?>"
                             <?php echo (int)$s['enabled'] === 1 ? 'checked' : ''; ?> title="开启/关闭监控（关闭后不再定时探测与预警）">
                    </div>
                  </td>
                  <td>
                    <?php if (in_array($s['type'], ['ssh', 'ssh_ping', 'bt'], true)): ?>
                      <div class="form-check form-switch switch-cell">
                        <input class="form-check-input tk-monitor" type="checkbox" role="switch" data-id="<?php echo (int)$s['id']; ?>"
                               <?php echo (int)($s['ssh_monitor'] ?? 0) === 1 ? 'checked' : ''; ?> title="开启/关闭资源采集（CPU / 内存 / 磁盘 / 负载 / 实时带宽）">
                      </div>
                    <?php else: ?>
                      <span class="text-muted small" title="该探测方式不支持资源采集">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="form-check form-switch switch-cell">
                      <input class="form-check-input tk-front" type="checkbox" role="switch" data-id="<?php echo (int)$s['id']; ?>"
                             <?php echo (int)($s['front_hidden'] ?? 0) === 1 ? '' : 'checked'; ?> title="前台状态页显示/隐藏">
                    </div>
                  </td>
                  <td class="td-time small text-muted"><?php echo time_text($s['last_check_at']); ?></td>
                  <td class="text-nowrap">
                    <form method="post" class="d-inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="move">
                      <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                      <input type="hidden" name="group" value="<?php echo $groupFilter; ?>">
                      <button type="submit" name="dir" value="up" class="btn btn-outline-secondary btn-sm py-0 px-1" title="上移（与前台展示顺序一致）" <?php echo empty($canUp[(int)$s['id']]) ? 'disabled' : ''; ?>><i class="mdi mdi-chevron-up"></i></button>
                      <button type="submit" name="dir" value="down" class="btn btn-outline-secondary btn-sm py-0 px-1" title="下移（与前台展示顺序一致）" <?php echo empty($canDown[(int)$s['id']]) ? 'disabled' : ''; ?>><i class="mdi mdi-chevron-down"></i></button>
                    </form>
                  </td>
                  <td class="text-nowrap">
                    <a class="btn btn-outline-secondary btn-sm py-0 px-1" href="network.php?id=<?php echo (int)$s['id']; ?>" title="网络质量详情（延迟/丢包/可用率）"><i class="mdi mdi-pulse"></i></a>
                    <?php if ((int)($s['metrics_retain_days'] ?? 0) > 0): ?>
                      <a class="btn btn-outline-secondary btn-sm py-0 px-1" href="metrics.php?server_id=<?php echo (int)$s['id']; ?>" title="资源占用趋势（已开启长期监控）"><i class="mdi mdi-chart-areaspline"></i></a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-info btn-sm btn-quick" title="快速采集：立即执行一次探测并弹窗展示结果"
                      data-id="<?php echo (int)$s['id']; ?>" data-name="<?php echo e($s['name']); ?>"><i class="mdi mdi-flash"></i> 探测</button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-edit" data-bs-toggle="modal" data-bs-target="#serverModal"
                      onclick="window.__editBtn=this"
                      data-id="<?php echo (int)$s['id']; ?>"
                      data-name="<?php echo e($s['name']); ?>"
                      data-type="<?php echo e($s['type']); ?>"
                      data-address="<?php echo e($s['address']); ?>"
                      data-port="<?php echo (int)$s['port']; ?>"
                      data-username="<?php echo e($s['username']); ?>"
                      data-has-password="<?php echo $s['password'] !== '' ? '1' : '0'; ?>"
                      data-interval="<?php echo (int)$s['check_interval']; ?>"
                      data-timeout="<?php echo (int)$s['timeout']; ?>"
                      data-enabled="<?php echo (int)$s['enabled']; ?>"
                      data-group="<?php echo (int)($s['group_id'] ?? 0); ?>"
                      data-ssh-monitor="<?php echo (int)($s['ssh_monitor'] ?? 0); ?>"
                      data-front-hidden="<?php echo (int)($s['front_hidden'] ?? 0); ?>"
                      data-metrics-retain="<?php echo (int)($s['metrics_retain_days'] ?? 0); ?>"
                      data-remark="<?php echo e($s['remark']); ?>">编辑</button>
                    <form method="post" class="d-inline form-delete">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 添加/编辑模态框 -->
<div class="modal fade" id="serverModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <?php echo csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title" id="serverModalTitle">添加节点</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="f-id" value="0">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">节点名称 <span class="text-danger">*</span></label>
            <input type="text" name="name" id="f-name" class="form-control" required maxlength="100" placeholder="如：官网 Web 服务">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">探测方式 <span class="text-danger">*</span></label>
            <select name="type" id="f-type" class="form-select">
              <?php foreach ($types as $k => $t): ?>
                <option value="<?php echo $k; ?>"><?php echo $t['label']; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row">
          <div class="col-md-8 mb-3">
            <label class="form-label" id="label-address">主机/IP <span class="text-danger">*</span></label>
            <input type="text" name="address" id="f-address" class="form-control" required placeholder="example.com 或 https://www.example.com">
          </div>
          <div class="col-md-4 mb-3" id="port-group">
            <label class="form-label">端口</label>
            <input type="number" name="port" id="f-port" class="form-control" min="1" max="65535" placeholder="如 80">
          </div>
        </div>
        <div class="row d-none" id="ssh-auth-group">
          <div class="col-md-6 mb-3" id="ssh-user-col">
            <label class="form-label">SSH 用户名（可选，填写则做登录验证）</label>
            <input type="text" name="username" id="f-username" class="form-control" autocomplete="off">
          </div>
          <div class="col-md-6 mb-3" id="ssh-pwd-col">
            <label class="form-label" id="label-password">SSH 密码（可选）</label>
            <input type="password" name="password" id="f-password" class="form-control" autocomplete="new-password">
          </div>
          <div class="col-12 mb-3" id="ssh-monitor-box">
            <div class="alert alert-light border py-2 mb-0">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ssh_monitor" id="f-ssh-monitor">
                <label class="form-check-label" for="f-ssh-monitor" id="ssh-monitor-label">同时监控服务器资源（CPU / 内存 / 各数据盘占用）</label>
              </div>
              <div class="form-text mb-0" id="ssh-monitor-tip">需填写账号密码；每次检测自动登录执行命令采集，超过阈值触发预警（阈值在 <a href="settings.php?tab=alert" target="_blank">系统设置</a> 中配置）</div>
            </div>
          </div>
          <div class="col-12 mb-3 d-none" id="bt-tip">
            <div class="alert alert-info py-2 mb-0">
              <i class="mdi mdi-information-outline me-1"></i>需在宝塔面板「设置 → API 接口」开启接口、获取接口密钥，并将本机服务器 IP 加入白名单；每次探测自动采集 CPU / 内存 / 磁盘 / 负载 / 实时带宽
            </div>
          </div>
          <div class="col-12 mb-3" id="retain-group">
            <label class="form-label">长期监控（保留历史数据，可查看性能趋势波浪图）</label>
            <select name="metrics_retain_days" id="f-metrics-retain" class="form-select">
              <option value="0">不启用</option>
              <option value="1">保留最近 1 天</option>
              <option value="3">保留最近 3 天</option>
              <option value="7">保留最近 7 天</option>
              <option value="30">保留最近 30 天</option>
            </select>
            <div class="form-text mb-0">每次检测自动记录 CPU / 内存 / 磁盘 / 负载数据，过期数据自动清理</div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-4 mb-3">
            <label class="form-label">检测间隔（秒）</label>
            <input type="number" name="check_interval" id="f-interval" class="form-control" min="10" value="60">
          </div>
          <div class="col-md-4 mb-3">
            <label class="form-label">超时时间（秒）</label>
            <input type="number" name="timeout" id="f-timeout" class="form-control" min="1" max="60" value="5">
          </div>
          <div class="col-md-4 mb-3 d-flex align-items-end">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="enabled" id="f-enabled" checked>
              <label class="form-check-label" for="f-enabled">启用监控</label>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3 d-flex align-items-center">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="front_hidden" id="f-front-hidden">
              <label class="form-check-label" for="f-front-hidden">前台隐藏（不在前台状态页展示）</label>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">所属分类</label>
            <select name="group_id" id="f-group" class="form-select">
              <option value="0">未分类</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?php echo (int)$g['id']; ?>"><?php echo e($g['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">可在 <a href="groups.php">节点分类</a> 页添加新分类</div>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">备注</label>
            <input type="text" name="remark" id="f-remark" class="form-control" maxlength="255">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
      </div>
    </form>
  </div>
</div>

<!-- 快速采集结果弹窗 -->
<div class="modal fade" id="quickModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="mdi mdi-flash text-info me-1"></i>快速采集<span class="text-muted fw-normal ms-2" id="qk-name"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="qk-body"></div>
      <div class="modal-footer">
        <span class="text-muted small me-auto" id="qk-foot-time"></span>
        <button type="button" class="btn btn-outline-primary btn-sm" id="qk-again"><i class="mdi mdi-refresh"></i> 重新采集</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<?php
$pageScript = '<script src="../js/chart.min.js"></script>'
    . '<script>window.probeResT = ' . json_encode($resThresholds) . ';window.probeDiskLimit = 2;</script>'
    . <<<'JS'
<script>
$(function () {
  window.probeRenderGauges(document);
  var defaultPorts = { tcp: 80, udp: 53, ssh: 22, ssh_ping: 22 };

  function refreshFormType() {
    var type = $('#f-type').val();
    var hasPwd = !!$('#f-password').data('hasPwd');
    if (type === 'http' || type === 'bt') {
      $('#port-group').hide();
    } else {
      $('#port-group').show();
    }
    if (type === 'http') {
      $('#label-address').html('网站 URL <span class="text-danger">*</span>');
      $('#f-address').attr('placeholder', 'https://www.example.com（HTTPS 自动检测 SSL 有效期）');
    } else if (type === 'bt') {
      $('#label-address').html('宝塔面板地址 <span class="text-danger">*</span>');
      $('#f-address').attr('placeholder', 'http://1.2.3.4:8888（含端口）');
    } else {
      $('#label-address').html('主机/IP <span class="text-danger">*</span>');
      $('#f-address').attr('placeholder', 'example.com 或 192.168.1.1');
    }
    if (type === 'ssh' || type === 'ssh_ping') {
      $('#ssh-auth-group').removeClass('d-none');
      $('#ssh-user-col').removeClass('d-none');
      $('#ssh-monitor-box').removeClass('d-none');
      $('#ssh-monitor-label').text('同时采集服务器资源（CPU / 内存 / 磁盘 / 负载 / 实时带宽）');
      $('#ssh-monitor-tip').html(type === 'ssh_ping'
        ? '需填写账号密码；每次检测同时执行 SSH 探测与 PING 探测，任一可达即判定在线，展示两种延迟；资源采集与 SSH 节点一致'
        : '需填写账号密码；每次检测自动登录执行命令采集，超过阈值触发预警（阈值在 <a href="settings.php?tab=alert" target="_blank">系统设置</a> 中配置）');
      $('#bt-tip').addClass('d-none');
      $('#label-password').html('SSH 密码（可选）');
      $('#f-password').attr('placeholder', hasPwd ? '留空表示不修改密码' : '');
    } else if (type === 'bt') {
      // 宝塔面板：复用密码字段存接口密钥，隐藏 SSH 专属项；资源采集走面板 API，同样可开关
      $('#ssh-auth-group').removeClass('d-none');
      $('#ssh-user-col').addClass('d-none');
      $('#ssh-monitor-box').removeClass('d-none');
      $('#ssh-monitor-label').text('同时采集服务器资源（CPU / 内存 / 磁盘 / 负载 / 实时带宽）');
      $('#ssh-monitor-tip').html('通过宝塔面板 API 采集，无需 SSH 账号；关闭后仅探测面板存活，不再请求资源接口');
      $('#bt-tip').removeClass('d-none');
      $('#label-password').html('接口密钥 <span class="text-danger">*</span>');
      $('#f-password').attr('placeholder', hasPwd ? '留空表示沿用已保存密钥' : '宝塔面板 API 接口密钥');
    } else {
      $('#ssh-auth-group').addClass('d-none');
    }
    if (defaultPorts[type] && !$('#f-port').data('keep')) {
      $('#f-port').val(defaultPorts[type]);
    }
    $('#f-port').data('keep', false);
    // ping / http / bt 不需要端口：清空值并解除限制，避免隐藏的 port 字段（如值 0 违反 min=1）导致校验静默拦截提交
    if (type === 'ping' || type === 'http' || type === 'bt') {
      $('#f-port').val('').prop('required', false).removeAttr('min max');
    } else {
      $('#f-port').prop('required', true).attr('min', 1).attr('max', 65535);
    }
  }

  $('#f-type').on('change', refreshFormType);

  var modal = document.getElementById('serverModal');
  modal.addEventListener('show.bs.modal', function (event) {
    // 兼容从行内 onclick 打开（event.relatedTarget 可能为空），改读按钮自身
    var btn = event.relatedTarget || window.__editBtn || event.currentTarget;
    var data = btn && btn.dataset ? btn.dataset : {};
    var id = parseInt(data.id || '0', 10);
    $('#f-id').val(id);
    if (id > 0) {
      $('#serverModalTitle').text('编辑节点');
      $('#f-name').val(data.name || '');
      $('#f-type').val(data.type || 'ping');
      $('#f-address').val(data.address || '');
      $('#f-port').val(data.port || '').data('keep', true);
      $('#f-username').val(data.username || '');
      $('#f-password').val('').data('hasPwd', data.hasPassword == '1').attr('placeholder', data.hasPassword == '1' ? '留空表示不修改密码' : '');
      $('#f-interval').val(data.interval || 60);
      $('#f-timeout').val(data.timeout || 5);
      $('#f-enabled').prop('checked', data.enabled != '0');
      $('#f-group').val(data.group || '0');
      $('#f-ssh-monitor').prop('checked', data.sshMonitor == '1');
      $('#f-front-hidden').prop('checked', data.frontHidden == '1');
      $('#f-metrics-retain').val(data.metricsRetain || '0');
      $('#f-remark').val(data.remark || '');
    } else {
      $('#serverModalTitle').text('添加节点');
      modal.querySelector('form').reset();
      $('#f-id').val(0);
      $('#f-enabled').prop('checked', true);
      $('#f-ssh-monitor').prop('checked', false);
      $('#f-front-hidden').prop('checked', false);
      $('#f-metrics-retain').val('0');
      $('#f-password').data('hasPwd', false).attr('placeholder', '');
    }
    refreshFormType();
  });

  $('.form-delete').on('submit', function (e) {
    if (!confirm('确定删除该节点及其探测日志吗？')) { e.preventDefault(); }
  });

  /* ---------- 列表内快速开关：监控 / 资源采集 / 前台显示（AJAX 即时生效，无需刷新） ---------- */
  var curGroup = new URLSearchParams(location.search).get('group') || '';

  function toggleReq(action, id) {
    return $.ajax({ url: 'servers.php', type: 'POST', dataType: 'json', data: { action: action, id: id, group: curGroup } });
  }
  function httpErr(xhr) {
    var body = stripTags(xhr.responseText);
    return '请求失败：HTTP ' + (xhr.status || '-') + (body ? '（' + body.substr(0, 120) + '）' : '');
  }
  // 失败时回滚开关状态（prop 不触发 change，不会造成循环）
  function swFail($sw, msg) {
    $sw.prop('disabled', false);
    $sw.prop('checked', !$sw.prop('checked'));
    probeNotify(msg || '操作失败', 'danger');
  }
  function swDone($sw, res) {
    $sw.prop('disabled', false);
    return !!(res && res.code === 0 && res.data);
  }
  // 同步「编辑」按钮缓存的数据，避免弹窗回填旧状态
  function syncEdit(id, key, val) {
    $('tr[data-sid="' + id + '"] .btn-edit').attr('data-' + key, val);
  }

  $('.tk-enabled').on('change', function () {
    var $sw = $(this).prop('disabled', true), id = $sw.data('id');
    toggleReq('toggle', id).done(function (res) {
      if (!swDone($sw, res)) { return swFail($sw, res && res.data ? res.data.message : ''); }
      syncEdit(id, 'enabled', res.data.enabled);
      probeNotify(res.data.message, 'success');
    }).fail(function (xhr) { swFail($sw, httpErr(xhr)); });
  });

  $('.tk-front').on('change', function () {
    var $sw = $(this).prop('disabled', true), id = $sw.data('id');
    toggleReq('toggle_front', id).done(function (res) {
      if (!swDone($sw, res)) { return swFail($sw, res && res.data ? res.data.message : ''); }
      syncEdit(id, 'front-hidden', res.data.front_hidden);
      probeNotify(res.data.message, 'success');
    }).fail(function (xhr) { swFail($sw, httpErr(xhr)); });
  });

  $('.tk-monitor').on('change', function () {
    var $sw = $(this).prop('disabled', true), id = $sw.data('id');
    toggleReq('toggle_monitor', id).done(function (res) {
      if (!swDone($sw, res)) { return swFail($sw, res && res.data ? res.data.message : ''); }
      var on = res.data.ssh_monitor == 1;
      syncEdit(id, 'ssh-monitor', on ? 1 : 0);
      // 资源环形图随开关显隐：关闭后不再展示（数据停止更新），开启后等待下次探测
      var $slot = $('tr[data-sid="' + id + '"] .res-slot');
      if (on) {
        if (!$slot.children().length) { $slot.html('<div class="small text-muted mt-1">待采集资源数据</div>'); }
        $slot.show();
      } else {
        $slot.hide();
      }
      probeNotify(res.data.message, 'success');
    }).fail(function (xhr) { swFail($sw, httpErr(xhr)); });
  });

  /* ---------- 快速采集：立即探测一次并弹窗展示结果（含报错原文） ---------- */
  var qkModal = null, qkCurrent = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pct(v) { return v == null || v === '' ? '-' : (Math.round(parseFloat(v) * 10) / 10) + '%'; }
  function fkb(kb) {
    if (kb == null) return '-';
    if (kb >= 1073741824) return (kb / 1073741824).toFixed(1) + ' TB';
    if (kb >= 1048576) return (kb / 1048576).toFixed(1) + ' GB';
    if (kb >= 1024) return (kb / 1024).toFixed(1) + ' MB';
    return kb + ' KB';
  }
  function fbps(b) {
    if (b == null) return '-';
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB/s';
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB/s';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB/s';
    return Math.round(b) + ' B/s';
  }

  // 与列表 resource_gauges_html 同构，复用共享环形图渲染
  function gaugesHtml(r) {
    var mount = esc(r.disk_mount || '/');
    var hist = [];
    if (r.load_hist != null) {
      hist = String(r.load_hist).split(',').filter(function (v) { return v !== ''; });
    } else if (r.load != null) {
      hist = [String(Math.round(r.load * 100) / 100)];
    }
    var item = function (g, v, label, sub, extra, ring2) {
      return '<div class="gauge-item' + (extra ? ' gauge-disk-group' : '') + '"><div class="gauge-wrap"><canvas width="72" height="72" data-gauge="' + g + '"></canvas>'
        + '<span class="gauge-val">' + pct(v) + '</span>' + (ring2 || '') + '</div>'
        + '<div class="gauge-info"><span class="gauge-label">' + label + '</span>' + (sub || '') + '</div>' + (extra || '') + '</div>';
    };
    var cpuSub = hist.length ? '<div class="gauge-sub">负载 ' + esc(hist.join(' / ')) + '</div>' : '';
    var hasSwap = r.swap_total != null && r.swap_total > 0 && r.swap_used != null;
    var memSub = (r.mem_used != null && r.mem_total != null) ? '<div class="gauge-sub">' + (hasSwap ? '内存 ' : '') + fkb(r.mem_used) + ' / ' + fkb(r.mem_total) + '</div>' : '';
    var swapPct = null;
    if (hasSwap) {
      swapPct = r.swap != null ? r.swap : Math.round(r.swap_used / r.swap_total * 1000) / 10;
      memSub += '<div class="gauge-sub gauge-swap">Swap ' + fkb(r.swap_used) + ' / ' + fkb(r.swap_total) + '</div>';
    }
    var swapRing = hasSwap
      ? '<div class="gauge-wrap gauge-swap-ring"><canvas width="36" height="36" data-gauge="swap"></canvas>'
        + '<span class="gauge-val gauge-swap-val">' + pct(swapPct) + '</span></div>'
      : '';
    var diskInfo = (r.disk_used != null && r.disk_total != null) ? '<div class="gauge-disk">' + fkb(r.disk_used) + ' / ' + fkb(r.disk_total) + '</div>' : '';
    var net = (r.net_rx != null || r.net_tx != null)
      ? '<div class="gauge-item gauge-net"><div class="gauge-net-box">'
        + '<b class="net-down"><i class="mdi mdi-arrow-down-bold-circle-outline"></i><span class="net-down-val">' + fbps(r.net_rx) + '</span></b>'
        + '<b class="net-up"><i class="mdi mdi-arrow-up-bold-circle-outline"></i><span class="net-up-val">' + fbps(r.net_tx) + '</span></b>'
        + '</div><div class="gauge-info"><span class="gauge-label">实时带宽</span></div></div>'
      : '';
    return '<div class="resource-gauges" data-cpu="' + (r.cpu == null ? '' : r.cpu) + '" data-mem="' + (r.mem == null ? '' : r.mem) + '"'
      + ' data-swap="' + (swapPct == null ? '' : swapPct) + '" data-disk="' + (r.disk == null ? '' : r.disk) + '" data-loadhist="' + esc(hist.join(',')) + '">'
      + item('cpu', r.cpu, 'CPU', cpuSub) + item('mem', r.mem, '内存', memSub, '', swapRing)
      + item('disk', r.disk, '磁盘 ' + mount, diskInfo, window.probeDiskListHtml(r))
      + net + '</div>';
  }

  function qkLoading(name) {
    $('#qk-name').text(name || '');
    $('#qk-foot-time').text('');
    $('#qk-again').prop('disabled', true);
    $('#qk-body').html('<div class="text-center py-5">'
      + '<div class="spinner-border text-primary mb-3" role="status"></div>'
      + '<div class="text-muted">正在采集「' + esc(name) + '」…</div>'
      + '<div class="text-muted small mt-1">SSH / 宝塔面板节点需登录采集资源，约需 3~10 秒</div></div>');
  }

  // 剔除 HTML 标签并压缩空白（展示服务端错误/警告原文用）
  function stripTags(s) {
    return String(s == null ? '' : s).replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<[^>]+>/g, ' ').replace(/[ \t\r]+/g, ' ').replace(/\n{2,}/g, '\n').trim();
  }

  function qkFail(title, detail) {
    $('#qk-again').prop('disabled', false);
    $('#qk-body').html('<div class="alert alert-danger mb-0">'
      + '<div class="fw-bold mb-1"><i class="mdi mdi-alert-circle-outline me-1"></i>' + esc(title) + '</div>'
      + '<div class="qk-err">' + esc(detail || '无详细信息') + '</div></div>');
  }

  // 采集成功但服务端夹带了 PHP 警告：结果照常展示，警告原文附在下方便于排查
  function qkNoise(text) {
    $('#qk-body').append('<div class="alert alert-warning mt-3 mb-0">'
      + '<div class="fw-bold mb-1"><i class="mdi mdi-alert-outline me-1"></i>服务端告警（不影响本次采集结果）</div>'
      + '<div class="qk-err">' + esc(text.substr(0, 1500)) + '</div></div>');
  }

  function qkResult(r) {
    var ok = r.status == 1;
    var metric = function (ico, label, val) {
      return val == null || val === '' ? '' : '<span class="qk-metric"><i class="mdi ' + ico + '"></i>' + label + '<b>' + esc(val) + '</b></span>';
    };
    var html = '<div class="qk-head">'
      + '<span class="badge ' + (ok ? 'badge-soft-success' : 'badge-soft-danger') + '"><span class="status-dot ' + (ok ? 'dot-success' : 'dot-danger') + '"></span>' + (ok ? '在线' : '离线') + '</span>'
      + metric('mdi-timer-sand', '延迟 ', r.latency == null ? null : r.latency + ' ms')
      + metric('mdi-lightning-bolt', '采集耗时 ', r.duration == null ? null : r.duration + ' ms')
      + metric('mdi-chart-donut-variant', '丢包 ', r.loss == null ? null : r.loss + '%')
      + metric('mdi-certificate-outline', 'SSL 剩余 ', r.ssl_days == null ? null : r.ssl_days + ' 天')
      + '</div>';
    html += '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + ' qk-msg"><i class="mdi '
      + (ok ? 'mdi-check-circle-outline' : 'mdi-close-circle-outline') + ' me-1"></i>'
      + esc(r.message || (ok ? '采集成功' : '采集失败')) + '</div>';
    if (r.resource) {
      html += '<div class="qk-res-title"><i class="mdi mdi-gauge me-1"></i>资源采集结果</div>' + gaugesHtml(r.resource);
    }
    // 质量历史色块（与前台同款）：占位后异步加载
    html += '<div class="qk-res-title mt-3"><i class="mdi mdi-chart-box-outline me-1"></i>质量历史（近 24 小时）<span class="qk-hist-stat" id="qk-hist-stat"></span></div>'
      + '<div class="qk-hist-bars" id="qk-hist-bars"><span class="text-muted small">加载中…</span></div>';
    $('#qk-again').prop('disabled', false);
    $('#qk-body').html(html);
    if (r.resource) { window.probeRenderGauges($('#qk-body')); }
    $('#qk-foot-time').text('采集时间：' + (r.checked_at || '-'));
  }

  // 采集后同步刷新列表行的状态/时间/资源环形图
  function qkApplyRow(id, r) {
    var $tr = $('tr[data-sid="' + id + '"]');
    if (!$tr.length) { return; }
    $tr.find('.td-status').html(r.status == 1
      ? '<span class="badge badge-soft-success"><span class="status-dot dot-success"></span>在线</span>'
      : '<span class="badge badge-soft-danger"><span class="status-dot dot-danger"></span>离线</span>');
    $tr.find('.td-time').text(r.checked_at || '-');
    if (!r.resource) { return; }
    var $slot = $tr.find('.res-slot');
    if (!$slot.length) { return; }
    if ($slot.find('.resource-gauges').length) {
      window.probeUpdateGauges($slot, r.resource);
    } else {
      $slot.html(gaugesHtml(r.resource));
      window.probeRenderGauges($slot);
    }
  }

  // 加载质量历史色块：逐小时可用率，颜色按后台设置的红黄阈值
  function qkQuality(id) {
    $.getJSON('api/quality.php', { sid: id, hours: 24 }).done(function (res) {
      var d = res && res.code === 0 ? res.data : null;
      if (!d || !d.bars || !d.bars.length) { $('#qk-hist-bars').html('<span class="text-muted small">暂无历史数据</span>'); return; }
      var upT = d.up_t || [98, 90];
      var bc = function (v) { if (v === null) { return ''; } return v >= upT[0] ? 'g' : (v >= upT[1] ? 'y' : 'r'); };
      var h = '';
      d.bars.forEach(function (b) {
        var t = b[0] + (b[1] === null ? ' 无数据' : ' 可用率 ' + b[1] + '%');
        h += '<div class="qk-hist-bar ' + bc(b[1]) + '" title="' + esc(t) + '"></div>';
      });
      $('#qk-hist-bars').html(h);
      if (d.uptime !== null) {
        $('#qk-hist-stat').html('可用率 <b>' + d.uptime + '%</b> · 平均延迟 ' + (d.avg_latency === null ? '-' : d.avg_latency + ' ms')
          + ' · <a href="network.php?id=' + id + '">详情</a>');
      } else {
        $('#qk-hist-stat').text('暂无统计');
      }
    }).fail(function () {
      $('#qk-hist-bars').html('<span class="text-muted small">质量历史加载失败</span>');
    });
  }

  function quickCheck(id, name) {
    qkCurrent = { id: id, name: name };
    if (!qkModal) { qkModal = new bootstrap.Modal(document.getElementById('quickModal')); }
    qkLoading(name);
    qkModal.show();
    $.ajax({
      url: 'api/probe.php',
      type: 'POST',
      data: { action: 'check', server_id: id },
      dataType: 'text',   // 自行解析：响应体混入 PHP 警告时仍能取出 JSON 并展示警告原文
      timeout: 120000
    }).done(function (text) {
      text = String(text == null ? '' : text);
      var at = text.indexOf('{"code"');
      var noise = at > 0 ? stripTags(text.substr(0, at)) : '';
      var res = null;
      try { res = JSON.parse(at >= 0 ? text.substr(at) : text); } catch (e) { res = null; }
      if (res && res.code === 0 && res.data) {
        qkResult(res.data);
        qkApplyRow(id, res.data);
        qkQuality(id);
        if (noise) { qkNoise(noise); }
      } else if (res) {
        qkFail('采集失败', (res.data && res.data.message) ? res.data.message : '接口返回异常（' + JSON.stringify(res) + '）');
      } else {
        qkFail('采集响应解析失败', stripTags(text).substr(0, 1500) || '接口返回空内容');
      }
    }).fail(function (xhr, status) {
      var detail = status === 'timeout' ? '请求超时（120 秒未返回），请检查节点超时设置或服务器负载'
        : (status === 'abort' ? '请求已取消' : 'HTTP ' + (xhr.status || '-') + ' ' + (status || ''));
      // 服务端报错（500 / PHP 错误页）：剔除标签后展示原文
      var body = stripTags(xhr.responseText || '');
      if (body) { detail += '\n' + body.substr(0, 1500); }
      qkFail('采集请求失败', detail);
    });
  }

  $('.btn-quick').on('click', function () {
    quickCheck($(this).data('id'), $(this).data('name'));
  });
  $('#qk-again').on('click', function () {
    if (qkCurrent) { quickCheck(qkCurrent.id, qkCurrent.name); }
  });
});
</script>
JS;
include __DIR__ . '/templates/footer.php';
