<?php

namespace App\Services\LlmGateway;

/**
 * R3b (丁方案): 把 R1 migration 里的"按 base_url 反推 vendor_key"逻辑抽成共享 helper。
 *
 * 在 R3 期间，老页面下方表单提交时不带 vendor_key 字段，所以由 controller 调
 * VendorKeyInferrer::infer($baseUrl) 自动反推。R4 抽屉上线后，表单会显式带 vendor_key
 * 字段（用户在 vendor-pick 步骤选好了），controller 优先信任 form 字段，仅在缺失时才用本 helper。
 */
class VendorKeyInferrer
{
    public static function infer(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return 'custom';
        }
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host === '') {
            return 'custom';
        }

        return match (true) {
            str_contains($host, 'volces.com')         => 'doubao',
            str_contains($host, 'dashscope.aliyuncs') => 'qwen',
            str_contains($host, 'deepseek.com')       => 'deepseek',
            str_contains($host, 'moonshot.cn')        => 'kimi',
            str_contains($host, 'bigmodel.cn')        => 'glm',
            str_contains($host, 'anthropic.com')      => 'claude',
            str_contains($host, 'openai.com')         => 'openai',
            default                                   => 'custom-' . substr(md5($host), 0, 6),
        };
    }
}
