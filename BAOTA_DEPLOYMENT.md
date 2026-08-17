# 链匣 LinkVault 宝塔部署指南

本文说明如何在宝塔 Linux 面板中部署 LinkVault。部署架构为：

- Nginx
- PHP 8.5 + PHP-FPM
- SQLite
- 宝塔计划任务

本文默认使用以下值，部署前请按实际情况替换：

| 项目 | 示例值 |
| --- | --- |
| 主域名 | `s.example.com` |
| 项目目录 | `/www/wwwroot/linkvault` |
| 网站根目录 | `/www/wwwroot/linkvault/public` |
| PHP CLI | `/www/server/php/85/bin/php` |
| PHP-FPM Socket | `/tmp/php-cgi-85.sock` |
| PHP-FPM 用户 | `www` |
| CLI 环境文件 | `/etc/linkvault/linkvault.env` |
| Web 环境文件 | `/etc/linkvault/linkvault-fastcgi.conf` |

主域名、PHP 路径、Socket 和运行用户必须以服务器实际配置为准。首次上线按第 1 至第 10 节依次操作；第 11 节以后用于日志、升级、备份恢复和排障。

## 1. 安装并检查环境

在宝塔“软件商店”安装：

- Nginx
- PHP 8.5
- PHP 扩展：`pdo_sqlite`、`sqlite3`
- PHP 扩展：`curl`，启用 Webhook 或目标健康检查时必需
- 系统命令：`sqlite3`、`openssl`
- 可选命令：`age`、`rclone`，启用加密异地备份时使用

生产 PHP 配置至少应包含：

```ini
display_errors = Off
display_startup_errors = Off
log_errors = On
expose_php = Off
upload_max_filesize = 2M
post_max_size = 3M
max_file_uploads = 1
opcache.enable = 1
```

在 SSH 中检查 PHP、扩展、SQLite CLI 和 FTS5：

```bash
/www/server/php/85/bin/php -v
/www/server/php/85/bin/php -m | grep -E 'PDO|pdo_sqlite|sqlite3|curl|Zend OPcache'
sqlite3 --version
/www/server/php/85/bin/php -r '$db=new PDO("sqlite::memory:"); echo $db->query("SELECT sqlite_compileoption_used(\"ENABLE_FTS5\")")->fetchColumn(), PHP_EOL;'
```

最后一条命令必须输出 `1`。如果 PHP 8.5、SQLite 扩展或 FTS5 缺失，应先修复运行环境，不要直接改用未经测试的 PHP 低版本。

确认 PHP-FPM 的实际用户和监听地址：

```bash
grep -R -E '^(user|group|listen)\s*=' \
  /www/server/php/85/etc/php-fpm.conf \
  /www/server/php/85/etc/php-fpm.d 2>/dev/null
```

后文以用户 `www`、Socket `/tmp/php-cgi-85.sock` 为例。

## 2. 上传项目

将项目上传或解压到：

```text
/www/wwwroot/linkvault
```

生产运行必须包含：

```text
app/
bin/
lib/
migrations/
public/
templates/
config.php
```

建议同时上传 `deploy/`，便于核对 Nginx、环境变量和日志轮转配置。

以下内容不应上传到生产服务器：

```text
.env
node_modules/
vendor/
tests/
test-results/
playwright-report/
data/*.sqlite*
data/*.log*
backups/*
restore-drill/*
```

应用生产运行不依赖 Composer，`vendor/` 仅用于开发检查。不要上传本机数据库、日志、备份或任何真实密钥。

每次发布都需要生成带内容哈希的静态资源。可在上传前于本地生成，也可上传后在服务器执行：

```bash
/www/server/php/85/bin/php /www/wwwroot/linkvault/bin/build-assets.php
```

## 3. 创建目录和权限

先创建运行目录：

```bash
install -d -o www -g www -m 750 \
  /www/wwwroot/linkvault/data \
  /www/wwwroot/linkvault/data/analytics-report-cache \
  /www/wwwroot/linkvault/data/analytics-exports \
  /www/wwwroot/linkvault/backups \
  /www/wwwroot/linkvault/restore-drill
```

再限制代码与运行数据权限：

```bash
chown -R root:root /www/wwwroot/linkvault
chown -R www:www \
  /www/wwwroot/linkvault/data \
  /www/wwwroot/linkvault/backups \
  /www/wwwroot/linkvault/restore-drill

find /www/wwwroot/linkvault -type d \
  -not -path '/www/wwwroot/linkvault/data*' \
  -not -path '/www/wwwroot/linkvault/backups*' \
  -not -path '/www/wwwroot/linkvault/restore-drill*' \
  -exec chmod 755 {} \;

find /www/wwwroot/linkvault -type f \
  -not -path '/www/wwwroot/linkvault/data/*' \
  -not -path '/www/wwwroot/linkvault/backups/*' \
  -not -path '/www/wwwroot/linkvault/restore-drill/*' \
  -exec chmod 644 {} \;
```

代码由 `root` 只读持有，只有 `data/`、`backups/` 和 `restore-drill/` 允许应用及计划任务写入。实际 PHP-FPM 用户不是 `www` 时，统一替换用户和组。

在宝塔中创建 PHP 网站，并将网站根目录设置为：

```text
/www/wwwroot/linkvault/public
```

不要把项目根目录设为网站根目录，否则配置、数据库和迁移文件可能暴露。

宝塔“防跨站攻击”不能只允许访问 `public/`，PHP 还需要读取上级目录中的应用代码并写入运行目录。可将允许目录调整为整个 `/www/wwwroot/linkvault/`；若外部命令仍被拦截，可仅对这个站点关闭该选项，不要全局关闭。

## 4. 配置环境变量

LinkVault 没有默认管理密码。公网部署至少必须设置：

- `LINKVAULT_ADMIN_PASSWORD`
- `LINKVAULT_BASE_URL`
- `LINKVAULT_DATABASE_PATH`
- `LINKVAULT_LOG_PATH`
- `LINKVAULT_BACKUP_DIR`

生成管理密码和可选安全密钥：

```bash
printf 'Aa1!%s\n' "$(openssl rand -hex 24)"
openssl rand -hex 32
```

管理密码至少 12 位，并满足小写字母、大写字母、数字、特殊字符中的至少三类。`LINKVAULT_SECURITY_KEY` 仅在启用 TOTP 时必需，必须使用独立随机值。

### 4.1 CLI 环境文件

创建只允许 `root` 读取的环境文件：

```bash
install -d -o root -g root -m 700 /etc/linkvault
```

创建 `/etc/linkvault/linkvault.env`：

```bash
LINKVAULT_ADMIN_PASSWORD='替换为强管理密码'
LINKVAULT_BASE_URL='https://s.example.com'
LINKVAULT_RELEASE_VERSION='V2.0.0'
LINKVAULT_BUILD_TIME='2026-08-11T00:00:00Z'
LINKVAULT_RELEASE_CHANGELOG='Initial production deployment'
LINKVAULT_RELEASE_ROLLBACK_VERSION=''
LINKVAULT_TRUSTED_PROXIES=''

LINKVAULT_DATABASE_PATH='/www/wwwroot/linkvault/data/linkvault.sqlite'
LINKVAULT_LOG_PATH='/www/wwwroot/linkvault/data/application.log'
LINKVAULT_SQLITE_CACHE_SIZE_KIB='32768'
LINKVAULT_SQLITE_BUSY_TIMEOUT_MS='5000'
LINKVAULT_SQLITE_SLOW_QUERY_MS='250'
LINKVAULT_REDIRECT_BUSY_TIMEOUT_MS='250'
LINKVAULT_REDIRECT_RETRY_ATTEMPTS='2'
LINKVAULT_HEALTH_BUSY_TIMEOUT_MS='100'
LINKVAULT_HEALTH_MIN_FREE_BYTES='134217728'

LINKVAULT_BACKUP_DIR='/www/wwwroot/linkvault/backups'
LINKVAULT_BACKUP_STATUS_DIR=''
LINKVAULT_BACKUP_RETENTION_DAYS='14'
LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS='900'
LINKVAULT_SQLITE3_BIN='/usr/bin/sqlite3'
LINKVAULT_BACKUP_MAX_AGE_SECONDS='28800'
LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS='300'
LINKVAULT_BACKUP_REMOTE_REQUIRED='0'

LINKVAULT_RESTORE_DRILL_DIR='/www/wwwroot/linkvault/restore-drill'
LINKVAULT_RESTORE_DRILL_SOURCE='local'
LINKVAULT_RESTORE_DRILL_MAX_AGE_SECONDS='691200'

LINKVAULT_API_TOKEN=''
LINKVAULT_API_RATE_LIMIT_REQUESTS='60'
LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS='60'
LINKVAULT_SECURITY_KEY=''

LINKVAULT_CANARY_ENABLED='0'
LINKVAULT_TARGET_HEALTH_ENABLED='0'
LINKVAULT_MAINTENANCE_WEBHOOK_URL=''
LINKVAULT_LIFECYCLE_WEBHOOK_URL=''
LINKVAULT_ALERT_WEBHOOK_URL=''

LINKVAULT_ANALYTICS_LOG_PATH='/www/wwwlogs/linkvault-analytics.log'
LINKVAULT_ANALYTICS_STATE_PATH='/www/wwwroot/linkvault/data/.analytics-ingest-state.json'
LINKVAULT_ANALYTICS_REPORT_CACHE_DIR='/www/wwwroot/linkvault/data/analytics-report-cache'
LINKVAULT_ANALYTICS_EXPORT_DIR='/www/wwwroot/linkvault/data/analytics-exports'
LINKVAULT_TARGET_HEALTH_STATUS_PATH='/www/wwwroot/linkvault/data/.target-health-state.json'
LINKVAULT_SYNTHETIC_STATUS_PATH='/www/wwwroot/linkvault/data/.synthetic-monitor-state.json'
```

这是一份可启动的基础配置。当前版本的全部变量、默认值和说明以 `deploy/linkvault.env.example` 为准；启用 API、TOTP、异地备份、分析、Webhook、Canary 或目标检查时，将对应变量补充到此文件。

设置权限：

```bash
chown root:root /etc/linkvault/linkvault.env
chmod 600 /etc/linkvault/linkvault.env
```

### 4.2 Web 环境文件

宝塔 PHP-FPM 通常不会自动继承 Shell 环境。创建 `/etc/linkvault/linkvault-fastcgi.conf`，把 Web 请求需要的配置通过 FastCGI 传入：

```nginx
fastcgi_param LINKVAULT_ADMIN_PASSWORD "替换为与 linkvault.env 相同的管理密码";
fastcgi_param LINKVAULT_BASE_URL "https://s.example.com";
fastcgi_param LINKVAULT_RELEASE_VERSION "V2.0.0";
fastcgi_param LINKVAULT_BUILD_TIME "2026-08-11T00:00:00Z";
fastcgi_param LINKVAULT_RELEASE_CHANGELOG "Initial production deployment";
fastcgi_param LINKVAULT_RELEASE_ROLLBACK_VERSION "";
fastcgi_param LINKVAULT_TRUSTED_PROXIES "";

fastcgi_param LINKVAULT_DATABASE_PATH "/www/wwwroot/linkvault/data/linkvault.sqlite";
fastcgi_param LINKVAULT_LOG_PATH "/www/wwwroot/linkvault/data/application.log";
fastcgi_param LINKVAULT_SQLITE_CACHE_SIZE_KIB "32768";
fastcgi_param LINKVAULT_SQLITE_BUSY_TIMEOUT_MS "5000";
fastcgi_param LINKVAULT_SQLITE_SLOW_QUERY_MS "250";
fastcgi_param LINKVAULT_REDIRECT_BUSY_TIMEOUT_MS "250";
fastcgi_param LINKVAULT_REDIRECT_RETRY_ATTEMPTS "2";
fastcgi_param LINKVAULT_HEALTH_BUSY_TIMEOUT_MS "100";
fastcgi_param LINKVAULT_HEALTH_MIN_FREE_BYTES "134217728";

fastcgi_param LINKVAULT_BACKUP_DIR "/www/wwwroot/linkvault/backups";
fastcgi_param LINKVAULT_BACKUP_STATUS_DIR "";
fastcgi_param LINKVAULT_BACKUP_MAX_AGE_SECONDS "28800";
fastcgi_param LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS "300";
fastcgi_param LINKVAULT_BACKUP_REMOTE_REQUIRED "0";

fastcgi_param LINKVAULT_API_TOKEN "";
fastcgi_param LINKVAULT_API_RATE_LIMIT_REQUESTS "60";
fastcgi_param LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS "60";
fastcgi_param LINKVAULT_SECURITY_KEY "";

fastcgi_param LINKVAULT_CANARY_ENABLED "0";
fastcgi_param LINKVAULT_TARGET_HEALTH_ENABLED "0";
fastcgi_param LINKVAULT_MAINTENANCE_WEBHOOK_URL "";
fastcgi_param LINKVAULT_LIFECYCLE_WEBHOOK_URL "";
fastcgi_param LINKVAULT_ALERT_WEBHOOK_URL "";

fastcgi_param LINKVAULT_ANALYTICS_STATE_PATH "/www/wwwroot/linkvault/data/.analytics-ingest-state.json";
fastcgi_param LINKVAULT_ANALYTICS_REPORT_CACHE_DIR "/www/wwwroot/linkvault/data/analytics-report-cache";
fastcgi_param LINKVAULT_ANALYTICS_EXPORT_DIR "/www/wwwroot/linkvault/data/analytics-exports";
fastcgi_param LINKVAULT_TARGET_HEALTH_STATUS_PATH "/www/wwwroot/linkvault/data/.target-health-state.json";
fastcgi_param LINKVAULT_SYNTHETIC_STATUS_PATH "/www/wwwroot/linkvault/data/.synthetic-monitor-state.json";
```

限制文件权限：

```bash
chown root:root /etc/linkvault/linkvault-fastcgi.conf
chmod 600 /etc/linkvault/linkvault-fastcgi.conf
```

CLI 文件使用 Shell 语法，Web 文件使用 Nginx 语法，不能直接互相 `include`。修改域名、密码、发布信息、路径或功能开关时，必须同步两份文件并重载 Nginx。`age` 私钥和只供恢复使用的 rclone 凭据不得放入 FastCGI 文件。

## 5. 配置域名、HTTPS 和 Nginx

1. 将域名 A/AAAA 记录解析到服务器。
2. 在宝塔网站中绑定明确的主域名，例如 `s.example.com`。
3. 申请 Let's Encrypt 证书。
4. 证书生效后启用强制 HTTPS。

不要使用接受任意 Host 的默认站点。每个自定义短链域名都必须同时加入：

- 宝塔站点域名列表
- Nginx `server_name`
- Host 白名单
- TLS 证书

管理端、API 和健康检查仍只允许 `LINKVAULT_BASE_URL` 对应的主域名。

### 5.1 站点配置

先确认第 4.2 节的 `/etc/linkvault/linkvault-fastcgi.conf` 已经创建；该文件不存在时，`nginx -t` 会直接失败。然后在宝塔网站的 Nginx 配置中删除自动生成的通用 PHP include，例如 `include enable-php-85.conf;`，并使用以下核心配置。证书相关行可保留宝塔自动生成的实际路径。

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name s.example.com;

    if ($host != s.example.com) { return 444; }
    return 301 https://s.example.com$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name s.example.com;

    ssl_certificate /www/server/panel/vhost/cert/s.example.com/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/s.example.com/privkey.pem;

    root /www/wwwroot/linkvault/public;
    index index.php;
    server_tokens off;
    client_max_body_size 3m;

    if ($host != s.example.com) { return 444; }

    location ~* ^/assets/(fonts/)?[A-Za-z0-9_.-]+\.[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]\.(css|js|woff2|svg)$ {
        try_files $uri =404;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~* ^/assets/(fonts/)?[A-Za-z0-9_.-]+\.(css|js|woff2|svg)$ {
        try_files $uri =404;
        expires 1h;
        add_header Cache-Control "public, must-revalidate";
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        include /etc/linkvault/linkvault-fastcgi.conf;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTP_HOST $host;
        fastcgi_param HTTPS $https if_not_empty;
        fastcgi_pass unix:/tmp/php-cgi-85.sock;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\. {
        return 404;
    }
}
```

注意：

- 将域名、证书路径和 PHP-FPM Socket 替换为实际值。
- `$host` 会规范化请求主机名并去除端口；Host 白名单中的域名必须与 `server_name` 一致，否则正常请求也会返回 `444`。
- `HTTP_HOST` 必须保留真实 Host，不能固定成主域名，否则已验证的自定义短链域名无法工作。启用自定义短链域名时，还要将其加入 `server_name` 和 Host 白名单；只有单个主域名时可使用示例中的精确比较。
- 宝塔配置编辑器可能错误拆分含 `{12}` 的正则，因此哈希文件名使用 12 个连续的 `[0-9a-f]` 表达，与 `[0-9a-f]{12}` 等价。每条 `location` 正则应保持在同一行。
- `/etc/linkvault/linkvault-fastcgi.conf` 必须存在后才能保留对应 `include`；不要通过删除该行绕过检查，否则 Web 请求无法获得管理密码、数据库路径等必要环境变量。
- 只允许执行 `public/index.php`，其他 PHP 文件统一返回 `404`。
- Nginx 直接面向客户端时，`LINKVAULT_TRUSTED_PROXIES` 保持为空。
- 经过 CDN 或上游代理时，只填写真实的代理直连 IP，并确保代理覆盖客户端传入的 `X-Forwarded-*` 请求头。

检查并重载 Nginx：

```bash
/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
```

### 5.2 生产限流和专用日志

上面的配置可以启动应用，但公网生产环境还应启用仓库 `deploy/nginx.conf` 中的限流与专用日志：

- `map`、`limit_req_zone`、`limit_conn_zone`、`log_format` 必须放在 Nginx 的 `http` 块中，不能放进站点 `server` 块。
- 宝塔通常可在 `/www/server/nginx/conf/nginx.conf` 的 `http` 块或其已包含的目录中加载独立文件。
- `limit_req`、`limit_conn`、`access_log` 和各 `location` 规则保留在站点配置中。
- 分析日志格式不得记录 IP、Cookie、Authorization、查询字符串或完整来源 URL。

修改前先备份宝塔 Nginx 配置，并在每次保存后执行 `nginx -t`。不确定宝塔当前 include 结构时，不要直接把整个 `deploy/nginx.conf` 粘贴到站点配置框。

## 6. 初始化数据库

加载 CLI 环境变量，并以 PHP-FPM 用户执行资源构建、迁移和检查：

```bash
cd /www/wwwroot/linkvault
set -a
. /etc/linkvault/linkvault.env
set +a

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php bin/build-assets.php

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php bin/migrate.php

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php bin/optimize-database.php

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php bin/doctor.php

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php bin/preflight.php
```

`migrate.php` 可重复执行，首次部署和每次升级后都必须运行。生产预检成功应输出：

```text
Production preflight passed.
```

`doctor.php` 可能因为未使用仓库的 systemd timer 而给出建议；使用宝塔计划任务时可以按第 10 节人工核对，但其他阻塞错误不能忽略。

## 7. 首次健康检查

重载 Nginx 和 PHP-FPM 后检查：

```bash
curl --fail --silent --show-error https://s.example.com/livez
curl --fail --silent --show-error https://s.example.com/readyz
```

- `/livez`：确认 PHP 可以响应。
- `/readyz`：检查强密码、数据库版本与读取、目录可写性和磁盘空间。
- `/healthz`：在 readiness 基础上检查最近一次备份。

首次部署还没有备份，`/healthz` 返回 `503` 属于预期。先创建一次备份：

```bash
set -a
. /etc/linkvault/linkvault.env
set +a

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/backup.php

curl --fail --silent --show-error https://s.example.com/healthz
```

三个探针和一条实际测试短链均正常后，再把域名正式切流。

## 8. 可选功能配置

完整变量以 `deploy/linkvault.env.example` 为唯一清单。启用功能时，先把对应变量加入 `/etc/linkvault/linkvault.env`；会影响 Web 页面或状态中心的变量还要同步到 `/etc/linkvault/linkvault-fastcgi.conf`。

### API Token

推荐登录后在“系统状态”中创建带作用域的数据库 Token。`LINKVAULT_API_TOKEN` 仅用于兼容旧的 `links:create` Token，至少 24 位；不使用时保持为空。

### TOTP

设置至少 32 位的独立 `LINKVAULT_SECURITY_KEY`，同步到 CLI 与 Web 配置后重载 Nginx，再从系统状态页启用。该密钥和恢复码必须分开离线保存。

### 自定义短链域名

在系统状态页添加域名，根据页面提示配置 `_linkvault-challenge.<域名>` TXT 记录并完成验证。随后把域名加入 Nginx、宝塔站点和证书。不要使用通配 Host 接收未验证域名。

### 访问分析

使用 `deploy/nginx.conf` 中的 `linkvault_analytics_json` 专用日志格式，并设置：

```bash
LINKVAULT_ANALYTICS_LOG_PATH='/www/wwwlogs/linkvault-analytics.log'
LINKVAULT_ANALYTICS_STATE_PATH='/www/wwwroot/linkvault/data/.analytics-ingest-state.json'
LINKVAULT_ANALYTICS_STATUS_MAX_AGE_SECONDS='900'
LINKVAULT_ANALYTICS_HOURLY_RETENTION_DAYS='90'
LINKVAULT_ANALYTICS_RETENTION_DAYS='365'
LINKVAULT_ANALYTICS_BATCH_MAX_LINES='100000'
LINKVAULT_ANALYTICS_MATERIALIZE_MAX_ROWS='250000'
LINKVAULT_ANALYTICS_REPORT_CACHE_SECONDS='60'
LINKVAULT_ANALYTICS_REPORT_CACHE_DIR='/www/wwwroot/linkvault/data/analytics-report-cache'
LINKVAULT_ANALYTICS_EXPORT_DIR='/www/wwwroot/linkvault/data/analytics-exports'
LINKVAULT_ANALYTICS_EXPORT_RETENTION_HOURS='24'
LINKVAULT_ANALYTICS_EXPORT_LEASE_SECONDS='900'
LINKVAULT_ANALYTICS_EXPORT_WORKER_BATCH_SIZE='5'
LINKVAULT_ANALYTICS_EXPORT_MAX_ROWS='500000'
```

确保 `www` 可以读取分析日志。不能把普通 access log 直接交给聚合器。

首次启用分析或升级到包含日级 Rollup 的版本后，执行一次历史数据回填：

```bash
set -a
. /etc/linkvault/linkvault.env
set +a

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/backfill-analytics-rollups.php
```

### 目标检查、Webhook 和 Canary

这些功能需要 `curl` 扩展。目标检查、维护通知、生命周期 Webhook、告警和 Canary 的完整变量直接从 `deploy/linkvault.env.example` 复制。所有 URL 只接受公网 HTTPS/443；Token 和签名密钥只能存放在权限为 `0600` 的配置文件中，不能写入计划任务命令行。

### 异地加密备份

基础部署默认：

```bash
LINKVAULT_BACKUP_REMOTE_REQUIRED='0'
```

启用异地备份前安装 `age` 和 `rclone`，再配置收件人公钥、只追加或不可变的远端对象路径和告警。恢复私钥不应放在 Web 环境或日常备份任务可读的位置。生产数据重要时，建议参照 `deploy/` 中的专用 systemd 备份账户方案，实现 Web 只读备份状态、不能读取或删除备份文件的权限隔离。

## 9. 上线检查清单

- 网站根目录是 `/www/wwwroot/linkvault/public`。
- PHP 版本、扩展、SQLite FTS5 和 OPcache 正常。
- 管理密码、域名和路径在 CLI 与 Web 配置中一致。
- 代码目录只读，运行目录只允许 `www` 写入。
- Nginx 只执行 `public/index.php`，未知 Host 被拒绝。
- HTTPS 生效，`LINKVAULT_BASE_URL` 使用完全一致的 HTTPS 地址。
- `build-assets.php`、`migrate.php`、`preflight.php` 均成功。
- `/livez`、`/readyz`、首次备份后的 `/healthz` 均返回 `200`。
- 实际创建、访问并停用一条测试短链。
- 必需计划任务已添加，手动执行退出码为 `0`，失败通知已开启。

## 10. 配置宝塔计划任务

宝塔计划任务通常以 `root` 运行。所有任务先加载环境文件，再通过 `runuser` 降权为 `www`，并使用独立 `flock` 防止重复执行。

通用脚本模板：

```bash
#!/usr/bin/env bash
set -euo pipefail
set -a
. /etc/linkvault/linkvault.env
set +a

flock -n /run/lock/linkvault-TASK.lock \
  runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/SCRIPT.php
```

将 `TASK` 和 `SCRIPT.php` 替换为下表内容。若宝塔不支持 `/bin/bash` 解释器，把脚本保存到仅 `root` 可读写的目录，设置 `chmod 700` 后由计划任务调用。

| 任务 | 建议周期 | 脚本 | 条件 |
| --- | --- | --- | --- |
| 在线备份 | 每 4 小时 | `backup.php` | 必需 |
| 备份年龄检查 | 每小时 | `check-backup-age.php` | 必需 |
| 域名退役 | 每分钟 | `process-domain-retirements.php` | 必需 |
| 运行数据清理 | 每天 03:20 | `cleanup-data.php` | 必需 |
| 数据库轻量优化 | 每天 03:30 | `optimize-database.php` | 建议 |
| 统计保留 | 每天 03:40 | `retain-stats.php` | 建议 |
| 恢复演练 | 每周 | `restore-drill.php` | 建议 |
| 分析聚合 | 每 5 分钟 | `aggregate-analytics.php` | 启用分析 |
| 分析导出队列 | 每分钟 | `process-analytics-exports.php` | 启用分析导出 |
| 分析异常检查 | 每 5 分钟 | `check-analytics-anomalies.php` | 启用分析告警 |
| 周经营摘要 | 每周一 | `send-business-summary.php weekly` | 配置周摘要订阅 |
| 月经营摘要 | 每月 1 日 | `send-business-summary.php monthly` | 配置月摘要订阅 |
| 目标健康检查 | 每 15 分钟 | `check-target-health.php` | 启用目标检查 |
| 维护摘要 | 每天 | `notify-maintenance.php` | 配置维护 Webhook |
| 生命周期 Webhook | 每分钟 | `dispatch-lifecycle-webhooks.php` | 配置生命周期 Webhook |

Canary 监控需要在同一个锁中先播种再检查：

```bash
#!/usr/bin/env bash
set -euo pipefail
set -a
. /etc/linkvault/linkvault.env
set +a

flock -n /run/lock/linkvault-endpoint-monitor.lock \
  runuser -u www --preserve-environment -- /bin/bash -c \
  '/www/server/php/85/bin/php /www/wwwroot/linkvault/bin/seed-canary.php && /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/check-http-endpoints.php'
```

每项任务创建后都要手动运行一次，确认退出码为 `0`，并在宝塔中开启失败通知和执行日志。服务器时区不是 UTC 时，应明确换算计划执行时间。

不要在应用运行期间直接复制 SQLite 主库。数据库使用 WAL 模式，直接复制可能遗漏尚未合并的数据；必须使用 `bin/backup.php` 或 SQLite `.backup`。

## 11. 日志轮转

应用日志会持续增长。以 `deploy/linkvault-logrotate.conf` 为基础创建 `/etc/logrotate.d/linkvault`，将应用日志段改为实际路径和用户：

```text
/www/wwwroot/linkvault/data/application.log {
    daily
    rotate 7
    maxage 7
    size 10M
    missingok
    notifempty
    compress
    delaycompress
    create 0640 www www
    su www www
}
```

该应用日志包含认证风控事件，最多保留 7 天。事件仅记录 IPv4 `/24` 或 IPv6 `/64` 网段标识，不记录 User-Agent；日志访问权限应仅授予安全运维人员。

分析原始日志禁止使用 `copytruncate`。轮转后必须向 Nginx 发送 `USR1` 重新打开日志，否则可能丢失或重复聚合。测试配置：

```bash
logrotate -d /etc/logrotate.d/linkvault
```

## 12. 升级

以下方法用于服务器上已经运行老版本、站点目录仍为 `/www/wwwroot/linkvault` 的情况。不要在宝塔文件管理器中直接全选覆盖老目录；这样容易覆盖数据库、日志或运行状态，也无法删除新版本已经废弃的代码文件。

### 12.1 升级前确认

1. 阅读新版本变更说明，确认 PHP、扩展和 Nginx 要求没有变化。跨多个版本升级时，不要跳过新版本包中的任何 `migrations/` 文件。
2. 确认网站根目录仍为 `/www/wwwroot/linkvault/public`，数据库和环境配置路径与第 4 节一致。
3. 将新版本压缩包上传到 `/www/backup/linkvault-release.zip`。压缩包中不要包含本地的 `data/`、`.env`、密码或 Token。
4. 在宝塔“计划任务”中暂停全部 LinkVault 任务，然后在“网站”中停用该站点或切换到静态维护页。不能只关闭管理页面，短链跳转和 API 也会写入数据库。
5. 等待当前计划任务结束。若任务脚本使用了第 10 节的 `flock`，可检查是否仍有任务持锁：

```bash
for lock in /run/lock/linkvault-*.lock; do
  [ -e "$lock" ] || continue
  flock -n "$lock" true || echo "仍在运行: $lock"
done
```

有“仍在运行”输出时先等待任务正常结束，不要在备份或迁移期间强制终止任务。

### 12.2 创建升级前备份

停止写流量后，使用老版本自带的在线备份命令创建一致性备份：

```bash
set -euo pipefail
set -a
. /etc/linkvault/linkvault.env
set +a

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/backup.php

sqlite3 /www/wwwroot/linkvault/data/linkvault.sqlite \
  'PRAGMA wal_checkpoint(TRUNCATE); PRAGMA integrity_check;'
```

最后一行必须输出 `0|0|0` 和 `ok`。同时记录 `backup.php` 输出的备份文件路径，并将 `/etc/linkvault/` 复制到仅 `root` 可读的站外备份目录：

```bash
UPGRADE_ID="$(date -u +%Y%m%d-%H%M%S)"
install -d -o root -g root -m 700 "/www/backup/linkvault-upgrade-${UPGRADE_ID}"
cp -a /etc/linkvault "/www/backup/linkvault-upgrade-${UPGRADE_ID}/"
printf '%s\n' "$UPGRADE_ID" > /root/.linkvault-upgrade-id
chmod 600 /root/.linkvault-upgrade-id
```

不要用 `cp` 直接复制正在运行的 `linkvault.sqlite` 作为数据库备份。`backup.php` 输出的文件才是数据库回滚源。`/root/.linkvault-upgrade-id` 用于后续命令在重新登录 SSH 后找到本次升级目录。

### 12.3 解压并替换代码

下面的命令假设压缩包解压后直接包含 `app/`、`bin/`、`migrations/`、`public/` 等目录。如果压缩包外层还有一个版本目录，应将 `NEW` 改成该目录。

```bash
set -euo pipefail

UPGRADE_ID="$(cat /root/.linkvault-upgrade-id)"
APP='/www/wwwroot/linkvault'
NEW="/www/backup/linkvault-upgrade-${UPGRADE_ID}/new"
OLD="/www/backup/linkvault-upgrade-${UPGRADE_ID}/old-code"

install -d -o root -g root -m 700 "$NEW" "$OLD"
unzip -q /www/backup/linkvault-release.zip -d "$NEW"

test -f "$NEW/config.php"
test -f "$NEW/bin/migrate.php"
test -d "$NEW/migrations"
test -f "$NEW/public/index.php"

rsync -a --delete \
  --exclude='/data/' \
  --exclude='/backups/' \
  --exclude='/restore-drill/' \
  "$APP/" "$OLD/"

rsync -a --delete \
  --exclude='/data/' \
  --exclude='/backups/' \
  --exclude='/restore-drill/' \
  --exclude='/.env' \
  "$NEW/" "$APP/"
```

第一个 `rsync` 保存可回滚的老代码副本，第二个 `rsync` 更新代码并删除已经废弃的代码文件；三个运行目录会原样保留。服务器没有 `rsync` 或 `unzip` 时，先通过系统包管理器安装，不要改成无排除规则的递归覆盖命令。

重新应用第 3 节的权限：

```bash
chown -R root:root /www/wwwroot/linkvault
chown -R www:www \
  /www/wwwroot/linkvault/data \
  /www/wwwroot/linkvault/backups \
  /www/wwwroot/linkvault/restore-drill

find /www/wwwroot/linkvault -type d \
  -not -path '/www/wwwroot/linkvault/data*' \
  -not -path '/www/wwwroot/linkvault/backups*' \
  -not -path '/www/wwwroot/linkvault/restore-drill*' \
  -exec chmod 755 {} \;

find /www/wwwroot/linkvault -type f \
  -not -path '/www/wwwroot/linkvault/data/*' \
  -not -path '/www/wwwroot/linkvault/backups/*' \
  -not -path '/www/wwwroot/linkvault/restore-drill/*' \
  -exec chmod 644 {} \;
```

### 12.4 更新配置并执行迁移

对照新包的 `deploy/linkvault.env.example` 检查是否增加了环境变量。保留原有密码、Token、TOTP 安全密钥和路径，不要用示例文件覆盖 `/etc/linkvault/linkvault.env`。至少更新以下发布信息，并在 `/etc/linkvault/linkvault.env` 与 `/etc/linkvault/linkvault-fastcgi.conf` 中保持一致：

```text
LINKVAULT_RELEASE_VERSION
LINKVAULT_BUILD_TIME
LINKVAULT_RELEASE_CHANGELOG
LINKVAULT_RELEASE_ROLLBACK_VERSION
```

其中 `LINKVAULT_RELEASE_ROLLBACK_VERSION` 填升级前版本。然后执行：

```bash
set -euo pipefail
set -a
. /etc/linkvault/linkvault.env
set +a

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/build-assets.php
runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/migrate.php
runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/optimize-database.php
runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/preflight.php
```

四条命令都必须成功，`preflight.php` 必须输出 `Production preflight passed.`。任一命令失败都不要恢复站点流量或计划任务，应先查看 PHP 错误日志和 `data/application.log`。

### 12.5 恢复服务并验收

先在宝塔中重载对应的 PHP-FPM，再检查并重载 Nginx：

```bash
/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
```

在宝塔中启用站点，然后检查：

```bash
curl --fail --silent --show-error https://s.example.com/livez
curl --fail --silent --show-error https://s.example.com/readyz
curl --fail --silent --show-error https://s.example.com/healthz
```

确认三个探针均返回成功、新版本号与构建时间正确，并实际创建、访问、停用一条测试短链。最后恢复全部 LinkVault 计划任务，各手动执行一次并检查退出码。升级稳定后再删除本次升级目录中的 `new/` 和 `/root/.linkvault-upgrade-id`；`old-code/` 与升级前数据库备份至少保留到本次发布观察期结束。

### 12.6 升级失败回滚

如果尚未执行 `migrate.php`，可直接用 `old-code/` 按第 12.3 节相同的排除规则覆盖回老代码，再恢复旧的 `/etc/linkvault/` 配置并重新执行旧版本预检。

如果已经执行过 `migrate.php`，不要只切回旧代码。数据库迁移通常不能靠旧代码撤销；应保持站点和计划任务停止，按第 14 节同时恢复 `backup.php` 创建的升级前数据库、`old-code/` 和升级前 `/etc/linkvault/` 配置。完成后重载 PHP-FPM 与 Nginx，并重新检查三个健康探针和测试短链。

## 13. 迁移已有数据库

不要直接上传运行中的 `linkvault.sqlite`、`linkvault.sqlite-wal` 和 `linkvault.sqlite-shm`。在源环境创建单文件在线备份：

```bash
sqlite3 data/linkvault.sqlite ".backup 'linkvault-deploy.sqlite'"
sqlite3 linkvault-deploy.sqlite "PRAGMA integrity_check;"
```

检查必须输出 `ok`。然后：

1. 暂停服务器写流量和计划任务。
2. 上传 `linkvault-deploy.sqlite` 到非公开临时路径。
3. 安装为 `/www/wwwroot/linkvault/data/linkvault.sqlite`。
4. 设置权限并执行迁移、预检。

```bash
install -o www -g www -m 640 /secure/path/linkvault-deploy.sqlite \
  /www/wwwroot/linkvault/data/linkvault.sqlite

set -a
. /etc/linkvault/linkvault.env
set +a

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/migrate.php
runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/preflight.php
```

## 14. 从备份恢复

恢复时不能有任何 PHP-FPM 请求或计划任务写入数据库：

1. 进入维护状态并暂停全部 LinkVault 计划任务。
2. 为当前数据库创建最后一份保留备份。
3. 对待恢复文件执行 `PRAGMA integrity_check`，结果必须为 `ok`。
4. 将备份安装为临时文件，再原子替换主库。
5. 确认应用已停止后，删除旧主库对应的 `-wal` 和 `-shm`。
6. 执行迁移、预检和健康检查。
7. 恢复流量与计划任务。

```bash
set -euo pipefail
set -a
. /etc/linkvault/linkvault.env
set +a

sqlite3 /secure/path/linkvault-backup.sqlite 'PRAGMA integrity_check;'
install -o www -g www -m 640 /secure/path/linkvault-backup.sqlite \
  /www/wwwroot/linkvault/data/linkvault.sqlite.restore
mv /www/wwwroot/linkvault/data/linkvault.sqlite.restore \
  /www/wwwroot/linkvault/data/linkvault.sqlite
rm -f /www/wwwroot/linkvault/data/linkvault.sqlite-wal \
  /www/wwwroot/linkvault/data/linkvault.sqlite-shm

runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/migrate.php
runuser -u www --preserve-environment -- \
  /www/server/php/85/bin/php /www/wwwroot/linkvault/bin/preflight.php
```

执行 `mv` 和删除 WAL/SHM 前必须确认应用及任务已经停止。至少每季度使用 `bin/restore-drill.php` 在隔离目录验证一次恢复能力。

## 15. 常见问题

### 503：应用未就绪

```bash
curl -i https://s.example.com/readyz
tail -n 100 /www/wwwroot/linkvault/data/application.log
```

常见原因：管理密码未传给 PHP-FPM、数据库未迁移、运行目录不可写、磁盘空间不足，或请求域名与 `LINKVAULT_BASE_URL` 不一致。

只有 `/healthz` 返回 `503`，通常表示备份不存在、校验失败或已经超过 `LINKVAULT_BACKUP_MAX_AGE_SECONDS`。

### 502：无法连接 PHP-FPM

```bash
ss -lx | grep -E 'php-cgi-85|php8.5'
/www/server/nginx/sbin/nginx -t
tail -n 100 /www/server/nginx/logs/error.log
```

将 `fastcgi_pass` 改成实际 Socket，并确认没有同时保留宝塔通用 PHP include 与自定义 `location = /index.php`。

### 404：路由未进入入口文件

确认网站根目录是 `public/`，并存在：

```nginx
try_files $uri $uri/ /index.php$is_args$args;
```

### open_basedir 或防跨站错误

将站点允许目录调整为 `/www/wwwroot/linkvault/`，并确保 CLI 任务可访问 SQLite、备份、日志和恢复目录。Web PHP 与 CLI PHP 的限制可能不同，需要分别检查。

### SQLite 扩展缺失

```bash
/www/server/php/85/bin/php --ini
/www/server/php/85/bin/php -m | grep -E 'pdo_sqlite|sqlite3'
```

还需要在 Web SAPI 中确认同一扩展已启用，不能只检查 CLI。

### CLI 与 Web 配置不一致

```bash
set -a
. /etc/linkvault/linkvault.env
set +a
/www/server/php/85/bin/php -r 'echo getenv("LINKVAULT_BASE_URL"), PHP_EOL;'
curl -sS https://s.example.com/healthz
```

如果状态中心显示的版本、路径或功能开关与 CLI 不一致，检查 `/etc/linkvault/linkvault-fastcgi.conf`，同步配置后重新加载 Nginx。

### Nginx 配置报错

`map`、`limit_req_zone`、`limit_conn_zone` 和 `log_format` 只能放在 `http` 上下文。站点配置框通常位于 `server` 上下文，不能直接粘贴 `deploy/nginx.conf` 顶部的全局段。

出现 `open() "/etc/linkvault/linkvault-fastcgi.conf" failed` 时，说明尚未完成第 4.2 节，或文件路径写错。创建并设置该文件权限后再执行 `nginx -t`，不要删除环境配置 include。

出现 `unknown directive "12}..."` 时，通常是宝塔配置编辑器拆分了含 `{12}` 的正则。使用第 5.1 节中 12 个连续 `[0-9a-f]` 的兼容写法，并确保整条 `location` 正则位于同一行。

## 16. 安全底线

- 不在仓库、站点目录、命令行参数或宝塔任务日志中保存真实密码和 Token。
- 不把项目根目录暴露为 Web 根目录。
- 不接受任意 Host，不对未知 Host 使用 `$host` 跳转。
- 不让 Nginx 执行 `index.php` 以外的 PHP 文件。
- 不在应用运行时直接复制 SQLite 主库。
- 不把恢复私钥传给 PHP-FPM Web worker。
- 不忽略迁移、预检、备份和恢复演练失败。
- 每次升级后同步 CLI 与 Web 环境，并重新验证三个健康探针。
