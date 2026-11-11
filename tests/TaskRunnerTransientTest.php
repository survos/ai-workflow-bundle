<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TaskRunnerTransientTest extends TestCase
{
    public function testNetworkServerAndRateLimitFailuresAreWorthRetrying(): void
    {
        self::assertTrue(TaskRunner::isTransient(new class('down') extends \RuntimeException implements TransportExceptionInterface {}));
        self::assertTrue(TaskRunner::isTransient(new class('boom') extends \RuntimeException implements ServerExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                throw new \LogicException();
            }
        }));
        self::assertTrue(TaskRunner::isTransient($this->clientError(429)));
        self::assertTrue(TaskRunner::isTransient(new RecoverableMessageHandlingException('later')));
        self::assertTrue(TaskRunner::isTransient(new \RuntimeException('wrapped', 0, new class('down') extends \RuntimeException implements TransportExceptionInterface {})));
    }

    public function testBadInputAndClientErrorsAreTheTasksOwnFailure(): void
    {
        self::assertFalse(TaskRunner::isTransient(new \InvalidArgumentException('no image')));
        self::assertFalse(TaskRunner::isTransient($this->clientError(400)));
    }

    private function clientError(int $status): \Throwable
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        return new class($response) extends \RuntimeException implements ClientExceptionInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
                parent::__construct('http');
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
