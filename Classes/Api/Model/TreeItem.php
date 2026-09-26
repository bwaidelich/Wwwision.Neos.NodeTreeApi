<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An entry of a tree: either a node, or a virtual item that groups nodes (e.g. by month)
 *
 * Everything a client needs to render it is decided by the server: label, icon, tooltip, appearance and badges – as well
 * as how it can be dragged and dropped onto
 */
final readonly class TreeItem implements ProvidesSchema
{
    public function __construct(
        public ItemId $id,
        public string $label,
        public string|null $icon,
        // translated to the interface language of the current user
        public string|null $tooltip,
        public Appearances $appearance,
        public Badges $badges,
        // whether the item can be expanded, i.e. whether requesting its children returns any
        public bool $hasChildren,
        // whether the item can be moved, see "moveItem"
        public bool $draggable,
        // whether items can be moved before and after it – if the parent of its level accepts them (see "dropKey")
        public bool $sortable,
        // identifies the node type constraints for the children of the item: other items can be moved into it, if this
        // is one of the "dropKeys" of the moved item. Null if nothing can be moved into it
        public string|null $dropKey,
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
