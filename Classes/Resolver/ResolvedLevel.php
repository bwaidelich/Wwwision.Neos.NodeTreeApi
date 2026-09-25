<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;

/**
 * A page of the children of an item, as resolved by a {@see ChildrenResolver}
 *
 * The cursors are payloads of the resolver that created them: requesting the respective page passes them back to it
 */
#[Flow\Proxy(false)]
final readonly class ResolvedLevel
{
    /**
     * @param list<Item> $items
     * @param array<mixed>|null $previousCursor
     * @param array<mixed>|null $nextCursor
     */
    public function __construct(
        // null for the root level
        public ItemId|null $parentId,
        public array $items,
        public array|null $previousCursor = null,
        public array|null $nextCursor = null,
    ) {
    }
}
