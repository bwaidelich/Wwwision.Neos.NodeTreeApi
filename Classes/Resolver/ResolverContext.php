<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Locale;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Tree\TreeDefinition;

/**
 * Everything a {@see ChildrenResolver} needs to know about the request
 */
#[Flow\Proxy(false)]
final readonly class ResolverContext
{
    public function __construct(
        // the key of the resolver this context is passed to, part of the ids of the virtual items it creates
        public string $resolverKey,
        public TreeDefinition $tree,
        public ContentSubgraphInterface $subgraph,
        public NodeTypeManager $nodeTypeManager,
        // the interface language of the current user
        public Locale $locale,
        private NodeLabelGeneratorInterface $nodeLabelGenerator,
    ) {
    }

    public function nodeItem(Node $node): Item
    {
        $icon = $this->nodeTypeManager->getNodeType($node->nodeTypeName)?->getConfiguration('ui.icon');
        return new Item(
            id: ItemId::forNode($node->aggregateId),
            label: $this->nodeLabelGenerator->getLabel($node),
            icon: is_string($icon) ? $icon : null,
            node: $node,
        );
    }

    /**
     * @return list<Item>
     */
    public function nodeItems(Nodes $nodes): array
    {
        return array_values($nodes->map($this->nodeItem(...)));
    }

    /**
     * An item that doesn't represent a node. Its children will be resolved by this context's resolver, with $payload
     * available as {@see ItemId::payload()} of the parent
     *
     * @param array<mixed> $payload
     */
    public function virtualItem(array $payload, string $label, string|null $icon): Item
    {
        return new Item(
            id: ItemId::forVirtualItem($this->resolverKey, $payload),
            label: $label,
            icon: $icon,
            node: null,
        );
    }
}
