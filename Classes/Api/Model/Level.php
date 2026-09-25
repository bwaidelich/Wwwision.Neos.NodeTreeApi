<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A page of the children of one item – or of the root level, if parentId is null
 */
final readonly class Level implements ProvidesSchema
{
    public function __construct(
        public ItemId|null $parentId,
        public TreeItems $items,
        // set if there are items before the first one, to be requested with "cursor"
        public Cursor|null $previousCursor,
        // set if there are items after the last one, to be requested with "cursor"
        public Cursor|null $nextCursor,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
