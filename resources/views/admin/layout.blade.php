<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '米蛙管理后台')</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/css/admin-pro.css?v={{ @filemtime(public_path('css/admin-pro.css')) }}">
    @stack('head')
</head>
<body>
@php
    $enterprise = \App\Models\Setting::read('enterprise_profile', []);
    $brandName = trim((string) ($enterprise['name'] ?? ''));
    $brandName = $brandName !== '' ? $brandName : '米蛙后台';
    $brandLogo = trim((string) ($enterprise['logo_url'] ?? ''));
    $adminUser = request()->attributes->get('admin_user');
    $adminCan = fn (string $permission): bool => $adminUser && method_exists($adminUser, 'hasAdminPermission') && $adminUser->hasAdminPermission($permission);
    $adminName = trim((string) ($adminUser->display_name ?? $adminUser->username ?? 'admin'));
    $adminInitial = function_exists('mb_substr') ? mb_substr($adminName, 0, 1, 'UTF-8') : substr($adminName, 0, 1);
    $settingsTab = strtolower(trim((string) request()->query('tab', 'channel')));
    if (! in_array($settingsTab, ['channel', 'model', 'enterprise'], true)) {
        $settingsTab = 'channel';
    }
    $settingsOpen = request()->is('admin/settings*') || request()->is('admin/accounts*') || request()->is('admin/operation-logs*');
@endphp

<div class="pro-app">
    <aside class="pro-sider" id="pro-sider">
        <div class="pro-brand">
            @if($brandLogo !== '')
                <img class="pro-brand-logo" src="{{ $brandLogo }}" alt="logo">
            @else
                <div class="pro-brand-mark">M</div>
            @endif
            <div style="min-width:0;">
                <div class="pro-brand-title" title="{{ $brandName }}">{{ $brandName }}</div>
                <div class="pro-brand-sub">Mifrog Admin Console</div>
            </div>
        </div>

        <nav class="pro-nav">
            @php
                $navItems = [
                    ['permission' => 'dashboard.view', 'href' => '/admin', 'label' => '仪表盘', 'active' => request()->path() === 'admin', 'icon' => 'M4 13h6V4H4v9Zm10 7h6v-9h-6v9ZM4 20h6v-5H4v5Zm10-9h6V4h-6v7Z'],
                    ['permission' => 'users.view', 'href' => '/admin/users', 'label' => '用户管理', 'active' => request()->is('admin/users*'), 'icon' => 'M16 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.31 0-6 1.79-6 4v2h8v-2c0-1.2.43-2.33 1.16-3.27A9.2 9.2 0 0 0 8 14Zm8 0c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z'],
                    ['permission' => 'skills.view', 'href' => '/admin/skills', 'label' => '技能管理', 'active' => request()->is('admin/skills*'), 'icon' => 'M20 6h-3.17A3 3 0 0 0 14 4H10a3 3 0 0 0-2.83 2H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2ZM10 6h4v2h-4V6Z'],
                    ['permission' => 'memory.view', 'href' => '/admin/memory', 'label' => '记忆中心', 'active' => request()->is('admin/memory*'), 'icon' => 'M6 4h8a4 4 0 0 1 4 4v10a2 2 0 0 1-2 2H6a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4Zm2 5h6v2H8V9Zm0 4h4v2H8v-2Z'],
                    ['permission' => 'audits.view', 'href' => '/admin/audits', 'label' => '审计中心', 'active' => request()->is('admin/audits*'), 'icon' => 'M11 3 4 6v5c0 5.25 3.62 10.16 8.5 11.43A12.2 12.2 0 0 0 21 11V6l-7-3h-3Zm0 7h2v5h-2v-5Zm0 6h2v2h-2v-2Z'],
                    ['permission' => 'doppelganger.view', 'href' => '/admin/doppelgangers', 'label' => '数字分身', 'active' => request()->is('admin/doppelgangers*'), 'icon' => 'M12 2a5 5 0 0 0-5 5v2a5 5 0 0 0 4 4.9V14a3 3 0 0 0-3 3v3h2v-3a1 1 0 0 1 2 0v3h2v-3a1 1 0 0 1 2 0v3h2v-3a3 3 0 0 0-3-3v-.1A5 5 0 0 0 17 9V7a5 5 0 0 0-5-5Zm-3 5a3 3 0 0 1 6 0v2a3 3 0 0 1-6 0V7Z'],
                ];
            @endphp

            @foreach($navItems as $item)
                @if($adminCan($item['permission']))
                    <a class="pro-nav-item {{ $item['active'] ? 'active' : '' }}" href="{{ $item['href'] }}">
                        <span class="pro-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="{{ $item['icon'] }}" fill="currentColor"/></svg></span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endif
            @endforeach

            @if($adminCan('settings.view') || $adminCan('admin_accounts.view') || $adminCan('ops_log.view'))
                <div class="pro-nav-group {{ $settingsOpen ? 'open' : '' }}" id="pro-settings-group">
                    <button type="button" class="pro-nav-item pro-nav-group-trigger {{ $settingsOpen ? 'active' : '' }}" id="pro-settings-trigger" aria-expanded="{{ $settingsOpen ? 'true' : 'false' }}">
                        <span class="pro-nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><path d="m19.14 12.94.86-1.49-1.84-3.19-1.7.34a5.44 5.44 0 0 0-1.19-.69l-.25-1.71h-3.68l-.25 1.71a5.48 5.48 0 0 0-1.2.69l-1.7-.34L4.86 11.45l.86 1.49c-.03.31-.03.63 0 .94l-.86 1.49 1.84 3.19 1.7-.34c.37.28.77.51 1.2.69l.25 1.71h3.68l.25-1.71c.42-.18.82-.41 1.19-.69l1.7.34 1.84-3.19-.86-1.49c.03-.31.03-.63 0-.94ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z" fill="currentColor"/></svg>
                        </span>
                        <span>系统配置</span>
                        <span class="pro-nav-caret" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    </button>
                    <div class="pro-nav-sub">
                        @if($adminCan('settings.view'))
                            <a class="pro-nav-sub-item {{ request()->is('admin/settings*') && $settingsTab === 'channel' ? 'active' : '' }}" href="/admin/settings?tab=channel">渠道配置</a>
                            <a class="pro-nav-sub-item {{ request()->is('admin/settings*') && $settingsTab === 'model' ? 'active' : '' }}" href="/admin/settings?tab=model">模型配置</a>
                            <a class="pro-nav-sub-item {{ request()->is('admin/settings*') && $settingsTab === 'enterprise' ? 'active' : '' }}" href="/admin/settings?tab=enterprise">企业配置</a>
                        @endif
                        @if($adminCan('admin_accounts.view'))
                            <a class="pro-nav-sub-item {{ request()->is('admin/accounts*') ? 'active' : '' }}" href="/admin/accounts">账号配置</a>
                        @endif
                        @if($adminCan('ops_log.view'))
                            <a class="pro-nav-sub-item {{ request()->is('admin/operation-logs*') ? 'active' : '' }}" href="/admin/operation-logs">操作日志</a>
                        @endif
                    </div>
                </div>
            @endif
        </nav>

        <div class="pro-sider-foot">
            <button type="button" class="pro-user-trigger" id="pro-user-trigger" aria-expanded="false" aria-controls="pro-user-pop">
                <span class="pro-user-avatar">{{ $adminInitial }}</span>
                <span class="pro-user-name">{{ $adminName }}</span>
                <span class="pro-user-caret">
                    <svg viewBox="0 0 24 24" fill="none"><path d="m7 10 5 5 5-5H7Z" fill="currentColor"/></svg>
                </span>
            </button>
            <div class="pro-user-pop" id="pro-user-pop" role="menu">
                <div class="pro-user-pop-title">{{ $adminName }}</div>
                <button type="button" class="pro-user-pop-item" onclick="document.getElementById('modal-profile').classList.add('open')">
                    <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-8 2-8 4v2h16v-2c0-2-4-4-8-4Z" fill="currentColor"/></svg>
                    修改账户信息
                </button>
                <button type="button" class="pro-user-pop-item" onclick="document.getElementById('modal-password').classList.add('open')">
                    <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM12 17a2 2 0 1 1 0-4 2 2 0 0 1 0 4ZM9 8V6a3 3 0 0 1 6 0v2H9Z" fill="currentColor"/></svg>
                    修改密码
                </button>
                <div style="border-top:1px solid #e8eff2; margin:4px 0;"></div>
                <form method="post" action="/admin/logout" style="margin:0;">
                    @csrf
                    <button type="submit" class="pro-user-pop-btn">退出登录</button>
                </form>
            </div>
        </div>
    </aside>

    <button class="pro-sider-mask" id="pro-sider-mask" type="button" aria-label="关闭菜单"></button>

    <div class="pro-main">
        <button type="button" class="pro-nav-toggle pro-nav-toggle-floating" id="pro-nav-toggle" aria-label="打开菜单">
            <svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>

        <main class="pro-content">
            @hasSection('header-actions')
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:14px;">
                    @yield('header-actions')
                </div>
            @endif

            @if(session('status'))
                <div class="pro-alert pro-alert-success" data-alert-auto-close>{{ session('status') }}</div>
            @endif

            @if(session('error'))
                <div class="pro-alert pro-alert-error">{{ session('error') }}</div>
            @endif

            @if($errors->any())
                <div class="pro-alert pro-alert-error">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    (function () {
        const trigger = document.getElementById('pro-user-trigger');
        const pop = document.getElementById('pro-user-pop');
        if (!trigger || !pop) {
            return;
        }

        const closePop = () => {
            pop.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        };

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const shouldOpen = !pop.classList.contains('open');
            pop.classList.toggle('open');
            trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (!pop.contains(event.target) && !trigger.contains(event.target)) {
                closePop();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closePop();
            }
        });
    })();

    (function () {
        const trigger = document.getElementById('pro-settings-trigger');
        const group = document.getElementById('pro-settings-group');
        if (!trigger || !group) {
            return;
        }

        trigger.addEventListener('click', function () {
            group.classList.toggle('open');
            trigger.setAttribute('aria-expanded', group.classList.contains('open') ? 'true' : 'false');
        });
    })();

    (function () {
        const navToggle = document.getElementById('pro-nav-toggle');
        const navMask = document.getElementById('pro-sider-mask');

        const closeNav = () => document.body.classList.remove('pro-nav-open');
        const openNav = () => document.body.classList.add('pro-nav-open');

        if (navToggle) {
            navToggle.addEventListener('click', function () {
                const opened = document.body.classList.contains('pro-nav-open');
                if (opened) {
                    closeNav();
                    return;
                }
                openNav();
            });
        }

        if (navMask) {
            navMask.addEventListener('click', closeNav);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeNav();
            }
        });
    })();

    (function () {
        const normalizeText = (text) => (text || '').replace(/\s+/g, ' ').trim();

        document.querySelectorAll('.pro-table-wrap table').forEach((table) => {
            const headRow = table.querySelector('thead tr');
            if (!headRow) {
                return;
            }

            const headers = Array.from(headRow.children).filter((cell) => /^(TH|TD)$/i.test(cell.tagName));
            if (headers.length === 0) {
                return;
            }

            table.style.setProperty('--pro-col-count', String(headers.length));

            let actionColumnIndex = -1;
            headers.forEach((header, index) => {
                const text = normalizeText(header.textContent);
                if (text === '操作') {
                    actionColumnIndex = index;
                }
            });

            table.querySelectorAll('tr').forEach((row) => {
                const cells = Array.from(row.children).filter((cell) => /^(TH|TD)$/i.test(cell.tagName));
                cells.forEach((cell, index) => {
                    if (index === actionColumnIndex) {
                        cell.classList.add('pro-col-action');
                        return;
                    }

                    cell.classList.add('pro-col-ellipsis');

                    if (cell.querySelector('input,button,select,textarea,form,.pro-inline-actions')) {
                        return;
                    }

                    const fullText = normalizeText(cell.textContent);
                    if (fullText !== '' && !cell.hasAttribute('title')) {
                        cell.setAttribute('title', fullText);
                    }
                });
            });
        });
    })();

    (function () {
        document.querySelectorAll('[data-alert-auto-close]').forEach((alertEl) => {
            setTimeout(() => {
                alertEl.style.transition = 'opacity 0.24s ease, transform 0.24s ease';
                alertEl.style.opacity = '0';
                alertEl.style.transform = 'translateY(-4px)';
                setTimeout(() => {
                    if (alertEl.parentNode) {
                        alertEl.parentNode.removeChild(alertEl);
                    }
                }, 260);
            }, 4000);
        });
    })();
</script>

<!-- ─── Modal: Edit Profile ─── -->
<div class="pro-modal-mask" id="modal-profile">
    <div class="pro-modal">
        <div class="pro-modal-header">
            <h3>修改账户信息</h3>
            <button type="button" class="pro-modal-close" onclick="this.closest('.pro-modal-mask').classList.remove('open')">&times;</button>
        </div>
        <form method="post" action="/admin/profile">
            @csrf
            <div class="pro-modal-body">
                <label class="pro-field-label">登录用户名</label>
                <input type="text" name="username" class="pro-input" value="{{ $adminUser->username ?? '' }}" required>
                <label class="pro-field-label" style="margin-top:12px;">展示名称</label>
                <input type="text" name="display_name" class="pro-input" value="{{ $adminUser->display_name ?? '' }}" required>
            </div>
            <div class="pro-modal-footer">
                <button type="button" class="pro-btn pro-btn-ghost" onclick="this.closest('.pro-modal-mask').classList.remove('open')">取消</button>
                <button type="submit" class="pro-btn pro-btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal: Change Password ─── -->
<div class="pro-modal-mask" id="modal-password">
    <div class="pro-modal">
        <div class="pro-modal-header">
            <h3>修改密码</h3>
            <button type="button" class="pro-modal-close" onclick="this.closest('.pro-modal-mask').classList.remove('open')">&times;</button>
        </div>
        <form method="post" action="/admin/password/change" id="form-change-pwd">
            @csrf
            <div class="pro-modal-body">
                <label class="pro-field-label">当前密码</label>
                <input type="password" name="current_password" class="pro-input" required>

                <label class="pro-field-label" style="margin-top:12px;">邮箱验证码</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" name="code" class="pro-input" maxlength="6" placeholder="6位验证码" required style="flex:1;">
                    <button type="button" class="pro-btn pro-btn-outline" id="btn-send-code" onclick="sendPwdCode(this)">发送验证码</button>
                </div>
                <div id="code-msg" style="font-size:12px; color:var(--pro-text-secondary); margin-top:4px;"></div>

                <label class="pro-field-label" style="margin-top:12px;">新密码</label>
                <input type="password" name="new_password" class="pro-input" minlength="8" required>

                <label class="pro-field-label" style="margin-top:12px;">确认新密码</label>
                <input type="password" name="new_password_confirmation" class="pro-input" minlength="8" required>
            </div>
            <div class="pro-modal-footer">
                <button type="button" class="pro-btn pro-btn-ghost" onclick="this.closest('.pro-modal-mask').classList.remove('open')">取消</button>
                <button type="submit" class="pro-btn pro-btn-primary">确认修改</button>
            </div>
        </form>
    </div>
</div>

<script>
function sendPwdCode(btn) {
    btn.disabled = true;
    var msgEl = document.getElementById('code-msg');
    msgEl.textContent = '正在发送…';
    msgEl.style.color = 'var(--pro-text-secondary)';

    fetch('/admin/password/send-code', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            msgEl.textContent = data.msg;
            msgEl.style.color = 'var(--pro-success)';
            var sec = 60;
            var timer = setInterval(function() {
                sec--;
                btn.textContent = sec + '秒后重发';
                if (sec <= 0) {
                    clearInterval(timer);
                    btn.textContent = '发送验证码';
                    btn.disabled = false;
                }
            }, 1000);
        } else {
            msgEl.textContent = data.msg;
            msgEl.style.color = 'var(--pro-error)';
            btn.disabled = false;
        }
    })
    .catch(function(err) {
        msgEl.textContent = '网络错误，请重试。';
        msgEl.style.color = 'var(--pro-error)';
        btn.disabled = false;
    });
}
</script>

@stack('scripts')
</body>
</html>
