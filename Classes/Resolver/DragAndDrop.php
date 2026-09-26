<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\Flow\Annotations as Flow;

/**
 * What a {@see ChildrenResolver} allows for one of the items it lists
 *
 * The tree adds the rules that apply to all items: permissions, tethered nodes and node type constraints
 */
#[Flow\Proxy(false)]
final readonly class DragAndDrop
{
    public function __construct(
        // whether the item can be moved elsewhere
        public bool $draggable,
        // whether other items can be dropped before and after it, i.e. whether the order of the level is the order of
        // the nodes
        public bool $sortable,
    ) {
    }

    public static function all(): self
    {
        return new self(true, true);
    }

    public static function none(): self
    {
        return new self(false, false);
    }
}
