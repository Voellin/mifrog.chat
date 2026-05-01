<?php

namespace Tests\Unit\Services;

use App\Models\Skill;
use App\Models\User;
use App\Services\SkillApiExecutorService;
use DomainException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SkillApiExecutorServiceTest extends TestCase
{
    private function makeSkill(string $key = 'inventory'): Skill
    {
        $skill = new Skill();
        $skill->forceFill([
            'id' => 101,
            'skill_key' => $key,
            'name' => 'Inventory',
            'description' => '查询库存',
            'meta' => ['executor' => 'http_api'],
            'is_active' => 1,
        ]);
        $skill->exists = true;
        return $skill;
    }

    private function makeUser(int $id = 7, string $name = 'lin'): User
    {
        $u = new User();
        $u->id = $id;
        $u->name = $name;
        return $u;
    }

    /**
     * Build an executor whose Guzzle client is backed by a MockHandler so we
     * can enqueue canned responses and inspect the requests it made.
     *
     * Returns an object whose `history` property is the live array that the
     * MockHandler middleware appends transactions to. We deliberately use an
     * object (not list-destructuring) because PHP list-destructuring strips
     * references, which broke the original test design.
     */
    private function makeExecutor(): object
    {
        $mock = new MockHandler();
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);

        $executor = new class($client) extends SkillApiExecutorService {
            private Client $injected;
            public function __construct(Client $c) { $this->injected = $c; }
            protected function buildClient(): Client { return $this->injected; }
        };

        return new class($executor, $mock, $history) {
            public SkillApiExecutorService $exec;
            public MockHandler $mock;
            /** @var array<int, array<string, mixed>> */
            public array $history;

            public function __construct(SkillApiExecutorService $exec, MockHandler $mock, array &$history)
            {
                $this->exec = $exec;
                $this->mock = $mock;
                // Bind by reference so that history middleware appends are
                // visible to the test through $this->history.
                $this->history = &$history;
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mifrog.skills.http_api', [
            'url_blacklist' => ['127.0.0.1', 'localhost', '0.0.0.0', '::1', '169.254.', 'metadata.google.internal'],
            'allowed_schemes' => ['http', 'https'],
            'default_timeout' => 10,
            'max_timeout' => 60,
            'max_response_bytes' => 65536,
        ]);
    }

    public function testUrlBlacklistBlocksLocalhost(): void
    {
        $ctx = $this->makeExecutor();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/黑名单/');
        $ctx->exec->execute(
            $this->makeSkill(),
            ['url' => 'http://localhost/api'],
            [],
            $this->makeUser()
        );
    }

    public function testUrlBlacklistPrefixMatchBlocksMetadataIp(): void
    {
        $ctx = $this->makeExecutor();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/黑名单前缀/');
        $ctx->exec->execute(
            $this->makeSkill(),
            ['url' => 'http://169.254.169.254/latest/meta-data/'],
            [],
            $this->makeUser()
        );
    }

    public function testInvalidSchemeRejected(): void
    {
        $ctx = $this->makeExecutor();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/scheme=/');
        // ftp://host/path has a valid host so parse_url succeeds, and the
        // scheme check is what throws. file:///etc/passwd has no host and
        // would be rejected with the generic 格式无效 message instead.
        $ctx->exec->execute(
            $this->makeSkill(),
            ['url' => 'ftp://internal.example.com/etc/passwd'],
            [],
            $this->makeUser()
        );
    }

    public function testRequiredParamMissingThrows(): void
    {
        $ctx = $this->makeExecutor();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/必填参数/');
        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
                'params' => [
                    ['name' => '商品ID', 'api_key' => 'spu_id', 'required' => true],
                ],
            ],
            [],
            $this->makeUser()
        );
    }

    public function testParamNameIsMappedToApiKeyInJsonBody(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));

        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api/query',
                'method' => 'POST',
                'params' => [
                    ['name' => '商品ID', 'api_key' => 'spu_id', 'required' => true],
                ],
            ],
            ['商品ID' => 'SPU-123'],
            $this->makeUser()
        );

        $this->assertCount(1, $ctx->history);
        /** @var PsrRequest $req */
        $req = $ctx->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertJson((string) $req->getBody());
        $this->assertSame(['spu_id' => 'SPU-123'], json_decode((string) $req->getBody(), true));
    }

    public function testVisibleFieldsFilterSimpleDotNotation(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['data' => ['total' => 5, 'ignore' => 'x']])
        ));

        $result = $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
                'visible_fields' => ['data.total'],
            ],
            [],
            $this->makeUser()
        );

        $this->assertStringContainsString('"total": 5', $result['answer']);
        $this->assertStringNotContainsString('ignore', $result['answer']);
    }

    public function testVisibleFieldsArrayProjection(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['items' => [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]]])
        ));

        $result = $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
                'visible_fields' => ['items[].a'],
            ],
            [],
            $this->makeUser()
        );

        $this->assertStringContainsString('"a": 1', $result['answer']);
        $this->assertStringContainsString('"a": 3', $result['answer']);
        $this->assertStringNotContainsString('"b": 2', $result['answer']);
    }

    public function testAuthorizationBearerDefaultInjection(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
                'token' => 'tok-xyz',
            ],
            [],
            $this->makeUser()
        );

        /** @var PsrRequest $req */
        $req = $ctx->history[0]['request'];
        $this->assertSame('Bearer tok-xyz', $req->getHeaderLine('Authorization'));
    }

    public function testCustomAuthorizationHeaderOverridesDefault(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
                'token' => 'tok-xyz',
                'headers' => ['Authorization' => 'ApiKey foo'],
            ],
            [],
            $this->makeUser()
        );

        /** @var PsrRequest $req */
        $req = $ctx->history[0]['request'];
        $this->assertSame('ApiKey foo', $req->getHeaderLine('Authorization'));
    }

    public function testGetMethodAppendsMappedParamsAsQuery(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/search',
                'method' => 'GET',
                'params' => [
                    ['name' => 'keyword', 'api_key' => 'q', 'required' => true],
                ],
            ],
            ['keyword' => '示例产品'],
            $this->makeUser()
        );

        /** @var PsrRequest $req */
        $req = $ctx->history[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertStringContainsString('q=', (string) $req->getUri());
        // URL-encoded Chinese
        $this->assertStringContainsString('%E9%98%B2%E6%99%92%E8%A1%A3', (string) $req->getUri());
    }

    public function testUrlPlaceholderRendering(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api/{{spu_id}}',
                'method' => 'GET',
                'params' => [
                    ['name' => '商品ID', 'api_key' => 'spu_id', 'required' => true],
                ],
            ],
            ['商品ID' => 'SPU-42'],
            $this->makeUser()
        );

        /** @var PsrRequest $req */
        $req = $ctx->history[0]['request'];
        $this->assertStringContainsString('/api/SPU-42', (string) $req->getUri());
        // The placeholder was consumed by URL; it should NOT also appear in query string.
        $this->assertStringNotContainsString('spu_id=', (string) $req->getUri());
    }

    public function testBodyTemplateRendering(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
                'body_template' => '{"spu_id":"{{spu_id}}","user":"{{user_name}}"}',
                'params' => [
                    ['name' => '商品ID', 'api_key' => 'spu_id', 'required' => true],
                ],
            ],
            ['商品ID' => 'SPU-99'],
            $this->makeUser(7, 'lin')
        );

        /** @var PsrRequest $req */
        $req = $ctx->history[0]['request'];
        $body = (string) $req->getBody();
        $decoded = json_decode($body, true);
        $this->assertSame('SPU-99', $decoded['spu_id']);
        $this->assertSame('lin', $decoded['user']);
    }

    public function testUnauthorizedResponseFormattedNicely(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_token"}'));

        $result = $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
            ],
            [],
            $this->makeUser()
        );

        $this->assertSame(401, $result['http_status']);
        $this->assertSame(401, $result['exit_code']);
        $this->assertStringContainsString('权限', $result['answer']);
        $this->assertStringContainsString('invalid_token', $result['answer']);
    }

    public function testTimeoutMarksTimedOut(): void
    {
        $ctx = $this->makeExecutor();
        $ctx->mock->append(new ConnectException(
            'cURL error 28: Operation timed out',
            new PsrRequest('POST', 'https://internal.example.com/api')
        ));

        $result = $ctx->exec->execute(
            $this->makeSkill(),
            [
                'url' => 'https://internal.example.com/api',
                'method' => 'POST',
            ],
            [],
            $this->makeUser()
        );

        $this->assertTrue($result['timed_out']);
        $this->assertSame(-1, $result['exit_code']);
        $this->assertStringContainsString('Skill /inventory 调用内部 API 失败', $result['answer']);
    }

    public function testMissingUrlThrows(): void
    {
        $ctx = $this->makeExecutor();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/未配置 api_url/');
        $ctx->exec->execute($this->makeSkill(), ['url' => ''], [], $this->makeUser());
    }
}
