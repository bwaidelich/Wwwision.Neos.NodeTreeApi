<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The result of the "listItems" operation
 *
 * Usually a single level. With "expandTo", one level per ancestor of the item to expand to – starting with the root
 * level, and ending with the one containing the item. The levels are a flat list, each refers to its parent item
 */
final readonly class ItemsResult implements ProvidesSchema
{
    public function __construct(
        public Levels $levels,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
