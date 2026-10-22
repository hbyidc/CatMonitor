<?php
/**
 * 探测引擎：PING / TCP / UDP / SSH / HTTP(S) / 宝塔面板
 *
 * 每个探测方法统一返回：
 *   ['status' => bool, 'latency' => float(ms), 'message' => string]
 */

final class ProbeService
{
    /**
     * 按服务器配置执行探测
     */
    public static function run(array $server): array
    {
        $timeout = max(1, (int)($server['timeout'] ?? 5));

        return match ($server['type']) {
            'ping' => self::ping($server['address'], $timeout),
            'tcp'  => self::tcp($server['address'], (int)$server['port'], $timeout),
            'udp'  => self::udp($server['address'], (int)$server['port'], $timeout),
            'ssh'  => self::ssh($server['address'], (int)$server['port'] ?: 22, $timeout, $server['username'] ?? '', $server['password'] ?? '', (int)($server['ssh_monitor'] ?? 0) === 1),
            'ssh_ping' => self::sshPing($server, $timeout),
            'http' => self::http($server['address'], $timeout),
            'bt'   => self::bt($server['address'], $server['password'] ?? '', $timeout, (int)($server['ssh_monitor'] ?? 0) === 1),
            default => ['status' => false, 'latency' => 0.0, 'message' => '未知探测类型'],
        };
    }

    /**
     * 执行探测并把结果写入数据库（更新服务器状态 + 插入日志 + 评估预警）
     */
    public static function checkServer(PDO $db, array $server): array
    {
        $result = self::run($server);
        $now    = time();

        // 连续失败计数 / SSL 剩余天数（仅网站类型更新）
        $failCount = $result['status'] ? 0 : ((int)($server['fail_count'] ?? 0) + 1);
        $sslDays   = $server['type'] === 'http' ? ($result['ssl_days'] ?? null) : ($server['ssl_days'] ?? null);

        $stmt = $db->prepare(
            'UPDATE servers SET last_status = ?, last_latency = ?, ping_latency = ?, last_message = ?, last_check_at = ?, fail_count = ?, ssl_days = ? WHERE id = ?'
        );
        $stmt->execute([
            $result['status'] ? 1 : 0,
            round($result['latency'], 2),
            $result['ping_latency'] ?? null,
            mb_substr($result['message'], 0, 250),
            $now,
            $failCount,
            $sslDays,
            (int)$server['id'],
        ]);

        // SSH 资源监控数据落库（CPU/内存/根分区/负载）+ PING 丢包率
        $loss = isset($result['loss']) ? (float)$result['loss'] : null;
        $load = $result['resource']['load'] ?? null;
        // 负载历史：滚动保留最近 3 个值（逗号分隔）
        $hist = array_values(array_filter(explode(',', (string)($server['load_history'] ?? '')), fn($v) => $v !== ''));
        if ($load !== null) {
            $hist[] = (string)$load;
            $hist = array_slice($hist, -3);
        }
        $db->prepare('UPDATE servers SET packet_loss = ?, load_usage = ?, load_history = ? WHERE id = ?')
            ->execute([$loss, $load, implode(',', $hist) ?: null, (int)$server['id']]);
        if (in_array($server['type'], ['ssh', 'ssh_ping', 'bt'], true) && (int)($server['ssh_monitor'] ?? 0) === 1 && isset($result['resource'])) {
            $res = $result['resource'];
            $disksJson = json_encode(resource_disks($res), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $db->prepare('UPDATE servers SET cpu_usage = ?, mem_usage = ?, mem_used = ?, mem_total = ?, swap_used = ?, swap_total = ?, disk_usage = ?, disk_mount = ?, disk_used = ?, disk_total = ?, net_rx_rate = ?, net_tx_rate = ?, disks_json = ? WHERE id = ?')
                ->execute([
                    $res['cpu'], $res['mem'], $res['mem_used'] ?? null, $res['mem_total'] ?? null,
                    $res['swap_used'] ?? null, $res['swap_total'] ?? null,
                    $res['disk'], $res['disk_mount'] ?? null, $res['disk_used'] ?? null, $res['disk_total'] ?? null,
                    $res['net_rx'] ?? null, $res['net_tx'] ?? null, $disksJson,
                    (int)$server['id'],
                ]);
            // 长期监控：写入历史数据并清理过期记录
            $retain = (int)($server['metrics_retain_days'] ?? 0);
            if ($retain > 0) {
                $db->prepare('INSERT INTO server_metrics (server_id, cpu, mem, disk, `load`, mem_used, mem_total, disk_used, disk_total, net_rx, net_tx, checked_at, disks_json)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        (int)$server['id'], $res['cpu'], $res['mem'], $res['disk'], $res['load'] ?? null,
                        $res['mem_used'] ?? null, $res['mem_total'] ?? null,
                        $res['disk_used'] ?? null, $res['disk_total'] ?? null,
                        $res['net_rx'] ?? null, $res['net_tx'] ?? null, $now, $disksJson,
                    ]);
                $db->prepare('DELETE FROM server_metrics WHERE server_id = ? AND checked_at < ?')
                    ->execute([(int)$server['id'], $now - $retain * 86400]);
            }
        }

        $log = $db->prepare(
            'INSERT INTO logs (server_id, status, latency, packet_loss, message, checked_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $log->execute([
            (int)$server['id'],
            $result['status'] ? 1 : 0,
            round($result['latency'], 2),
            $loss,
            mb_substr($result['message'], 0, 250),
            $now,
        ]);

        // 预警规则评估（离线/SSL，含频率限制与时间窗口）
        AlertService::evaluate($db, array_merge($server, ['fail_count' => $failCount, 'ssl_days' => $sslDays]), $result);

        return $result;
    }

    /* ---------------- PING ---------------- */

    public static function ping(string $host, int $timeout = 5): array
    {
        if (!is_valid_host($host)) {
            return ['status' => false, 'latency' => 0.0, 'message' => '主机名不合法'];
        }

        // 优先：纯 PHP Socket ICMP（无需 exec，不经过 shell，无注入风险）
        if (extension_loaded('sockets')) {
            $native = self::pingNative($host, $timeout);
            if ($native !== null) {
                return $native;
            }
        }

        // 降级：系统 ping 命令（需要 exec 未被禁用）
        if (self::execDisabled()) {
            return ['status' => false, 'latency' => 0.0, 'message' => 'PING 不可用：sockets 扩展无权限且 exec 被禁用'];
        }

        $cmd  = sprintf('ping -c 4 -W %d %s 2>&1', max(1, $timeout), escapeshellarg($host));
        $out  = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);

        // 解析平均延迟与丢包率：rtt min/avg/max/mdev = 0.03/0.06/0.1/0.02 ms；4 packets transmitted, 25% packet loss
        $loss = null;
        if (preg_match('/(\d+)% packet loss/', $text, $lm)) {
            $loss = (float)$lm[1];
        }
        if ($code === 0 && preg_match('/=\s*[\d.]+\/([\d.]+)\/[\d.]+/', $text, $m)) {
            return ['status' => true, 'latency' => (float)$m[1], 'loss' => $loss, 'message' => 'PING 正常，平均延迟 ' . $m[1] . ' ms'];
        }

        // 失败时提取关键错误行
        foreach ($out as $line) {
            if (stripos($line, 'unknown') !== false || stripos($line, 'unreachable') !== false || stripos($line, '100% packet loss') !== false) {
                return ['status' => false, 'latency' => 0.0, 'message' => trim($line)];
            }
        }

        return ['status' => false, 'latency' => 0.0, 'message' => 'PING 失败（丢包或主机不可达）'];
    }

    /**
     * 纯 PHP ICMP Ping（仅 IPv4）
     * 优先使用 SOCK_DGRAM ICMP（内核非特权 ping socket，由 ping_group_range 控制），
     * 失败时尝试 SOCK_RAW（需要 CAP_NET_RAW）；两者都无权限返回 null 交给系统 ping。
     */
    private static function pingNative(string $host, int $timeout, int $count = 4): ?array
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null; // 无法解析或 IPv6，交给系统 ping
        }

        $sock  = @socket_create(AF_INET, SOCK_DGRAM, getprotobyname('icmp'));
        $isRaw = false;
        if (!$sock) {
            $sock  = @socket_create(AF_INET, SOCK_RAW, getprotobyname('icmp'));
            $isRaw = true;
        }
        if (!$sock) {
            return null; // 权限不足，降级
        }

        $id        = getmypid() & 0xFFFF;
        $payload   = 'ServerProbe' . str_repeat('0123456789', 2);
        $latencies = [];

        for ($seq = 1; $seq <= $count; $seq++) {
            $packet = self::icmpPacket($id, $seq, $payload);

            socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => max(1, $timeout), 'usec' => 0]);
            if (@socket_sendto($sock, $packet, strlen($packet), 0, $ip, 0) === false) {
                continue;
            }

            $start    = microtime(true);
            $deadline = $start + $timeout;
            while (microtime(true) < $deadline) {
                $buf  = '';
                $from = '';
                $port = 0;
                $r    = @socket_recvfrom($sock, $buf, 1024, 0, $from, $port);
                if ($r === false || $r === 0) {
                    break; // 超时
                }
                $icmp = $buf;
                if ($isRaw && strlen($buf) >= 28) {
                    // RAW 模式返回包含 IP 头，需剥离
                    $icmp = substr($buf, (ord($buf[0]) & 0x0F) * 4);
                }
                if (strlen($icmp) < 8) {
                    continue;
                }
                $hdr = unpack('Ctype/Ccode/nchecksum/nid/nseq', substr($icmp, 0, 8));
                // type 0 = Echo Reply；DGRAM socket 由内核过滤只收自己的回包，RAW 需再校验 ID
                if ($hdr['type'] === 0 && $hdr['seq'] === ($seq & 0xFFFF) && (!$isRaw || $hdr['id'] === $id)) {
                    $latencies[] = (microtime(true) - $start) * 1000;
                    break;
                }
            }
        }
        socket_close($sock);

        if (!$latencies) {
            return ['status' => false, 'latency' => 0.0, 'loss' => 100.0, 'message' => sprintf('PING 失败：%d/%d 丢包（主机不可达或禁 ICMP）', $count, $count)];
        }

        $avg  = array_sum($latencies) / count($latencies);
        $loss = ($count - count($latencies)) / $count * 100;

        return [
            'status'  => true,
            'latency' => $avg,
            'loss'    => $loss,
            'message' => sprintf('PING 正常，收到 %d/%d，平均延迟 %.2f ms', count($latencies), $count, $avg),
        ];
    }

    /** 构造 ICMP Echo Request 包（Type=8） */
    private static function icmpPacket(int $id, int $seq, string $payload): string
    {
        $packet   = pack('CCnnn', 8, 0, 0, $id & 0xFFFF, $seq & 0xFFFF) . $payload;
        $checksum = self::icmpChecksum($packet);
        $packet[2] = $checksum[0];
        $packet[3] = $checksum[1];

        return $packet;
    }

    /** ICMP 校验和（16 位反码求和） */
    private static function icmpChecksum(string $data): string
    {
        if (strlen($data) % 2) {
            $data .= "\0";
        }
        $sum = 0;
        for ($i = 0, $len = strlen($data); $i < $len; $i += 2) {
            $sum += (ord($data[$i]) << 8) + ord($data[$i + 1]);
        }
        while ($sum >> 16) {
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }

        return pack('n', ~$sum & 0xFFFF);
    }

    /* ---------------- TCP ---------------- */

    public static function tcp(string $host, int $port, int $timeout = 5): array
    {
        $start = microtime(true);
        $fp    = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout
        );
        $latency = (microtime(true) - $start) * 1000;

        if (!$fp) {
            return ['status' => false, 'latency' => 0.0, 'message' => "TCP 连接失败：{$errstr}"];
        }
        fclose($fp);

        return ['status' => true, 'latency' => $latency, 'message' => "TCP 端口 {$port} 开放"];
    }

    /* ---------------- UDP ---------------- */

    public static function udp(string $host, int $port, int $timeout = 5): array
    {
        $start = microtime(true);
        $fp    = @stream_socket_client(
            sprintf('udp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout
        );
        $latency = (microtime(true) - $start) * 1000;

        if (!$fp) {
            return ['status' => false, 'latency' => 0.0, 'message' => "UDP 连接失败：{$errstr}"];
        }

        fwrite($fp, "\x00"); // 发送探测包
        stream_set_timeout($fp, $timeout);
        $resp = fread($fp, 256);
        $meta = stream_get_meta_data($fp);
        fclose($fp);

        // 收到 ICMP 端口不可达：fread 返回空且未超时 => 离线
        if (($resp === false || $resp === '') && !$meta['timed_out']) {
            return ['status' => false, 'latency' => $latency, 'message' => 'UDP 端口不可达（ICMP 拒绝）'];
        }
        if ($resp !== false && $resp !== '') {
            return ['status' => true, 'latency' => $latency, 'message' => 'UDP 收到响应（' . strlen($resp) . ' 字节）'];
        }

        // 超时未收到拒绝包：视为可达（UDP 无连接特性，可能为防火墙过滤）
        return ['status' => true, 'latency' => $latency, 'message' => 'UDP 包已发送，未收到拒绝响应（视为可达）'];
    }

    /* ---------------- SSH ---------------- */

    public static function ssh(string $host, int $port, int $timeout = 5, string $username = '', string $password = '', bool $monitor = false): array
    {
        // 优先使用 ssh2 扩展做真实认证检测
        if (extension_loaded('ssh2')) {
            return self::sshViaSsh2($host, $port, $timeout, $username, $password, $monitor);
        }

        // 降级方案：TCP 连接 + 读取 SSH 服务 Banner
        $start = microtime(true);
        $fp    = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout
        );
        $latency = (microtime(true) - $start) * 1000;

        if (!$fp) {
            return ['status' => false, 'latency' => 0.0, 'message' => "SSH 连接失败：{$errstr}"];
        }

        stream_set_timeout($fp, $timeout);
        $banner = trim((string)fgets($fp, 255));
        fclose($fp);

        if (str_starts_with($banner, 'SSH-')) {
            return ['status' => true, 'latency' => $latency, 'message' => 'SSH 服务正常：' . $banner . '（未安装 ssh2 扩展，仅检测服务可用性）'];
        }

        return ['status' => false, 'latency' => $latency, 'message' => "端口 {$port} 已开放，但未返回 SSH Banner"];
    }

    private static function sshViaSsh2(string $host, int $port, int $timeout, string $username, string $password, bool $monitor): array
    {
        $start = microtime(true);
        $conn  = @ssh2_connect($host, $port);
        $latency = (microtime(true) - $start) * 1000;

        if (!$conn) {
            return ['status' => false, 'latency' => 0.0, 'message' => 'SSH 握手失败'];
        }
        if ($username === '' || $password === '') {
            return ['status' => true, 'latency' => $latency, 'message' => 'SSH 握手成功（未配置账号密码，跳过登录验证）'];
        }
        if (!@ssh2_auth_password($conn, $username, $password)) {
            return ['status' => false, 'latency' => $latency, 'message' => 'SSH 账号密码验证失败'];
        }

        $result = ['status' => true, 'latency' => $latency, 'message' => 'SSH 登录验证成功'];

        // 资源监控：登录后远程采集 CPU / 内存 / 根分区占用
        if ($monitor) {
            $res = self::sshCollectResource($conn, $timeout);
            if ($res === null) {
                $result['message'] .= '；资源采集失败（命令执行异常）';
            } else {
                // 资源数据仅落库由前端环形图展示，不再拼入探测消息
                $result['resource'] = $res;
            }
        }

        return $result;
    }

    /**
     * SSH+PING 组合探测：同时执行两种探测，任一可达即判定在线；
     * SSH 通时延迟/消息以 SSH 为准，SSH 不通但 PING 通时降级用 PING 延迟判在线；
     * PING 延迟单独通过 ping_latency 返回，丢包率沿用 PING 结果；资源采集能力与 SSH 一致
     */
    private static function sshPing(array $server, int $timeout): array
    {
        $ping = self::ping($server['address'], $timeout);
        $ssh  = self::ssh(
            $server['address'],
            (int)$server['port'] ?: 22,
            $timeout,
            $server['username'] ?? '',
            $server['password'] ?? '',
            (int)($server['ssh_monitor'] ?? 0) === 1
        );
        $result = $ssh;
        // 任一通道可达即在线：SSH 不可达但 PING 通时，以 PING 延迟判定在线
        if (!$ssh['status'] && $ping['status']) {
            $result['status']  = true;
            $result['latency'] = round((float)$ping['latency'], 2);
            $result['message'] = 'SSH 不可达（' . $ssh['message'] . '），PING 可达判定在线';
        }
        $result['ping_latency'] = $ping['status'] ? round((float)$ping['latency'], 2) : null;
        if (isset($ping['loss'])) {
            $result['loss'] = $ping['loss'];
        }
        $result['message'] .= $ping['status']
            ? '；PING ' . round((float)$ping['latency'], 2) . ' ms'
            : '；PING 不可达';

        return $result;
    }

    /**
     * 通过已认证的 SSH 连接采集资源占用（CPU / 内存 / 根分区 / 实时带宽）
     * CPU 采用 /proc/stat 两次采样差值（间隔 1 秒），内存基于 MemAvailable，
     * 磁盘取 df -P / 的使用率、已用、总容量（KB）与挂载点；
     * 带宽取 /proc/net/dev 两次采样差值（与 CPU 共用 1 秒间隔，排除 lo 与虚拟网卡）
     * 返回 ['cpu','mem','disk','disk_mount','disk_used','disk_total','net_rx','net_tx']，整体失败返回 null
     */
    private static function sshCollectResource($conn, int $timeout): ?array
    {
        $stat = "awk '/^cpu /{t=\$2+\$3+\$4+\$5+\$6+\$7+\$8+\$9; i=\$5+\$6; print t, i}' /proc/stat";
        $net  = "awk 'NR>2{gsub(/:/,\" \"); i=\$1; if(i==\"lo\"||i~/^(veth|docker|br-|virbr|tun|tap|wg)/)next; r+=\$2; t+=\$10}END{print r+0, t+0}' /proc/net/dev";
        $cmd  = "n1=\$({$net}); a=\$({$stat}); sleep 1; b=\$({$stat}); n2=\$({$net}); "
            . "m=\$(awk '/MemTotal/{t=\$2}/MemAvailable/{v=\$2}END{if(t>0)printf \"%.1f %d %d\",(t-v)*100/t,t-v,t}' /proc/meminfo); "
            . "w=\$(awk '/SwapTotal/{t=\$2}/SwapFree/{f=\$2}END{printf \"%.1f %d %d\",(t>0?(t-f)*100/t:0),t-f,t}' /proc/meminfo); "
            . "l=\$(cut -d' ' -f1 /proc/loadavg); n=\$(grep -c ^processor /proc/cpuinfo); "
            . 'echo "CPU1=$a;CPU2=$b;MEM=$m;SWAP=$w;LOAD=$l;NCPU=$n;NET1=$n1;NET2=$n2"; '
            . 'LC_ALL=C df -lPkT 2>/dev/null';

        $stream = @ssh2_exec($conn, $cmd);
        if (!$stream) {
            return null;
        }
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, max(10, $timeout + 3));
        $out = '';
        while (($buf = fread($stream, 4096)) !== false && $buf !== '') {
            $out .= $buf;
        }
        fclose($stream);

        return self::parseSshResource($out);
    }

    /** 解析 SSH 资源输出，磁盘按挂载点独立读取，失败不影响 CPU/内存。 */
    private static function parseSshResource(string $out): ?array
    {
        // SWAP 段为可选分组（兼容无 Swap 采集的旧格式输出）：8=pct 9=used 10=total，11=LOAD 12=NCPU
        if (!preg_match('/CPU1=(\d+) (\d+);CPU2=(\d+) (\d+);MEM=([\d.]*) (\d*) (\d*);(?:SWAP=([\d.]+) (-?\d+) (\d+);)?LOAD=([\d.]*);NCPU=(\d*)/', $out, $m)) {
            return null;
        }

        $cpu = null;
        $dt = (int)$m[3] - (int)$m[1];
        $di = (int)$m[4] - (int)$m[2];
        if ($dt > 0) {
            $cpu = max(0.0, min(100.0, round(100 - $di / $dt * 100, 1)));
        }

        // Swap：SwapTotal 为 0 或无 SWAP 段视为无 Swap，字段置 null（展示层不显示 Swap 行）
        $swapPct = $swapUsed = $swapTotal = null;
        if (($m[10] ?? '') !== '' && (int)$m[10] > 0) {
            $swapPct   = max(0.0, min(100.0, (float)$m[8]));
            $swapUsed  = max(0, (int)$m[9]);
            $swapTotal = (int)$m[10];
        }

        // 实时带宽：两次采样字节数差值即 1 秒内的下行/上行速率（B/s），采集失败不阻断整体
        $netRx = null;
        $netTx = null;
        if (preg_match('/NET1=(\d+) (\d+);NET2=(\d+) (\d+)/', $out, $nm)) {
            $netRx = max(0, (int)$nm[3] - (int)$nm[1]);
            $netTx = max(0, (int)$nm[4] - (int)$nm[2]);
        }

        $parts = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('/^(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+-?\d+\s+(\d+(?:\.\d+)?)%\s+(.+)$/', trim($line), $dm)) continue;
            $mount = preg_replace_callback('/\\\\([0-7]{3})/', static fn($v) => chr(octdec($v[1])), $dm[6]);
            $parts[] = ['device' => $dm[1], 'fs' => $dm[2], 'total' => (int)$dm[3], 'used' => (int)$dm[4], 'pct' => (float)$dm[5], 'mount' => $mount];
        }
        $disks = normalize_resource_disks($parts);
        $primary = $disks[0] ?? [];

        return [
            'disks'      => $disks,
            'cpu'        => $cpu,
            'mem'        => $m[5] !== '' ? max(0.0, min(100.0, (float)$m[5])) : null,
            'mem_used'   => $m[6] !== '' ? (int)$m[6] : null,   // KB
            'mem_total'  => $m[7] !== '' ? (int)$m[7] : null,   // KB
            'swap'       => $swapPct,
            'swap_used'  => $swapUsed,   // KB
            'swap_total' => $swapTotal,  // KB
            'disk'       => $primary['pct'] ?? null,
            'disk_used'  => $primary['used'] ?? null,   // KB
            'disk_total' => $primary['total'] ?? null, // KB
            'disk_mount' => $primary['mount'] ?? null,
            'load'       => ($m[11] !== '' && (int)$m[12] > 0)
                ? round((float)$m[11] / (int)$m[12] * 100, 1)   // 1 分钟负载 / 核心数
                : null,
            'net_rx'     => $netRx, // B/s 下行速率
            'net_tx'     => $netTx, // B/s 上行速率
        ];
    }

    /* ---------------- 网站 HTTP(S) ---------------- */

    public static function http(string $url, int $timeout = 5): array
    {
        if (!extension_loaded('curl')) {
            return ['status' => false, 'latency' => 0.0, 'message' => 'cURL 扩展未安装'];
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host   = (string)parse_url($url, PHP_URL_HOST);
        $port   = (int)parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => max(1, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ServerProbe/1.0',
        ]);

        $start = microtime(true);
        $body  = curl_exec($ch);
        $latency = (microtime(true) - $start) * 1000;

        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ok  = $body !== false && $code >= 200 && $code < 400;
        $msg = $body === false ? 'HTTP 请求失败：' . $err : ($ok ? "HTTP {$code}" : "HTTP 状态码异常：{$code}");

        $sslDays = null;

        // HTTPS 站点附加 SSL 证书有效期检测
        if ($scheme === 'https' && $host !== '') {
            $ssl = self::sslCertInfo($host, $port, $timeout);
            if (!$ssl['ok']) {
                $msg .= '；SSL：' . $ssl['message'];
            } elseif ($ssl['days'] < 0) {
                return [
                    'status'   => false,
                    'latency'  => $latency,
                    'message'  => sprintf(
                        'SSL 证书已过期 %d 天（%s 到期）',
                        abs($ssl['days']),
                        date('Y-m-d', $ssl['valid_to'])
                    ),
                    'ssl_days' => $ssl['days'],
                ];
            } else {
                $sslDays = $ssl['days'];
                $msg .= sprintf('；SSL 证书剩余 %d 天%s', $ssl['days'], $ssl['days'] <= 30 ? '（即将过期）' : '');
            }
        }

        return ['status' => $ok, 'latency' => $latency, 'message' => $msg, 'ssl_days' => $sslDays];
    }

    /**
     * 获取 SSL 证书信息（verify_peer 关闭，证书过期也能读取到期时间）
     * 返回 ['ok' => bool, 'days' => int, 'valid_to' => int, 'message' => string]
     */
    private static function sslCertInfo(string $host, int $port, int $timeout): array
    {
        if (!extension_loaded('openssl')) {
            return ['ok' => false, 'message' => 'openssl 扩展未安装'];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $fp = @stream_socket_client(
            sprintf('tls://%s:%d', $host, $port),
            $errno,
            $errstr,
            max(1, $timeout),
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$fp) {
            return ['ok' => false, 'message' => '证书获取失败：' . $errstr];
        }
        $params = stream_context_get_params($fp);
        fclose($fp);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return ['ok' => false, 'message' => '未捕获到对端证书'];
        }
        $info = openssl_x509_parse($cert);
        if (!$info || empty($info['validTo_time_t'])) {
            return ['ok' => false, 'message' => '证书解析失败'];
        }

        $validTo = (int)$info['validTo_time_t'];

        return [
            'ok'       => true,
            'days'     => (int)floor(($validTo - time()) / 86400),
            'valid_to' => $validTo,
            'message'  => '',
        ];
    }

    /* ---------------- 宝塔面板 ---------------- */

    /**
     * 宝塔面板探测：通过面板 API（签名 request_token）拉取系统状态
     * GetSystemTotal 判定面板存活；开启资源采集时再取 GetNetWork（CPU/内存/负载/带宽）与 GetDiskInfo（根分区）
     * 返回结构与 SSH 资源监控一致，复用同一套环形图/趋势图/预警链路
     */
    public static function bt(string $panelUrl, string $apiKey, int $timeout = 5, bool $monitor = true): array
    {
        if (!extension_loaded('curl')) {
            return ['status' => false, 'latency' => 0.0, 'message' => 'cURL 扩展未安装'];
        }
        $base = rtrim(trim($panelUrl), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            return ['status' => false, 'latency' => 0.0, 'message' => '面板地址不合法，需为完整 URL（如 http://1.2.3.4:8888）'];
        }
        if ($apiKey === '') {
            return ['status' => false, 'latency' => 0.0, 'message' => '未配置宝塔面板接口密钥'];
        }

        $start = microtime(true);
        $total = self::btApi($base . '/system?action=GetSystemTotal', $apiKey, $timeout);
        $latency = (microtime(true) - $start) * 1000;

        if ($total === null) {
            return ['status' => false, 'latency' => $latency, 'message' => '宝塔面板无响应或返回非 JSON（检查地址/安全入口/IP 白名单）'];
        }
        // 面板错误响应：{"status":false,"msg":"..."}（如密钥错误、IP 未授权）
        if (isset($total['status']) && $total['status'] === false) {
            return ['status' => false, 'latency' => $latency, 'message' => '宝塔面板拒绝：' . (string)($total['msg'] ?? '签名校验失败')];
        }
        if (!isset($total['cpuNum'])) {
            return ['status' => false, 'latency' => $latency, 'message' => '宝塔面板返回数据异常（无系统统计字段）'];
        }

        $msg = sprintf('宝塔面板正常：%s，面板版本 %s，运行 %s', $total['system'] ?? '-', $total['version'] ?? '-', $total['time'] ?? '-');
        if (!$monitor) {
            // 未开启资源采集：只判定面板存活，省去实时状态与磁盘两次接口请求
            return ['status' => true, 'latency' => $latency, 'message' => $msg];
        }

        // 实时状态与磁盘信息：失败不阻断整体探测，仅缺对应资源项
        $net  = self::btApi($base . '/system?action=GetNetWork', $apiKey, $timeout);
        $disk = self::btApi($base . '/system?action=GetDiskInfo', $apiKey, $timeout);
        $cores = max(1, (int)($total['cpuNum'] ?? 1));

        $cpu = isset($net['cpu'][0]) ? round((float)$net['cpu'][0], 1) : (isset($total['cpuRealUsed']) ? round((float)$total['cpuRealUsed'], 1) : null);

        // 内存：面板单位 MB → 转 KB 与 SSH 口径对齐
        $mem = $memUsed = $memTotal = null;
        $m = $net['mem'] ?? null;
        if (is_array($m) && (float)($m['memTotal'] ?? 0) > 0) {
            $memTotal = (int)round((float)$m['memTotal'] * 1024);
            $memUsed  = (int)round((float)($m['memRealUsed'] ?? 0) * 1024);
            $mem      = round(min(100, $memUsed / $memTotal * 100), 1);
        } elseif ((float)($total['memTotal'] ?? 0) > 0) {
            $memTotal = (int)round((float)$total['memTotal'] * 1024);
            $memUsed  = (int)round((float)($total['memRealUsed'] ?? 0) * 1024);
            $mem      = round(min(100, $memUsed / $memTotal * 100), 1);
        }

        // 负载：1 分钟负载 / 核心数 × 100，与 SSH 口径一致
        $load = null;
        if (isset($net['load']['one'])) {
            $load = round((float)$net['load']['one'] / $cores * 100, 1);
        }

        // 实时带宽：GetNetWork 的 down/up 为 KB/s → 转 B/s
        $netRx = isset($net['down']) ? (int)round((float)$net['down'] * 1024) : null;
        $netTx = isset($net['up']) ? (int)round((float)$net['up'] * 1024) : null;

        // 磁盘：取根分区（无则第一个分区），size = [总量, 已用, 可用, 使用率]
        $disks = self::parseBtDisks($disk ?? []);
        $primary = $disks[0] ?? [];
        $diskPct = $primary['pct'] ?? null;
        $diskUsed = $primary['used'] ?? null;
        $diskTotal = $primary['total'] ?? null;
        $diskMount = $primary['mount'] ?? null;

        return [
            'status'   => true,
            'latency'  => $latency,
            'message'  => $msg,
            'resource' => [
                'cpu'        => $cpu,
                'mem'        => $mem,
                'mem_used'   => $memUsed,
                'mem_total'  => $memTotal,
                'disk'       => $diskPct,
                'disk_used'  => $diskUsed,
                'disk_total' => $diskTotal,
                'disk_mount' => $diskMount,
                'disks'      => $disks,
                'load'       => $load,
                'net_rx'     => $netRx,
                'net_tx'     => $netTx,
            ],
        ];
    }

    /** 保留宝塔返回的所有真实分区，统一容量为 KB；无设备字段时按挂载点去重。 */
    private static function parseBtDisks(array $disk): array
    {
        $items = [];
        foreach ($disk as $part) {
            if (!is_array($part) || !is_array($part['size'] ?? null)) continue;
            $size = $part['size'];
            if (!isset($size[0], $size[1], $size[3])) continue;
            $pct = rtrim(trim((string)$size[3]), '%');
            $items[] = [
                'mount' => (string)($part['path'] ?? ''),
                'device' => (string)($part['filesystem'] ?? $part['device'] ?? ''),
                'fs' => (string)($part['type'] ?? $part['fstype'] ?? ''),
                'total' => self::btSizeToKb((string)$size[0]),
                'used' => self::btSizeToKb((string)$size[1]),
                'pct' => is_numeric($pct) ? (float)$pct : null,
            ];
        }
        return normalize_resource_disks($items);
    }

    /** 宝塔 API 签名请求：request_token = md5(request_time + md5(api_sk))，统一 POST */
    private static function btApi(string $url, string $apiKey, int $timeout): ?array
    {
        $t    = time();
        $data = [
            'request_token' => md5($t . md5($apiKey)),
            'request_time'  => $t,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(5, $timeout + 5),
            CURLOPT_CONNECTTIMEOUT => max(1, $timeout),
            // 面板多为自签证书，不校验；内存 Cookie 会话（不落盘）
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEFILE     => '',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            return null;
        }
        $json = json_decode((string)$body, true);

        return is_array($json) ? $json : null;
    }

    /**
     * 宝塔磁盘容量文本转 KB
     * 兼容各版本面板写法：8.3G / 37.6GB / 512MiB / 999B / 102400（无单位即 KB）
     */
    private static function btSizeToKb(string $s): ?int
    {
        $s = trim(str_replace(',', '', $s));
        if ($s === '' || !preg_match('/^(-?[\d.]+)\s*([KMGT])?[iI]?([bB])?$/i', $s, $m)) {
            return null;
        }
        // 倍率以 KB 为基准；单位只取 K/M/G/T 首字母，GB、GiB 等均按 1024 进制处理
        $mult = ['' => 1, 'k' => 1, 'm' => 1024, 'g' => 1048576, 't' => 1073741824];
        $unit = strtolower($m[2] ?? '');
        $kb   = (float)$m[1] * ($mult[$unit] ?? 1);
        // 仅有 B 后缀（无 K/M/G/T）时为纯字节
        if ($unit === '' && ($m[3] ?? '') !== '') {
            $kb /= 1024;
        }

        return (int)round($kb);
    }

    /* ---------------- 工具 ---------------- */

    /** exec 是否被禁用（宝塔环境常见） */
    private static function execDisabled(): bool
    {
        if (!function_exists('exec')) {
            return true;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return in_array('exec', $disabled, true);
    }
}
