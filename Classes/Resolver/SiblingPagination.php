<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Resolver;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindPrecedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSucceedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

/**
 * Pages through child nodes in the order of their position, with cursors relative to a sibling ("after X",
 * "before Y") rather than offsets: nodes that are inserted or removed while a client scrolls don't shift the pages.
 * And a page can start anywhere, e.g. around a node that is to be revealed
 *
 * Cursors are {"after": <node aggregate id>} or {"before": <node aggregate id>}
 */
#[Flow\Proxy(false)]
final readonly class SiblingPagination
{
    public function __construct(
        private ContentSubgraphInterface $subgraph,
        private NodeTypeCriteria $nodeTypes,
    ) {
    }

    /**
     * @param array<mixed>|null $cursor
     * @return array{nodes: Nodes, previousCursor: array<mixed>|null, nextCursor: array<mixed>|null}
     */
    public function page(NodeAggregateId $parentNodeAggregateId, array|null $cursor, int $limit): array
    {
        if ($cursor === null) {
            [$nodes, $hasMore] = self::slice($this->subgraph->findChildNodes($parentNodeAggregateId, FindChildNodesFilter::create(
                nodeTypes: $this->nodeTypes,
                pagination: Pagination::fromLimitAndOffset($limit + 1, 0),
            )), $limit);
            return ['nodes' => $nodes, 'previousCursor' => null, 'nextCursor' => $hasMore ? self::after($nodes) : null];
        }
        if (is_string($cursor['after'] ?? null)) {
            [$nodes, $hasMore] = self::slice($this->subgraph->findSucceedingSiblingNodes(self::nodeAggregateId($cursor['after']), FindSucceedingSiblingNodesFilter::create(
                nodeTypes: $this->nodeTypes,
                pagination: Pagination::fromLimitAndOffset($limit + 1, 0),
            )), $limit);
            return ['nodes' => $nodes, 'previousCursor' => self::before($nodes), 'nextCursor' => $hasMore ? self::after($nodes) : null];
        }
        if (is_string($cursor['before'] ?? null)) {
            // preceding siblings are returned closest first
            [$nodes, $hasMore] = self::slice($this->subgraph->findPrecedingSiblingNodes(self::nodeAggregateId($cursor['before']), FindPrecedingSiblingNodesFilter::create(
                nodeTypes: $this->nodeTypes,
                pagination: Pagination::fromLimitAndOffset($limit + 1, 0),
            )), $limit);
            $nodes = $nodes->reverse();
            return ['nodes' => $nodes, 'previousCursor' => $hasMore ? self::before($nodes) : null, 'nextCursor' => self::after($nodes)];
        }
        throw new InvalidRequest('The cursor is malformed', 1790060401);
    }

    /**
     * A page with $node in the middle – or closer to the start/end, if there are not enough siblings before/after it
     *
     * @return array{nodes: Nodes, previousCursor: array<mixed>|null, nextCursor: array<mixed>|null}
     */
    public function around(Node $node, int $limit): array
    {
        // up to $limit on either side: $limit - 1 to fill the page from one side, plus one to tell whether there are more
        $allPreceding = $this->subgraph->findPrecedingSiblingNodes($node->aggregateId, FindPrecedingSiblingNodesFilter::create(
            nodeTypes: $this->nodeTypes,
            pagination: Pagination::fromLimitAndOffset($limit, 0),
        ));
        $allSucceeding = $this->subgraph->findSucceedingSiblingNodes($node->aggregateId, FindSucceedingSiblingNodesFilter::create(
            nodeTypes: $this->nodeTypes,
            pagination: Pagination::fromLimitAndOffset($limit, 0),
        ));
        $half = intdiv($limit - 1, 2);
        $numberOfPreceding = min($allPreceding->count(), $half + max(0, $limit - 1 - $half - $allSucceeding->count()));
        $numberOfSucceeding = min($allSucceeding->count(), $limit - 1 - $numberOfPreceding);
        [$preceding, $hasPreceding] = self::slice($allPreceding, $numberOfPreceding);
        [$succeeding, $hasSucceeding] = self::slice($allSucceeding, $numberOfSucceeding);
        $nodes = $preceding->reverse()->append($node)->merge($succeeding);
        return [
            'nodes' => $nodes,
            'previousCursor' => $hasPreceding ? self::before($nodes) : null,
            'nextCursor' => $hasSucceeding ? self::after($nodes) : null,
        ];
    }

    /**
     * @return array{Nodes, bool} the first $limit nodes, and whether there were more
     */
    private static function slice(Nodes $nodes, int $limit): array
    {
        if ($nodes->count() <= $limit) {
            return [$nodes, false];
        }
        return [Nodes::fromArray(array_slice(iterator_to_array($nodes, false), 0, $limit)), true];
    }

    /**
     * @return array{after: string}|null
     */
    private static function after(Nodes $nodes): array|null
    {
        $last = $nodes->last();
        return $last !== null ? ['after' => $last->aggregateId->value] : null;
    }

    /**
     * @return array{before: string}|null
     */
    private static function before(Nodes $nodes): array|null
    {
        $first = $nodes->first();
        return $first !== null ? ['before' => $first->aggregateId->value] : null;
    }

    private static function nodeAggregateId(string $value): NodeAggregateId
    {
        return NodeAggregateId::tryFromString($value) ?? throw new InvalidRequest('The cursor is malformed', 1790060402);
    }
}
