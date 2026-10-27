<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Execution;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Callers provide complete, versioned inputs, including model/options and content hashes. */
final class OperationCache
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * @template T of object
     *
     * @param array<string, mixed> $identity  no credentials; include tenant/scope when applicable
     * @param callable(): T        $operation must validate the result before returning it
     *
     * @return CachedExecution<T>
     */
    public function execute(array $identity, callable $operation, int $ttl = 86400): CachedExecution
    {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('Cache lifetime must be positive.');
        }
        $key = 'ai_operation_'.\hash('sha256', \json_encode($identity, JSON_THROW_ON_ERROR));
        $hit = true;
        $started = \microtime(true);
        $record = $this->cache->get($key, function (ItemInterface $item) use ($operation, $ttl, &$hit): array {
            $hit = false;
            $started = \microtime(true);
            $value = $operation(); // Exceptions are never stored.
            $item->expiresAfter($ttl);

            return [$value, (int) \round((\microtime(true) - $started) * 1000), (new \DateTimeImmutable())->format(DATE_ATOM)];
        });

        return new CachedExecution($record[0], $key, $hit, (int) \round((\microtime(true) - $started) * 1000), $record[1], $record[2]);
    }
}
