# 链匣 LinkVault

你的链接，收放自如。

Your links, in your control.

链匣 LinkVault 是一个按 PHP 8.5 编写的自用短链接服务，无需 Composer，使用 SQLite 保存数据。

## 环境要求

- PHP 8.5
- 启用 `pdo_sqlite` 和 `sqlite3` 扩展，SQLite 构建需包含 FTS5；启用定期目标健康检查时还需 `curl` 扩展
- 备份与恢复操作需要可从 `PATH` 调用的 `sqlite3` CLI；生产异地备份还需要 `age` 和 `rclone`

## 功能

- 管理密码登录
- 生成随机短码或自定义短码
- 短链接跳转
- 跳转次数统计
- 标签、收藏、状态筛选、排序和批量操作
- 7/14/30 天单链接趋势、创建/最后访问时间和状态变更记录
- 重复目标地址提示与复用确认
- 二维码、系统分享和一键复制
- 编辑、停用/启用、定时启用、快捷有效期、点击上限和一次性链接；一次性链接可确认访问后再消费
- 可选链接访问密码：哈希存储、独立限速、失败审计和一次性解锁会话
- 链接停用、过期或次数耗尽后可显示自定义说明或跳转备用地址
- 按标题、短码、标签或目标地址搜索，超过 100 条时分页
- 回收站、恢复和永久删除
- JSON 导入 Dry Run、错误预览、按短码冲突跳过/覆盖/生成新短码的合并模式、字段差异预览，以及全部/当前视图/所选链接导出与不可恢复的审计数据快照
- 书签脚本和带 `links:create`、`links:read`、`links:write`、`links:delete` 作用域的 Bearer Token API；Token 支持轮换、失效时间和使用记录
- 按 UTC 自然日、当前视图聚合的跳转统计
- 连续 14 日趋势、热门链接、状态分布和零点击链接统计
- 匿名访问画像：浏览器本地时区日期范围、上一周期对比，以及链接、标签、活动、来源、媒介、设备、地区和流量类型联动筛选
- 可点击趋势和画像下钻；增长最快、下降最多、机器人占比最高、首次有流量及长期无流量榜单
- 趋势、来源、设备、地区、活动和当前筛选结果 CSV；活动字段按访问发生时的快照归因
- 链接维护工作区：即将过期、长期零点击、次数即将耗尽、已失效和目标健康异常链接
- 常用筛选保存，以及即将过期、配额临界、长期零点击和备份异常的每日 Webhook 通知
- 已完成自然周或自然月的经营摘要订阅：新增链接、点击趋势、自动化异常来源、失效链接、热门链接、目标健康和备份状态可投递至邮件或 Webhook
- 链接创建、即将失效、停用和目标异常的签名生命周期 Webhook；事件通过事务性 outbox 投递并支持重试
- 多短链域名、DNS TXT 所有权验证、域名启停和按域名配置品牌名称、标语与固定主题
- 全局操作审计，以及含发布版本、合成监控、数据库、Schema、磁盘、备份、API、恢复演练的状态中心
- 关键字段 before/after 结构化审计；累计点击永久保留，日统计可按策略归档或删除
- API `Idempotency-Key`、OpenAPI 3.1 文档和统一错误码
- Chromium 浏览器扩展：自动标签建议、`Ctrl/Cmd + Shift + L` 一键保存、右键保存、快捷搜索、高级创建字段、离线队列和链接健康提示；以及管理页 `Ctrl/Cmd + K` 快捷命令、重复链接合并和批量标签规则
- 带 `conversions:write` 作用域、时间戳 HMAC 签名和强制幂等校验的转化事件 API，以及漏斗转化率
- 公开恶意链接举报、独立举报配额、域名黑名单、风险扫描与可审计的滥用处置流程
- 可选 TOTP 管理员第二因素，以及单次使用的离线恢复码
- 定期在隔离数据库执行自动恢复演练
- 定期执行带 DNS 全量校验、IP 固定和手动重定向策略的目标地址健康检查
- SQLite 本地存储
- `/livez`、`/readyz` 和 `/healthz` 分层健康检查

## 本地运行

```powershell
$securePassword = Read-Host "输入管理密码" -AsSecureString
$env:LINKVAULT_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
php bin/doctor.php
php bin/migrate.php
php bin/build-assets.php
php -S 127.0.0.1:8080 -t public public/router.php
```

本地查看访问分析时，在另一个 PowerShell 窗口启动聚合循环：

```powershell
while ($true) { php bin/aggregate-analytics.php; Start-Sleep -Seconds 300 }
```

本地路由器只把短链 GET/HEAD 与确认 POST 写入匿名分析日志，不记录 IP、查询字符串、Cookie 或完整来源 URL。生产环境仍由 Nginx/Caddy 采集，并由系统定时器聚合。

`bin/doctor.php` 是只读安装检查：集中显示 PHP 版本与扩展、SQLite FTS5、迁移、目录权限、公开 HTTPS、备份工具和 Linux systemd 定时器状态，并按当前失败项输出下一步命令。存在阻塞项时退出码为 `1`，仅有建议项时仍可继续本地开发。

浏览器打开主页：`http://127.0.0.1:8080`；管理登录入口为 `http://127.0.0.1:8080/login`。

应用没有默认密码。未配置强密码时，服务会返回 `503`，不会进入登录页或处理短链接跳转。

## 快速创建 API

登录后可在“系统状态”中创建带名称、作用域、可选失效时间、独立窗口配额和 CIDR 白名单的 Token；明文只显示一次，数据库仅保存 SHA-256 摘要。管理页支持立即轮换或吊销，并展示使用次数、最近使用时间、最近鉴权记录及 CIDR 拒绝或配额耗尽告警。轮换后的 Token 继承原配额和 CIDR 策略。已有 Token 默认只有 `links:create`，不会因迁移自动获得读取或修改权限。

`GET /api/links` 和 `GET /api/links/{id}` 需要 `links:read`；`PATCH /api/links/{id}` 与 `POST /api/links/{id}/disable` 需要 `links:write`；`DELETE /api/links/{id}` 需要 `links:delete`。修改、停用和删除必须携带资源最新的 `If-Match` ETag；删除是移入回收站，不是永久删除。短链域名字段只能选择已验证且启用的域名。

现有部署仍可通过环境变量配置一个独立且至少 24 位的兼容 Token：

```powershell
$env:LINKVAULT_API_TOKEN = "replace-with-a-long-random-token"
```

```bash
curl -X POST https://s.example.com/api/shorten \
  -H 'Authorization: Bearer replace-with-a-long-random-token' \
  -H 'Idempotency-Key: 6f45b837-0277-4aa0-92dd-dfe88a2f96ee' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/long/path","title":"文档","tags":["工作","文档"]}'
```

请求体上限为 64 KiB，且必须使用 `application/json`。可选字段为字符串类型的 `slug`、`expires_at`、`starts_at`、`one_time_mode`、`campaign_name`、`source`、`medium`、`content`，整数类型的 `max_clicks`，以及布尔类型的 `one_time`、`favorite` 和 `force`；`tags` 可为字符串或字符串数组。活动字段会自动写入目标地址对应的 `utm_*` 参数。`one_time_mode` 支持 `immediate`（首次访问即消费）和 `confirm`（确认访问后消费），仅在 `one_time=true` 时生效。默认遇到重复目标地址会返回已有短链接，只有 JSON 布尔值 `force=true` 才继续创建。管理页“快速创建”区域提供可复制的书签脚本。

`Idempotency-Key` 可选，默认保留 24 小时。相同键和相同规范化参数会返回首次成功请求保存的状态码与响应体，并设置 `Idempotency-Replayed: true`；同一键用于不同参数返回 `409 idempotency_conflict`。服务只保存键的 SHA-256 摘要，不保存原始键。完整契约见 [OpenAPI 3.1](docs/openapi.json)，错误码见 [API 错误码](docs/api-errors.md)。

轮换数据库 Token 时可设置最长 24 小时的新旧 Token 并行窗口，默认 15 分钟；旧 Token 到达过渡截止时间后自动失效，新 Token 的自然失效时间独立设置。已过期或已吊销 Token 的请求会返回 `401 invalid_token`，不会占用业务配额或写入使用记录。环境变量 Token 不能在页面中轮换，迁移到数据库 Token 后应从进程环境中删除旧值并重启服务。

## 管理员第二因素与恢复

TOTP 为可选功能。启用前先通过进程环境配置至少 32 位、独立随机生成的 `LINKVAULT_SECURITY_KEY`，并确保 Web 进程和命令行任务读取同一值。系统状态页可开始设置，验证首个动态口令后会生成 10 个恢复码；TOTP 密钥使用 AES-256-GCM 加密保存，恢复码仅保存 SHA-256 摘要。

这是单管理员恢复路径：登录页的第二因素输入可填写任一未使用恢复码，成功后该码立即作废。恢复登录后应检查安全密钥、重新设置 TOTP 或重置恢复码。恢复码只在生成时显示一次，应与数据库备份和 `LINKVAULT_SECURITY_KEY` 分开离线保存；密钥丢失时动态口令无法解密，但恢复码仍可登录并停用旧 TOTP。

## 修改密码

密码至少需要 12 位，并包含小写字母、大写字母、数字、特殊字符中的至少三类。推荐只通过环境变量设置：

```powershell
$securePassword = Read-Host "输入管理密码" -AsSecureString
$env:LINKVAULT_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
php -S 127.0.0.1:8080 -t public public/router.php
```

不要把真实密码提交到版本库或写入公开文档。

## 部署到服务器

1. 把项目上传到服务器。
2. Web 根目录指向 `public`。
3. 确认 PHP 已启用 `pdo_sqlite` 和 `sqlite3` 扩展；启用目标健康检查或配置任一 Webhook 时同时启用 `curl`。
4. 通过进程管理器、容器 Secret 或系统环境变量设置 `LINKVAULT_ADMIN_PASSWORD`。
5. 首次部署及每次升级后运行 `php bin/build-assets.php`，生成带内容哈希的 CSS、JS、字体、SVG 和 `public/assets/manifest.json`。
6. 首次部署及每次升级后，在启动 Web 流量前运行 `php bin/migrate.php`。Web 请求不会自动建表或修改表结构；迁移命令可重复执行。生产服务器可使用下方的 systemd 单次任务，让迁移与 PHP-FPM 读取同一环境文件。
7. 生产环境始终设置固定公开地址。只有请求 Host 为 `localhost`、`127.0.0.1` 或 `::1`，且直连对端地址也属于 loopback 时，本机开发才可省略：

```bash
LINKVAULT_BASE_URL=https://s.example.com
```

应用会拒绝与该地址域名或端口不匹配的 `Host`。Nginx 示例也会拒绝未知 Host，并使用固定域名生成 HTTP 到 HTTPS 跳转。

自定义短链域名在“系统状态”中添加。为 `_linkvault-challenge.<域名>` 配置页面给出的 TXT 值，验证成功后才能选择该域名创建链接；管理、API 和健康检查路由仍只允许 `LINKVAULT_BASE_URL`。Nginx/Caddy 的 `server_name` 或站点标签及 TLS 证书必须显式加入同一域名，不能使用接受任意 Host 的 catch-all。当前短码在所有域名间全局唯一。

反向代理部署还必须通过 `LINKVAULT_TRUSTED_PROXIES` 配置代理直连 IP，多个 IP 用英文逗号分隔，例如 `127.0.0.1,10.0.0.10`。只有来自这些 IP 的 `X-Forwarded-Proto` 和 `X-Forwarded-For` 才会用于 `Secure` Cookie 判断和登录 IP 限速；代理必须覆盖而不是追加客户端传入的同名请求头。API 边缘限速用于保护入口容量，应用层的业务配额只在 Token 鉴权成功后按 Token 独立计数。

仓库提供 [Nginx](deploy/nginx.conf) 和 [Caddy](deploy/Caddyfile) 示例，使用前需要替换域名、项目路径和 PHP-FPM Socket；使用上游代理时还要把 Caddy 的 `trusted_proxies` 示例地址替换为准确的代理直连 IP。每次新增、启用或停用短链域名，以及每次调整可信代理时，先运行 `php bin/check-deployment-domains.php --server=caddy --config=/etc/caddy/Caddyfile` 或 `--server=nginx --config=/etc/nginx/sites-enabled/linkvault.conf`；`--generate` 可输出待审核的域名/代理清单。两份配置只允许 FastCGI 执行 `index.php`；Apache 的 [`.htaccess`](public/.htaccess) 同样拒绝其他 PHP 文件。Caddy 限速示例需要使用 `xcaddy build --with github.com/mholt/caddy-ratelimit` 构建。带内容哈希的资源缓存一年并标记 `immutable`，未哈希源资源只缓存一小时，动态 HTML/API 响应不缓存；Nginx 对可压缩静态类型启用 gzip，Caddy 使用 zstd/gzip。

Nginx/Caddy 示例同时对 `/login` 设置单客户端及整站限速，并对合法短码路径设置每客户端 20 RPS、全局 200 RPS 的起始上限；容量测试后应按 PHP-FPM worker、SQLite 写入延迟和实际流量调整。Nginx 另有限制单 IP 与整站连接数，并把登录请求写入专用的 `linkvault-security.log`。直接面向客户端的边缘服务器可把 [Fail2ban 配置](deploy/fail2ban) 安装到 `/etc/fail2ban`，然后只启用与当前 Web 服务器对应的 jail；规则会根据连续的登录 `429` 响应封禁来源一小时。部署在上游代理后时，必须先可靠还原真实客户端 IP，并把封禁动作接入上游代理或防火墙；不要在应用主机上直接封禁代理直连 IP。

环境变量应配置在 PHP-FPM 的 systemd 服务、池配置或容器 Secret 中；如果 PHP-FPM 使用默认的 `clear_env = yes`，需要用池配置的 `env[LINKVAULT_ADMIN_PASSWORD]`、`env[LINKVAULT_BASE_URL]` 等条目显式传入。仓库提供独立的 [PHP-FPM pool](deploy/php-fpm-linkvault.conf)、[systemd 环境传递配置](deploy/php-fpm-linkvault.service.conf)、[迁移任务](deploy/linkvault-migrate.service) 和 [生产预检任务](deploy/linkvault-preflight.service)。Nginx 和 Caddy 示例均已连接该独立 pool。

Debian/Ubuntu 的安装示例（服务名及 PHP 版本需按系统调整）：

```bash
sudo install -d -o root -g root -m 0755 /etc/linkvault
sudo useradd --system --home /nonexistent --shell /usr/sbin/nologin linkvault-backup
sudo install -d -o www-data -g www-data -m 0750 /var/lib/linkvault /var/log/linkvault
sudo install -d -o linkvault-backup -g linkvault-backup -m 0700 /var/backups/linkvault
sudo install -d -o linkvault-backup -g www-data -m 2750 /var/lib/linkvault-backup-status
sudo install -o root -g root -m 0600 deploy/linkvault.env.example /etc/linkvault/linkvault.env
sudoedit /etc/linkvault/linkvault.env
# Required when LINKVAULT_RESTORE_DRILL_SOURCE=remote. Keep both root-only.
sudo install -o root -g root -m 0400 /secure/offline/age-identity /etc/linkvault/restore-age-identity
sudo install -o root -g root -m 0400 /secure/rclone.conf /etc/linkvault/restore-rclone.conf

sudo install -m 0644 deploy/php-fpm-linkvault.conf /etc/php/8.5/fpm/pool.d/linkvault.conf
sudo install -d -m 0755 /etc/systemd/system/php8.5-fpm.service.d
sudo install -m 0644 deploy/php-fpm-linkvault.service.conf /etc/systemd/system/php8.5-fpm.service.d/linkvault.conf
sudo install -m 0644 deploy/linkvault-migrate.service deploy/linkvault-preflight.service /etc/systemd/system/
sudo systemctl daemon-reload

sudo systemctl restart php8.5-fpm
sudo nginx -t && sudo systemctl reload nginx
curl --fail --silent --show-error https://s.example.com/healthz
```

环境文件中的 `REPLACE_ME`、示例域名和示例 rclone 目标必须替换；管理密码至少 12 位并满足前述复杂度。Nginx 直接面向客户端时保持 `LINKVAULT_TRUSTED_PROXIES` 为空；只有存在 CDN 或负载均衡时才填写其实际直连 IP。PHP-FPM 的 drop-in 使用 `Requires=` 和 `After=` 启动迁移、再执行生产预检；任一任务失败都会阻止 PHP-FPM 启动。`linkvault-preflight.service` 会以 `www-data` 和同一环境文件检查占位值、密码策略、HTTPS 公网地址、代理 IP、备份与告警目标、文件权限、数据库完整性、外键和当前 schema。重启后 `/healthz` 返回 200 才能证明实际处理请求的 PHP-FPM worker 也继承了配置。

生产环境必须在 PHP-FPM 使用的 `php.ini` 或池配置中设置 `display_errors = Off` 和 `display_startup_errors = Off`，并开启 `log_errors = On`。可参考 [deploy/php-production.ini](deploy/php-production.ini)。应用入口也会关闭错误展示，但 PHP 启动及语法错误发生在入口执行前，只能由 PHP-FPM 配置避免泄露路径和堆栈。

数据库默认创建在 `data/linkvault.sqlite`，可通过 `LINKVAULT_DATABASE_PATH` 修改位置。应用日志默认写入 `data/application.log`，可通过 `LINKVAULT_LOG_PATH` 修改位置；登录失败、登录锁定、限速存储异常、跳转统计更新异常和未捕获错误都会以 JSON Lines 写入该日志。认证风控事件中的来源 IP 仅保留 IPv4 `/24` 或 IPv6 `/64` 网段标识，不保留 User-Agent；日志用于检测暴力尝试、调查安全事件和验证限速策略，不用于用户画像。认证风控日志默认按日轮转并保留 7 天，访问应限制给安全运维人员，超期日志应从在线目录和备份中删除。每条日志都带有 `LINKVAULT_RELEASE_VERSION`、`LINKVAULT_BUILD_TIME` 和当前 Schema 版本；响应中的 `X-Request-ID` 和错误页请求编号可与日志的 `request_id` 对照。日志中不记录密码。生产环境应使用系统日志轮转工具限制日志大小和保留时间，并监控 `login_failed`、`login_blocked`、`unhandled_exception`、`fatal_error` 及数据库错误事件。

应用会对来源 IP 和当前会话分别限速：15 分钟内失败 5 次后锁定 15 分钟。登录请求不再在 PHP worker 内同步等待，公网部署必须保留上述代理层限速。认证会话的空闲超时为 30 分钟，绝对超时为 8 小时。

管理请求的 SQLite 锁等待预算默认为 5 秒并最多重试 3 次；短链接跳转使用 250 毫秒锁等待和 2 次尝试。跳转统计是尽力而为的，统计写入失败时会记录日志但仍返回有效的 302 跳转，不让统计锁竞争阻塞用户访问。

会话 Cookie 显式启用 `HttpOnly`、`SameSite=Lax` 和严格会话模式。在 HTTPS 请求下会自动启用 `Secure`；应用本身不强制跳转 HTTPS，公网部署仍应在 Web 服务器或反向代理层提供 HTTPS。

应用还会统一发送 CSP、`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy` 和 `Permissions-Policy`；HTTPS 请求会发送 HSTS。默认管理域名的 HSTS 包含子域名，自定义短链域名只发送当前主机策略。启用公网 HTTPS 前应确认默认主域下的子域名都支持 HTTPS。普通请求和公开健康探针只检查 `PRAGMA user_version`；完整表、索引、外键和触发器校验由迁移命令、生产预检及登录后的系统状态页执行。

## 工程与运维

入口文件只负责启动和路由分发。启动配置与通用安全逻辑位于 `app/bootstrap.php`，链接 CRUD、统计、导入导出位于 `app/LinkService.php`，Token 生命周期位于 `app/ApiTokenService.php`，页面模板位于 `templates/`，浏览器资源位于 `public/assets/`。数据库迁移按 `migrations/001_*.sql` 到当前版本连续执行，升级前后都应运行 `php bin/migrate.php`。

导入接受 `kind=link_export` 的 `version=1`、`version=2` 或域名感知的 `version=3` 文件，不接受审计数据快照或无类型 JSON。v2 会迁移 `password_protected`、`invalid_message` 和 `fallback_url`；密码材料不会导出，因此受保护链接导入后保持停用，并由持久化状态强制要求重新设置密码后才能启用。冲突策略在上传时固定并写入 Dry Run 计划：`skip` 跳过已有短码，`overwrite` 只覆盖可迁移字段并保留记录 ID、点击统计、回收站状态和本地访问保护，`new_slug` 预览确定性生成的新短码。确认阶段会在 `BEGIN IMMEDIATE` 中重新校验记录指纹和短码占用，任何陈旧计划整体拒绝。限制为 2 MiB、5000 条记录和每条目标 URL 2048 字节；JSON 仍会先在内存中解析，因此应根据 PHP `memory_limit` 保留余量。

健康检查不需要登录：`GET /livez` 只确认 PHP 进程可响应；`GET /readyz` 检查强密码、数据库版本与读取、数据库文件和目录可写性及磁盘剩余空间，全程不申请 SQLite 写锁；`GET /healthz` 在 readiness 上再根据 `.last-local-success.json` 校验备份文件存在性、大小、SHA-256 和时效，异地备份设为必需时还要求异地成功标记有效。三个响应都包含发布版本、构建时间和支持的 Schema 版本。Nginx 示例为后两个探针配置了独立的单 IP 与全局限速。失败统一返回 HTTP `503`，响应不暴露路径或异常。固定域名部署仍会拒绝不匹配的 `Host`。数据库文件缺失、只读、磁盘满、I/O 错误或损坏时，公共短链也返回 `503`；只有数据库可读取且确认 slug 不存在或不可用时才返回 `404`。

应用日志是 JSON Lines，默认写入 `data/application.log`，开发服务器日志也写在 `data/`。生产环境安装 [deploy/linkvault-logrotate.conf](deploy/linkvault-logrotate.conf)，并按实际 `LINKVAULT_LOG_PATH` 修改文件头；应用日志保留 7 个压缩轮次、单文件超过 10 MiB 时轮转，其中包含的认证风控事件也不得超过 7 天。分析原始日志使用 rename/reopen 轮转并默认保留 30 个未压缩日轮次，聚合器会先排空旧 inode 再切换新文件；游标还保存偏移前的内容检查点，可识别旧 `copytruncate` 配置导致的同 inode 截断再增长，并从新活动文件起点恢复消费。生产配置仍禁止 `copytruncate`，因为复制与截断之间写入的记录无法由消费者恢复。Caddy 示例关闭了分析日志的内建轮转，确保只有该策略管理文件。日志目录不应放在 Web 可下载路径，`.gitignore` 已排除运行日志、备份和恢复演练目录。

自动备份使用 SQLite 在线 `.backup`，不会直接复制处于 WAL 模式的主库。`bin/backup.php` 会执行完整性、应用 schema 和外键校验；生产模式随后用 age 收件人公钥加密，通过 `rclone copyto --immutable` 上传对象存储并核对远端对象大小，最后原子更新成功标记。日常备份主机只需要公钥；远端恢复演练使用的 age 私钥和 rclone 配置应由专用恢复主机或受控 systemd credential 提供，不能放进应用环境文件。远端桶应开启版本控制、生命周期和可选的对象锁；不要用 `rclone sync` 传播本地保留清理。Linux systemd 安装示例：

```bash
sudo install -m 0644 deploy/linkvault-backup.service deploy/linkvault-backup.timer \
  deploy/linkvault-backup-age.service deploy/linkvault-backup-age.timer \
  deploy/linkvault-restore-drill.service deploy/linkvault-restore-drill.timer \
  deploy/linkvault-endpoint-monitor.service deploy/linkvault-endpoint-monitor.timer \
  deploy/linkvault-maintenance-notify.service deploy/linkvault-maintenance-notify.timer \
  deploy/linkvault-lifecycle-webhook.service deploy/linkvault-lifecycle-webhook.timer \
  deploy/linkvault-domain-retirement.service deploy/linkvault-domain-retirement.timer \
  deploy/linkvault-data-cleanup.service deploy/linkvault-data-cleanup.timer \
  deploy/linkvault-stats-retention.service deploy/linkvault-stats-retention.timer \
  deploy/linkvault-analytics.service deploy/linkvault-analytics.timer \
  deploy/linkvault-analytics-export.service deploy/linkvault-analytics-export.timer \
  deploy/linkvault-analytics-rollup-backfill.service \
  deploy/linkvault-analytics-anomaly.service deploy/linkvault-analytics-anomaly.timer \
  deploy/linkvault-target-health.service deploy/linkvault-target-health.timer \
  deploy/linkvault-notify@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo install -d -o www-data -g www-data -m 0700 /var/lib/linkvault-restore-drill
sudo install -o root -g root -m 0400 /secure/offline/age-identity /etc/linkvault/restore-age-identity
sudo install -o root -g root -m 0400 /secure/rclone.conf /etc/linkvault/restore-rclone.conf
sudo systemctl enable --now linkvault-backup.timer linkvault-backup-age.timer \
  linkvault-restore-drill.timer linkvault-endpoint-monitor.timer \
  linkvault-maintenance-notify.timer linkvault-lifecycle-webhook.timer linkvault-domain-retirement.timer linkvault-data-cleanup.timer \
  linkvault-stats-retention.timer linkvault-analytics.timer linkvault-analytics-export.timer \
  linkvault-analytics-anomaly.timer linkvault-target-health.timer
sudo systemctl start linkvault-backup.service
sudo systemctl start linkvault-backup-age.service
sudo systemctl start linkvault-restore-drill.service
sudo systemctl start linkvault-analytics-rollup-backfill.service
```

`linkvault-backup.service` 使用无登录权限的 `linkvault-backup` 账户。备份目录必须为该账户独占的 `0700` 目录，不能把 `www-data` 加入其属组或授予 ACL；备份账户仅通过补充组只读访问 SQLite。校验完成后，任务把不含备份内容的证明 marker 发布到 `LINKVAULT_BACKUP_STATUS_DIR`。该目录使用 setgid `2750`、属主 `linkvault-backup`、属组 `www-data`，因此 Web 只能读取健康状态，不能读取、覆盖或删除备份。

备份默认强制异地上传，需要配置 `LINKVAULT_BACKUP_AGE_RECIPIENT`、`LINKVAULT_BACKUP_RCLONE_REMOTE` 和 HTTPS 告警 Webhook。`LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS` 限制每个 SQLite、age 和 rclone 命令的最长执行时间，默认 900 秒。备份任务即时失败或每小时年龄检查失败都会触发 `OnFailure=linkvault-notify@%n.service`。首次启用后必须先成功运行一次备份，否则 `/healthz` 会保持 `503`；用 `journalctl -u linkvault-backup.service -u linkvault-backup-age.service` 核对结果。隔离部署下 `/healthz` 校验受权限保护的证明 marker 及其时效，备份任务本身负责 SQLite 完整性、SHA-256 和远端对象大小校验。

Nginx 与 Caddy 示例为 `/api/*`、`/readyz`、`/healthz` 配置了独立限流，并将这些请求写入 `linkvault-endpoints` JSON 日志。应用还通过 `LINKVAULT_API_RATE_LIMIT_REQUESTS` 和 `LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS` 对 API Token 做跨 worker 原子限流；Token 未配置独立配额时继承这组默认值。超限返回 `429`、`Retry-After`，CIDR 拒绝返回 `403 source_not_allowed`，两者都会聚合为管理端告警并写入应用日志。配置至少 24 字符的专用 `LINKVAULT_METRICS_TOKEN` 后，Prometheus 可使用 Bearer Token 抓取 `GET /metrics`；未配置时该端点返回 `404`。指标包括请求量、canary 跳转延迟、SQLite 锁等待、队列积压、Webhook 死信、备份年龄、分析延迟和目标检查失败率。`linkvault-endpoint-monitor.timer` 每 5 分钟通过 `LINKVAULT_BASE_URL` 探测首页、登录表单、readiness、operational health、预置 canary 短链和 API 鉴权入口；失败会复用 `linkvault-notify@.service` 发送 Webhook 告警。每次运行还会把各探针的 HTTP 状态、耗时和校验结果原子写入 `LINKVAULT_SYNTHETIC_STATUS_PATH`，状态中心据此展示具体异常；结果时效由 `LINKVAULT_SYNTHETIC_STATUS_MAX_AGE_SECONDS` 控制。启用 `LINKVAULT_CANARY_ENABLED=1` 后运行 `php bin/seed-canary.php` 可幂等创建 canary，探针使用 `HEAD`，不会增加链接点击。站外监控使用 `.github/workflows/synthetic.yml`，仓库需配置 `SYNTHETIC_BASE_URL`、`SYNTHETIC_CANARY_TARGET_URL` Secrets 和可选的 `SYNTHETIC_CANARY_SLUG` Variable。

`linkvault-restore-drill.timer` 默认使用 `LINKVAULT_RESTORE_DRILL_SOURCE=local`；生产环境可切换为 `remote`。远端模式只读取 `.last-remote-success.json` 中经过严格校验的对象 basename、大小和 SHA-256，使用 `rclone copyto` 下载已配置目标中的该对象，不会列举桶内容；随后通过 `age --decrypt --identity` 解密到 `.part` 并原子发布隔离 SQLite 副本。演练会先执行迁移前完整性、`user_version` 和 `links` 表检查，再运行当前迁移以及完整性、外键、Schema、回滚写入和最多 10 条跳转抽查。明文运行目录必须完整删除后才发布 v2 成功标记，结果不会写入生产数据库审计表。systemd 单元通过 `LoadCredential` 提供 `/etc/linkvault/restore-age-identity` 和 `/etc/linkvault/restore-rclone.conf`；不需要独立 rclone 配置时可移除后者的 `LoadCredential`、`Environment` 两行并保持 `LINKVAULT_RESTORE_RCLONE_CONFIG` 未设置。

`linkvault-maintenance-notify.timer` 每日汇总即将过期、点击配额临界、长期零点击链接及本地/异地备份异常，并向 `LINKVAULT_MAINTENANCE_WEBHOOK_URL` 发送 JSON；未配置 URL 时任务安全跳过，同一 UTC 日的成功通知默认不重复发送。维护页和通知从同一策略读取 `LINKVAULT_MAINTENANCE_EXPIRING_DAYS`、`LINKVAULT_MAINTENANCE_STALE_DAYS`、`LINKVAULT_MAINTENANCE_QUOTA_PERCENT`，并在一次评估中共用同一 UTC 时间。备份任务的即时失败仍通过独立告警 Webhook 发送。

所有出站 Webhook 仅允许无凭据、无片段的公网 HTTPS/443 地址。发送前会解析并检查全部 A/AAAA 答案，任何私有、保留或公私混合结果都会被拒绝；连接通过 `CURLOPT_RESOLVE` 固定到已验证地址并核对实际主连接 IP，且不会自动跟随重定向，因此 Bearer Token 不会被转发到其他来源。生命周期事件还带稳定事件 ID、时间戳和 `HMAC-SHA256(timestamp.event_id.body)` 签名；接收方必须按事件 ID 去重。Webhook 返回 3xx 时任务按失败处理，outbox 最多重试 8 次后进入死信。

`linkvault-data-cleanup.timer` 每日固定清理超过保留期的管理端创建请求、API 幂等记录、批量操作记录、Token 使用记录、失效 Token 元数据和审计记录。批量预览 15 分钟有效，可逆操作在应用后 24 小时内允许撤销，操作证据保留 7 天。清理不再由普通请求随机触发；幂等保留期由 `LINKVAULT_IDEMPOTENCY_RETENTION_SECONDS` 设置，Token 使用记录默认保留 90 天，已吊销或失效 Token 默认额外保留 180 天，分别由 `LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS` 和 `LINKVAULT_API_TOKEN_RETENTION_DAYS` 设置，审计保留期由 `LINKVAULT_AUDIT_RETENTION_DAYS` 设置。

`linkvault-stats-retention.timer` 每日处理超过 `LINKVAULT_DAILY_STATS_RETENTION_DAYS` 的跳转日统计。`LINKVAULT_DAILY_STATS_RETENTION_MODE=archive` 会先写入归档表再删除在线明细，`delete` 则直接删除明细；两种模式都不会修改 `links.clicks` 累计点击。同一任务还会把超过 `LINKVAULT_ANALYTICS_HOURLY_RETENTION_DAYS`（默认 90 天）的访问小时聚合事务性汇总到日级表，再按 `LINKVAULT_ANALYTICS_RETENTION_DAYS` 清理日级数据。

`linkvault-analytics.timer` 每 5 分钟解析 Nginx/Caddy 的 JSON 访问日志。解析过程只在内存中读取 User-Agent 和来源站点，SQLite 仅保存 UTC 小时、链接、国家/地区、设备大类、浏览器/系统大类、来源域名、流量类型和活动字段的聚合计数。消费位置与聚合写入位于同一事务；分析页展示最新数据时间、聚合完成时间、聚合状态和日志积压。状态缺失、过期、失败、日志缺失或存在积压时，页面不展示零流量结论，持续归零告警也会暂停。两份示例分析日志都不包含客户端或代理链 IP、Cookie、Authorization、请求查询字符串、来源页路径和来源页查询字符串；原始日志由 logrotate 默认保留 30 天，日聚合默认保留 365 天，管理端状态中心同步展示这些治理边界。使用 Caddy 时将 `LINKVAULT_ANALYTICS_LOG_PATH` 改为 `/var/log/caddy/linkvault-analytics.log`。示例读取 CDN 提供的 `CF-IPCountry` 国家码；只有限制源站仅接受可信 CDN 流量时该字段才可信，直连部署应保持未知地区或接入受信任的 GeoIP 变量。

`linkvault-analytics-anomaly.timer` 每 5 分钟检查已闭合小时。它会检测相对前 24 小时基线的访问暴增、已有基线后的持续归零、达到最小请求量后的异常机器人占比，以及聚合状态过期、连续失败或日志缺失。通知复用 `LINKVAULT_ALERT_WEBHOOK_URL`，状态表按异常类型去重并由 `LINKVAULT_ANALYTICS_ANOMALY_COOLDOWN_SECONDS` 控制重复提醒。

`linkvault-target-health.timer` 每 15 分钟启动独立 CLI 批次。任务只选择启用且未删除的到期链接，默认每批最多 50 条；公开短链跳转不会发起任何目标探测。检查器仅允许配置端口上的 HTTP/HTTPS，拒绝凭据与片段，连接前有界解析 CNAME 并校验全部 A/AAAA 答案，拒绝任何私有、保留、文档、基准或过渡地址。每一跳通过 `CURLOPT_RESOLVE` 固定到已验证 IP，同时保留原始 Host/SNI 并核对实际主连接 IP；传输失败时会在同一总超时预算内最多尝试 4 个已验证地址。重定向由应用逐跳处理，不转发凭据，不允许 HTTPS 降级、跨源、私网目标、循环或超过上限。目标异常只进入维护页和状态中心，不影响 `/readyz` 或 `/healthz`。

分析页默认通过浏览器 IANA 时区计算 7/30/90 日及自定义日期边界；页面同时展示实际聚合数据起止日期和保留边界，明确区分保留期内零流量与已清理数据。活跃时段只读取小时表，默认仅覆盖最近 90 天；超过小时保留窗口的数据只有 UTC 日级精度，因此很长的本地时区查询在首尾边界只能按日近似。迁移后先运行一次 `linkvault-analytics-rollup-backfill.service`；完成前报表继续读取原事实表，完成后完整 UTC 日期改读日维度汇总，非 UTC 首尾边界仍读取小时表。分析页导出会进入持久化后台队列并轮询状态，默认最多 500,000 行，文件保存 24 小时；上限和保留期分别由 `LINKVAULT_ANALYTICS_EXPORT_MAX_ROWS`、`LINKVAULT_ANALYTICS_EXPORT_RETENTION_HOURS` 设置。旧的同步导出接口仍限制 50,000 行。页面中的“疑似人工”是 User-Agent 分类，不是 UV。

当前不采集或保存 UV 摘要。启用每日轮换 HMAC 前，需要先明确处理目的与告知方式、可信代理下的 IP 口径、密钥保管与轮换、摘要保留期、访问权限及删除响应；即使不可逆，摘要仍应按假名化数据管理。

转化事件使用独立的 `conversions:write` 作用域。`POST /api/conversions` 必须同时携带 `Idempotency-Key`、Unix 秒级 `X-LinkVault-Timestamp` 和 `X-LinkVault-Signature`；签名值为以当前 Bearer Token 为密钥，对 `timestamp.idempotencyKey.rawBody` 计算的 `sha256=<HMAC-SHA256>`。默认只接受前后 300 秒内的请求。管理端“转化”工作区按短链累计点击作为入口，展示事件阶段数量及事件数/点击数转化率；该比率不是用户级漏斗，不采集 UV 标识。

`browser-extension/` 可在 Chromium 扩展管理页以“加载已解压的扩展程序”安装。Token 仅保存在浏览器扩展本地存储中，建议为扩展创建只含 `links:create`、短失效时间、独立配额和准确 CIDR 的 Token。

上架 Chrome Web Store 前，配置受监控的 `LINKVAULT_BROWSER_EXTENSION_PRIVACY_CONTACT`，并在商店隐私页提交公开 HTTPS 地址 `https://<你的服务域名>/browser-extension-privacy`。链接搜索和健康状态还需要为扩展 Token 增加 `links:read` 作用域。

公开举报入口为 `GET/POST /report`，默认每个来源每小时 5 次，来源地址仅用于当日哈希配额和举报去重，不写入举报记录。管理员可在“信任与安全”工作区复核、停用或恢复链接、维护域名黑名单，并运行单链接扫描；定时批量扫描可执行 `php bin/scan-link-risks.php`。

## 时间与过期设置

数据库和 JSON 导入导出中的时间统一保存为 UTC。管理页面会按浏览器当前时区显示创建、过期和最后跳转时间；`datetime-local` 输入也按浏览器本地时间解释，并在提交时携带该日期对应的 UTC 偏移。这样设置“明天 18:00”时无需手工换算 UTC。

日统计按 UTC 自然日写入。管理页的近 14 日总数和明细跟随当前搜索、状态、标签与收藏筛选；详情页可切换单条链接近 7、14、30 个 UTC 自然日趋势。

导入文件中的 `expires_at` 和 `starts_at` 应使用带时区的 ISO 8601，例如 `2026-08-01T10:00:00Z` 或 `2026-08-01T18:00:00+08:00`。没有时区的导入时间会被标记为无效，以免产生歧义。导入还支持 `tags`、`is_favorite`、`max_clicks`、`is_one_time` 和 `one_time_mode`。

## 冒烟测试

测试会创建临时数据库、执行独立迁移并启动临时 PHP 服务，不会读写正式数据库：

```powershell
php tests/smoke.php
```

覆盖 v1-v23 历史升级矩阵、跨多版本失败回滚与重试恢复、备份校验、分层健康探针、数据库缺失 `503`、创建幂等、一次性链接确认、保存筛选、结构化审计、统计归档、作用域 Token 与 CRUD API、跨 100 条边界的导入回滚、登录与 CSRF、链接状态、安全响应头及并发跳转计数。开发依赖安装后还可运行：

```bash
composer install && composer check
npm install && npx playwright install chromium && npm run test:e2e
k6 run -e BASE_URL=https://staging.example.com -e SLUG=capacity01 tests/capacity.js
```

## 性能基线与 SQLite 维护

生产 PHP-FPM 必须加载 `deploy/php-production.ini` 并确认 Web SAPI 的 `opcache.enable=1`；不要用默认关闭 OPcache 的 CLI SAPI 结果代替检查。SQLite 每个 PHP-FPM worker 使用独立页缓存，`LINKVAULT_SQLITE_CACHE_SIZE_KIB` 默认 32768。内存预算至少按 `cache_size × pm.max_children` 计算，并为 PHP、OPcache、Nginx 和操作系统页缓存留出余量。

发布迁移和每日数据清理服务都会执行 `php bin/optimize-database.php`。它运行轻量的 `PRAGMA optimize`，不会执行阻塞性 `VACUUM`。应用日志对超过 `LINKVAULT_SQLITE_SLOW_QUERY_MS` 的数据库操作记录耗时、操作类型、表名和 SQL 指纹；参数值不会写入日志。锁超时单独记录为 `sqlite_lock_wait`。

分析报表会将不超过 `LINKVAULT_ANALYTICS_MATERIALIZE_MAX_ROWS`（默认 250,000）的当前范围物化为请求级临时表，供总量、趋势、维度和排名重复使用；设为 `0` 可关闭。结果按数据库/WAL 指纹缓存 `LINKVAULT_ANALYTICS_REPORT_CACHE_SECONDS`（默认 60）秒，任何数据库写入都会改变指纹并自动失效。缓存目录由 `LINKVAULT_ANALYTICS_REPORT_CACHE_DIR` 指定，必须仅允许 PHP-FPM 用户访问。迁移服务会自动拉起一次日维度 Rollup 回填；也可手工执行 `php bin/backfill-analytics-rollups.php`。生产数据量较大时先从 100,000–250,000 的物化上限开始，并同时观察 PHP-FPM RSS、临时磁盘和 P95。

离线基准覆盖后台首尾页、搜索、分析报表、过滤 CSV 和跳转查询：

```bash
php tests/performance/benchmark.php --sizes=10000,100000,1000000 --iterations=7 --output=benchmark.json
php tests/performance/benchmark.php --sizes=100000 --iterations=7 --rollup-ready
```

真实生产拓扑下使用已登录管理员会话运行混合负载，并单独检查哈希静态资源：

```bash
k6 run -e BASE_URL=https://staging.example.com -e SLUG=capacity01 -e ADMIN_COOKIE='linkvault_session=REDACTED' tests/capacity-workloads.js
k6 run -e ASSET_URL=https://staging.example.com/assets/app.45121adb305c.js tests/static-assets.js
```

Nginx 将无查询参数的路由分类性能记录写入 `linkvault-performance.log`，静态传输记录写入 `linkvault-static.log`。以下命令按最近 24 小时输出各类请求 P50/P95/P99、5xx、静态 304 比例、传输字节、慢 SQL、锁失败和 WAL 大小：

```bash
php bin/performance-report.php --window-seconds=86400
```

哈希资源使用一年 `immutable` 缓存，浏览器命中不会到达 Nginx，因此服务端日志中的 `validation_hit_ratio` 只代表条件请求的 304 比例，不等同于完整浏览器缓存命中率。完整命中率应同时从 RUM/CDN 指标采集。每次发布及容量变化后保存首页、跳转、后台列表、搜索、分析和导出的分位数基线，并同步记录 PHP-FPM worker 数、CPU、内存、数据库/WAL 大小和数据量。

## 备份与恢复

管理页的“导出链接”只包含可迁移的非回收站链接字段，可再次通过 JSON 导入；“审计数据快照”还包含回收站、累计点击、创建/更新时间、最后访问时间、日统计、批量操作、已保存分析视图、目标健康、分析告警、Token 脱敏元数据和使用记录。快照内的 `table_manifest` 明确列出包含表、排除表和脱敏字段；它仅用于审计或离线处理，不包含 Token 摘要或密码材料，不能导入，也不能作为数据库恢复文件。可恢复备份必须使用下面的 SQLite 在线备份流程。

删除短链接默认进入回收站；只有在回收站中执行“永久删除”才会移除数据。部署后仍应使用 SQLite 在线备份命令定期备份，不要在应用运行时直接复制主数据库文件，因为 WAL 中可能还有未合并的数据。

创建备份并立即检查 SQLite 完整性：

```powershell
New-Item -ItemType Directory -Force .\backups
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backup = ".\backups\linkvault-$stamp.sqlite"
sqlite3 .\data\linkvault.sqlite ".backup '$backup'"
sqlite3 $backup "PRAGMA integrity_check;"
```

`integrity_check` 必须输出 `ok`，但它不验证数据库能否被当前应用使用。日常自动备份应通过 `php bin/backup.php` 创建。手工恢复异地对象时，先用 `rclone copyto <remote-object> linkvault.sqlite.age` 下载，再在隔离恢复主机用 `age --decrypt --identity <offline-identity> --output linkvault.sqlite linkvault.sqlite.age` 解密，然后执行完整性检查和下述恢复流程。自动演练可设置 `LINKVAULT_RESTORE_DRILL_SOURCE=remote`、`LINKVAULT_RESTORE_AGE_IDENTITY`，并在需要时设置 `LINKVAULT_RESTORE_RCLONE_CONFIG`；至少每季度验证一次密钥可用性和远端下载。

恢复步骤：

1. 停止应用或进入维护状态，确保没有写请求。
2. 使用上面的 `.backup` 命令对当前数据库做一次保留副本，确保 WAL 中的已提交数据也被包含。
3. 对待恢复备份执行 `sqlite3 <备份文件> "PRAGMA integrity_check;"`，确认输出 `ok`。
4. 用备份文件替换 `data/linkvault.sqlite`，并在应用停止状态下删除同目录残留的 `linkvault.sqlite-wal` 和 `linkvault.sqlite-shm`。
5. 再次执行 `sqlite3 .\data\linkvault.sqlite "PRAGMA integrity_check;"`，然后运行 `php bin/migrate.php` 将恢复的数据升级到当前结构。
6. 启动应用并抽查短链接。

至少每季度进行一次恢复演练。演练应恢复到隔离路径，并使用独立端口验证：

```powershell
$sourceBackup = ".\backups\linkvault-YYYYMMDD-HHMMSS.sqlite"
$drillDir = ".\restore-drill\$(Get-Date -Format 'yyyyMMdd-HHmmss')"
New-Item -ItemType Directory -Force $drillDir
Copy-Item $sourceBackup "$drillDir\linkvault.sqlite"
$env:LINKVAULT_DATABASE_PATH = (Resolve-Path "$drillDir\linkvault.sqlite")
$env:LINKVAULT_LOG_PATH = "$drillDir\application.log"
$securePassword = Read-Host "输入演练管理密码" -AsSecureString
$env:LINKVAULT_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
php bin/migrate.php
php -S 127.0.0.1:8081 -t public public/router.php
```

记录演练日期、使用的备份、`integrity_check` 结果、链接抽查结果和实际恢复耗时。
