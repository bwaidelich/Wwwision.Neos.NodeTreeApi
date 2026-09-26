<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindPrecedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSucceedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\DropPosition;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Api\Model\MoveResult;
use Wwwision\Neos\NodeTreeApi\Api\Model\MoveTarget;
use Wwwision\Neos\NodeTreeApi\Resolver\ParentItem;

/**
 * Moves the items of a tree – and tells where they can be moved to
 *
 * Before the content repository gets to check a move, this checks the rules of the tree: the resolvers decide which
 * items can be dragged and which are sortable, and the user needs edit permission on the moved node *and* on its new
 * parent
 */
#[Flow\Scope('singleton')]
final readonly class ItemMover
{
    /**
     * The strategy for nodes of types without the option "moveNodeStrategy" (which is defined by the Neos UI)
     */
    private const RelationDistributionStrategy DEFAULT_STRATEGY = RelationDistributionStrategy::STRATEGY_GATHER_ALL;

    public function __construct(
        private TreeLoader $treeLoader,
        private LabelTranslator $labelTranslator,
    ) {
    }

    /**
     * The drop keys of all items the item can be moved into, see {@see ChildConstraints}
     *
     * @return list<string>
     * @throws InvalidRequest | NotFound
     */
    public function dropKeys(TreeRequest $request, ItemId $itemId): array
    {
        $nodeType = $request->nodeTypeManager->getNodeType($request->node($itemId)->nodeTypeName);
        return $nodeType !== null ? $request->childConstraints->dropKeysAccepting($nodeType) : [];
    }

    /**
     * @throws InvalidRequest | NotFound | Forbidden | Conflict
     */
    public function move(TreeRequest $request, ItemId $itemId, MoveTarget $moveTarget, int $limit): MoveResult
    {
        if (!$request->permissions->workspaceWritable) {
            throw new Forbidden($this->labelTranslator->translateOwn('move.forbiddenWorkspace', [$request->context->workspace]), 1790061101);
        }
        $node = $this->movedNode($request, $itemId);
        $nodeType = $request->nodeTypeManager->getNodeType($node->nodeTypeName) ?? throw $this->notMovable();
        $previousParent = $request->subgraph->findParentNode($node->aggregateId) ?? throw $this->notMovable();
        [$newParent, $precedingSiblingId, $succeedingSiblingId] = $this->placement($request, $node, $moveTarget);
        if (!$request->permissions->canEdit($newParent)) {
            throw new Forbidden($this->labelTranslator->translateOwn('move.forbiddenTarget'), 1790061102);
        }
        $parentChanges = !$newParent->aggregateId->equals($previousParent->aggregateId);
        // the content repository only checks the constraints if the parent changes, too
        if ($parentChanges && !$request->childConstraints->allowsChild($newParent, $nodeType)) {
            throw $this->notAllowedThere();
        }

        try {
            $request->contentRepository->handle(MoveNodeAggregate::create(
                $node->workspaceName,
                $node->dimensionSpacePoint,
                $node->aggregateId,
                self::strategy($nodeType),
                // like the Neos UI: with the same parent, the node name would be "already covered"
                $parentChanges ? $newParent->aggregateId : null,
                $precedingSiblingId,
                $succeedingSiblingId,
            ));
        } catch (AccessDenied $exception) {
            throw new Forbidden($exception->getMessage(), 1790061103, $exception);
        } catch (\DomainException $exception) {
            // the constraint checks of the content repository, e.g. for moving a node into its own descendants
            throw new Conflict($this->labelTranslator->translateOwn('move.notAllowedThere'), 1790061104, $exception);
        }

        $request = $request->refreshed();
        $previousParent = $request->subgraph->findNodeById($previousParent->aggregateId);
        return new MoveResult(
            $request->context,
            $this->treeLoader->expandTo($request, $itemId, $limit)->levels,
            $previousParent !== null && $request->contains($previousParent) ? $this->treeLoader->nodeItem($request, $previousParent) : null,
        );
    }

    /**
     * The node to move – if it can be dragged
     */
    private function movedNode(TreeRequest $request, ItemId $itemId): Node
    {
        if (!$itemId->isNode()) {
            throw $this->notMovable();
        }
        $node = $request->node($itemId);
        if ($node->aggregateId->equals($request->siteNode->aggregateId) || $node->classification->isTethered()) {
            throw $this->notMovable();
        }
        if ($request->pathBelowSiteNode($node) === null) {
            throw new NotFound(sprintf('There is no item "%s" in this tree', $itemId->value), 1790061105);
        }
        if (!$request->permissions->canEdit($node)) {
            throw new Forbidden($this->labelTranslator->translateOwn('move.forbiddenItem'), 1790061106);
        }
        if (!$this->listingResolverAllows($request, $node, draggable: true)) {
            throw $this->notMovable();
        }
        return $node;
    }

    /**
     * The new parent of the moved node, and the siblings it is to be placed between
     *
     * @return array{Node, NodeAggregateId|null, NodeAggregateId|null}
     */
    private function placement(TreeRequest $request, Node $node, MoveTarget $moveTarget): array
    {
        // virtual items can't be dropped onto
        if (!$moveTarget->target->isNode()) {
            throw $this->notAllowedThere();
        }
        $target = $request->node($moveTarget->target);
        if ($target->aggregateId->equals($node->aggregateId)) {
            throw $this->notAllowedThere();
        }
        $targetIsSiteNode = $target->aggregateId->equals($request->siteNode->aggregateId);
        if (!$targetIsSiteNode && $request->pathBelowSiteNode($target) === null) {
            throw new NotFound(sprintf('There is no item "%s" in this tree', $moveTarget->target->value), 1790061107);
        }
        if ($moveTarget->position === DropPosition::into) {
            // appended to the children
            return [$target, null, null];
        }
        if ($targetIsSiteNode || !$this->listingResolverAllows($request, $target, sortable: true)) {
            throw $this->notAllowedThere();
        }
        $targetParent = $request->subgraph->findParentNode($target->aggregateId) ?? throw $this->notAllowedThere();
        // both siblings, so that the content repository can place the node sensibly in other dimensions, too
        if ($moveTarget->position === DropPosition::before) {
            $precedingSibling = $request->subgraph->findPrecedingSiblingNodes($target->aggregateId, FindPrecedingSiblingNodesFilter::create(pagination: Pagination::fromLimitAndOffset(1, 0)))->first();
            return [$targetParent, self::otherThan($node, $precedingSibling), $target->aggregateId];
        }
        $succeedingSibling = $request->subgraph->findSucceedingSiblingNodes($target->aggregateId, FindSucceedingSiblingNodesFilter::create(pagination: Pagination::fromLimitAndOffset(1, 0)))->first();
        return [$targetParent, $target->aggregateId, self::otherThan($node, $succeedingSibling)];
    }

    /**
     * Whether the resolver that lists $node allows to drag it, or to drop items before and after it
     */
    private function listingResolverAllows(TreeRequest $request, Node $node, bool $draggable = false, bool $sortable = false): bool
    {
        $parent = $request->subgraph->findParentNode($node->aggregateId);
        if ($parent === null) {
            return false;
        }
        [$resolverKey, $resolver] = $request->resolverFor(new ParentItem(ItemId::forNode($parent->aggregateId), $parent));
        $context = $request->context($resolverKey);
        $dragAndDrop = $resolver->dragAndDrop($context, $context->nodeItem($node));
        return (!$draggable || $dragAndDrop->draggable) && (!$sortable || $dragAndDrop->sortable);
    }

    private static function strategy(NodeType $nodeType): RelationDistributionStrategy
    {
        $strategy = $nodeType->getConfiguration('options.moveNodeStrategy');
        return (is_string($strategy) ? RelationDistributionStrategy::tryFrom($strategy) : null) ?? self::DEFAULT_STRATEGY;
    }

    private static function otherThan(Node $node, Node|null $sibling): NodeAggregateId|null
    {
        return $sibling !== null && !$sibling->aggregateId->equals($node->aggregateId) ? $sibling->aggregateId : null;
    }

    private function notMovable(): Conflict
    {
        return new Conflict($this->labelTranslator->translateOwn('move.notMovable'), 1790061108);
    }

    private function notAllowedThere(): Conflict
    {
        return new Conflict($this->labelTranslator->translateOwn('move.notAllowedThere'), 1790061109);
    }
}
