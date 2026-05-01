<?php

namespace App\Services\Feishu;

use App\Services\FeishuCliClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resource download domain service extracted from FeishuService.
 *
 * Handles downloading message attachments (files / images) from Feishu,
 * trying CLI first when available, falling back to Guzzle HTTP.
 *
 * Behavior contract (preserved verbatim from FeishuService):
 * - Throws RuntimeException on param/config/token/network failure.
 * - On success returns ['path','mime_type','size','file_name'].
 * - Attempts multiple URI variants (message-scoped, type-scoped, download suffix).
 * - Cleans up .part temp files on failure.
 */
class FeishuResourceService
{
    public function __construct(
        private readonly FeishuTransport $transport,
        private readonly FeishuCliClient $feishuCliClient,
    ) {
    }

    /**
     * @param  array<string,mixed>  $resource
     * @return array{path:string, mime_type:string, size:int, file_name:string}
     */
    public function downloadMessageResource(array $resource): array
    {
        $sourceMessageId = trim((string) ($resource['source_message_id'] ?? ''));
        $fileKey = trim((string) ($resource['file_key'] ?? ''));
        $type = strtolower(trim((string) ($resource['attachment_type'] ?? 'file')));
        $targetPath = trim((string) ($resource['target_path'] ?? ''));

        if ($fileKey === '' || $targetPath === '') {
            throw new \RuntimeException('download resource params invalid');
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            throw new \RuntimeException('feishu config not enabled');
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            throw new \RuntimeException('feishu token unavailable');
        }

        $dir = dirname($targetPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $uris = [];
        if ($sourceMessageId !== '') {
            $uris[] = 'im/v1/messages/'.rawurlencode($sourceMessageId).'/resources/'.rawurlencode($fileKey).'?type='.rawurlencode($type);
            $uris[] = 'im/v1/messages/'.rawurlencode($sourceMessageId).'/resources/'.rawurlencode($fileKey);
        }
        if ($type === 'image') {
            $uris[] = 'im/v1/images/'.rawurlencode($fileKey);
        } else {
            $uris[] = 'im/v1/files/'.rawurlencode($fileKey);
            $uris[] = 'im/v1/files/'.rawurlencode($fileKey).'/download';
        }

        $tmpPath = $targetPath.'.part';
        foreach ($uris as $uri) {
            if (File::exists($tmpPath)) {
                File::delete($tmpPath);
            }

            if ($this->feishuCliClient->isEnabled() && $this->feishuCliClient->isAvailable()) {
                try {
                    $download = $this->feishuCliClient->downloadBotResource($config, 'get', $uri, $tmpPath);
                    $sizeBytes = (int) Arr::get($download, 'size_bytes', 0);
                    if (! File::exists($tmpPath) || ($sizeBytes <= 0 && File::size($tmpPath) <= 0)) {
                        Log::warning('feishu.cli.download_failed', [
                            'uri' => $uri,
                            'cli_ok' => $download['ok'] ?? null,
                            'cli_error_type' => $download['error']['type'] ?? null,
                            'cli_error_message' => $download['error']['message'] ?? null,
                            'cli_error_hint' => $download['error']['hint'] ?? null,
                            'tmp_path_exists' => File::exists($tmpPath),
                            'size_bytes' => $sizeBytes,
                        ]);
                        // Fall through to HTTP download below
                    } else {
                        File::move($tmpPath, $targetPath);

                        return [
                            'path' => $targetPath,
                            'mime_type' => trim((string) Arr::get($download, 'content_type', '')),
                            'size' => File::size($targetPath),
                            'file_name' => basename($targetPath),
                        ];
                    }
                } catch (Throwable $cliEx) {
                    Log::warning('feishu.cli.download_fallback', [
                        'uri' => $uri,
                        'error' => $cliEx->getMessage(),
                    ]);
                    // Fall through to HTTP download below
                }
            }

            // A2: 占位 token (__MIFROG_CLI_BOT__) 不能直接当 Bearer token 用——
            // 飞书会 reject 99991668 "Invalid access token"。fallback 前先换成真 tenant token。
            $bearerToken = $token;
            if ($bearerToken === \App\Services\Feishu\FeishuTransport::CLI_BOT_TOKEN) {
                $bearerToken = $this->transport->tenantTokenViaHttp(
                    (string) ($config['app_id'] ?? ''),
                    (string) ($config['app_secret'] ?? '')
                );
                if (! $bearerToken) {
                    Log::warning('feishu.http.fallback_no_token', ['uri' => $uri]);
                    continue;
                }
            }

            try {
                $response = $this->transport->client()->request('get', $uri, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$bearerToken,
                        'Content-Type' => 'application/json',
                        'Accept' => '*/*',
                    ],
                    'http_errors' => false,
                    'sink' => $tmpPath,
                ]);
            } catch (Throwable) {
                continue;
            }

            if ($response->getStatusCode() === 200 && File::exists($tmpPath) && File::size($tmpPath) > 0) {
                File::move($tmpPath, $targetPath);
                $contentType = trim((string) $response->getHeaderLine('Content-Type'));
                $disposition = trim((string) $response->getHeaderLine('Content-Disposition'));
                $resolvedName = $this->extractFilenameFromDisposition($disposition) ?: basename($targetPath);

                return [
                    'path' => $targetPath,
                    'mime_type' => $contentType,
                    'size' => File::size($targetPath),
                    'file_name' => $resolvedName,
                ];
            }

            if (File::exists($tmpPath)) {
                File::delete($tmpPath);
            }
        }

        if (File::exists($tmpPath)) {
            File::delete($tmpPath);
        }

        throw new \RuntimeException('feishu resource download failed');
    }

    private function extractFilenameFromDisposition(string $disposition): string
    {
        if ($disposition === '') {
            return '';
        }

        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $disposition, $m) === 1) {
            return rawurldecode(trim((string) $m[1], "\"' "));
        }

        if (preg_match('/filename=([^;]+)/i', $disposition, $m) === 1) {
            return trim((string) $m[1], "\"' ");
        }

        return '';
    }
}
