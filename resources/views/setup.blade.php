<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mifrog Setup Wizard</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/favicon-192.png">
    <style>
        body { font-family: "Segoe UI", sans-serif; margin: 0; background: #f5f7fb; color: #111827; }
        .wrap { max-width: 920px; margin: 24px auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
        h1 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        label { font-size: 14px; color: #374151; display: block; margin-bottom: 6px; }
        input { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; }
        .full { grid-column: 1 / -1; }
        .err { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; margin-bottom: 14px; }
        button { margin-top: 14px; background: #0f766e; color: white; border: 0; border-radius: 8px; padding: 12px 18px; cursor: pointer; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Mifrog Setup Wizard</h1>
    <p>Fill in the initial deployment configuration. The installer will run database migrations automatically.</p>

    @if($errors->any())
        <div class="err">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="post" action="/setup">
        @csrf
        <div class="grid">
            <div>
                <label>DB_HOST</label>
                <input name="db_host" value="{{ old('db_host', '127.0.0.1') }}" required>
            </div>
            <div>
                <label>DB_PORT</label>
                <input name="db_port" value="{{ old('db_port', '3306') }}" required>
            </div>
            <div>
                <label>DB_DATABASE</label>
                <input name="db_database" value="{{ old('db_database') }}" required>
            </div>
            <div>
                <label>DB_USERNAME</label>
                <input name="db_username" value="{{ old('db_username') }}" required>
            </div>
            <div class="full">
                <label>DB_PASSWORD</label>
                <input name="db_password" type="password" value="{{ old('db_password') }}">
            </div>
            <div>
                <label>Feishu APP ID</label>
                <input name="feishu_app_id" value="{{ old('feishu_app_id') }}">
            </div>
            <div>
                <label>Feishu APP SECRET</label>
                <input name="feishu_app_secret" type="password" value="{{ old('feishu_app_secret') }}">
            </div>
            <div class="full">
                <label>Feishu Encrypt Key (optional)</label>
                <input name="feishu_encrypt_key" type="password" value="{{ old('feishu_encrypt_key') }}">
            </div>
            <div class="full">
                <label>Feishu Verification Token (optional)</label>
                <input name="feishu_verification_token" type="password" value="{{ old('feishu_verification_token') }}">
            </div>
            <div>
                <label>Model Gateway Base URL</label>
                <input name="model_base_url" value="{{ old('model_base_url', 'https://api.openai.com/v1') }}">
            </div>
            <div>
                <label>Default Model Name</label>
                <input name="model_name" value="{{ old('model_name', 'gpt-4o-mini') }}">
            </div>
            <div class="full">
                <label>Model API KEY</label>
                <input name="model_api_key" type="password" value="{{ old('model_api_key') }}">
            </div>
            <div>
                <label>Admin Username</label>
                <input name="admin_username" value="{{ old('admin_username', 'admin') }}" required>
            </div>
            <div>
                <label>Admin Display Name</label>
                <input name="admin_display_name" value="{{ old('admin_display_name', 'Mifrog Admin') }}" required>
            </div>
            <div>
                <label>Admin Password</label>
                <input name="admin_password" type="password" required>
            </div>
            <div>
                <label>Default Monthly Quota (tokens)</label>
                <input name="default_monthly_quota_tokens" value="{{ old('default_monthly_quota_tokens', '0') }}">
            </div>
        </div>
        <button type="submit">Run Setup</button>
    </form>
</div>
</body>
</html>
