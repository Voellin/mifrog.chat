<?php

namespace Tests\Unit\Services;

use App\Models\Run;
use App\Services\FeishuCliClient;
use App\Services\FeishuDocsTaskService;
use App\Services\FeishuService;
use App\Services\FeishuTokenService;
use App\Services\LlmGatewayService;
use App\Services\MemoryService;
use PHPUnit\Framework\TestCase;

class FeishuDocsTaskServiceTest extends TestCase
{
    public function testExecuteCreateFallsBackToContentPromptWhenDraftContentIsEmpty(): void
    {
        $service = $this->makeService(
            llmResponse: ['content' => '{"title":"招商方案报告","content":""}', 'input_tokens' => 12, 'output_tokens' => 18],
            cliAssertion: function (array $command): void {
                $this->assertSame('docs', $command[0]);
                $this->assertSame('+create', $command[1]);

                $markdownIndex = array_search('--markdown', $command, true);
                $this->assertNotFalse($markdownIndex);
                $this->assertSame(
                    "# 招商方案报告\n\n请围绕政府招商方案写一份提报文档",
                    $command[$markdownIndex + 1]
                );
            }
        );

        $result = $service->execute(
            new Run(),
            [
                'action' => 'create',
                'title' => '招商方案报告',
                'content_prompt' => '请围绕政府招商方案写一份提报文档',
            ],
            [
                ['role' => 'user', 'content' => '帮我写一份报告'],
            ]
        );

        $this->assertSame('created', $result['status']);
        $this->assertSame('doc-token-123', $result['document_id']);
    }

    public function testExecuteCreateFallsBackToLatestUserTextWhenPlannerDidNotExtractContentPrompt(): void
    {
        $service = $this->makeService(
            llmResponse: ['content' => '{"title":"招商方案报告","content":""}', 'input_tokens' => 12, 'output_tokens' => 18],
            cliAssertion: function (array $command): void {
                $markdownIndex = array_search('--markdown', $command, true);
                $this->assertNotFalse($markdownIndex);
                $this->assertSame(
                    "# 招商方案报告\n\n公司主营产业招商服务，需要一份政府招商提报。",
                    $command[$markdownIndex + 1]
                );
            }
        );

        $result = $service->execute(
            new Run(),
            [
                'action' => 'create',
                'title' => '招商方案报告',
                'content_prompt' => '',
            ],
            [
                ['role' => 'assistant', 'content' => '请补充文档方向'],
                ['role' => 'user', 'content' => '公司主营产业招商服务，需要一份政府招商提报。'],
            ]
        );

        $this->assertSame('created', $result['status']);
        $this->assertSame('doc-token-123', $result['document_id']);
    }

    public function testExecuteCreateReturnsClarifyBeforeCallingCliWhenNoMarkdownCanBePrepared(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $llmGateway = $this->createMock(LlmGatewayService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $memoryService = $this->createMock(MemoryService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', null, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $memoryService->expects($this->once())->method('getMemoryContext')->willReturn('');
        $llmGateway->expects($this->once())
            ->method('chatWithCapability')
            ->willReturn(['content' => '{"title":"招商方案报告","content":""}', 'input_tokens' => 1, 'output_tokens' => 1]);
        $cliClient->expects($this->never())->method('runSkillCommand');
        $feishuService->expects($this->never())->method('readConfig');

        $service = new FeishuDocsTaskService(
            $feishuService,
            $llmGateway,
            $tokenService,
            $memoryService,
            $cliClient
        );

        $result = $service->execute(
            new Run(),
            [
                'action' => 'create',
                'title' => '招商方案报告',
                'content_prompt' => '',
            ],
            []
        );

        $this->assertSame('clarify', $result['status']);
        $this->assertNotSame('', trim((string) ($result['message'] ?? '')));
    }

    public function testExecuteReadUsesMarkdownPayloadFromFetchResult(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $llmGateway = $this->createMock(LlmGatewayService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $memoryService = $this->createMock(MemoryService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', null, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                'access-token',
                ['docs', '+fetch', '--doc', 'doc-token-123', '--format', 'json'],
                'user'
            )
            ->willReturn([
                'code' => 0,
                'data' => [
                    'doc_id' => 'doc-token-123',
                    'markdown' => "## 背景\n这是文档正文",
                ],
            ]);
        $llmGateway->expects($this->once())
            ->method('chatWithCapability')
            ->willReturn([
                'content' => '这是一份关于背景的文档摘要。',
                'input_tokens' => 9,
                'output_tokens' => 6,
            ]);
        $memoryService->expects($this->never())->method('getMemoryContext');

        $service = new FeishuDocsTaskService(
            $feishuService,
            $llmGateway,
            $tokenService,
            $memoryService,
            $cliClient
        );

        $result = $service->execute(
            new Run(),
            [
                'action' => 'read',
                'doc_url' => 'https://www.feishu.cn/docx/doc-token-123',
            ],
            [
                ['role' => 'user', 'content' => '把刚刚那份文档读出来'],
            ]
        );

        $this->assertSame('read', $result['status']);
        $this->assertSame('这是一份关于背景的文档摘要。', $result['message']);
        $this->assertSame('doc-token-123', $result['document_id']);
    }

    /**
     * @param  array<string,mixed>  $llmResponse
     */
    private function makeService(array $llmResponse, callable $cliAssertion): FeishuDocsTaskService
    {
        $feishuService = $this->createMock(FeishuService::class);
        $llmGateway = $this->createMock(LlmGatewayService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $memoryService = $this->createMock(MemoryService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', null, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $memoryService->expects($this->once())->method('getMemoryContext')->willReturn('');
        $llmGateway->expects($this->once())
            ->method('chatWithCapability')
            ->willReturn($llmResponse);
        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                'access-token',
                $this->callback(function (array $command) use ($cliAssertion): bool {
                    $cliAssertion($command);

                    return true;
                }),
                'user'
            )
            ->willReturn([
                'code' => 0,
                'data' => [
                    'document_id' => 'doc-token-123',
                    'url' => 'https://applink.feishu.cn/client/docx/open?docToken=doc-token-123',
                ],
            ]);

        return new FeishuDocsTaskService(
            $feishuService,
            $llmGateway,
            $tokenService,
            $memoryService,
            $cliClient
        );
    }
}
