<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

/**
 * Lists the child nodes that are part of the tree (see its "baseNodeType"), ordered by their position
 */
#[Flow\Proxy(false)]
final readonly class DefaultNodeResolver implements ChildrenResolver
{
    public function resolve(ResolverContext $context, ParentItem $parent, array|null $cursor, int $limit): ResolvedLevel
    {
        $page = $this->pagination($context)->page($parent->node()->aggregateId, $cursor, $limit);
        return new ResolvedLevel($parent->id, $context->nodeItems($page['nodes']), $page['previousCursor'], $page['nextCursor']);
    }

    public function hasChildren(ResolverContext $context, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            // TODO: batch this, once the content graph can count the children of several nodes at once
            $result[$item->id->value] = $context->subgraph->countChildNodes($item->node()->aggregateId, CountChildNodesFilter::create(nodeTypes: $context->tree->nodeTypes)) > 0;
        }
        return $result;
    }

    public function resolveAround(ResolverContext $context, ParentItem $parent, Node $descendant, int $limit): array
    {
        $page = $this->pagination($context)->around($descendant, $limit);
        return [new ResolvedLevel($parent->id, $context->nodeItems($page['nodes']), $page['previousCursor'], $page['nextCursor'])];
    }

    private function pagination(ResolverContext $context): SiblingPagination
    {
        return new SiblingPagination($context->subgraph, $context->tree->nodeTypes);
    }
}
