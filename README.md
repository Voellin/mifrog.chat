#### 全链路配置Step-by-step：https://vcno26d3qz3w.feishu.cn/docx/OSiGdCa1tooKd9x19COcVOa5nld

- Demo体验地址：https://vcno26d3qz3w.feishu.cn/docx/OSiGdCa1tooKd9x19COcVOa5nld

- 用户名：admin

- 密码：123456

### 中文版

#### 前置环境

在运行 `install.sh` 之前，宿主机需先具备以下基础设施（脚本本身**不**安装系统组件，避免与已有面板/CI 冲突）：

| 组件 | 版本 | 用途 |
|---|---|---|
| Linux x86-64 | 任意主流发行版（Ubuntu 22.04 / CentOS 7+ 已测试） | 操作系统 |
| PHP | **8.2 或更高** | Laravel 12 运行时 |
| PHP 扩展 | `pdo_mysql`, `redis`, `mbstring`, `bcmath`, `curl`, `gd`, `zip`, `openssl`, `xml`, `json`, `fileinfo`, `tokenizer` | Laravel + Mifrog 业务 |
| Composer | 2.x | PHP 包管理器 |
| MySQL | 5.7+ 或 8.0 | 主数据库 |
| Redis | 6+ | 缓存（可选）/ session |
| Nginx | 任意稳定版 | Web 服务器 |
| Supervisor | 任意稳定版 | 队列 worker 守护 |

如缺扩展，参考：

```bash
# Ubuntu / Debian
sudo apt install -y php8.2-mysql php8.2-redis php8.2-mbstring php8.2-bcmath \
                    php8.2-curl php8.2-gd php8.2-zip php8.2-xml

# CentOS / Rocky
sudo yum install -y php-mysqlnd php-redis php-mbstring php-bcmath \
                    php-curl php-gd php-zip php-xml
```

#### 部署步骤

```bash
# 1. 克隆仓库到任一稳定路径（推荐 /var/www/mifrog 或 /opt/mifrog）
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/Voellin/mifrog.chat.git mifrog
cd mifrog

# 2. 运行一键安装脚本
sudo bash install.sh
```

脚本会交互式询问：

- 应用域名（含协议，例 `https://mifrog.example.com`）
- Web 进程用户（默认 `www-data`）
- MySQL 主机/端口/root 凭据（用于建库 + 应用账号）
- 应用 DB 名/用户/密码（密码留空将自动生成）
- Redis 主机/端口

完成后会做这些事：

1. 检查 PHP 版本、扩展、Composer/MySQL/Redis 客户端是否齐全
2. 把仓库内 `bin/lark-cli` 复制到 `/usr/local/bin/lark-cli`
3. 自动创建 MySQL 数据库 + 应用账号
4. 写入 `.env`（含合理默认值）
5. `composer install --no-dev --optimize-autoloader`
6. `php artisan key:generate`、`storage:link`
7. 生成 Nginx / Supervisor / crontab 三个模板到 `install_artifacts/output/`
8. 设置文件权限

#### 完成基础设施配置

按脚本最后输出的提示：

```bash
# Nginx
sudo cp install_artifacts/output/nginx-mifrog.conf /etc/nginx/conf.d/
sudo nginx -t && sudo nginx -s reload     # 编辑文件填 SSL 证书路径

# Supervisor
sudo cp install_artifacts/output/supervisor-mifrog.ini /etc/supervisor/conf.d/   # Ubuntu/Debian
# 或 /etc/supervisord.d/   # CentOS
sudo supervisorctl reread && sudo supervisorctl update

# Crontab
sudo crontab -e
# 把 install_artifacts/output/mifrog-crontab.txt 内容粘贴进去
```

#### 完成 Web 安装向导

打开浏览器访问 `https://yourdomain.com/setup`，填写：

- 飞书自建应用：App ID / App Secret / Encrypt Key / Verification Token
- 模型 API：Base URL（默认 `https://api.openai.com/v1`）/ API Key / 模型名（如 `gpt-4o-mini`）
- 管理员账号：用户名 / 显示名 / 密码（不少于 8 位）
- 默认月度 Token 配额（0 = 不限制）

提交后系统会跑 migration、写入 setting、创建 admin 用户、生成 `storage/app/setup.lock`，最后跳转到 `/admin/login`。

#### 升级与维护

- 升级 Mifrog：`git pull && composer install --no-dev && php artisan migrate --force && supervisorctl restart mifrog:*`
- 升级 lark-cli：替换 `bin/lark-cli` → `cp bin/lark-cli /usr/local/bin/lark-cli`
- 重新进入安装向导：删除 `storage/app/setup.lock` 后访问 `/setup`

---

### English version

#### Prerequisites

Before running `install.sh`, the host must have:

| Component | Version | Purpose |
|---|---|---|
| Linux x86-64 | Ubuntu 22.04 / CentOS 7+ tested | OS |
| PHP | **8.2+** | Laravel 12 runtime |
| PHP extensions | `pdo_mysql`, `redis`, `mbstring`, `bcmath`, `curl`, `gd`, `zip`, `openssl`, `xml`, `json`, `fileinfo`, `tokenizer` | Laravel + Mifrog |
| Composer | 2.x | PHP package manager |
| MySQL | 5.7+ or 8.0 | Primary database |
| Redis | 6+ | Cache (optional) / sessions |
| Nginx | any stable | Web server |
| Supervisor | any stable | Queue worker daemon |

`install.sh` does **not** install OS-level components, to avoid conflicts with control panels (BT Panel, cPanel, Plesk) or CI agents.

#### Install steps

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/Voellin/mifrog.chat.git mifrog
cd mifrog
sudo bash install.sh
```

The script will interactively ask for:

- App URL (with scheme, e.g. `https://mifrog.example.com`)
- Web process user (default `www-data`)
- MySQL host/port + root credentials (for DB and app-user creation)
- App DB name/user/password (blank password is auto-generated)
- Redis host/port

Then it will:

1. Check PHP version, extensions, and clients
2. Copy `bin/lark-cli` to `/usr/local/bin/lark-cli`
3. Create the MySQL database and app user
4. Write `.env` with reasonable defaults
5. `composer install --no-dev --optimize-autoloader`
6. Run `php artisan key:generate` and `storage:link`
7. Render Nginx / Supervisor / crontab templates into `install_artifacts/output/`
8. Set file permissions

#### Wire up the infrastructure

Follow the script's final guidance to copy the generated artifacts:

```bash
sudo cp install_artifacts/output/nginx-mifrog.conf /etc/nginx/conf.d/
sudo nginx -t && sudo nginx -s reload   # Then edit the file to fill SSL paths

sudo cp install_artifacts/output/supervisor-mifrog.ini /etc/supervisor/conf.d/   # Ubuntu/Debian
# Or /etc/supervisord.d/                                                          # CentOS
sudo supervisorctl reread && sudo supervisorctl update

sudo crontab -e
# Paste content of install_artifacts/output/mifrog-crontab.txt
```

#### Complete the web wizard

Browse to `https://yourdomain.com/setup` and fill in:

- Feishu custom app: App ID / Secret / Encrypt Key / Verification Token
- Model API: Base URL (default `https://api.openai.com/v1`) / API Key / model name (e.g. `gpt-4o-mini`)
- Admin account: username / display name / password (>= 8 chars)
- Default monthly token quota (0 means unlimited)

On submit, Laravel runs the schema dump, writes settings, creates the admin user, and saves `storage/app/setup.lock`. You'll be redirected to `/admin/login`.

#### Upgrade & maintenance

- Upgrade Mifrog: `git pull && composer install --no-dev && php artisan migrate --force && supervisorctl restart mifrog:*`
- Upgrade lark-cli: replace `bin/lark-cli` then `cp bin/lark-cli /usr/local/bin/lark-cli`
- Re-enter the install wizard: delete `storage/app/setup.lock`, then visit `/setup` again
