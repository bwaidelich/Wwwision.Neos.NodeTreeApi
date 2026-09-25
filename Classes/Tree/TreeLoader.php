<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Exception\ContentRepositoryNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Wwwision\Neos\NodeTreeApi\Api\Model\Cursor;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Api\Model\Level;
use Wwwision\Neos\NodeTreeApi\Api\Model\Levels;
use Wwwision\Neos\NodeTreeApi\Api\Model\NodeInfo;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeItem;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeItems;
use Wwwision\Neos\NodeTreeApi\Resolver\Item;
use Wwwision\Neos\NodeTreeApi\Resolver\ParentItem;
use Wwwision\Neos\NodeTreeApi\Resolver\ResolvedLevel;
use Wwwision\Neos\NodeTreeApi\Resolver\ResolverContext;

/**
 * Loads the levels of a tree: picks the resolver for each parent, and asks the responsible resolvers whether the
 * resulting items have children
 */
#[Flow\Scope('singleton')]
final readonly class TreeLoader
{
    public function __construct(
        private TreeDefinitions $treeDefinitions,
        private ResolverRegistry $resolverRegistry,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private NodeLabelGeneratorInterface $nodeLabelGenerator,
        private LabelTranslator $labelTranslator,
    ) {
    }

    /**
     * @throws InvalidRequest | NotFound | Forbidden
     */
    public function load(
        string $contentRepositoryId,
        string $workspaceName,
        TreeId $treeId,
        string $siteNodeName,
        string $dimensionSpacePoint,
        ItemId|null $parentId,
        Cursor|null $cursor,
        int $limit,
    ): Levels {
        $tree = $this->treeDefinitions->get($treeId) ?? throw new NotFound(sprintf('There is no tree "%s"', $treeId->value), 1790060601);
        $contentRepository = $this->contentRepository($contentRepositoryId);
        $subgraph = $this->subgraph($contentRepository, $workspaceName, $dimensionSpacePoint);
        $request = new TreeRequest($tree, $subgraph, $contentRepository->getNodeTypeManager(), $this->resolverRegistry, $this->nodeLabelGenerator, $this->labelTranslator->locale());
        $siteNode = $this->siteNode($subgraph, $siteNodeName);

        if ($parentId === null) {
            if ($cursor !== null) {
                throw new InvalidRequest('The root level has only one page, a "cursor" requires a "parent"', 1790060602);
            }
            return $this->render($request, [null, $this->rootLevel($request, $siteNode)]);
        }
        $parent = $this->parentItem($request, $parentId);
        [$resolverKey, $resolver] = $request->resolverFor($parent);
        if ($cursor !== null && $cursor->resolverKey() !== $resolverKey) {
            throw new InvalidRequest('The cursor does not belong to the children of this parent', 1790060603);
        }
        return $this->render($request, [$resolverKey, $resolver->resolve($request->context($resolverKey), $parent, $cursor?->payload(), $limit)]);
    }

    private function contentRepository(string $contentRepositoryId): ContentRepository
    {
        try {
            $id = ContentRepositoryId::fromString($contentRepositoryId);
        } catch (\InvalidArgumentException) {
            throw new InvalidRequest(sprintf('"%s" is no valid content repository id', $contentRepositoryId), 1790060604);
        }
        try {
            return $this->contentRepositoryRegistry->get($id);
        } catch (ContentRepositoryNotFoundException) {
            throw new NotFound(sprintf('There is no content repository "%s"', $id->value), 1790060605);
        }
    }

    private function subgraph(ContentRepository $contentRepository, string $workspaceName, string $dimensionSpacePoint): ContentSubgraphInterface
    {
        $workspace = WorkspaceName::tryFromString($workspaceName) ?? throw new InvalidRequest(sprintf('"%s" is no valid workspace name', $workspaceName), 1790060606);
        try {
            $dimensions = DimensionSpacePoint::fromJsonString($dimensionSpacePoint);
        } catch (\Exception) {
            throw new InvalidRequest('"dimensions" has to be a JSON object with the dimension values, e.g. {"language":"en_US"}', 1790060607);
        }
        if (!$contentRepository->getVariationGraph()->getDimensionSpacePoints()->contains($dimensions)) {
            throw new InvalidRequest(sprintf('The dimensions %s are not configured in content repository "%s"', $dimensions->toJson(), $contentRepository->id->value), 1790060608);
        }
        try {
            return $contentRepository->getContentSubgraph($workspace, $dimensions);
        } catch (AccessDenied $exception) {
            throw new Forbidden($exception->getMessage(), 1790060609, $exception);
        } catch (WorkspaceDoesNotExist) {
            throw new NotFound(sprintf('There is no workspace "%s"', $workspace->value), 1790060610);
        }
    }

    private function siteNode(ContentSubgraphInterface $subgraph, string $siteNodeName): Node
    {
        try {
            $name = NodeName::fromString($siteNodeName);
        } catch (\InvalidArgumentException) {
            throw new InvalidRequest(sprintf('"%s" is no valid site node name', $siteNodeName), 1790060611);
        }
        $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
        $siteNode = $sitesNode !== null ? $subgraph->findNodeByPath($name, $sitesNode->aggregateId) : null;
        return $siteNode ?? throw new NotFound(sprintf('There is no site "%s"', $name->value), 1790060612);
    }

    private function rootLevel(TreeRequest $request, Node $siteNode): ResolvedLevel
    {
        return new ResolvedLevel(null, [$request->context(TreeDefinition::DEFAULT_RESOLVER)->nodeItem($siteNode)]);
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
     * @param array{string|null, ResolvedLevel} ...$resolvedLevels each with the key of the resolver it was resolved by,
     *   which is the one its cursors are passed to
     */
    private function render(TreeRequest $request, array ...$resolvedLevels): Levels
    {
        $hasChildren = $this->hasChildren($request, ...array_column($resolvedLevels, 1));
        $levels = [];
        foreach ($resolvedLevels as [$cursorResolverKey, $resolvedLevel]) {
            $levels[] = new Level(
                parentId: $resolvedLevel->parentId,
                items: new TreeItems(...array_map(
                    static fn (Item $item) => new TreeItem($item->id, $item->label, $item->icon, $hasChildren[$item->id->value] ?? false, self::nodeInfo($item->node)),
                    $resolvedLevel->items,
                )),
                previousCursor: $cursorResolverKey !== null && $resolvedLevel->previousCursor !== null ? Cursor::create($cursorResolverKey, $resolvedLevel->previousCursor) : null,
                nextCursor: $cursorResolverKey !== null && $resolvedLevel->nextCursor !== null ? Cursor::create($cursorResolverKey, $resolvedLevel->nextCursor) : null,
            );
        }
        return new Levels(...$levels);
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
            $hasChildren = [...$hasChildren, ...$this->resolverRegistry->get((string)$resolverKey)->hasChildren($request->context((string)$resolverKey), $items)];
        }
        return $hasChildren;
    }

    private static function nodeInfo(Node|null $node): NodeInfo|null
    {
        if ($node === null) {
            return null;
        }
        return new NodeInfo(
            aggregateId: $node->aggregateId->value,
            nodeTypeName: $node->nodeTypeName->value,
            isTethered: $node->classification->isTethered(),
            isDisabled: $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled()),
            isHiddenInMenu: (bool)$node->getProperty('hiddenInMenu'),
            isFallback: !$node->originDimensionSpacePoint->equals(OriginDimensionSpacePoint::fromDimensionSpacePoint($node->dimensionSpacePoint)),
        );
    }
}
