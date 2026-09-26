<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Flow\I18n\Locale;
use Wwwision\Neos\NodeTreeApi\Presentation\NodePresenter;
use Wwwision\Neos\NodeTreeApi\Resolver\ChildrenResolver;
use Wwwision\Neos\NodeTreeApi\Resolver\ParentItem;
use Wwwision\Neos\NodeTreeApi\Resolver\ResolverContext;

/**
 * @internal the state of one request to the {@see TreeLoader}
 */
#[Flow\Proxy(false)]
final readonly class TreeRequest
{
    public function __construct(
        public TreeDefinition $tree,
        public ContentSubgraphInterface $subgraph,
        private NodeTypeManager $nodeTypeManager,
        private ResolverRegistry $resolverRegistry,
        private NodePresenter $nodePresenter,
        private Locale $locale,
        private DatetimeFormatter $datetimeFormatter,
    ) {
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

    /**
     * Whether the node is of a type that is part of the tree
     */
    public function contains(Node $node): bool
    {
        $nodeType = $this->nodeTypeManager->getNodeType($node->nodeTypeName);
        return $nodeType !== null && $this->tree->contains($nodeType);
    }
}
