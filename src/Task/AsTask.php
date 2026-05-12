<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use function Symfony\Component\String\u;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsTask
{
    public readonly string $name;

    public function __construct(
        public readonly string $description,
        public readonly string $class = '',
    ) {
        $this->name = $class !== '' ? self::resolveName($class) : '';
    }

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'class'       => $this->class,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'] ?? '',
            class:       $data['class']       ?? '',
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
