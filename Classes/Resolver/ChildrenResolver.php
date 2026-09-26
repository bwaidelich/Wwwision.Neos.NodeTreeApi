<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Lists the children of tree items
 *
 * A resolver is responsible for the children of the nodes it is mapped to (see "childrenResolvers" of a tree), and
 * for the children of all virtual items it creates. Its options are passed to the constructor as named arguments,
 * everything else it needs is part of the {@see ResolverContext}
 */
interface ChildrenResolver
{
    /**
     * A page of the children of $parent
     *
     * @param array<mixed>|null $cursor a cursor this resolver returned before, null for the first page
     * @throws \Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest if the cursor or the parent is invalid
     */
    public function resolve(ResolverContext $context, ParentItem $parent, array|null $cursor, int $limit): ResolvedLevel;

    /**
     * Whether the given items have any children
     *
     * @param list<ParentItem> $items
     * @return array<string, bool> by item id
     */
    public function hasChildren(ResolverContext $context, array $items): array;

    /**
     * The levels from the children of $parent down to the level that contains $descendant – which is a child of
     * $parent, or a descendant of virtual items between them
     *
     * Each level should be a window around the respective ancestor of $descendant (or $descendant itself), with
     * cursors for the items before and after it
     *
     * @return non-empty-list<ResolvedLevel>
     */
    public function resolveAround(ResolverContext $context, ParentItem $parent, Node $descendant, int $limit): array;

    /**
     * Whether an item of the levels this resolver lists can be dragged, and whether items can be dropped before and
     * after it
     *
     * Only called for items that represent nodes: virtual items can neither be dragged nor dropped onto. Moving a node
     * "into" another one is up to the tree, it always appends the node to the children
     */
    public function dragAndDrop(ResolverContext $context, Item $item): DragAndDrop;
}
