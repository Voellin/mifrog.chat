<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>米蛙管理后台登录</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="/css/admin-pro.css?v={{ @filemtime(public_path('css/admin-pro.css')) }}">
</head>
<body class="pro-auth">
<div class="pro-auth-card">
    <h1 class="pro-auth-title">米蛙管理后台</h1>
    <div class="pro-auth-desc">企业智能助手配置中心</div>

    @if(session('status'))
        <div class="pro-alert pro-alert-success" style="margin-top:14px;">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="pro-alert pro-alert-error" style="margin-top:14px;">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="post" action="/admin/login" style="margin-top:14px;" class="pro-grid">
        @csrf
        <div class="pro-field">
            <label>用户名</label>
            <input type="text" name="username" value="{{ old('username') }}" required>
        </div>

        <div class="pro-field">
            <label>密码</label>
            <input name="password" type="password" required>
        </div>

        <button type="submit" class="pro-btn pro-btn-primary" style="width:100%;">登录</button>
    </form>

    <div style="text-align:center; margin-top:12px;">
        <button type="button" class="pro-link-btn" onclick="document.getElementById('modal-forgot').classList.add('open')">忘记密码？</button>
    </div>
</div>

<!-- ─── Modal: Forgot Password ─── -->
<div class="pro-modal-mask" id="modal-forgot">
    <div class="pro-modal">
        <div class="pro-modal-header">
            <h3>重置密码</h3>
            <button type="button" class="pro-modal-close" onclick="this.closest('.pro-modal-mask').classList.remove('open')">&times;</button>
        </div>
        <div class="pro-modal-body" id="forgot-step-1">
            <p style="font-size:13px; color:var(--pro-text-secondary); margin-bottom:12px;">输入你的管理员用户名，我们将向绑定的邮箱发送验证码。</p>
            <label class="pro-field-label">用户名</label>
            <input type="text" id="forgot-username" class="pro-input" required>

            <div style="display:flex; gap:8px; margin-top:12px;">
                <label class="pro-field-label" style="flex:1; margin:0;">
                    <span style="display:block; margin-bottom:4px;">验证码</span>
                    <input type="text" id="forgot-code" class="pro-input" maxlength="6" placeholder="6位验证码">
                </label>
                <div style="display:flex; align-items:flex-end;">
                    <button type="button" class="pro-btn pro-btn-outline" id="btn-forgot-send" onclick="forgotSendCode()">发送验证码</button>
                </div>
            </div>
            <div id="forgot-msg" style="font-size:12px; margin-top:6px; min-height:18px;"></div>
        </div>

        <div class="pro-modal-body" id="forgot-step-2" style="display:none;">
            <p style="font-size:13px; color:var(--pro-success); margin-bottom:12px;" id="forgot-verified-msg">验证码验证通过</p>
            <label class="pro-field-label">新密码</label>
            <input type="password" id="forgot-new-pwd" class="pro-input" minlength="8">
            <label class="pro-field-label" style="margin-top:12px;">确认新密码</label>
            <input type="password" id="forgot-new-pwd2" class="pro-input" minlength="8">
            <div id="forgot-pwd-msg" style="font-size:12px; margin-top:6px; min-height:18px;"></div>
        </div>

        <div class="pro-modal-footer">
            <button type="button" class="pro-btn pro-btn-ghost" onclick="this.closest('.pro-modal-mask').classList.remove('open')">取消</button>
            <button type="button" class="pro-btn pro-btn-primary" id="btn-forgot-next" onclick="forgotVerifyCode()">验证</button>
            <button type="button" class="pro-btn pro-btn-primary" id="btn-forgot-reset" style="display:none;" onclick="forgotResetPwd()">重置密码</button>
        </div>
    </div>
</div>

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function forgotSendCode() {
    var btn = document.getElementById('btn-forgot-send');
    var msgEl = document.getElementById('forgot-msg');
    var username = document.getElementById('forgot-username').value.trim();
    if (!username) { msgEl.textContent = '请输入用户名'; msgEl.style.color = 'var(--pro-error)'; return; }

    btn.disabled = true;
    msgEl.textContent = '正在发送…';
    msgEl.style.color = 'var(--pro-text-secondary)';

    fetch('/admin/password/forgot/send-code', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username })
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
                if (sec <= 0) { clearInterval(timer); btn.textContent = '发送验证码'; btn.disabled = false; }
            }, 1000);
        } else {
            msgEl.textContent = data.msg;
            msgEl.style.color = 'var(--pro-error)';
            btn.disabled = false;
        }
    })
    .catch(function() { msgEl.textContent = '网络错误'; msgEl.style.color = 'var(--pro-error)'; btn.disabled = false; });
}

function forgotVerifyCode() {
    var msgEl = document.getElementById('forgot-msg');
    var username = document.getElementById('forgot-username').value.trim();
    var code = document.getElementById('forgot-code').value.trim();
    if (!username || !code) { msgEl.textContent = '请填写用户名和验证码'; msgEl.style.color = 'var(--pro-error)'; return; }

    var btn = document.getElementById('btn-forgot-next');
    btn.disabled = true;

    fetch('/admin/password/forgot/verify', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, code: code })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            document.getElementById('forgot-step-1').style.display = 'none';
            document.getElementById('forgot-step-2').style.display = 'block';
            btn.style.display = 'none';
            document.getElementById('btn-forgot-reset').style.display = 'inline-flex';
            // store token
            window._forgotToken = data.token;
        } else {
            msgEl.textContent = data.msg;
            msgEl.style.color = 'var(--pro-error)';
            btn.disabled = false;
        }
    })
    .catch(function() { msgEl.textContent = '网络错误'; msgEl.style.color = 'var(--pro-error)'; btn.disabled = false; });
}

function forgotResetPwd() {
    var msgEl = document.getElementById('forgot-pwd-msg');
    var pwd = document.getElementById('forgot-new-pwd').value;
    var pwd2 = document.getElementById('forgot-new-pwd2').value;

    if (pwd.length < 8) { msgEl.textContent = '密码至少8个字符'; msgEl.style.color = 'var(--pro-error)'; return; }
    if (pwd !== pwd2) { msgEl.textContent = '两次密码不一致'; msgEl.style.color = 'var(--pro-error)'; return; }

    var btn = document.getElementById('btn-forgot-reset');
    btn.disabled = true;

    fetch('/admin/password/forgot/reset', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: window._forgotToken, new_password: pwd, new_password_confirmation: pwd2 })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            msgEl.textContent = data.msg;
            msgEl.style.color = 'var(--pro-success)';
            setTimeout(function() { window.location.reload(); }, 1500);
        } else {
            msgEl.textContent = data.msg;
            msgEl.style.color = 'var(--pro-error)';
            btn.disabled = false;
        }
    })
    .catch(function() { msgEl.textContent = '网络错误'; msgEl.style.color = 'var(--pro-error)'; btn.disabled = false; });
}
</script>
</body>
</html>
