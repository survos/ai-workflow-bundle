<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Tests\Execution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Execution\OperationCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(OperationCache::class)]
final class OperationCacheTest extends TestCase
{
    #[Test]
    public function modelPromptScopeAndContentChangesInvalidateCache(): void
    {
        $cache = new OperationCache(new ArrayAdapter());
        $identity = ['model' => 'v1', 'prompt' => 'v1', 'scope' => 'a', 'content' => 'abc'];
        $calls = 0;
        $call = static function () use (&$calls): object {
            ++$calls;

            return (object) ['answer' => 'ok'];
        };
        $first = $cache->execute($identity, $call);
        self::assertFalse($first->cacheHit);
        self::assertTrue($cache->execute($identity, $call)->cacheHit);
        foreach (['model', 'prompt', 'scope', 'content'] as $field) {
            $changed = $identity;
            $changed[$field] = 'changed';
            self::assertFalse($cache->execute($changed, $call)->cacheHit);
        }
        self::assertSame(5, $calls);
    }

    #[Test]
    public function failureIsNotCached(): void
    {
        $cache = new OperationCache(new ArrayAdapter());
        try {
            $cache->execute(['task' => 'failure'], static fn () => throw new \RuntimeException('failed'));
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('failed', $e->getMessage());
        }
        self::assertFalse($cache->execute(['task' => 'failure'], static fn () => new \stdClass())->cacheHit);
    }
}
