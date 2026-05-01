<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 2000 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Cubet Techno Labs](https://cubettech.com)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[Many](https://www.many.co.uk)**
- **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
- **[DevSquad](https://devsquad.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[OP.GG](https://op.gg)**
- **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
- **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## 部署 / Deployment

> **English version below in this section.**

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
sudo git clone https://github.com/Voellin/Mifrog.git mifrog
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
sudo git clone https://github.com/Voellin/Mifrog.git mifrog
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
