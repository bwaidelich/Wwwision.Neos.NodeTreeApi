<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The result of the "moveItem" operation
 *
 * The levels are the ones of "listItems" with "expandTo" the moved item: down to the level that contains it at its new
 * place. Clients replace their levels of the same parents, and remove the item from its previous level
 */
final readonly class MoveResult implements ProvidesSchema
{
    public function __construct(
        public ItemsContext $context,
        public Levels $levels,
        // the previous parent of the moved item – e.g. to update its "hasChildren". Null if it is not part of the tree
        public TreeItem|null $previousParent,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
