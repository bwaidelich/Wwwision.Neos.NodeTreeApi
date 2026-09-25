<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The name of a configured tree, e.g. "default" (see "Wwwision.Neos.NodeTreeApi.trees")
 */
final readonly class TreeId implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The name of a tree',
            examples: ['default'],
            pattern: '^[a-zA-Z0-9_-]{1,100}$',
        );
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
