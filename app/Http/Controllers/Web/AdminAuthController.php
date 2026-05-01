<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = AdminUser::query()
            ->where('username', $request->string('username')->toString())
            ->where('is_active', true)
            ->first();

        if (! $admin || ! Hash::check($request->string('password')->toString(), $admin->password)) {
            return back()->withInput()->withErrors(['login' => '用户名或密码错误。']);
        }

        $request->session()->put('admin_user_id', $admin->id);
        $admin->update(['last_login_at' => now()]);

        return redirect('/admin');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_user_id');

        return redirect('/admin/login');
    }

    /* ── Update Profile (username + display_name) ── */

    public function updateProfile(Request $request)
    {
        $admin = $request->attributes->get('admin_user');

        $request->validate([
            'username'     => 'required|string|max:50|alpha_dash',
            'display_name' => 'required|string|max:80',
        ]);

        $newUsername = $request->string('username')->toString();

        // Ensure unique username (excluding self)
        $exists = AdminUser::query()
            ->where('username', $newUsername)
            ->where('id', '!=', $admin->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['username' => '该用户名已被占用。']);
        }

        $admin->update([
            'username'     => $newUsername,
            'display_name' => $request->string('display_name')->toString(),
        ]);

        return back()->with('status', '账户信息已更新。');
    }

    /* ── Send Password-Reset Verification Code ── */

    public function sendPasswordCode(Request $request)
    {
        $admin = $request->attributes->get('admin_user');

        if (! $admin->email) {
            return response()->json(['ok' => false, 'msg' => '尚未设置邮箱地址，无法发送验证码。'], 422);
        }

        // Rate limit: one code per 60 s
        $cacheKey = 'admin_pwd_code_' . $admin->id;
        $ttlKey   = 'admin_pwd_code_ttl_' . $admin->id;

        if (Cache::has($ttlKey)) {
            return response()->json(['ok' => false, 'msg' => '验证码已发送，请60秒后再试。'], 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($cacheKey, $code, now()->addMinutes(10));
        Cache::put($ttlKey, true, now()->addSeconds(60));

        // Send email via Laravel Mail
        try {
            $appName = config('app.name', 'Mifrog');
            $toEmail = $admin->email;
            $subject = "[{$appName}] 密码修改验证码";
            $body    = "你好 {$admin->display_name}，\n\n你正在修改管理后台密码，验证码为：\n\n    {$code}\n\n验证码10分钟内有效，请勿泄露。\n\n—— {$appName}";

            Mail::raw($body, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)->subject($subject);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[AdminAuth] Failed to send password code email', [
                'admin_id' => $admin->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'msg' => '邮件发送失败：' . $e->getMessage()], 500);
        }

        $masked = self::maskEmail($admin->email);

        return response()->json(['ok' => true, 'msg' => "验证码已发送至 {$masked}"]);
    }

    /* ── Change Password (with code verification) ── */

    public function changePassword(Request $request)
    {
        $admin = $request->attributes->get('admin_user');

        $request->validate([
            'current_password' => 'required|string',
            'code'             => 'required|string|size:6',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        // Verify current password
        if (! Hash::check($request->string('current_password')->toString(), $admin->password)) {
            return back()->withErrors(['current_password' => '当前密码不正确。']);
        }

        // Verify code
        $cacheKey   = 'admin_pwd_code_' . $admin->id;
        $storedCode = Cache::get($cacheKey);

        if (! $storedCode || $storedCode !== $request->string('code')->toString()) {
            return back()->withErrors(['code' => '验证码无效或已过期。']);
        }

        Cache::forget($cacheKey);
        Cache::forget('admin_pwd_code_ttl_' . $admin->id);

        $admin->update([
            'password' => Hash::make($request->string('new_password')->toString()),
        ]);

        return back()->with('status', '密码已成功修改。');
    }


    /* ── Forgot Password: Send Code (no auth required) ── */

    public function forgotSendCode(Request $request)
    {
        $request->validate(['username' => 'required|string']);
        $genericResponse = [
            'ok' => true,
            'msg' => '如果账号存在且已绑定邮箱，验证码已发送，请注意查收。',
        ];

        $admin = AdminUser::query()
            ->where('username', $request->input('username'))
            ->where('is_active', true)
            ->first();

        if (! $admin || ! $admin->email) {
            return response()->json($genericResponse);
        }

        $cacheKey = 'forgot_pwd_code_' . $admin->id;
        $ttlKey   = 'forgot_pwd_code_ttl_' . $admin->id;

        if (\Illuminate\Support\Facades\Cache::has($ttlKey)) {
            return response()->json($genericResponse);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($cacheKey, $code, now()->addMinutes(10));
        Cache::put($ttlKey, true, now()->addSeconds(60));

        try {
            $appName = config('app.name', 'Mifrog');
            $body = "你好 {$admin->display_name}，\n\n你正在通过「忘记密码」重置管理后台密码，验证码为：\n\n    {$code}\n\n验证码10分钟内有效，请勿泄露。\n\n—— {$appName}";

            Mail::raw($body, function ($message) use ($admin, $appName) {
                $message->to($admin->email)->subject("[{$appName}] 密码重置验证码");
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[AdminAuth] Forgot-password email failed', [
                'admin_id' => $admin->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'msg' => '邮件发送失败：' . $e->getMessage()], 500);
        }

        $masked = self::maskEmail($admin->email);
        return response()->json(['ok' => true, 'msg' => "验证码已发送至 {$masked}"]);
    }

    /* ── Forgot Password: Verify Code ── */

    public function forgotVerifyCode(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'code'     => 'required|string|size:6',
        ]);

        $admin = AdminUser::query()
            ->where('username', $request->input('username'))
            ->where('is_active', true)
            ->first();

        if (! $admin) {
            return response()->json(['ok' => false, 'msg' => '验证码无效或已过期。'], 422);
        }

        $cacheKey   = 'forgot_pwd_code_' . $admin->id;
        $storedCode = Cache::get($cacheKey);

        if (! $storedCode || $storedCode !== $request->input('code')) {
            return response()->json(['ok' => false, 'msg' => '验证码无效或已过期。'], 422);
        }

        // Generate a one-time reset token (valid 10 min)
        $token = bin2hex(random_bytes(32));
        Cache::put('forgot_reset_token_' . $token, $admin->id, now()->addMinutes(10));
        Cache::forget($cacheKey);

        return response()->json(['ok' => true, 'token' => $token]);
    }

    /* ── Forgot Password: Reset ── */

    public function forgotResetPassword(Request $request)
    {
        $request->validate([
            'token'        => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $token   = $request->input('token');
        $adminId = Cache::get('forgot_reset_token_' . $token);

        if (! $adminId) {
            return response()->json(['ok' => false, 'msg' => '重置令牌无效或已过期，请重新验证。'], 422);
        }

        $admin = AdminUser::find($adminId);
        if (! $admin) {
            return response()->json(['ok' => false, 'msg' => '用户不存在。'], 422);
        }

        $admin->update([
            'password' => Hash::make($request->input('new_password')),
        ]);

        Cache::forget('forgot_reset_token_' . $token);

        return response()->json(['ok' => true, 'msg' => '密码已重置，请使用新密码登录。']);
    }

    /* ── helpers ── */

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $len = mb_strlen($local);
        if ($len <= 2) {
            $masked = $local[0] . '***';
        } else {
            $masked = mb_substr($local, 0, 2) . str_repeat('*', max($len - 2, 3));
        }
        return $masked . '@' . $domain;
    }
}
