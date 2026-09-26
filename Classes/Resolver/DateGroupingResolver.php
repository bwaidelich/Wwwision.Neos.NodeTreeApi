<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\Ordering;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingDirection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\AndCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueGreaterThan;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueLessThan;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueStartsWith;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;
use Wwwision\Neos\NodeTreeApi\Tree\NotFound;

/**
 * Groups child nodes of one type by a date property: in virtual year items, with virtual month items below them
 * (unless the granularity is "year"). Other children that are part of the tree are listed after the years
 *
 * Example configuration (see "Wwwision.Neos.NodeTreeApi.resolvers"):
 *
 *   'blogPostingsByDate':
 *     className: 'Wwwision\Neos\NodeTreeApi\Resolver\DateGroupingResolver'
 *     options:
 *       groupedNodeType: 'Neos.Demo:Document.BlogPosting'
 *       property: 'datePublished'
 *
 * Grouped nodes can be dragged, but nothing can be dropped before or after them since their order is the one of their
 * dates. Dropping a grouped node "into" the parent adds it to the group of its date.
 *
 * Grouping uses the date as it is stored, i.e. in the timezone it was entered in. Grouped nodes without a date are
 * not listed. The years and months of a parent are not paginated – with a limit only for the other children
 */
#[Flow\Proxy(false)]
final readonly class DateGroupingResolver implements ChildrenResolver
{
    private NodeTypeName $groupedNodeType;
    private PropertyName $property;

    /**
     * @param string $groupedNodeType the type of the child nodes to group (including sub types)
     * @param string $property the name of their date property
     * @param string $granularity "month" for years with months, "year" for years only
     * @param bool $newestFirst the order of years, months and grouped nodes
     * @param string $yearLabelFormat CLDR date pattern for the labels of years
     * @param string $monthLabelFormat CLDR date pattern for the labels of months
     * @param string|null $icon of the year and month items
     */
    public function __construct(
        string $groupedNodeType,
        string $property,
        private string $granularity = 'month',
        private bool $newestFirst = true,
        private string $yearLabelFormat = 'y',
        private string $monthLabelFormat = 'LLLL',
        private string|null $icon = 'calendar-alt',
    ) {
        if (!in_array($granularity, ['month', 'year'], true)) {
            throw new \InvalidArgumentException(sprintf('The granularity has to be "month" or "year", got "%s"', $granularity), 1790060801);
        }
        $this->groupedNodeType = NodeTypeName::fromString($groupedNodeType);
        $this->property = PropertyName::fromString($property);
    }

    public function resolve(ResolverContext $context, ParentItem $parent, array|null $cursor, int $limit): ResolvedLevel
    {
        if ($parent->node !== null) {
            return $this->resolveNode($context, $parent, $cursor === null ? null : self::arrayValue($cursor, 'others'), $limit);
        }
        $payload = $parent->id->payload();
        $parentNodeAggregateId = self::nodeAggregateId($payload);
        $year = self::intValue($payload, 'y');
        $month = isset($payload['m']) ? self::intValue($payload, 'm') : null;
        if ($month === null && $this->granularity === 'month') {
            return new ResolvedLevel($parent->id, $this->monthItems($context, $parentNodeAggregateId, $year));
        }
        $offset = $cursor === null ? 0 : self::intValue($cursor, 'offset');
        return $this->groupedNodesPage($context, $parent->id, $parentNodeAggregateId, self::datePrefix($year, $month), $offset, $limit);
    }

    public function hasChildren(ResolverContext $context, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            // year and month items are only created if they contain nodes
            $result[$item->id->value] = $item->node === null
                || $context->subgraph->countChildNodes($item->node->aggregateId, CountChildNodesFilter::create(nodeTypes: $context->tree->nodeTypes)) > 0;
        }
        return $result;
    }

    public function resolveAround(ResolverContext $context, ParentItem $parent, Node $descendant, int $limit): array
    {
        $date = $this->dateOf($context, $descendant);
        if ($date === null) {
            // one of the other children
            $page = $this->othersPagination($context)->around($descendant, $limit);
            $items = $context->nodeItems($page['nodes']);
            if ($page['previousCursor'] === null) {
                $items = [...$this->yearItems($context, $parent->node()->aggregateId), ...$items];
            }
            return [new ResolvedLevel($parent->id, $items, self::othersCursor($page['previousCursor']), self::othersCursor($page['nextCursor']))];
        }
        $parentNodeAggregateId = $parent->node()->aggregateId;
        $year = (int)$date->format('Y');
        $month = (int)$date->format('n');
        $levels = [$this->resolveNode($context, $parent, null, $limit)];
        $groupId = $this->yearId($context, $parentNodeAggregateId, $year);
        if ($this->granularity === 'month') {
            $levels[] = new ResolvedLevel($groupId, $this->monthItems($context, $parentNodeAggregateId, $year));
            $groupId = $this->monthId($context, $parentNodeAggregateId, $year, $month);
        } else {
            $month = null;
        }
        $datePrefix = self::datePrefix($year, $month);
        // the number of grouped nodes that come before the descendant, ignoring ties
        $index = $context->subgraph->countChildNodes($parentNodeAggregateId, CountChildNodesFilter::create(
            nodeTypes: $this->groupedNodeTypeCriteria($context),
            propertyValue: AndCriteria::create(
                PropertyValueStartsWith::create($this->property, $datePrefix, true),
                $this->newestFirst
                    ? PropertyValueGreaterThan::create($this->property, $date->format(DATE_ATOM))
                    : PropertyValueLessThan::create($this->property, $date->format(DATE_ATOM)),
            ),
        ));
        $levels[] = $this->groupedNodesPage($context, $groupId, $parentNodeAggregateId, $datePrefix, max(0, $index - intdiv($limit - 1, 2)), $limit);
        return $levels;
    }

    /**
     * Grouped nodes are ordered by their date, so dropping items before or after them has no effect
     */
    public function dragAndDrop(ResolverContext $context, Item $item): DragAndDrop
    {
        return new DragAndDrop(draggable: true, sortable: $item->node === null || $this->dateOf($context, $item->node) === null);
    }

    /**
     * The children of a node: years first, followed by the other children
     *
     * @param array<mixed>|null $othersCursor
     */
    private function resolveNode(ResolverContext $context, ParentItem $parent, array|null $othersCursor, int $limit): ResolvedLevel
    {
        $page = $this->othersPagination($context)->page($parent->node()->aggregateId, $othersCursor, $limit);
        $items = $context->nodeItems($page['nodes']);
        if ($page['previousCursor'] === null) {
            $items = [...$this->yearItems($context, $parent->node()->aggregateId), ...$items];
        }
        return new ResolvedLevel($parent->id, $items, self::othersCursor($page['previousCursor']), self::othersCursor($page['nextCursor']));
    }

    /**
     * @return list<Item>
     */
    private function yearItems(ResolverContext $context, NodeAggregateId $parentNodeAggregateId): array
    {
        $firstYear = $this->yearOfBoundaryNode($context, $parentNodeAggregateId, OrderingDirection::ASCENDING);
        $lastYear = $this->yearOfBoundaryNode($context, $parentNodeAggregateId, OrderingDirection::DESCENDING);
        if ($firstYear === null || $lastYear === null) {
            return [];
        }
        $items = [];
        foreach ($this->newestFirst ? range($lastYear, $firstYear) : range($firstYear, $lastYear) as $year) {
            if ($this->countGroupedNodes($context, $parentNodeAggregateId, self::datePrefix($year, null)) > 0) {
                $items[] = $context->virtualItem(['p' => $parentNodeAggregateId->value, 'y' => $year], $this->formatDate($context, $year, 1, $this->yearLabelFormat), $this->icon);
            }
        }
        return $items;
    }

    /**
     * @return list<Item>
     */
    private function monthItems(ResolverContext $context, NodeAggregateId $parentNodeAggregateId, int $year): array
    {
        $items = [];
        foreach ($this->newestFirst ? range(12, 1) : range(1, 12) as $month) {
            if ($this->countGroupedNodes($context, $parentNodeAggregateId, self::datePrefix($year, $month)) > 0) {
                $items[] = $context->virtualItem(['p' => $parentNodeAggregateId->value, 'y' => $year, 'm' => $month], $this->formatDate($context, $year, $month, $this->monthLabelFormat), $this->icon);
            }
        }
        return $items;
    }

    private function groupedNodesPage(ResolverContext $context, ItemId $parentId, NodeAggregateId $parentNodeAggregateId, string $datePrefix, int $offset, int $limit): ResolvedLevel
    {
        $nodes = $context->subgraph->findChildNodes($parentNodeAggregateId, FindChildNodesFilter::create(
            nodeTypes: $this->groupedNodeTypeCriteria($context),
            propertyValue: PropertyValueStartsWith::create($this->property, $datePrefix, true),
            ordering: Ordering::byProperty($this->property, $this->newestFirst ? OrderingDirection::DESCENDING : OrderingDirection::ASCENDING),
            pagination: Pagination::fromLimitAndOffset($limit + 1, $offset),
        ));
        $items = $context->nodeItems($nodes);
        $hasMore = count($items) > $limit;
        return new ResolvedLevel(
            $parentId,
            array_slice($items, 0, $limit),
            $offset > 0 ? ['offset' => max(0, $offset - $limit)] : null,
            $hasMore ? ['offset' => $offset + $limit] : null,
        );
    }

    private function yearOfBoundaryNode(ResolverContext $context, NodeAggregateId $parentNodeAggregateId, OrderingDirection $direction): int|null
    {
        $node = $context->subgraph->findChildNodes($parentNodeAggregateId, FindChildNodesFilter::create(
            nodeTypes: $this->groupedNodeTypeCriteria($context),
            // excludes nodes without a date
            propertyValue: PropertyValueGreaterThan::create($this->property, ''),
            ordering: Ordering::byProperty($this->property, $direction),
            pagination: Pagination::fromLimitAndOffset(1, 0),
        ))->first();
        $date = $node !== null ? $this->dateOf($context, $node) : null;
        return $date !== null ? (int)$date->format('Y') : null;
    }

    private function countGroupedNodes(ResolverContext $context, NodeAggregateId $parentNodeAggregateId, string $datePrefix): int
    {
        return $context->subgraph->countChildNodes($parentNodeAggregateId, CountChildNodesFilter::create(
            nodeTypes: $this->groupedNodeTypeCriteria($context),
            propertyValue: PropertyValueStartsWith::create($this->property, $datePrefix, true),
        ));
    }

    /**
     * The date of a grouped node, null for other nodes
     */
    private function dateOf(ResolverContext $context, Node $node): \DateTimeInterface|null
    {
        if ($context->nodeTypeManager->getNodeType($node->nodeTypeName)?->isOfType($this->groupedNodeType) !== true) {
            return null;
        }
        $date = $node->getProperty($this->property->value);
        return $date instanceof \DateTimeInterface ? $date : null;
    }

    private function groupedNodeTypeCriteria(ResolverContext $context): NodeTypeCriteria
    {
        return NodeTypeCriteria::create(NodeTypeNames::with($this->groupedNodeType), $context->tree->nodeTypes->explicitlyDisallowedNodeTypeNames);
    }

    private function othersPagination(ResolverContext $context): SiblingPagination
    {
        return new SiblingPagination($context->subgraph, $context->tree->nodeTypes->withAdditionalDisallowedNodeType($this->groupedNodeType));
    }

    private function yearId(ResolverContext $context, NodeAggregateId $parentNodeAggregateId, int $year): ItemId
    {
        return ItemId::forVirtualItem($context->resolverKey, ['p' => $parentNodeAggregateId->value, 'y' => $year]);
    }

    private function monthId(ResolverContext $context, NodeAggregateId $parentNodeAggregateId, int $year, int $month): ItemId
    {
        return ItemId::forVirtualItem($context->resolverKey, ['p' => $parentNodeAggregateId->value, 'y' => $year, 'm' => $month]);
    }

    private function formatDate(ResolverContext $context, int $year, int $month, string $pattern): string
    {
        return $context->formatDate(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)), $pattern);
    }

    /**
     * The start of the stored date strings of a year ("2026") or month ("2026-09")
     */
    private static function datePrefix(int $year, int|null $month): string
    {
        return $month === null ? sprintf('%04d', $year) : sprintf('%04d-%02d', $year, $month);
    }

    /**
     * @param array<mixed>|null $othersCursor
     * @return array{others: array<mixed>}|null
     */
    private static function othersCursor(array|null $othersCursor): array|null
    {
        return $othersCursor !== null ? ['others' => $othersCursor] : null;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function nodeAggregateId(array $payload): NodeAggregateId
    {
        $value = $payload['p'] ?? null;
        return (is_string($value) ? NodeAggregateId::tryFromString($value) : null) ?? throw new NotFound('The item does not exist', 1790060802);
    }

    /**
     * @param array<mixed> $data
     */
    private static function intValue(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : throw new InvalidRequest('The item or cursor is malformed', 1790060803);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : throw new InvalidRequest('The cursor is malformed', 1790060804);
    }
}
