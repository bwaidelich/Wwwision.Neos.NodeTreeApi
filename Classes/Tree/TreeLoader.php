<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\Cursor;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemsResult;
use Wwwision\Neos\NodeTreeApi\Api\Model\Level;
use Wwwision\Neos\NodeTreeApi\Api\Model\Levels;
use Wwwision\Neos\NodeTreeApi\Api\Model\NodeInfo;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeItem;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeItems;
use Wwwision\Neos\NodeTreeApi\Resolver\DragAndDrop;
use Wwwision\Neos\NodeTreeApi\Resolver\Item;
use Wwwision\Neos\NodeTreeApi\Resolver\ParentItem;
use Wwwision\Neos\NodeTreeApi\Resolver\ResolvedLevel;

/**
 * Loads the levels of a tree: picks the resolver for each parent, and asks the responsible resolvers whether the
 * resulting items have children and how they can be dragged and dropped
 */
#[Flow\Scope('singleton')]
final readonly class TreeLoader
{
    /**
     * @throws InvalidRequest | NotFound
     */
    public function load(TreeRequest $request, ItemId|null $parentId, Cursor|null $cursor, int $limit, ItemId|null $expandTo = null): ItemsResult
    {
        if ($cursor !== null && $cursor->contextHash() !== $request->context->hash()) {
            throw new InvalidRequest('The cursor belongs to another site, workspace or dimensions', 1790060617);
        }
        if ($expandTo !== null) {
            if ($parentId !== null || $cursor !== null) {
                throw new InvalidRequest('"expandTo" can\'t be combined with "parent" or "cursor"', 1790060614);
            }
            return $this->expandTo($request, $expandTo, $limit);
        }
        if ($parentId === null) {
            if ($cursor !== null) {
                throw new InvalidRequest('The root level has only one page, a "cursor" requires a "parent"', 1790060602);
            }
            return new ItemsResult($request->context, $this->render($request, [null, $this->rootLevel($request)]));
        }
        $parent = $this->parentItem($request, $parentId);
        [$resolverKey, $resolver] = $request->resolverFor($parent);
        if ($cursor !== null && $cursor->resolverKey() !== $resolverKey) {
            throw new InvalidRequest('The cursor does not belong to the children of this parent', 1790060603);
        }
        return new ItemsResult($request->context, $this->render($request, [$resolverKey, $resolver->resolve($request->context($resolverKey), $parent, $cursor?->payload(), $limit)]));
    }

    /**
     * The root level, followed by the levels down to the one containing the target – each a window around the
     * respective ancestor of the target. Virtual levels in between are added by the resolvers that create them
     *
     * @throws InvalidRequest | NotFound
     */
    public function expandTo(TreeRequest $request, ItemId $targetId, int $limit): ItemsResult
    {
        if (!$targetId->isNode()) {
            throw new InvalidRequest('"expandTo" has to be the id of an item that represents a node', 1790060615);
        }
        $notFound = new NotFound(sprintf('There is no item "%s" in this tree', $targetId->value), 1790060616);
        $target = $request->subgraph->findNodeById($targetId->nodeAggregateId()) ?? throw $notFound;
        $levels = [[null, $this->rootLevel($request)]];
        if (!$target->aggregateId->equals($request->siteNode->aggregateId)) {
            $parent = new ParentItem(ItemId::forNode($request->siteNode->aggregateId), $request->siteNode);
            foreach ($request->pathBelowSiteNode($target) ?? throw $notFound as $node) {
                [$resolverKey, $resolver] = $request->resolverFor($parent);
                foreach ($resolver->resolveAround($request->context($resolverKey), $parent, $node, $limit) as $level) {
                    $levels[] = [$resolverKey, $level];
                }
                $parent = new ParentItem(ItemId::forNode($node->aggregateId), $node);
            }
        }
        return new ItemsResult($request->context, $this->render($request, ...$levels));
    }

    /**
     * The item that represents $node, as it is listed in the level of its parent
     */
    public function nodeItem(TreeRequest $request, Node $node): TreeItem
    {
        if ($node->aggregateId->equals($request->siteNode->aggregateId)) {
            $level = [null, $this->rootLevel($request)];
        } else {
            $parent = $request->subgraph->findParentNode($node->aggregateId);
            $resolverKey = $parent !== null ? $request->resolverFor(new ParentItem(ItemId::forNode($parent->aggregateId), $parent))[0] : TreeDefinition::DEFAULT_RESOLVER;
            $level = [$resolverKey, new ResolvedLevel(null, [$request->context($resolverKey)->nodeItem($node)])];
        }
        return $this->render($request, $level)->levels[0]->items->items[0];
    }

    private function rootLevel(TreeRequest $request): ResolvedLevel
    {
        return new ResolvedLevel(null, [$request->context(TreeDefinition::DEFAULT_RESOLVER)->nodeItem($request->siteNode)]);
    }

    private function parentItem(TreeRequest $request, ItemId $parentId): ParentItem
    {
        if (!$parentId->isNode()) {
            return new ParentItem($parentId, null);
        }
        $node = $request->subgraph->findNodeById($parentId->nodeAggregateId());
        if ($node === null || !$request->contains($node)) {
            throw new NotFound(sprintf('There is no item "%s" in this tree', $parentId->value), 1790060613);
        }
        return new ParentItem($parentId, $node);
    }

    /**
     * @param array{string|null, ResolvedLevel} ...$resolvedLevels each with the key of the resolver it was resolved by
     *   – which is the one its cursors are passed to, and the one that decides about dragging its items. Null for the
     *   root level
     */
    private function render(TreeRequest $request, array ...$resolvedLevels): Levels
    {
        $hasChildren = $this->hasChildren($request, ...array_column($resolvedLevels, 1));
        $contextHash = $request->context->hash();
        $levels = [];
        foreach ($resolvedLevels as [$resolverKey, $resolvedLevel]) {
            $levels[] = new Level(
                parentId: $resolvedLevel->parentId,
                items: new TreeItems(...array_map(
                    fn (Item $item) => $this->treeItem($request, $resolverKey, $item, $hasChildren[$item->id->value] ?? false),
                    $resolvedLevel->items,
                )),
                previousCursor: $resolverKey !== null && $resolvedLevel->previousCursor !== null ? Cursor::create($resolverKey, $contextHash, $resolvedLevel->previousCursor) : null,
                nextCursor: $resolverKey !== null && $resolvedLevel->nextCursor !== null ? Cursor::create($resolverKey, $contextHash, $resolvedLevel->nextCursor) : null,
            );
        }
        return new Levels(...$levels);
    }

    private function treeItem(TreeRequest $request, string|null $resolverKey, Item $item, bool $hasChildren): TreeItem
    {
        $draggable = false;
        $sortable = false;
        $dropKey = null;
        if ($item->node !== null) {
            $dragAndDrop = $resolverKey !== null ? $request->resolver($resolverKey)->dragAndDrop($request->context($resolverKey), $item) : DragAndDrop::none();
            $editable = $request->permissions->canEdit($item->node);
            $draggable = $dragAndDrop->draggable && $editable && !$item->node->classification->isTethered();
            $sortable = $dragAndDrop->sortable;
            $dropKey = $editable ? $request->childConstraints->dropKeyFor($item->node) : null;
        }
        return new TreeItem(
            id: $item->id,
            label: $item->label,
            icon: $item->icon,
            tooltip: $item->tooltip,
            appearance: $item->appearance,
            badges: $item->badges,
            hasChildren: $hasChildren,
            draggable: $draggable,
            sortable: $sortable,
            dropKey: $dropKey,
            node: $item->node !== null ? new NodeInfo($item->node->aggregateId->value, $item->node->nodeTypeName->value) : null,
        );
    }

    /**
     * Asks the resolver of each item whether it has children – all items of one resolver at once
     *
     * @return array<string, bool>
     */
    private function hasChildren(TreeRequest $request, ResolvedLevel ...$resolvedLevels): array
    {
        $itemsByResolverKey = [];
        foreach ($resolvedLevels as $resolvedLevel) {
            foreach ($resolvedLevel->items as $item) {
                $parent = $item->asParent();
                $itemsByResolverKey[$request->resolverFor($parent)[0]][] = $parent;
            }
        }
        $hasChildren = [];
        foreach ($itemsByResolverKey as $resolverKey => $items) {
            $hasChildren = [...$hasChildren, ...$request->resolver((string)$resolverKey)->hasChildren($request->context((string)$resolverKey), $items)];
        }
        return $hasChildren;
    }
}
