<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use function Symfony\Component\String\u;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsTask
{
    public readonly string $name;

    /**
     * @param list<string> $consumes claim predicates this task reads (its declared dependencies)
     * @param list<string> $produces claim predicates this task writes
     * @param list<string> $samples  sample input URLs/text for self-testing and the app:tasks tester
     */
    public function __construct(
        public readonly string $description,
        public readonly string $class = '',
        public readonly array $consumes = [],
        public readonly array $produces = [],
        public readonly array $samples = [],
    ) {
        $this->name = $class !== '' ? self::resolveName($class) : '';
    }

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'class'       => $this->class,
            'consumes'    => $this->consumes,
            'produces'    => $this->produces,
            'samples'     => $this->samples,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'] ?? '',
            class:       $data['class']       ?? '',
            consumes:    $data['consumes']    ?? [],
            produces:    $data['produces']    ?? [],
            samples:     $data['samples']     ?? [],
        );
    }

    private static function resolveName(string $class): string
    {
        if (defined($class . '::TASK')) {
            return constant($class . '::TASK');
        }

        return self::deriveName((new \ReflectionClass($class))->getShortName());
    }

    public static function deriveName(string $shortClassName): string
    {
        return u(preg_replace('/Task$/', '', $shortClassName) ?? $shortClassName)
            ->snake()
            ->toString();
    }
}
