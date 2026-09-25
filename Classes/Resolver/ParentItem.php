<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

/**
 * The item whose children are to be resolved
 */
#[Flow\Proxy(false)]
final readonly class ParentItem
{
    public function __construct(
        public ItemId $id,
        // null for virtual items
        public Node|null $node,
    ) {
    }

    /**
     * The node of a parent that is known to represent one, i.e. for resolvers that are mapped to node types
     */
    public function node(): Node
    {
        if ($this->node === null) {
            throw new InvalidRequest(sprintf('Item "%s" does not represent a node', $this->id->value), 1790060301);
        }
        return $this->node;
    }
}
