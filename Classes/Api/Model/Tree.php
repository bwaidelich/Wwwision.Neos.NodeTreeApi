<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A tree that can be loaded via the "listItems" operation
 */
final readonly class Tree implements ProvidesSchema
{
    public function __construct(
        public TreeId $id,
        // translated to the interface language of the current user
        public string $label,
        public string $icon,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
