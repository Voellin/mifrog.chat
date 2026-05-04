<?php

namespace Tests\Unit\Services;

use App\Services\RunExecutionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

class RunExecutionServiceToolCallingWiringTest extends TestCase
{
    public function testRunExecutionServiceKeepsToolCallingDependencyAndHelperMethod(): void
    {
        $reflection = new ReflectionClass(RunExecutionService::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $parameterNames = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $constructor->getParameters()
        );

        $this->assertContains('toolCallingAgentService', $parameterNames);
        $this->assertTrue($reflection->hasMethod('executeWithToolCalling'));

        $method = $reflection->getMethod('executeWithToolCalling');
        $returnType = $method->getReturnType();

        $this->assertTrue($method->isPrivate());
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('bool', $returnType->getName());
    }

    public function testRunExecutionServiceUsesSafeMemoryPersistenceHelper(): void
    {
        $reflection = new ReflectionClass(RunExecutionService::class);

        $this->assertTrue($reflection->hasMethod('persistMemorySafely'));
        $helper = $reflection->getMethod('persistMemorySafely');
        $this->assertTrue($helper->isPrivate());

        $source = file_get_contents($reflection->getFileName());
        $this->assertIsString($source);
        $this->assertSame(1, substr_count($source, '$this->memoryService->persist('));
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'persistMemorySafely('));
    }
}
