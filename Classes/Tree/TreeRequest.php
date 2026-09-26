<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Flow\I18n\Locale;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemsContext;
use Wwwision\Neos\NodeTreeApi\Presentation\NodePresenter;
use Wwwision\Neos\NodeTreeApi\Resolver\ChildrenResolver;
use Wwwision\Neos\NodeTreeApi\Resolver\ParentItem;
use Wwwision\Neos\NodeTreeApi\Resolver\ResolverContext;

/**
 * @internal the state of one request to a tree, see {@see TreeRequestFactory}
 */
#[Flow\Proxy(false)]
final readonly class TreeRequest
{
    public ChildConstraints $childConstraints;

    public function __construct(
        public TreeDefinition $tree,
        public ContentRepository $contentRepository,
        public ContentSubgraphInterface $subgraph,
        // site, workspace and dimensions – including the defaults for the values that were not part of the request
        public ItemsContext $context,
        public Node $siteNode,
        public Permissions $permissions,
        public NodeTypeManager $nodeTypeManager,
        private ResolverRegistry $resolverRegistry,
        private NodePresenter $nodePresenter,
        private Locale $locale,
        private DatetimeFormatter $datetimeFormatter,
    ) {
        $this->childConstraints = new ChildConstraints($nodeTypeManager, $subgraph, $tree);
    }

    /**
     * The same request with a new subgraph, e.g. to see the result of a command
     */
    public function withSubgraph(ContentSubgraphInterface $subgraph): self
    {
        return new self($this->tree, $this->contentRepository, $subgraph, $this->context, $this->siteNode, $this->permissions, $this->nodeTypeManager, $this->resolverRegistry, $this->nodePresenter, $this->locale, $this->datetimeFormatter);
    }

    public function context(string $resolverKey): ResolverContext
    {
        return new ResolverContext($resolverKey, $this->tree, $this->subgraph, $this->nodeTypeManager, $this->locale, $this->nodePresenter, $this->datetimeFormatter);
    }

    /**
     * The resolver responsible for the children of $parent: the one mapped to the node type of a node, the one that
     * created a virtual item
     *
     * @return array{string, ChildrenResolver}
     */
    public function resolverFor(ParentItem $parent): array
    {
        if ($parent->node === null) {
            $resolverKey = $parent->id->resolverKey();
            if (!in_array($resolverKey, $this->tree->resolverKeys(), true)) {
                throw new NotFound(sprintf('There is no item "%s" in this tree', $parent->id->value), 1790060701);
            }
        } else {
            $nodeType = $this->nodeTypeManager->getNodeType($parent->node->nodeTypeName);
            $resolverKey = $nodeType !== null ? $this->tree->resolverKeyFor($nodeType) : TreeDefinition::DEFAULT_RESOLVER;
        }
        return [$resolverKey, $this->resolverRegistry->get($resolverKey)];
    }

    public function resolver(string $resolverKey): ChildrenResolver
    {
        return $this->resolverRegistry->get($resolverKey);
    }

    /**
     * Whether the node is of a type that is part of the tree
     */
    public function contains(Node $node): bool
    {
        $nodeType = $this->nodeTypeManager->getNodeType($node->nodeTypeName);
        return $nodeType !== null && $this->tree->contains($nodeType);
    }

    /**
     * The node an item of this tree represents
     *
     * @throws InvalidRequest if the item is virtual
     * @throws NotFound if there is no such node, or it is not part of the tree
     */
    public function node(ItemId $itemId): Node
    {
        if (!$itemId->isNode()) {
            throw new InvalidRequest(sprintf('Item "%s" does not represent a node', $itemId->value), 1790060702);
        }
        $node = $this->subgraph->findNodeById($itemId->nodeAggregateId());
        if ($node === null || !$this->contains($node)) {
            throw new NotFound(sprintf('There is no item "%s" in this tree', $itemId->value), 1790060703);
        }
        return $node;
    }

    /**
     * The nodes from the child of the site node down to $node (including it) – null if $node is no descendant of the
     * site node, or if any of them is not part of the tree
     *
     * @return list<Node>|null
     */
    public function pathBelowSiteNode(Node $node): array|null
    {
        $path = [$node];
        foreach ($this->subgraph->findAncestorNodes($node->aggregateId, FindAncestorNodesFilter::create()) as $ancestor) {
            if ($ancestor->aggregateId->equals($this->siteNode->aggregateId)) {
                $path = array_reverse($path);
                return array_any($path, fn (Node $node) => !$this->contains($node)) ? null : $path;
            }
            $path[] = $ancestor;
        }
        return null;
    }
}
