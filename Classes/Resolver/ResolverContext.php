<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Flow\I18n\Locale;
use Wwwision\Neos\NodeTreeApi\Api\Model\Appearances;
use Wwwision\Neos\NodeTreeApi\Api\Model\Badges;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Presentation\NodePresenter;
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
        private NodePresenter $nodePresenter,
        private DatetimeFormatter $datetimeFormatter,
    ) {
    }

    /**
     * An item that represents $node, rendered by the {@see NodePresenter}
     */
    public function nodeItem(Node $node): Item
    {
        $presentation = $this->nodePresenter->present($node);
        return new Item(
            id: ItemId::forNode($node->aggregateId),
            label: $presentation->label,
            icon: $presentation->icon,
            tooltip: $presentation->tooltip,
            appearance: $presentation->appearance,
            badges: $presentation->badges,
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
     * Formats a date in the interface language of the current user
     *
     * @param string $pattern a CLDR date pattern, e.g. "LLLL y" for "September 2026"
     */
    public function formatDate(\DateTimeInterface $date, string $pattern): string
    {
        return $this->datetimeFormatter->formatDateTimeWithCustomPattern($date, $pattern, $this->locale);
    }

    /**
     * An item that doesn't represent a node. Its children will be resolved by this context's resolver, with $payload
     * available as {@see ItemId::payload()} of the parent
     *
     * @param array<mixed> $payload
     */
    public function virtualItem(
        array $payload,
        string $label,
        string|null $icon,
        string|null $tooltip = null,
        Appearances $appearance = new Appearances(),
        Badges $badges = new Badges(),
    ): Item {
        return new Item(
            id: ItemId::forVirtualItem($this->resolverKey, $payload),
            label: $label,
            icon: $icon,
            tooltip: $tooltip,
            appearance: $appearance,
            badges: $badges,
            node: null,
        );
    }
}
