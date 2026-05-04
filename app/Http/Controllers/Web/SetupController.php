<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ModelKey;
use App\Models\ModelProvider;
use App\Models\Setting;
use App\Services\LlmGateway\VendorKeyInferrer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PDO;
use Throwable;

class SetupController extends Controller
{
    public function index()
    {
        if (file_exists(storage_path('app/setup.lock'))) {
            return redirect('/admin/login');
        }

        return view('setup');
    }

    public function store(Request $request)
    {
        if (file_exists(storage_path('app/setup.lock'))) {
            return redirect('/admin/login');
        }

        $validator = Validator::make($request->all(), [
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
            'feishu_app_id' => 'nullable|string',
            'feishu_app_secret' => 'nullable|string',
            'feishu_encrypt_key' => 'nullable|string',
            'feishu_verification_token' => 'nullable|string',
            'model_base_url' => 'nullable|string',
            'model_api_key' => 'nullable|string',
            'model_name' => 'nullable|string',
            'admin_username' => 'required|string|min:3',
            'admin_display_name' => 'required|string|min:2',
            'admin_password' => 'required|string|min:8',
            'default_monthly_quota_tokens' => 'nullable|integer|min:0',
        ]);
        $validator->validate();

        $this->probeDatabase($request);
        $this->writeEnv($request);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $request->string('db_host')->toString(),
            'database.connections.mysql.port' => (string) $request->integer('db_port'),
            'database.connections.mysql.database' => $request->string('db_database')->toString(),
            'database.connections.mysql.username' => $request->string('db_username')->toString(),
            'database.connections.mysql.password' => $request->input('db_password'),
            'queue.default' => 'database',
        ]);

        try {
            Artisan::call('config:clear');
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors([
                'setup' => 'Migration failed: '.$e->getMessage(),
            ]);
        }

        DB::transaction(function () use ($request): void {
            AdminUser::query()->updateOrCreate(
                ['username' => $request->string('admin_username')->toString()],
                [
                    'display_name' => $request->string('admin_display_name')->toString(),
                    'password' => Hash::make($request->string('admin_password')->toString()),
                    'is_active' => true,
                ]
            );

            Setting::write('feishu', [
                'app_id' => $request->input('feishu_app_id'),
                'app_secret' => $request->input('feishu_app_secret'),
                'encrypt_key' => $request->input('feishu_encrypt_key'),
                'verification_token' => $request->input('feishu_verification_token'),
            ]);

            // R3c (丁方案 + A2 直切): 不再写 Setting('model_gateway')；按 vendor_key upsert ModelProvider，
            // 写两个新 settings (active_main_model_id / active_vision_model_id)
            $baseUrl = trim((string) $request->input('model_base_url', 'https://api.openai.com/v1'));
            $model = (string) $request->input('model_name', 'gpt-4o-mini');
            $apiKey = (string) $request->input('model_api_key', '');
            $vendorKey = VendorKeyInferrer::infer($baseUrl);

            $modelEntry = [
                'capability' => 'text',
                'capabilities' => ['text'],
                'model_id' => $model,
                'label' => 'default',
            ];

            $defaultQuota = (int) $request->input('default_monthly_quota_tokens', 0);
            Setting::write('default_monthly_quota_tokens', $defaultQuota);
            Setting::write('retention_days', 180);
            Setting::write('admin_api_token', bin2hex(random_bytes(24)));
            Setting::write('active_main_model_id', $model);
            Setting::write('active_vision_model_id', ''); // 首次安装不预设视觉覆盖

            $existingProvider = ModelProvider::query()->where('vendor_key', $vendorKey)->first();
            if ($existingProvider) {
                $existingProvider->update([
                    'name' => $existingProvider->name ?: ('provider-' . $vendorKey),
                    'base_url' => $baseUrl,
                    'default_model' => $model,
                    'models' => [$modelEntry],
                    'defaults' => ['text' => $model, 'vision' => ''],
                    'is_active' => true,
                ]);
                $provider = $existingProvider->refresh();
            } else {
                $provider = ModelProvider::query()->create([
                    'vendor_key' => $vendorKey,
                    'name' => 'provider-' . $vendorKey,
                    'base_url' => $baseUrl,
                    'default_model' => $model,
                    'models' => [$modelEntry],
                    'defaults' => ['text' => $model, 'vision' => ''],
                    'is_active' => true,
                    'sort_order' => 100,
                ]);
            }

            if ($apiKey !== '') {
                ModelKey::query()->updateOrCreate(
                    ['provider_id' => $provider->id, 'name' => 'default'],
                    ['api_key' => $apiKey, 'is_active' => true]
                );
            }
        });

        file_put_contents(storage_path('app/setup.lock'), now()->toIso8601String());

        return redirect('/admin/login')->with('status', 'Setup completed. Please sign in with the admin account.');
    }

    private function probeDatabase(Request $request): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $request->string('db_host')->toString(),
            $request->integer('db_port'),
            $request->string('db_database')->toString(),
        );

        new PDO($dsn, $request->string('db_username')->toString(), (string) $request->input('db_password'));
    }

    private function writeEnv(Request $request): void
    {
        $pairs = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $request->string('db_host')->toString(),
            'DB_PORT' => (string) $request->integer('db_port'),
            'DB_DATABASE' => $request->string('db_database')->toString(),
            'DB_USERNAME' => $request->string('db_username')->toString(),
            'DB_PASSWORD' => (string) $request->input('db_password'),
            'QUEUE_CONNECTION' => 'database',
            'FEISHU_APP_ID' => (string) $request->input('feishu_app_id', ''),
            'FEISHU_APP_SECRET' => (string) $request->input('feishu_app_secret', ''),
            'FEISHU_ENCRYPT_KEY' => (string) $request->input('feishu_encrypt_key', ''),
            'FEISHU_VERIFICATION_TOKEN' => (string) $request->input('feishu_verification_token', ''),
        ];

        $envPath = base_path('.env');
        $content = file_exists($envPath) ? file_get_contents($envPath) : '';

        foreach ($pairs as $key => $value) {
            $line = $key.'='.$this->quoteEnv($value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', (string) $content)) {
                $content = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, (string) $content);
            } else {
                $content .= PHP_EOL.$line;
            }
        }

        file_put_contents($envPath, trim((string) $content).PHP_EOL);
    }

    private function quoteEnv(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s|"|\'/' , $value)) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
