<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An entry of a tree: either a node, or a virtual item that groups nodes (e.g. by month)
 */
final readonly class TreeItem implements ProvidesSchema
{
    public function __construct(
        public ItemId $id,
        public string $label,
        public string|null $icon,
        // whether the item can be expanded, i.e. whether requesting its children returns any
        public bool $hasChildren,
        // the node this item represents, null for virtual items
        public NodeInfo|null $node,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
