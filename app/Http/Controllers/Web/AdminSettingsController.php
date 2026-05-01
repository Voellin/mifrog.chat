<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\ModelKey;
use App\Services\LlmGateway\VendorKeyInferrer;
use App\Models\ModelProvider;
use App\Models\QuotaPolicy;
use App\Models\QuotaUsageLedger;
use App\Models\Setting;
use App\Models\User;
use App\Services\FeishuCliClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSettingsController extends Controller
{
    private const CAPABILITY_SUGGESTIONS = ['text', 'vision', 'speech', 'embedding', 'rerank', 'audio', 'video', 'ocr', 'other'];
    private const TAB_CHANNEL = 'channel';
    private const TAB_MODEL = 'model';
    private const TAB_ENTERPRISE = 'enterprise';
    /** Q5: enterprise tab now hosts a SECOND independent form ("对话上下文设置"); section value distinguishes them. */
    private const SECTION_MEMORY_CONTEXT = 'memory_context';
    /** R4 (丁方案): 顶部"当前生效模型"区独立 form，主模型 + 视觉覆盖 onChange 立即提交 */
    private const SECTION_ACTIVE_MODELS = 'active_models';

    /** 日报/周报发送时间设置：企业 tab 下的第三个独立表单 */
    private const SECTION_SUMMARY_SCHEDULE = 'summary_schedule';

    public function __construct(
        private readonly FeishuCliClient $feishuCliClient,
    ) {
    }

    public function index(Request $request)
    {
        $feishu = Setting::read('feishu', []);
        $enterprise = Setting::read('enterprise_profile', []);
        $memoryHistoryWindowHours = (int) Setting::read('memory.history_window_hours', 24);
        if ($memoryHistoryWindowHours <= 0) {
            $memoryHistoryWindowHours = 24;
        }

        // 日报/周报发送时间（默认 daily 07:00 / weekly 周一 07:30，跟现有 crontab 兼容）
        $summarySchedule = Setting::read('summary_schedule', []);
        $summaryDailyAt = $this->normalizeTimeOfDay((string) Arr::get($summarySchedule, 'daily_at', ''), '07:00');
        $summaryWeeklyAt = $this->normalizeTimeOfDay((string) Arr::get($summarySchedule, 'weekly_at', ''), '07:30');
        $summaryWeeklyDow = (int) Arr::get($summarySchedule, 'weekly_dow', 1);
        if ($summaryWeeklyDow < 1 || $summaryWeeklyDow > 7) {
            $summaryWeeklyDow = 1;
        }

        $tab = strtolower(trim((string) $request->query('tab', self::TAB_CHANNEL)));
        if (! in_array($tab, [self::TAB_CHANNEL, self::TAB_MODEL, self::TAB_ENTERPRISE], true)) {
            $tab = self::TAB_CHANNEL;
        }

        // R7 (多 vendor 共存): 取所有 active provider，按 sort_order asc, id asc 排序
        $activeProvidersRaw = ModelProvider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $activeProviders = [];
        foreach ($activeProvidersRaw as $p) {
            $key = ModelKey::query()->where('provider_id', $p->id)->where('name', 'default')->where('is_active', true)->first();
            $vk = (string) ($p->vendor_key ?: 'custom');
            $base = (string) $p->base_url;
            $host = $base !== '' ? (parse_url($base, PHP_URL_HOST) ?: $base) : '';
            $vmeta = $this->vendorIconMeta($vk);
            $activeProviders[] = [
                'id' => (int) $p->id,
                'vendor_key' => $vk,
                'name' => $vmeta['name'],
                'sub' => $vmeta['sub'],
                'icon' => $vmeta['icon'],
                'icon_class' => $vmeta['icon_class'],
                'base_url' => $base,
                'host' => $host,
                'api_key_configured' => $key !== null && trim((string) $key->api_key) !== '',
                'models' => $this->normalizeModels(is_array($p->models) ? $p->models : []),
                'sort_order' => (int) $p->sort_order,
                'last_test_status' => (string) ($p->last_test_status ?? ''),
                'last_test_at' => $p->last_test_at?->toIso8601String(),
                'last_test_at_human' => $p->last_test_at?->locale('zh_CN')?->diffForHumans(),
                'last_test_message' => (string) ($p->last_test_message ?? ''),
            ];
        }

        // 主 vendor（兼容老变量，view 里部分老代码仍引用）= sort_order 第一
        $activeProvider = $activeProvidersRaw->first();
        $providerKey = $activeProvider
            ? ModelKey::query()->where('provider_id', $activeProvider->id)->where('is_active', true)->first()
            : null;
        $models = $this->normalizeModels(is_array($activeProvider?->models ?? null) ? $activeProvider->models : []);

        // 全部 vendor 的 model_id 合集 → 主模型/视觉覆盖下拉用
        $allMountedOptions = [];
        foreach ($activeProviders as $vp) {
            foreach ($vp['models'] as $m) {
                $mid = trim((string) ($m['model_id'] ?? ''));
                if ($mid !== '') {
                    $allMountedOptions[] = ['model_id' => $mid, 'vendor_key' => $vp['vendor_key']];
                }
            }
        }

        // 新数据源：active_main_model_id / active_vision_model_id（A2 直切，不再读 model_gateway.defaults）
        $activeMain = trim((string) Setting::read('active_main_model_id', ''));
        $activeVision = trim((string) Setting::read('active_vision_model_id', ''));
        $defaults = ['text' => $activeMain, 'vision' => $activeVision, 'speech' => ''];
        if ($defaults['text'] === '' && ! empty($allMountedOptions)) {
            $defaults['text'] = (string) $allMountedOptions[0]['model_id'];
        }

        $availableModelIds = collect($allMountedOptions)->pluck('model_id')->filter()->unique()->values()->all();
        $capabilitySuggestions = collect(array_merge(
            self::CAPABILITY_SUGGESTIONS,
            collect($models)->pluck('capability')->filter()->all()
        ))->map(fn ($item) => strtolower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('admin.settings', [
            'feishu' => [
                'app_id' => (string) Arr::get($feishu, 'app_id', ''),
                'app_secret' => (string) Arr::get($feishu, 'app_secret', ''),
                'encrypt_key' => (string) Arr::get($feishu, 'encrypt_key', ''),
                'verification_token' => (string) Arr::get($feishu, 'verification_token', ''),
            ],
            'modelGateway' => [
                // 兼容老 view 变量；值从 ModelProvider 表取
                'base_url' => (string) ($activeProvider?->base_url ?? ''),
            ],
            'models' => $models,
            'defaults' => $defaults,
            'availableModelIds' => $availableModelIds,
            'capabilitySuggestions' => $capabilitySuggestions,
            'apiKeyConfigured' => trim((string) ($providerKey?->api_key ?? '')) !== '',
            'activeProviders' => $activeProviders,
            'allMountedOptions' => $allMountedOptions,
            'enterprise' => [
                'name' => trim((string) Arr::get($enterprise, 'name', '')),
                'logo_url' => trim((string) Arr::get($enterprise, 'logo_url', '')),
            ],
            'memoryHistoryWindowHours' => $memoryHistoryWindowHours,
            'summarySchedule' => [
                'daily_at' => $summaryDailyAt,
                'weekly_at' => $summaryWeeklyAt,
                'weekly_dow' => $summaryWeeklyDow,
            ],
            'activeTab' => $tab,
        ]);
    }

    public function update(Request $request)
    {
        $section = strtolower(trim((string) $request->input('section', self::TAB_CHANNEL)));
        if (! in_array($section, [self::TAB_CHANNEL, self::TAB_MODEL, self::TAB_ENTERPRISE, self::SECTION_MEMORY_CONTEXT, self::SECTION_ACTIVE_MODELS, self::SECTION_SUMMARY_SCHEDULE], true)) {
            $section = self::TAB_CHANNEL;
        }

        $this->authorizeAdminPermission($request, match ($section) {
            self::TAB_CHANNEL => 'settings.channel.update',
            self::TAB_ENTERPRISE => 'settings.enterprise.update',
            self::SECTION_MEMORY_CONTEXT => 'settings.enterprise.update', // 同一权限，本质都是企业 tab 下的设置
            self::SECTION_SUMMARY_SCHEDULE => 'settings.enterprise.update', // 同上：企业 tab 下的子设置
            self::SECTION_ACTIVE_MODELS => 'settings.model.update',
            default => 'settings.model.update',
        });

        if ($section === self::TAB_CHANNEL) {
            $data = $request->validate([
                'section' => 'required|string|in:channel,model,enterprise,memory_context,summary_schedule',
                'feishu_app_id' => 'nullable|string|max:255',
                'feishu_app_secret' => 'nullable|string|max:255',
                'feishu_encrypt_key' => 'nullable|string|max:255',
                'feishu_verification_token' => 'nullable|string|max:255',
            ]);

            Setting::write('feishu', [
                'app_id' => trim((string) ($data['feishu_app_id'] ?? '')),
                'app_secret' => trim((string) ($data['feishu_app_secret'] ?? '')),
                'encrypt_key' => trim((string) ($data['feishu_encrypt_key'] ?? '')),
                'verification_token' => trim((string) ($data['feishu_verification_token'] ?? '')),
            ]);

            \App\Services\AdminOperationLogger::log($request, 'settings.channel.update', '保存飞书渠道配置（app_id / app_secret / encrypt_key / verification_token）', ['target_type' => 'setting', 'target_id' => 0, 'setting_key' => 'feishu']);

            return redirect('/admin/settings?tab=channel')->with('status', '渠道配置已保存。');
        }

        if ($section === self::TAB_ENTERPRISE) {
            $data = $request->validate([
                'section' => 'required|string|in:channel,model,enterprise,memory_context,summary_schedule',
                'enterprise_name' => 'nullable|string|max:36',
                'enterprise_logo_url' => 'nullable|string|max:1024',
                'enterprise_logo_file' => 'nullable|file|mimes:jpg,jpeg,png,webp,svg|max:4096',
            ]);

            $logoUrl = trim((string) ($data['enterprise_logo_url'] ?? ''));
            if ($request->hasFile('enterprise_logo_file')) {
                $file = $request->file('enterprise_logo_file');
                if ($file !== null && $file->isValid()) {
                    $extension = strtolower((string) $file->getClientOriginalExtension());
                    if ($extension === '') {
                        $extension = 'png';
                    }

                    $filename = 'logo-'.date('YmdHis').'-'.Str::random(8).'.'.$extension;
                    $targetDir = public_path('uploads/enterprise');
                    if (! is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    $file->move($targetDir, $filename);
                    $logoUrl = '/uploads/enterprise/'.$filename;
                }
            }

            Setting::write('enterprise_profile', [
                'name' => trim((string) ($data['enterprise_name'] ?? '')),
                'logo_url' => $logoUrl,
            ]);

            \App\Services\AdminOperationLogger::log($request, 'settings.enterprise.update', sprintf('保存企业配置：name=「%s」', trim((string) ($data['enterprise_name'] ?? ''))), ['target_type' => 'setting', 'target_id' => 0, 'setting_key' => 'enterprise_profile']);

            return redirect('/admin/settings?tab=enterprise')->with('status', '企业配置已保存。');
        }

        if ($section === self::SECTION_SUMMARY_SCHEDULE) {
            $data = $request->validate([
                'section' => 'required|string|in:channel,model,enterprise,memory_context,active_models,summary_schedule',
                'summary_daily_at' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'summary_weekly_at' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'summary_weekly_dow' => 'required|integer|between:1,7',
            ]);

            Setting::write('summary_schedule', [
                'daily_at' => $data['summary_daily_at'],
                'weekly_at' => $data['summary_weekly_at'],
                'weekly_dow' => (int) $data['summary_weekly_dow'],
            ]);

            \App\Services\AdminOperationLogger::log(
                $request,
                'settings.enterprise.update',
                sprintf('保存日报/周报发送时间：daily=%s, weekly=周%d %s', $data['summary_daily_at'], (int) $data['summary_weekly_dow'], $data['summary_weekly_at']),
                ['target_type' => 'setting', 'target_id' => 0, 'setting_key' => 'summary_schedule']
            );

            return redirect('/admin/settings?tab=enterprise')->with('status', '日报/周报发送时间已保存。');
        }

        if ($section === self::SECTION_ACTIVE_MODELS) {
            // R4 (丁方案 + B3 onChange 立即提交): 顶部"当前生效模型"区单独提交
            $data = $request->validate([
                'section' => 'required|string|in:channel,model,enterprise,memory_context,active_models,summary_schedule',
                'active_main_model_id' => 'nullable|string|max:255',
                'active_vision_model_id' => 'nullable|string|max:255',
            ]);

            $main = trim((string) ($data['active_main_model_id'] ?? ''));
            $vision = trim((string) ($data['active_vision_model_id'] ?? ''));
            Setting::write('active_main_model_id', $main);
            Setting::write('active_vision_model_id', $vision);

            \App\Services\AdminOperationLogger::log(
                $request,
                'settings.model.update',
                sprintf('切换当前生效模型：主=「%s」，视觉覆盖=「%s」', $main, $vision ?: '(无)'),
                ['target_type' => 'setting', 'target_id' => 0, 'setting_key' => 'active_models', 'active_main' => $main, 'active_vision' => $vision]
            );

            // 来自异步提交（fetch）→ 返 JSON；常规表单 POST → redirect
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['ok' => true, 'active_main' => $main, 'active_vision' => $vision]);
            }
            return redirect('/admin/settings?tab=model')->with('status', '当前生效模型已切换。');
        }

        if ($section === self::SECTION_MEMORY_CONTEXT) {
            // Q5: 对话上下文设置 — 独立 form, 独立保存按钮
            $data = $request->validate([
                'section' => 'required|string|in:channel,model,enterprise,memory_context,summary_schedule',
                'memory_history_window_hours' => 'required|integer|min:1|max:720',
            ]);

            $memoryHistoryWindowHours = max(1, min(720, (int) $data['memory_history_window_hours']));
            Setting::write('memory.history_window_hours', $memoryHistoryWindowHours);

            \App\Services\AdminOperationLogger::log($request, 'settings.enterprise.update', sprintf('保存对话上下文配置：history_window=%d 小时', $memoryHistoryWindowHours), ['target_type' => 'setting', 'target_id' => 0, 'setting_key' => 'memory.history_window_hours', 'memory_history_window_hours' => $memoryHistoryWindowHours]);

            return redirect('/admin/settings?tab=enterprise')->with('status', '对话上下文配置已保存。');
        }

        // R3b (丁方案 + A2 直切): 表单字段集兼容老 view，新增 vendor_key / active_main_model_id / active_vision_model_id
        // (R4 抽屉化后才会显式带过来；R3 期间 vendor_key 由 base_url 反推，主模型/视觉默认从 default_text/vision_model 兼容)
        $data = $request->validate([
            'section' => 'required|string|in:channel,model,enterprise,memory_context,summary_schedule',
            'model_base_url' => 'required|string|max:1024',
            'model_api_key' => 'nullable|string|max:4096',
            'clear_model_api_key' => 'nullable|boolean',
            'model_capability' => 'nullable|array',
            'model_capability.*' => 'nullable|string|max:32',
            'model_id' => 'nullable|array',
            'model_id.*' => 'nullable|string|max:255',
            'model_label' => 'nullable|array',
            'model_label.*' => 'nullable|string|max:255',
            'default_text_model' => 'nullable|string|max:255',
            'default_vision_model' => 'nullable|string|max:255',
            'default_speech_model' => 'nullable|string|max:255', // R3 期间收下但忽略；丁方案废弃 speech 槽
            'vendor_key' => 'nullable|string|max:32',
            'active_main_model_id' => 'nullable|string|max:255',
            'active_vision_model_id' => 'nullable|string|max:255',
        ]);

        $logCtx = ['model_count' => 0, 'vendor_key' => '', 'active_main' => '', 'active_vision' => ''];

        DB::transaction(function () use ($data, &$logCtx): void {
            $baseUrl = trim((string) ($data['model_base_url'] ?? ''));

            // vendor_key: form > 反推
            $vendorKey = trim((string) ($data['vendor_key'] ?? ''));
            if ($vendorKey === '') {
                $vendorKey = VendorKeyInferrer::infer($baseUrl);
            }

            // 1) 组装 models[] (含 capabilities 数组)
            $models = $this->buildModelsFromForm(
                $data['model_capability'] ?? [],
                $data['model_id'] ?? [],
                $data['model_label'] ?? []
            );
            // 给每个 entry 加 capabilities 数组（默认 = [capability]）
            $models = array_map(function (array $row): array {
                $cap = strtolower(trim((string) ($row['capability'] ?? '')));
                $row['capabilities'] = $cap !== '' ? [$cap] : ['text'];
                return $row;
            }, $models);

            // 2) 计算 active_main_model_id / active_vision_model_id
            //    优先级：form 新字段 (active_main_model_id) > 兼容字段 (default_text_model)
            //           > 第一个 text 能力的 model_id > models[0]
            $activeMain = trim((string) ($data['active_main_model_id'] ?? ($data['default_text_model'] ?? '')));
            if ($activeMain === '') {
                $textCandidate = collect($models)->firstWhere('capability', 'text');
                $activeMain = trim((string) ($textCandidate['model_id'] ?? ''));
            }
            if ($activeMain === '' && ! empty($models)) {
                $activeMain = trim((string) ($models[0]['model_id'] ?? ''));
            }
            $activeVision = trim((string) ($data['active_vision_model_id'] ?? ($data['default_vision_model'] ?? '')));
            // active_vision 留空 = 不覆盖（用主模型读图，要求主模型支持 vision）

            // 3) api_key (clear / new / 沿用旧)
            $existingProvider = ModelProvider::query()->where('vendor_key', $vendorKey)->first();
            $existingKeyRow = $existingProvider
                ? ModelKey::query()->where('provider_id', $existingProvider->id)->where('name', 'default')->first()
                : null;
            $existingApiKey = (string) ($existingKeyRow?->api_key ?? '');
            $newApiKey = $existingApiKey;

            $clearApiKey = (bool) ($data['clear_model_api_key'] ?? false);
            if ($clearApiKey) {
                $newApiKey = '';
            } elseif (trim((string) ($data['model_api_key'] ?? '')) !== '') {
                $newApiKey = trim((string) $data['model_api_key']);
            }

            // 4) upsert ModelProvider by vendor_key (定 sort_order：已存在沿用旧值；新建时取当前最大 +10)
            if ($existingProvider) {
                $existingProvider->update([
                    'name' => $existingProvider->name ?: 'provider-' . $vendorKey,
                    'base_url' => $baseUrl,
                    'default_model' => $activeMain,
                    'models' => $models,
                    'defaults' => ['text' => $activeMain, 'vision' => $activeVision],
                    'is_active' => true,
                ]);
                $provider = $existingProvider->refresh();
            } else {
                $maxSort = (int) ModelProvider::query()->max('sort_order');
                $provider = ModelProvider::query()->create([
                    'vendor_key' => $vendorKey,
                    'name' => 'provider-' . $vendorKey,
                    'base_url' => $baseUrl,
                    'default_model' => $activeMain,
                    'models' => $models,
                    'defaults' => ['text' => $activeMain, 'vision' => $activeVision],
                    'is_active' => true,
                    'sort_order' => $maxSort > 0 ? $maxSort + 10 : 100,
                ]);
            }

            // 5) upsert ModelKey
            if ($newApiKey !== '') {
                ModelKey::query()->updateOrCreate(
                    ['provider_id' => $provider->id, 'name' => 'default'],
                    ['api_key' => $newApiKey, 'is_active' => true]
                );
            } else {
                ModelKey::query()
                    ->where('provider_id', $provider->id)
                    ->where('name', 'default')
                    ->update(['is_active' => false]);
            }

            // 6) 写两个新 settings（A2 直切：不再写 Setting('model_gateway'))
            Setting::write('active_main_model_id', $activeMain);
            Setting::write('active_vision_model_id', $activeVision);

            $logCtx['model_count'] = count($models);
            $logCtx['vendor_key'] = $vendorKey;
            $logCtx['active_main'] = $activeMain;
            $logCtx['active_vision'] = $activeVision;
        });

        \App\Services\AdminOperationLogger::log(
            $request,
            'settings.model.update',
            sprintf('保存模型配置：vendor=%s，主模型=「%s」，视觉覆盖=「%s」，挂载 %d 个模型',
                $logCtx['vendor_key'], $logCtx['active_main'], $logCtx['active_vision'] ?: '(无)', $logCtx['model_count']),
            ['target_type' => 'setting', 'target_id' => 0, 'setting_key' => 'model_providers#'.$logCtx['vendor_key'], 'model_count' => $logCtx['model_count']]
        );

        return redirect('/admin/settings?tab=model')->with('status', '模型配置已保存。');
    }

    public function testChannel(): RedirectResponse
    {
        $feishu = Setting::read('feishu', []);
        $appId = trim((string) Arr::get($feishu, 'app_id', ''));
        $appSecret = trim((string) Arr::get($feishu, 'app_secret', ''));

        if ($appId === '' || $appSecret === '') {
            return redirect('/admin/settings?tab=channel')->with('error', 'Channel config is incomplete: missing App ID or App Secret.');
        }

        $scopeSummary = 'Address-book scope check was not executed.';
        try {
            $scopeBody = $this->feishuCliClient->callBotApi([
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'enabled' => true,
            ], 'get', 'contact/v3/scopes?department_id_type=open_department_id&user_id_type=open_id');
            $scopeCode = (int) Arr::get($scopeBody, 'code', -1);
            if ($scopeCode === 0) {
                $departments = (array) Arr::get($scopeBody, 'data.authed_departments', []);
                $users = (array) Arr::get($scopeBody, 'data.authed_users', []);
                $scopeSummary = 'Address-book scope check succeeded: '.count($departments).' departments, '.count($users).' users.';
            } else {
                $msg = trim((string) Arr::get($scopeBody, 'msg', 'unknown error'));
                return redirect('/admin/settings?tab=channel')->with('error', 'Feishu connection test failed: '.$msg);
            }
        } catch (\Throwable $e) {
            return redirect('/admin/settings?tab=channel')->with('error', 'Feishu connection test failed: '.$e->getMessage());
        }

        \App\Services\AdminOperationLogger::log($request, 'settings.channel.test', '测试飞书渠道连接（成功）', ['target_type' => 'setting', 'target_id' => 0]);

        return redirect('/admin/settings?tab=channel')
            ->with('status', 'Feishu connection test succeeded.')
            ->with('test_result', [
                'section' => 'channel',
                'title' => 'Feishu Connection Test',
                'content' => $scopeSummary,
            ]);
    }

    public function testModel(): RedirectResponse
    {
        // R3b: 数据源全部切到表 + 两个新 settings；不再读 Setting('model_gateway')
        $provider = ModelProvider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
        $providerKey = $provider
            ? ModelKey::query()->where('provider_id', $provider->id)->where('is_active', true)->first()
            : null;

        $baseUrl = trim((string) ($provider?->base_url ?? ''));
        $apiKey = trim((string) ($providerKey?->api_key ?? ''));

        $model = trim((string) Setting::read('active_main_model_id', $provider?->default_model ?? ''));
        if ($model === '' && is_array($provider?->models)) {
            foreach ($provider->models as $row) {
                $candidate = trim((string) ($row['model_id'] ?? ''));
                if ($candidate !== '') {
                    $model = $candidate;
                    break;
                }
            }
        }

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            return redirect('/admin/settings?tab=model')->with('error', '模型配置不完整，请先保存 Base URL、API Key 和文本模型。');
        }

        $client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => 35,
            'http_errors' => false,
        ]);

        try {
            $resp = $client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => '你是连通性测试助手。'],
                        ['role' => 'user', 'content' => '请回复"模型连通测试成功"。'],
                    ],
                    'temperature' => 0,
                ],
            ]);
        } catch (GuzzleException $e) {
            return redirect('/admin/settings?tab=model')->with('error', '模型连接测试失败：'.$e->getMessage());
        }

        $body = json_decode((string) $resp->getBody(), true);
        if ($resp->getStatusCode() >= 400) {
            $msg = trim((string) (Arr::get($body, 'error.message') ?: Arr::get($body, 'message') ?: 'HTTP '.$resp->getStatusCode()));

            return redirect('/admin/settings?tab=model')->with('error', '模型连接测试失败：'.$msg);
        }

        $reply = trim((string) Arr::get($body, 'choices.0.message.content', ''));
        if ($reply === '') {
            $reply = '模型返回为空文本，但接口可达。';
        }

        \App\Services\AdminOperationLogger::log($request, 'settings.model.test', '测试模型连通性（成功）', ['target_type' => 'setting', 'target_id' => 0]);

        return redirect('/admin/settings?tab=model')
            ->with('status', '模型连接测试成功：接口可达并返回内容。')
            ->with('test_result', [
                'section' => 'model',
                'title' => '模型连接测试',
                'content' => '模型：'.$model.'；Prompt Tokens：'.(int) Arr::get($body, 'usage.prompt_tokens', 0).'；Completion Tokens：'.(int) Arr::get($body, 'usage.completion_tokens', 0).'；回复预览：'.$this->truncateForView($reply, 180),
            ]);
    }

    /**
     * R6-test (丁): 抽屉 step 3 的"测试连接"按钮端点。
     * 接 form 临时数据：vendor_key (可选, 仅日志用) + base_url + api_key + model_id。
     * 不写 DB，纯探活：发一次极小 chat/completions 请求，返 JSON {ok, message, model, tokens}。
     *
     * api_key 为空时，从该 vendor_key 已存的 ModelKey.api_key 取（B 选项语义）。
     */
    public function testModelConnection(Request $request)
    {
        $data = $request->validate([
            'base_url' => 'required|string|max:1024',
            'api_key' => 'nullable|string|max:4096',
            'model_id' => 'required|string|max:255',
            'vendor_key' => 'nullable|string|max:32',
        ]);

        $baseUrl = trim((string) $data['base_url']);
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $model = trim((string) $data['model_id']);
        $vendorKey = trim((string) ($data['vendor_key'] ?? ''));

        // R8: 找现有 vendor row（如果有）以便写 last_test_*
        $existingVendor = $vendorKey !== ''
            ? ModelProvider::query()->where('vendor_key', $vendorKey)->first()
            : null;

        // B 选项 fallback：没填新 api_key → 取现有 ModelKey
        if ($apiKey === '' && $existingVendor) {
            $existingKey = ModelKey::query()->where('provider_id', $existingVendor->id)
                ->where('name', 'default')->where('is_active', true)->first();
            if ($existingKey) {
                $apiKey = trim((string) $existingKey->api_key);
            }
        }

        // R8 helper：把测试结果持久化到 model_providers
        $persist = function (string $status, string $message) use ($existingVendor): void {
            if ($existingVendor === null) return;
            $existingVendor->update([
                'last_test_status' => $status,
                'last_test_at' => now(),
                'last_test_message' => substr($message, 0, 500),
            ]);
        };

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            $msg = '配置不完整：' . ($baseUrl === '' ? 'Base URL 为空。' : ($apiKey === '' ? 'API Key 为空。' : '未指定测试模型。'));
            $persist('failed', $msg);
            return response()->json(['ok' => false, 'message' => $msg], 422);
        }

        $client = new Client(['base_uri' => rtrim($baseUrl, '/').'/', 'timeout' => 35, 'http_errors' => false]);

        try {
            $resp = $client->post('chat/completions', [
                'headers' => ['Authorization' => 'Bearer '.$apiKey, 'Content-Type' => 'application/json'],
                'json' => ['model' => $model, 'messages' => [
                    ['role' => 'system', 'content' => '你是连通性测试助手。'],
                    ['role' => 'user', 'content' => '请回复"连通正常"四个字。'],
                ], 'temperature' => 0, 'max_tokens' => 32],
            ]);
        } catch (GuzzleException $e) {
            $msg = '网络错误：'.$e->getMessage();
            $persist('failed', $msg);
            return response()->json(['ok' => false, 'message' => $msg], 200);
        }

        $body = json_decode((string) $resp->getBody(), true);
        $status = $resp->getStatusCode();

        if ($status >= 400) {
            $errMsg = trim((string) (Arr::get($body, 'error.message') ?: Arr::get($body, 'message') ?: 'HTTP '.$status));
            $msg = 'HTTP '.$status.'：'.$errMsg;
            $persist('failed', $msg);
            return response()->json(['ok' => false, 'message' => $msg], 200);
        }

        $reply = trim((string) Arr::get($body, 'choices.0.message.content', ''));
        $promptTokens = (int) Arr::get($body, 'usage.prompt_tokens', 0);
        $completionTokens = (int) Arr::get($body, 'usage.completion_tokens', 0);

        $persist('ok', sprintf('OK · %s · %d+%d tokens', $model, $promptTokens, $completionTokens));

        \App\Services\AdminOperationLogger::log(
            $request,
            'settings.model.test',
            sprintf('抽屉测试连接（成功）vendor=%s model=%s', $vendorKey ?: '(N/A)', $model),
            ['target_type' => 'setting', 'target_id' => 0, 'vendor_key' => $vendorKey, 'model' => $model, 'tokens_in' => $promptTokens, 'tokens_out' => $completionTokens]
        );

        return response()->json([
            'ok' => true,
            'message' => '连接正常',
            'model' => $model,
            'reply' => $this->truncateForView($reply, 80),
            'tokens_in' => $promptTokens,
            'tokens_out' => $completionTokens,
        ]);
    }

    // ────────────────────────────────────────────────────
    //  Token Quota Management
    // ────────────────────────────────────────────────────

    public function quotaData(Request $request)
    {
        $period = now()->format('Y-m');

        $pool = QuotaPolicy::query()
            ->whereNull('department_id')
            ->whereNull('user_id')
            ->where('is_active', true)
            ->first();

        $deptAllocations = QuotaPolicy::query()
            ->whereNotNull('department_id')
            ->whereNull('user_id')
            ->where('is_active', true)
            ->get()
            ->map(function ($policy) use ($period) {
                $dept = Department::find($policy->department_id);
                $used = QuotaUsageLedger::query()
                    ->where('department_id', $policy->department_id)
                    ->where('period_key', $period)
                    ->sum('used_tokens');
                return [
                    'id' => $policy->id,
                    'type' => 'department',
                    'target_id' => $policy->department_id,
                    'target_name' => $dept?->name ?? '未知部门',
                    'token_limit' => (int) $policy->token_limit,
                    'used' => (int) $used,
                    'period' => $policy->period,
                ];
            });

        $userAllocations = QuotaPolicy::query()
            ->whereNotNull('user_id')
            ->where('is_active', true)
            ->get()
            ->map(function ($policy) use ($period) {
                $user = User::find($policy->user_id);
                $used = QuotaUsageLedger::query()
                    ->where('user_id', $policy->user_id)
                    ->where('period_key', $period)
                    ->sum('used_tokens');
                return [
                    'id' => $policy->id,
                    'type' => 'user',
                    'target_id' => $policy->user_id,
                    'target_name' => $user?->name ?? '未知用户',
                    'token_limit' => (int) $policy->token_limit,
                    'used' => (int) $used,
                    'period' => $policy->period,
                ];
            });

        $totalUsed = QuotaUsageLedger::query()
            ->where('period_key', $period)
            ->sum('used_tokens');

        $departments = Department::all(['id', 'name']);
        $users = User::query()->where('is_active', true)->get(['id', 'name', 'department_id']);

        return response()->json([
            'pool' => [
                'token_limit' => $pool ? (int) $pool->token_limit : 0,
                'period' => $pool?->period ?? 'monthly',
                'used' => (int) $totalUsed,
            ],
            'default_monthly_limit' => (int) Setting::read('default_monthly_quota_tokens', 0),
            'allocations' => collect($deptAllocations)->concat($userAllocations)->values(),
            'departments' => $departments,
            'users' => $users,
            'current_period' => $period,
        ]);
    }

    public function saveQuotaPool(Request $request)
    {
        $data = $request->validate([
            'token_limit' => 'required|integer|min:0',
            'period' => 'nullable|string|in:monthly,weekly,daily',
        ]);

        $pool = QuotaPolicy::query()
            ->whereNull('department_id')
            ->whereNull('user_id')
            ->first();

        if ($pool) {
            $pool->update([
                'token_limit' => (int) $data['token_limit'],
                'period' => $data['period'] ?? 'monthly',
                'is_active' => true,
            ]);
        } else {
            QuotaPolicy::create([
                'department_id' => null,
                'user_id' => null,
                'token_limit' => (int) $data['token_limit'],
                'period' => $data['period'] ?? 'monthly',
                'is_active' => true,
            ]);
        }

        \App\Services\AdminOperationLogger::log($request, 'settings.quota.pool', '调整 Token 配额总池', ['target_type' => 'setting', 'target_id' => 0]);
        return response()->json(['status' => 'ok', 'message' => 'Token 总量已保存。']);
    }

    public function saveQuotaDefault(Request $request)
    {
        $data = $request->validate([
            'token_limit' => 'required|integer|min:0',
        ]);

        Setting::write('default_monthly_quota_tokens', (int) $data['token_limit']);

        \App\Services\AdminOperationLogger::log($request, 'settings.quota.default', '调整 Token 配额默认值', ['target_type' => 'setting', 'target_id' => 0]);
        return response()->json(['status' => 'ok', 'message' => '每人默认月上限已保存。']);
    }

    public function saveQuotaAllocation(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string|in:department,user',
            'target_id' => 'required|integer',
            'token_limit' => 'required|integer|min:1',
        ]);

        $query = QuotaPolicy::query();
        if ($data['type'] === 'department') {
            $query->where('department_id', $data['target_id'])->whereNull('user_id');
        } else {
            $query->where('user_id', $data['target_id']);
        }

        $existing = $query->first();
        if ($existing) {
            $existing->update([
                'token_limit' => (int) $data['token_limit'],
                'period' => 'monthly',
                'is_active' => true,
            ]);
        } else {
            QuotaPolicy::create([
                'department_id' => $data['type'] === 'department' ? $data['target_id'] : null,
                'user_id' => $data['type'] === 'user' ? $data['target_id'] : null,
                'token_limit' => (int) $data['token_limit'],
                'period' => 'monthly',
                'is_active' => true,
            ]);
        }

        \App\Services\AdminOperationLogger::log($request, 'settings.quota.allocate', '调整 Token 配额分配', ['target_type' => 'setting', 'target_id' => 0]);
        return response()->json(['status' => 'ok', 'message' => '配额分配已保存。']);
    }

    public function deleteQuotaAllocation(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer']);

        $policy = QuotaPolicy::find($data['id']);
        if ($policy) {
            if ($policy->department_id === null && $policy->user_id === null) {
                return response()->json(['status' => 'error', 'message' => '不能通过此接口删除总池。'], 422);
            }
            $policy->update(['is_active' => false]);
        }

        \App\Services\AdminOperationLogger::log($request, 'settings.quota.allocate.delete', '删除一条 Token 配额分配', ['target_type' => 'setting', 'target_id' => 0]);
        return response()->json(['status' => 'ok', 'message' => '配额已移除。']);
    }

    // ────────────────────────────────────────────────────
    //  Private helpers
    // ────────────────────────────────────────────────────

    private function normalizeModels(array $models): array
    {
        $normalized = [];
        $seen = [];

        foreach ($models as $row) {
            $capability = strtolower(trim((string) Arr::get($row, 'capability', 'other')));
            $modelId = trim((string) Arr::get($row, 'model_id', ''));
            $label = trim((string) Arr::get($row, 'label', ''));

            if ($modelId === '') {
                continue;
            }

            if ($capability === '' || preg_match('/^[a-z0-9_-]{1,32}$/', $capability) !== 1) {
                $capability = 'other';
            }

            $dedupeKey = $capability.'::'.$modelId;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $normalized[] = [
                'capability' => $capability,
                'model_id' => $modelId,
                'label' => $label,
            ];
        }

        return array_values($normalized);
    }

    /** R7: vendor_key → 显示元信息（图标 / 名字）字典 */
    private function vendorIconMeta(string $vendorKey): array
    {
        return match ($vendorKey) {
            'doubao'   => ['name' => '字节跳动 / 豆包',   'sub' => 'Doubao · Ark · Volcengine',  'icon' => '豆', 'icon_class' => 'doubao'],
            'qwen'     => ['name' => '阿里云 / 通义千问', 'sub' => 'DashScope · Qwen',          'icon' => '通', 'icon_class' => 'qwen'],
            'deepseek' => ['name' => '深度求索 / DeepSeek','sub' => 'deepseek-chat · reasoner','icon' => 'D',  'icon_class' => 'deepseek'],
            'claude'   => ['name' => 'Anthropic / Claude','sub' => 'claude-haiku · sonnet · opus','icon' => 'A','icon_class' => 'claude'],
            'kimi'     => ['name' => '月之暗面 / Kimi',   'sub' => 'Moonshot AI',              'icon' => 'K',  'icon_class' => 'kimi'],
            'glm'      => ['name' => '智谱 / GLM',        'sub' => 'BigModel / ZhipuAI',       'icon' => 'G',  'icon_class' => 'glm'],
            'openai'   => ['name' => 'OpenAI',           'sub' => 'gpt-4o · gpt-4o-mini',     'icon' => 'O',  'icon_class' => 'openai'],
            default    => ['name' => '自定义 / OpenAI 兼容', 'sub' => 'OpenAI-Compatible Gateway', 'icon' => '⚙', 'icon_class' => 'custom'],
        };
    }

    private function buildModelsFromForm(array $capabilities, array $modelIds, array $labels): array
    {
        $rows = [];
        $max = max(count($capabilities), count($modelIds), count($labels));

        for ($index = 0; $index < $max; $index++) {
            $rows[] = [
                'capability' => $capabilities[$index] ?? 'other',
                'model_id' => $modelIds[$index] ?? '',
                'label' => $labels[$index] ?? '',
            ];
        }

        return $this->normalizeModels($rows);
    }

    private function truncateForView(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $max) {
                return $text;
            }

            return mb_substr($text, 0, $max - 1, 'UTF-8').'…';
        }

        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3).'...';
    }
    private function normalizeTimeOfDay(string $candidate, string $fallback): string
    {
        $candidate = trim($candidate);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $candidate) === 1) {
            return $candidate;
        }
        return $fallback;
    }
}
