# 服务器探针监控系统（Server Probe Admin）

基于 **Light Year Admin V5**（Bootstrap 5）后台模板 + **PHP 8.0+** 的服务器/网站状态监控系统。

支持探测方式：

| 方式 | 说明 |
|------|------|
| PING | ICMP 连通性与平均延迟（优先纯 PHP Socket 实现，无权限时降级 exec 系统 ping） |
| TCP | 端口连通性检测（如 80/443/3306 等） |
| UDP | UDP 端口探测（依据 ICMP 拒绝/超时判断） |
| SSH | 优先 ssh2 扩展做登录验证；未安装 ssh2 时降级为 SSH Banner 检测 |
| HTTP(S) | cURL 请求网站，检测状态码与响应延迟 |

## 目录结构

```
admin/
├── bootstrap.php          # 引导文件（配置/Session/数据库）
├── config.php             # 数据库等全局配置
├── install.php            # 安装向导（建表+初始管理员）
├── login.php / logout.php / captcha.php
├── index.php              # 监控总览（仪表盘）
├── servers.php            # 服务器管理（增删改查）
├── logs.php               # 探测日志
├── settings.php           # 系统设置 / 运行环境检测
├── edit_pwd.php           # 修改密码
├── api/probe.php          # AJAX 探测接口（status / check / check_all）
├── cron/probe.php         # 定时探测脚本（CLI）
├── includes/
│   ├── db.php             # PDO（sqlite / mysql）
│   ├── auth.php           # 登录认证
│   ├── functions.php      # 公共函数
│   └── probe.php          # 探测引擎（核心）
├── templates/             # header / sidebar / footer 布局
└── data/                  # sqlite 数据库与运行数据（禁止外部访问）
```

## 安装步骤

1. 宝塔面板将站点的 PHP 版本设置为 **8.0 ~ 8.4**。
2. 浏览器访问 `https://你的域名/admin/install.php`，按向导完成环境检查、数据库配置与管理员设置。
3. 登录后在「服务器管理」添加监控节点。
4. 配置计划任务（每分钟执行）：

   ```bash
   /www/server/php/81/bin/php /www/wwwroot/你的站点目录/admin/cron/probe.php
   ```

   （81 换成站点实际使用的 PHP 版本，如 PHP 8.4 为 `/www/server/php/84/bin/php`）

5. 安装完成后建议删除 `install.php`。

## 预警功能

在「系统设置 → 预警设置」中配置：

- **离线预警**：节点连续 X 次检测失败时触发（失败恢复后计数自动清零）
- **SSL 预警**：网站类型节点证书剩余 ≤ X 天时触发（已过期也会触发）
- **频率限制**：每 X 天内同一节点同一类型最多预警 X 次，避免骚扰
- **时间窗口**：仅在设定时段内发送（支持跨天，如 22:00–08:00）
- **通知渠道**：
  - SMTP 邮件：支持 SSL(465)/STARTTLS(587)/不加密(25)，AUTH LOGIN 认证，可发测试邮件
  - Webhook：POST JSON（含 type/server/message/time 等字段），可选 `X-Probe-Token` 密钥头
- 预警发送记录见「预警日志」页面（含各渠道发送结果）

预警在每次探测后自动评估（定时任务 / 手动探测均会触发）。

## 数据库

默认使用 **SQLite**（`admin/data/probe.db`），零配置开箱即用。
如需 MySQL，修改 `admin/config.php` 中 `db.type` 为 `mysql` 并填写连接信息，然后重新执行安装向导。

## 安全建议（重要）

1. 在站点 Nginx 配置中禁止直接访问数据目录与安装脚本：

   ```nginx
   location ~* ^/admin/data/ { deny all; }
   location = /admin/install.php { deny all; }   # 安装完成后
   ```

2. 生产环境可将 `bootstrap.php` 中 `display_errors` 关闭。
3. SSH 密码以明文保存在数据库中（仅用于探测登录验证），如不需要登录验证可只填端口、留空账号密码（降级为服务可用性检测）。

## 依赖环境

- PHP 8.0+（含 pdo_sqlite 或 pdo_mysql、gd、curl、mbstring、sockets）
- PING 探测优先使用纯 PHP ICMP Socket（需 sockets 扩展，无需 exec）；IPv6 或降级场景才需要 `exec`
- SSH 登录验证需安装 ssh2 扩展（可选）
