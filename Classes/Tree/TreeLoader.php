<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Wwwision\Neos\NodeTreeApi\Api\Model\Cursor;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemsContext;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemsResult;
use Wwwision\Neos\NodeTreeApi\Api\Model\Level;
use Wwwision\Neos\NodeTreeApi\Api\Model\Levels;
use Wwwision\Neos\NodeTreeApi\Api\Model\NodeInfo;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeItem;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeItems;
use Wwwision\Neos\NodeTreeApi\Presentation\NodePresenter;
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
        private SiteRepository $siteRepository,
        private UserService $userService,
        private WorkspaceService $workspaceService,
        private NodePresenter $nodePresenter,
        private LabelTranslator $labelTranslator,
        private DatetimeFormatter $datetimeFormatter,
    ) {
    }

    /**
     * @throws InvalidRequest | NotFound | Forbidden
     */
    public function load(
        TreeId $treeId,
        SiteDetectionResult $detectedSite,
        string|null $workspaceName,
        string|null $dimensionSpacePoint,
        ItemId|null $parentId,
        Cursor|null $cursor,
        int $limit,
        ItemId|null $expandTo = null,
    ): ItemsResult {
        $tree = $this->treeDefinitions->get($treeId) ?? throw new NotFound(sprintf('There is no tree "%s"', $treeId->value), 1790060601);
        $site = $this->siteRepository->findOneByNodeName($detectedSite->siteNodeName) ?? throw new NotFound(sprintf('There is no site "%s"', $detectedSite->siteNodeName->value), 1790060612);
        $contentRepository = $this->contentRepositoryRegistry->get($detectedSite->contentRepositoryId);
        $workspace = $workspaceName !== null ? self::workspaceName($workspaceName) : $this->defaultWorkspaceName($contentRepository);
        $dimensions = $dimensionSpacePoint !== null ? self::dimensionSpacePoint($dimensionSpacePoint) : $site->getConfiguration()->defaultDimensionSpacePoint;
        $subgraph = $this->subgraph($contentRepository, $workspace, $dimensions);
        $context = new ItemsContext($detectedSite->siteNodeName->value, $contentRepository->id->value, $workspace->value, $dimensions->coordinates);
        $request = new TreeRequest($tree, $subgraph, $context->hash(), $contentRepository->getNodeTypeManager(), $this->resolverRegistry, $this->nodePresenter, $this->labelTranslator->locale(), $this->datetimeFormatter);
        $siteNode = $this->siteNode($subgraph, $detectedSite->siteNodeName);

        if ($cursor !== null && $cursor->contextHash() !== $request->contextHash) {
            throw new InvalidRequest('The cursor belongs to another site, workspace or dimensions', 1790060617);
        }
        if ($expandTo !== null) {
            if ($parentId !== null || $cursor !== null) {
                throw new InvalidRequest('"expandTo" can\'t be combined with "parent" or "cursor"', 1790060614);
            }
            return new ItemsResult($context, $this->render($request, ...$this->expandTo($request, $siteNode, $expandTo, $limit)));
        }
        if ($parentId === null) {
            if ($cursor !== null) {
                throw new InvalidRequest('The root level has only one page, a "cursor" requires a "parent"', 1790060602);
            }
            return new ItemsResult($context, $this->render($request, [null, $this->rootLevel($request, $siteNode)]));
        }
        $parent = $this->parentItem($request, $parentId);
        [$resolverKey, $resolver] = $request->resolverFor($parent);
        if ($cursor !== null && $cursor->resolverKey() !== $resolverKey) {
            throw new InvalidRequest('The cursor does not belong to the children of this parent', 1790060603);
        }
        return new ItemsResult($context, $this->render($request, [$resolverKey, $resolver->resolve($request->context($resolverKey), $parent, $cursor?->payload(), $limit)]));
    }

    /**
     * The personal workspace of the current user – or "live" if there is none
     */
    private function defaultWorkspaceName(ContentRepository $contentRepository): WorkspaceName
    {
        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            return WorkspaceName::forLive();
        }
        try {
            return $this->workspaceService->getPersonalWorkspaceForUser($contentRepository->id, $user->getId())->workspaceName;
        } catch (\RuntimeException) {
            return WorkspaceName::forLive();
        }
    }

    private static function workspaceName(string $workspaceName): WorkspaceName
    {
        return WorkspaceName::tryFromString($workspaceName) ?? throw new InvalidRequest(sprintf('"%s" is no valid workspace name', $workspaceName), 1790060606);
    }

    private static function dimensionSpacePoint(string $dimensionSpacePoint): DimensionSpacePoint
    {
        $invalidDimensions = new InvalidRequest('"dimensions" has to be a JSON object with the dimension values, e.g. {"language":"en_US"}', 1790060607);
        try {
            $dimensionValues = json_decode($dimensionSpacePoint, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $invalidDimensions;
        }
        if (!is_array($dimensionValues) || array_filter($dimensionValues, is_string(...)) !== $dimensionValues) {
            throw $invalidDimensions;
        }
        try {
            return DimensionSpacePoint::fromArray($dimensionValues);
        } catch (\InvalidArgumentException) {
            throw $invalidDimensions;
        }
    }

    private function subgraph(ContentRepository $contentRepository, WorkspaceName $workspace, DimensionSpacePoint $dimensions): ContentSubgraphInterface
    {
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

    private function siteNode(ContentSubgraphInterface $subgraph, SiteNodeName $siteNodeName): Node
    {
        $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
        $siteNode = $sitesNode !== null ? $subgraph->findNodeByPath($siteNodeName->toNodeName(), $sitesNode->aggregateId) : null;
        return $siteNode ?? throw new NotFound(sprintf('There is no site node "%s"', $siteNodeName->value), 1790060611);
    }

    private function rootLevel(TreeRequest $request, Node $siteNode): ResolvedLevel
    {
        return new ResolvedLevel(null, [$request->context(TreeDefinition::DEFAULT_RESOLVER)->nodeItem($siteNode)]);
    }

    /**
     * The root level, followed by the levels down to the one containing the target – each a window around the
     * respective ancestor of the target. Virtual levels in between are added by the resolvers that create them
     *
     * @return non-empty-list<array{string|null, ResolvedLevel}>
     */
    private function expandTo(TreeRequest $request, Node $siteNode, ItemId $targetId, int $limit): array
    {
        if (!$targetId->isNode()) {
            throw new InvalidRequest('"expandTo" has to be the id of an item that represents a node', 1790060615);
        }
        $notFound = new NotFound(sprintf('There is no item "%s" in this tree', $targetId->value), 1790060616);
        $target = $request->subgraph->findNodeById($targetId->nodeAggregateId()) ?? throw $notFound;
        $levels = [[null, $this->rootLevel($request, $siteNode)]];
        if ($target->aggregateId->equals($siteNode->aggregateId)) {
            return $levels;
        }

        // the nodes from the child of the site node down to the target
        $path = [$target];
        $isBelowSiteNode = false;
        foreach ($request->subgraph->findAncestorNodes($target->aggregateId, FindAncestorNodesFilter::create()) as $ancestor) {
            if ($ancestor->aggregateId->equals($siteNode->aggregateId)) {
                $isBelowSiteNode = true;
                break;
            }
            $path[] = $ancestor;
        }
        $path = array_reverse($path);
        if (!$isBelowSiteNode || array_any($path, static fn (Node $node) => !$request->contains($node))) {
            throw $notFound;
        }

        $parent = new ParentItem(ItemId::forNode($siteNode->aggregateId), $siteNode);
        foreach ($path as $node) {
            [$resolverKey, $resolver] = $request->resolverFor($parent);
            foreach ($resolver->resolveAround($request->context($resolverKey), $parent, $node, $limit) as $level) {
                $levels[] = [$resolverKey, $level];
            }
            $parent = new ParentItem(ItemId::forNode($node->aggregateId), $node);
        }
        return $levels;
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
                    static fn (Item $item) => new TreeItem(
                        id: $item->id,
                        label: $item->label,
                        icon: $item->icon,
                        tooltip: $item->tooltip,
                        appearance: $item->appearance,
                        badges: $item->badges,
                        hasChildren: $hasChildren[$item->id->value] ?? false,
                        node: $item->node !== null ? new NodeInfo($item->node->aggregateId->value, $item->node->nodeTypeName->value) : null,
                    ),
                    $resolvedLevel->items,
                )),
                previousCursor: $cursorResolverKey !== null && $resolvedLevel->previousCursor !== null ? Cursor::create($cursorResolverKey, $request->contextHash, $resolvedLevel->previousCursor) : null,
                nextCursor: $cursorResolverKey !== null && $resolvedLevel->nextCursor !== null ? Cursor::create($cursorResolverKey, $request->contextHash, $resolvedLevel->nextCursor) : null,
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
}
