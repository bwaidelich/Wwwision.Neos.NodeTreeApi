<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Schematic;

/**
 * The maximum number of items per level
 */
final readonly class Limit implements ProvidesSchema
{
    public const int DEFAULT = 50;
    private const int MAXIMUM = 200;

    private function __construct(
        public int $value,
    ) {
    }

    public static function fromInteger(int $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function default(): self
    {
        return self::fromInteger(self::DEFAULT);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= IntegerSchema::create(
            description: sprintf('The maximum number of items per level, %d by default', self::DEFAULT),
            minimum: 1,
            maximum: self::MAXIMUM,
        );
    }
}
