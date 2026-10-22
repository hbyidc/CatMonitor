<?php
/**
 * 在线更新 / 授权服务（UpdateService）
 *
 * 核心原则：授权或网络失败绝不阻断系统。
 *  - bootstrap.php 仅加载本类，不做任何授权检测；系统所有功能与授权状态完全解耦；
 *  - 本类所有方法只返回结果数组，从不 exit / die / 抛出未捕获异常；
 *  - 授权失败时只是「无法在线升级」，监控、探测、后台管理照常可用。
 *
 * 对接接口：https://authn.hbyidc.com/api/index.php
 *   action=query         授权查询（只读，快速判断是否正版）
 *   action=verify        授权检测（返回 24h token + 配额）
 *   action=check_update  检查可用版本列表
 *   action=upgrade       获取指定版本下载链接 + db_upgrade SQL
 *
 * 升级引擎（applyUpgrade）逐版本执行：
 *   取 token → 获取下载信息 → 下载 ZIP → 校验 → 备份将被覆盖的文件
 *   → 执行 DB 升级 SQL → 解压覆盖（排除 config.php / data/ / uploads/）
 *   → 写入新版本号；任一步失败自动回滚已覆盖文件并返回错误。
 *   可传入 $emit 回调，将「每一步进度」与「失败完整明细」（含出错 SQL、PDO errorInfo、
 *   未预期异常的类型/位置/调用栈）实时推送给调用方（api/update.php 的 SSE 端点据此流式展示）。
 */

final class UpdateService
{
    // ==================== 基础信息 ====================

    /** 授权 / 更新 API 端点 */
    public static function apiBase(): string
    {
        return defined('PROBE_AUTH_API') ? PROBE_AUTH_API : 'https://authn.hbyidc.com/api/index.php';
    }

    /** 应用 ID（settings 覆盖 > 常量默认） */
    public static function appId(PDO $db): int
    {
        $v = trim(get_setting($db, 'update_app_id', ''));
        if ($v !== '' && ctype_digit($v)) {
            return (int)$v;
        }

        return defined('PROBE_APP_ID') ? (int)PROBE_APP_ID : 1;
    }

    /** 当前生效版本号：settings.app_version 优先，其次代码内 PROBE_VERSION */
    public static function currentVersion(PDO $db): string
    {
        $v = trim(get_setting($db, 'app_version', ''));
        if ($v !== '') {
            return $v;
        }

        return defined('PROBE_VERSION') ? PROBE_VERSION : '0.0.0';
    }

    /** 当前访问域名（去掉端口） */
    public static function domain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        return preg_replace('/:\d+$/', '', (string)$host);
    }

    /** 服务器 IP（授权开启 IP 检测时使用） */
    public static function serverIp(): string
    {
        return (string)($_SERVER['SERVER_ADDR'] ?? '');
    }

    /** 已保存的授权码 */
    public static function licenseKey(PDO $db): string
    {
        return trim(get_setting($db, 'license_key', ''));
    }

    /** 站点根目录（admin 的上一级） */
    public static function siteRoot(): string
    {
        return dirname(ADMIN_ROOT);
    }

    // ==================== 底层 HTTP ====================

    /**
     * 统一的 API 请求（GET 传参）。任何异常都转为 code=0 结果，绝不抛出 / 退出。
     *
     * @return array{code:int,msg:string,data:array}
     */
    public static function api(array $params, int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'msg' => '服务器未安装 cURL 扩展，无法连接授权服务器', 'data' => []];
        }

        $url = self::apiBase() . '?' . http_build_query($params);
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return ['code' => 0, 'msg' => '授权接口必须使用 HTTPS，禁止明文传输授权信息', 'data' => []];
        }
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ServerProbe/' . (defined('PROBE_VERSION') ? PROBE_VERSION : '0'),
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            return ['code' => 0, 'msg' => '网络请求失败：' . ($err !== '' ? $err : 'HTTP ' . $httpCode), 'data' => []];
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['code' => 0, 'msg' => '响应解析失败（非 JSON 数据）', 'data' => []];
        }

        return [
            'code' => (int)($json['code'] ?? 0),
            'msg'  => (string)($json['msg'] ?? ''),
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
        ];
    }

    // ==================== 授权 / 更新接口 ====================

    /** 授权查询（只读，快速判断是否正版） */
    public static function query(PDO $db): array
    {
        return self::api([
            'action' => 'query',
            'app_id' => self::appId($db),
            'domain' => self::domain(),
        ]);
    }

    /** 授权检测（完整验证，成功返回 token + 配额） */
    public static function verify(PDO $db, string $licenseKey): array
    {
        $params = [
            'action'      => 'verify',
            'app_id'      => self::appId($db),
            'domain'      => self::domain(),
            'license_key' => $licenseKey,
        ];
        $ip = self::serverIp();
        if ($ip !== '') {
            $params['ip'] = $ip;
        }

        return self::api($params);
    }

    /** 检查更新（token 可选；无授权时仍可能返回版本列表，但升级会被拒） */
    public static function checkUpdate(PDO $db, string $token = ''): array
    {
        $params = [
            'action'          => 'check_update',
            'app_id'          => self::appId($db),
            'current_version' => self::currentVersion($db),
        ];
        if ($token !== '') {
            $params['token'] = $token;
        }

        return self::api($params);
    }

    /** 获取指定版本的升级信息（下载链接 + db_upgrade SQL） */
    public static function upgradeInfo(PDO $db, string $version, string $token, string $type = 'update'): array
    {
        return self::api([
            'action'  => 'upgrade',
            'app_id'  => self::appId($db),
            'version' => $version,
            'token'   => $token,
            'type'    => $type === 'full' ? 'full' : 'update',
        ], 25);
    }

    // ==================== Token 管理 ====================

    /**
     * 获取可用 token（缓存优先；过期或未缓存则用授权码重新 verify）。
     * 授权码未配置或验证失败均返回 ok=false，不抛异常。
     *
     * @return array{ok:bool,token:string,msg:string,data:array}
     */
    public static function token(PDO $db, bool $force = false): array
    {
        $cached = get_setting($db, 'update_token', '');
        $expire = (int)get_setting($db, 'update_token_expire', '0');
        if (!$force && $cached !== '' && $expire > time() + 60) {
            return ['ok' => true, 'token' => $cached, 'msg' => '', 'data' => []];
        }

        $license = self::licenseKey($db);
        if ($license === '') {
            return ['ok' => false, 'token' => '', 'msg' => '尚未配置授权码，请先在下方填写并保存', 'data' => []];
        }

        $res = self::verify($db, $license);
        if ($res['code'] === 1 && !empty($res['data']['token'])) {
            $tok = (string)$res['data']['token'];
            set_setting($db, 'update_token', $tok);
            // token 有效期 24h，提前 1h 视为过期，为升级过程留足时间
            set_setting($db, 'update_token_expire', (string)(time() + 23 * 3600));
            set_setting($db, 'license_version_name', (string)($res['data']['version_name'] ?? ''));
            set_setting($db, 'license_expire_at', (string)($res['data']['expire_at'] ?? ''));
            set_setting($db, 'license_status', (string)($res['data']['status'] ?? 'authorized'));

            return ['ok' => true, 'token' => $tok, 'msg' => $res['msg'] !== '' ? $res['msg'] : '授权验证通过', 'data' => $res['data']];
        }

        // 验证失败：清理可能已失效的缓存
        set_setting($db, 'update_token', '');
        set_setting($db, 'update_token_expire', '0');
        set_setting($db, 'license_status', '');

        return ['ok' => false, 'token' => '', 'msg' => $res['msg'] !== '' ? $res['msg'] : '授权验证失败', 'data' => $res['data']];
    }

    /** 汇总当前授权 / 版本状态（供设置页与 AJAX 复用） */
    public static function status(PDO $db): array
    {
        $license      = self::licenseKey($db);
        $cachedExpire = (int)get_setting($db, 'update_token_expire', '0');

        return [
            'version'        => self::currentVersion($db),
            'code_version'   => defined('PROBE_VERSION') ? PROBE_VERSION : '',
            'app_id'         => self::appId($db),
            'domain'         => self::domain(),
            'api_base'       => self::apiBase(),
            'license_set'    => $license !== '',
            'license_mask'   => $license !== '' ? self::mask($license) : '',
            'version_name'   => get_setting($db, 'license_version_name', ''),
            'license_status' => get_setting($db, 'license_status', ''),
            'expire_at'      => get_setting($db, 'license_expire_at', ''),
            'token_valid'    => get_setting($db, 'update_token', '') !== '' && $cachedExpire > time() + 60,
            'last_update_at' => get_setting($db, 'update_last_at', ''),
            'zip_ext'        => class_exists('ZipArchive'),
            'curl_ext'       => function_exists('curl_init'),
            'root_writable'  => is_writable(self::siteRoot()) && is_writable(ADMIN_ROOT),
        ];
    }

    /** 授权码打码展示 */
    private static function mask(string $s): string
    {
        $n = strlen($s);
        if ($n <= 8) {
            return str_repeat('*', $n);
        }

        return substr($s, 0, 4) . str_repeat('*', max(4, $n - 8)) . substr($s, -4);
    }

    // ==================== 升级引擎 ====================

    /** 覆盖时始终排除的路径前缀（相对站点根，正斜杠），保护本地配置与数据 */
    private static function excludePrefixes(): array
    {
        return ['admin/config.php', 'admin/data/', 'uploads/', '.user.ini'];
    }

    private static function isExcluded(string $rel): bool
    {
        // 授权码只从数据库读取，不允许更新包重新生成公开的授权文件。
        if (strtolower(basename($rel)) === 'license.dat') {
            return true;
        }
        foreach (self::excludePrefixes() as $p) {
            if ($rel === $p || str_starts_with($rel, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 执行单个版本的完整升级。任一步失败返回 ok=false 且尽量回滚，绝不抛出 / 退出。
     *
     * 可选 $emit 回调用于「实时」推送每一步（SSE 流式）：
     *   $emit(['type'=>'step','text'=>…])                 进度步骤
     *   $emit(['type'=>'error','msg'=>…,'detail'=>[…]])   失败（含完整明细）
     * 未传 $emit 时行为与旧版一致（仅在返回体的 steps 里汇总）。
     *
     * @return array{ok:bool,version:string,msg:string,steps:array,backup:string,detail:array}
     */
    public static function applyUpgrade(PDO $db, string $version, string $type = 'update', ?callable $emit = null): array
    {
        $steps   = [];
        $zipPath = '';
        $zip     = null;
        // 进度：既写入 steps（供返回体汇总），又实时 emit（供 SSE 逐条推送）
        $log = static function (string $s) use (&$steps, $emit): void {
            $steps[] = $s;
            if ($emit) {
                $emit(['type' => 'step', 'text' => $s]);
            }
        };
        // 失败：统一清理临时资源（关闭 ZIP / 删除临时包），实时 emit 错误明细，并回传完整 steps
        $fail = static function (string $msg, array $detail = [], string $backup = '') use ($version, &$steps, &$zip, &$zipPath, $emit): array {
            if ($zip instanceof ZipArchive) {
                // 未成功 open() 的 ZipArchive 调 close() 会抛 ValueError（@ 抑制不了异常），必须 try/catch；
                // 否则该异常会冒泡并掩盖真正的失败原因（曾导致“更新包损坏”被 fatal ValueError 覆盖）。
                try {
                    $zip->close();
                } catch (Throwable $ignore) {
                    // 忽略：对象未初始化或已关闭
                }
                $zip = null;
            }
            if ($zipPath !== '' && is_file($zipPath)) {
                @unlink($zipPath);
                $zipPath = '';
            }
            if ($emit) {
                $emit(['type' => 'error', 'msg' => $msg, 'detail' => $detail, 'backup' => $backup]);
            }

            return ['ok' => false, 'version' => $version, 'msg' => $msg, 'steps' => $steps, 'backup' => $backup, 'detail' => $detail];
        };

        if (!class_exists('ZipArchive')) {
            return $fail('服务器未安装 Zip 扩展，无法解压更新包', ['stage' => 'env']);
        }

        try {
            // 1) 授权 token
            $log('[1/8] 正在校验授权…');
            $tk = self::token($db);
            if (!$tk['ok']) {
                return $fail('授权校验失败，无法升级：' . $tk['msg'], ['stage' => 'auth']);
            }
            $log('[1/8] 授权校验通过');

            // 2) 获取升级信息
            $log('[2/8] 正在获取 v' . $version . ' 的升级信息…');
            $info = self::upgradeInfo($db, $version, $tk['token'], $type);
            if ($info['code'] !== 1 || empty($info['data']['download_url'])) {
                return $fail($info['msg'] !== '' ? $info['msg'] : '获取升级信息失败', ['stage' => 'info', 'api_msg' => $info['msg'], 'api_data' => $info['data']]);
            }
            $downloadUrl = (string)$info['data']['download_url'];
            $dbUpgrade   = (string)($info['data']['db_upgrade'] ?? '');
            $log('[2/8] 已获取下载链接' . (trim($dbUpgrade) !== '' ? '（含数据库升级脚本）' : '（本版本无数据库脚本）'));

            // 3) 下载更新包
            $tmpDir = ADMIN_ROOT . '/data/tmp';
            if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true)) {
                return $fail('临时目录创建失败：' . $tmpDir, ['stage' => 'download', 'path' => $tmpDir]);
            }
            // 预检写权限：提前给出可自助排查的明确错误（避免到 download() 才报模糊的“无法写入”）
            if (!is_writable($tmpDir)) {
                return $fail(
                    '临时目录不可写：' . $tmpDir . '（请将归属改为 Web 运行用户并赋予写权限，例如：chown www:www "' . $tmpDir . '" && chmod 755）',
                    ['stage' => 'download', 'path' => $tmpDir, 'writable' => false, 'owner' => @fileowner($tmpDir), 'perms' => @substr(sprintf('%o', @fileperms($tmpDir)), -4)]
                );
            }
            $zipPath = $tmpDir . '/update_' . preg_replace('/[^0-9a-zA-Z._-]/', '', $version) . '_' . time() . '.zip';
            $log('[3/8] 开始下载更新包…');
            $dl = self::download($downloadUrl, $zipPath);
            if (!$dl['ok']) {
                return $fail('下载更新包失败：' . $dl['msg'], ['stage' => 'download', 'url' => $downloadUrl]);
            }
            $log('[3/8] 更新包下载完成（' . self::humanSize((int)filesize($zipPath)) . '）');

            // 4) 校验 ZIP 并规划条目
            $log('[4/8] 正在校验更新包完整性…');
            $zip     = new ZipArchive();
            $openRes = $zip->open($zipPath);
            if ($openRes !== true) {
                return $fail(
                    '更新包无法打开（ZipArchive 错误码 ' . var_export($openRes, true) . '，大小 ' . self::humanSize((int)@filesize($zipPath)) . '）：可能已损坏或下载不完整',
                    ['stage' => 'verify', 'path' => $zipPath, 'zip_open_code' => $openRes, 'size' => (int)@filesize($zipPath)]
                );
            }
            $plan = self::planEntries($zip);
            if ($plan['error'] !== '') {
                return $fail($plan['error'], ['stage' => 'verify']);
            }
            $log('[4/8] 更新包校验通过，待写入 ' . count($plan['files']) . ' 个文件');

            // 5) 备份将被覆盖的文件
            $log('[5/8] 正在备份将被覆盖的原文件…');
            $backup = self::backup($plan['files']);
            if (!$backup['ok']) {
                return $fail('备份失败，已中止升级：' . $backup['msg'], ['stage' => 'backup']);
            }
            $log('[5/8] 已备份 ' . count($backup['files']) . ' 个原文件' . ($backup['dir'] !== '' ? '（' . $backup['dir'] . '）' : ''));

            // 6) 执行数据库升级 SQL（逐条推送进度；失败返回每条语句的完整错误明细）
            if (trim($dbUpgrade) !== '') {
                $total = count(self::splitSql($dbUpgrade));
                $log('[6/8] 开始导入数据库升级脚本（共 ' . $total . ' 条语句）…');
                $sqlErrors = self::runSql($db, $dbUpgrade, static function (string $line) use ($log): void {
                    $log('        ' . $line);
                });
                if ($sqlErrors !== []) {
                    self::restore($backup['dir'], $backup['files']);

                    return $fail(
                        '数据库升级失败（' . count($sqlErrors) . ' 条语句出错），已回滚文件覆盖',
                        ['stage' => 'db', 'sql_errors' => $sqlErrors],
                        $backup['dir']
                    );
                }
                $log('[6/8] 数据库升级完成（' . $total . ' 条语句全部执行成功）');
            } else {
                $log('[6/8] 本版本无数据库变更，跳过');
            }

            // 7) 解压覆盖
            $log('[7/8] 正在写入程序文件…');
            $ex = self::extract($zip, $plan['files']);
            $zip->close();
            $zip = null;
            @unlink($zipPath);
            $zipPath = '';
            if (!$ex['ok']) {
                self::restore($backup['dir'], $backup['files']);

                return $fail('文件覆盖失败，已回滚：' . $ex['msg'], ['stage' => 'extract', 'written' => $ex['written']], $backup['dir']);
            }
            $log('[7/8] 文件覆盖完成（写入 ' . $ex['written'] . ' 个文件）');

            // 8) 写入新版本号
            set_setting($db, 'app_version', $version);
            set_setting($db, 'update_last_at', date('Y-m-d H:i:s'));
            set_setting($db, 'update_last_version', $version);
            $log('[8/8] 版本号已更新为 v' . $version);

            return ['ok' => true, 'version' => $version, 'msg' => 'v' . $version . ' 升级成功', 'steps' => $steps, 'backup' => $backup['dir'], 'detail' => []];
        } catch (Throwable $e) {
            // 兜底：任何未预期异常都转为「完整详细报错」（类型 / 位置 / 调用栈），并尽力清理临时资源
            return $fail(
                '升级过程发生未预期错误：' . $e->getMessage(),
                [
                    'stage'     => 'exception',
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
        }
    }

    /** 下载文件到本地（流式写入，失败自动清理） */
    private static function download(string $url, string $dest): array
    {
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return ['ok' => false, 'msg' => '更新包下载地址必须使用 HTTPS，请在授权服务器配置 HTTPS 下载链接'];
        }
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            return ['ok' => false, 'msg' => '无法写入临时文件：' . $dest . '（目录不可写，请确认 Web 运行用户对 ' . dirname($dest) . ' 有写权限）'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 15,
            // 严格校验信任链和证书域名；不允许因证书问题降级为明文或跳过验证。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok       = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false) {
            @unlink($dest);

            return ['ok' => false, 'msg' => $err !== '' ? $err : 'curl 执行失败'];
        }
        if ($httpCode >= 400) {
            @unlink($dest);

            return ['ok' => false, 'msg' => 'HTTP ' . $httpCode];
        }
        if (!is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);

            return ['ok' => false, 'msg' => '下载内容为空'];
        }
        // 校验 ZIP 魔数（PK\x03\x04 等）：下载服务器可能以 HTTP 200 返回错误页（HTML/JSON）而非压缩包，
        // 若不在此拦截，后续 ZipArchive::open() 失败会报“更新包损坏”，掩盖真实原因。
        $magic = (string)@file_get_contents($dest, false, null, 0, 4);
        if (strncmp($magic, "PK\x03\x04", 4) !== 0 && strncmp($magic, "PK\x05\x06", 4) !== 0 && strncmp($magic, "PK\x07\x08", 4) !== 0) {
            $snippet = self::headSnippet($dest);
            @unlink($dest);

            return ['ok' => false, 'msg' => '下载内容不是有效的 ZIP（HTTP ' . $httpCode . '，疑似下载服务器返回错误页或令牌失效）。响应开头：' . $snippet];
        }

        return ['ok' => true, 'msg' => ''];
    }

    /** 读取文件开头若干字节用于诊断：去除控制/二进制噪声，保留可见 ASCII 与中文，便于识别 HTML/JSON 错误页 */
    private static function headSnippet(string $path, int $len = 200): string
    {
        $s = (string)@file_get_contents($path, false, null, 0, $len);
        if ($s === '') {
            return '(空)';
        }
        $clean = preg_replace('/[^\x20-\x7E\x{4e00}-\x{9fff}]+/u', ' ', $s);
        if (!is_string($clean)) {
            $clean = preg_replace('/[^\x20-\x7E]+/', ' ', $s); // 非法 UTF-8 退回逐字节过滤
        }
        $clean = trim((string)$clean);

        return $clean === '' ? '(无可读文本，疑似二进制内容)' : $clean;
    }

    /**
     * 规划 ZIP 条目：自动剥离单一外层包裹目录，计算相对站点根的安全路径，
     * 拒绝目录穿越，过滤排除项。
     *
     * @return array{files:array,dirs:array,error:string}
     */
    private static function planEntries(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false) {
                $names[] = $n;
            }
        }
        $wrapper = self::detectWrapper($names);
        $root    = self::siteRoot();

        $files = [];
        $dirs  = [];
        foreach ($names as $i => $name) {
            $rel = ltrim(str_replace('\\', '/', $name), '/');
            if ($wrapper !== '' && str_starts_with($rel, $wrapper)) {
                $rel = substr($rel, strlen($wrapper));
            }
            $rel = self::normalizeRel($rel);
            if ($rel === null) {
                return ['files' => [], 'dirs' => [], 'error' => '更新包含非法路径（可能存在目录穿越）：' . $name];
            }
            if ($rel === '' || self::isExcluded($rel)) {
                continue;
            }
            if (str_ends_with($name, '/')) {
                $dirs[] = $rel;
                continue;
            }
            $files[] = ['index' => $i, 'rel' => $rel, 'target' => $root . '/' . $rel];
        }

        if ($files === []) {
            return ['files' => [], 'dirs' => $dirs, 'error' => '更新包内没有可写入的文件'];
        }

        return ['files' => $files, 'dirs' => $dirs, 'error' => ''];
    }

    /** 检测并返回需要剥离的单一外层包裹目录（形如 "xxx/"），无则返回空串 */
    private static function detectWrapper(array $names): string
    {
        // 站点根已知的顶层目录 / 文件，若外层恰是它们则不剥离（避免误伤只更新 admin/ 的包）
        $known = ['admin', 'css', 'js', 'images', 'fonts', 'uploads', 'data', 'index.php', 'favicon.ico'];
        $first = null;
        foreach ($names as $n) {
            $rel = ltrim(str_replace('\\', '/', $n), '/');
            if ($rel === '') {
                continue;
            }
            $seg = explode('/', $rel)[0];
            if ($first === null) {
                $first = $seg;
            } elseif ($first !== $seg) {
                return '';
            }
        }
        if ($first === null || $first === '' || in_array($first, $known, true)) {
            return '';
        }
        // 存在与外层同名的文件（而非纯目录），不视为包裹目录
        foreach ($names as $n) {
            if (ltrim(str_replace('\\', '/', $n), '/') === $first) {
                return '';
            }
        }

        return $first . '/';
    }

    /** 规范化相对路径；含 .. 等穿越返回 null，空路径返回 '' */
    private static function normalizeRel(string $rel): ?string
    {
        $rel   = str_replace('\\', '/', $rel);
        $rel   = ltrim($rel, '/');
        if ($rel === '') {
            return '';
        }
        $clean = [];
        foreach (explode('/', $rel) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                return null;
            }
            $clean[] = $p;
        }

        return implode('/', $clean);
    }

    /**
     * 备份将被覆盖的现有文件到 admin/data/backups/{时间戳}/，保留相对结构。
     *
     * @return array{ok:bool,dir:string,files:array,msg:string}
     */
    private static function backup(array $files): array
    {
        $dir = ADMIN_ROOT . '/data/backups/' . date('Ymd_His');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'dir' => '', 'files' => [], 'msg' => '备份目录创建失败'];
        }
        $backed = [];
        foreach ($files as $f) {
            if (!is_file($f['target'])) {
                continue; // 新文件无需备份
            }
            $bakPath = $dir . '/' . $f['rel'];
            $bakDir  = dirname($bakPath);
            if (!is_dir($bakDir) && !@mkdir($bakDir, 0755, true)) {
                return ['ok' => false, 'dir' => $dir, 'files' => $backed, 'msg' => '备份子目录创建失败'];
            }
            if (!@copy($f['target'], $bakPath)) {
                return ['ok' => false, 'dir' => $dir, 'files' => $backed, 'msg' => '备份文件复制失败：' . $f['rel']];
            }
            $backed[] = $f['rel'];
        }

        return ['ok' => true, 'dir' => $dir, 'files' => $backed, 'msg' => ''];
    }

    /** 从备份回滚（尽力而为，不抛出） */
    private static function restore(string $dir, array $rels): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $root = self::siteRoot();
        foreach ($rels as $rel) {
            $bak = $dir . '/' . $rel;
            if (is_file($bak)) {
                @copy($bak, $root . '/' . $rel);
            }
        }
    }

    /** 解压覆盖：先写临时文件再 rename，避免半途中断损坏原文件 */
    private static function extract(ZipArchive $zip, array $files): array
    {
        $written = 0;
        foreach ($files as $f) {
            $name   = $zip->getNameIndex($f['index']);
            $target = $f['target'];
            $tdir   = dirname($target);
            if (!is_dir($tdir) && !@mkdir($tdir, 0755, true)) {
                return ['ok' => false, 'written' => $written, 'msg' => '目录创建失败：' . $tdir];
            }

            $stream = $name === false ? false : $zip->getStream($name);
            if (!$stream) {
                return ['ok' => false, 'written' => $written, 'msg' => '读取压缩包条目失败：' . $f['rel']];
            }
            $stat     = $zip->statIndex($f['index']);
            $expected = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;

            $tmpTarget = $target . '.updtmp';
            $out       = @fopen($tmpTarget, 'wb');
            if (!$out) {
                fclose($stream);

                return ['ok' => false, 'written' => $written, 'msg' => '目标文件不可写：' . $f['rel']];
            }
            $readError = false;
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) { $readError = true; break; }
                if ($chunk !== '') { fwrite($out, $chunk); }
            }
            fclose($stream);
            fclose($out);

            // 读取中断或大小与压缩包声明不符 → 视为损坏，清理临时文件并失败（触发回滚）
            clearstatcache(true, $tmpTarget);
            $actual = (int)@filesize($tmpTarget);
            if ($readError || ($expected > 0 && $actual !== $expected)) {
                @unlink($tmpTarget);

                return ['ok' => false, 'written' => $written, 'msg' => '写入校验失败（数据不完整）：' . $f['rel']];
            }

            if (!@rename($tmpTarget, $target)) {
                @unlink($tmpTarget);

                return ['ok' => false, 'written' => $written, 'msg' => '替换文件失败：' . $f['rel']];
            }
            $written++;
        }

        return ['ok' => true, 'written' => $written, 'msg' => ''];
    }

    // ==================== SQL 工具 ====================

    /**
     * 执行 SQL 脚本（按语句分割）。可选 $log 回调逐条推送进度（用于 SSE 实时展示导入过程）。
     * 返回「结构化」错误数组（空 = 全部成功）：每项含 index / sql / message / error_info，
     * 便于前端展示完整详细报错（出错的是第几条、原始 SQL、PDO 错误码与信息）。
     *
     * @return array<int, array{index:int, sql:string, message:string, error_info:array}>
     */
    public static function runSql(PDO $db, string $sql, ?callable $log = null): array
    {
        $errors     = [];
        $statements = self::splitSql($sql);
        $total      = count($statements);
        foreach ($statements as $i => $stmt) {
            $no = $i + 1;
            try {
                $db->exec($stmt);
                if ($log) {
                    $log('· SQL ' . $no . '/' . $total . ' 执行成功：' . self::sqlHead($stmt));
                }
            } catch (PDOException $e) {
                $errors[] = [
                    'index'      => $no,
                    'sql'        => $stmt,
                    'message'    => $e->getMessage(),
                    'error_info' => $db->errorInfo(),
                ];
                if ($log) {
                    $log('✘ SQL ' . $no . '/' . $total . ' 执行失败：' . $e->getMessage());
                }
            }
        }

        return $errors;
    }

    /** SQL 语句摘要（折叠空白 + 截断），用于日志逐条展示，避免超长语句刷屏 */
    private static function sqlHead(string $stmt, int $max = 90): string
    {
        $s = trim((string)preg_replace('/\s+/', ' ', $stmt));
        if (function_exists('mb_substr')) {
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
        }

        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    /**
     * 将 SQL 脚本拆分为独立语句：正确忽略行注释(-- / #)、块注释、
     * 以及单引号 / 双引号 / 反引号内（含反斜杠转义）的分号。
     */
    public static function splitSql(string $sql): array
    {
        $sql  = str_replace(["\r\n", "\r"], "\n", $sql);
        $len  = strlen($sql);
        $out  = [];
        $buf  = '';
        $inS  = $inD = $inB = false;   // 单引号 / 双引号 / 反引号
        $inL  = $inBlk = false;        // 行注释 / 块注释

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inL) {
                if ($c === "\n") { $inL = false; $buf .= $c; }
                continue;
            }
            if ($inBlk) {
                if ($c === '*' && $n === '/') { $inBlk = false; $i++; }
                continue;
            }
            // 字符串内的反斜杠转义：连同下一字符原样保留
            if (($inS || $inD) && $c === '\\') {
                $buf .= $c . $n;
                $i++;
                continue;
            }
            if (!$inS && !$inD && !$inB) {
                if ($c === '-' && $n === '-') { $inL = true; $i++; continue; }
                if ($c === '#') { $inL = true; continue; }
                if ($c === '/' && $n === '*') { $inBlk = true; $i++; continue; }
            }
            if ($c === "'" && !$inD && !$inB) { $inS = !$inS; $buf .= $c; continue; }
            if ($c === '"' && !$inS && !$inB) { $inD = !$inD; $buf .= $c; continue; }
            if ($c === '`' && !$inS && !$inD) { $inB = !$inB; $buf .= $c; continue; }
            if ($c === ';' && !$inS && !$inD && !$inB) {
                $t = trim($buf);
                if ($t !== '') { $out[] = $t; }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $t = trim($buf);
        if ($t !== '') { $out[] = $t; }

        return $out;
    }

    /** 字节数转可读大小 */
    public static function humanSize(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($b >= 1024 && $i < 3) {
            $b /= 1024;
            $i++;
        }

        return round($b, $i > 0 ? 1 : 0) . ' ' . $u[$i];
    }
}
