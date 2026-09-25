<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;

/**
 * A tree item as resolved by a {@see ChildrenResolver} – before it is known whether it has children
 *
 * Create instances with {@see ResolverContext::nodeItem()} or {@see ResolverContext::virtualItem()}
 */
#[Flow\Proxy(false)]
final readonly class Item
{
    public function __construct(
        public ItemId $id,
        public string $label,
        public string|null $icon,
        // null for virtual items
        public Node|null $node,
    ) {
    }

    public function asParent(): ParentItem
    {
        return new ParentItem($this->id, $this->node);
    }
}
