<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tests\Unit\Resolver;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindPrecedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSucceedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;
use Wwwision\Neos\NodeTreeApi\Resolver\SiblingPagination;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

final class SiblingPaginationTest extends TestCase
{
    private const array CHILDREN = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];

    private SiblingPagination $pagination;

    protected function setUp(): void
    {
        $this->pagination = new SiblingPagination($this->fakeSubgraph(), NodeTypeCriteria::fromFilterString('Neos.Neos:Document'));
    }

    public function testFirstPageHasNoPreviousCursor(): void
    {
        $page = $this->pagination->page(NodeAggregateId::fromString('parent'), null, 3);

        self::assertSame(['a', 'b', 'c'], self::ids($page['nodes']));
        self::assertNull($page['previousCursor']);
        self::assertSame(['after' => 'c'], $page['nextCursor']);
    }

    public function testPagesAfterACursor(): void
    {
        $page = $this->pagination->page(NodeAggregateId::fromString('parent'), ['after' => 'c'], 3);

        self::assertSame(['d', 'e', 'f'], self::ids($page['nodes']));
        self::assertSame(['before' => 'd'], $page['previousCursor']);
        self::assertSame(['after' => 'f'], $page['nextCursor']);

        $lastPage = $this->pagination->page(NodeAggregateId::fromString('parent'), $page['nextCursor'], 3);
        self::assertSame(['g'], self::ids($lastPage['nodes']));
        self::assertNull($lastPage['nextCursor']);
    }

    public function testPagesBeforeACursorAreInPositionOrder(): void
    {
        $page = $this->pagination->page(NodeAggregateId::fromString('parent'), ['before' => 'f'], 3);

        self::assertSame(['c', 'd', 'e'], self::ids($page['nodes']));
        self::assertSame(['before' => 'c'], $page['previousCursor']);
        self::assertSame(['after' => 'e'], $page['nextCursor']);

        $firstPage = $this->pagination->page(NodeAggregateId::fromString('parent'), $page['previousCursor'], 3);
        self::assertSame(['a', 'b'], self::ids($firstPage['nodes']));
        self::assertNull($firstPage['previousCursor']);
    }

    /**
     * @return iterable<array{string, int, list<string>, bool, bool}>
     */
    public static function windows(): iterable
    {
        yield 'in the middle' => ['d', 3, ['c', 'd', 'e'], true, true];
        yield 'even limit' => ['d', 4, ['c', 'd', 'e', 'f'], true, true];
        yield 'at the start' => ['a', 3, ['a', 'b', 'c'], false, true];
        yield 'at the end' => ['g', 3, ['e', 'f', 'g'], true, false];
        yield 'close to the end' => ['f', 4, ['d', 'e', 'f', 'g'], true, false];
        yield 'all fit' => ['d', 10, self::CHILDREN, false, false];
        yield 'just the node' => ['d', 1, ['d'], true, true];
    }

    /**
     * @param list<string> $expectedIds
     */
    #[DataProvider('windows')]
    public function testWindowsAroundANodeAreFilledFromBothSides(string $nodeId, int $limit, array $expectedIds, bool $hasPrevious, bool $hasNext): void
    {
        $page = $this->pagination->around(self::node($nodeId), $limit);

        self::assertSame($expectedIds, self::ids($page['nodes']));
        self::assertSame($hasPrevious ? ['before' => $expectedIds[0]] : null, $page['previousCursor']);
        self::assertSame($hasNext ? ['after' => $expectedIds[count($expectedIds) - 1]] : null, $page['nextCursor']);
    }

    /**
     * @return iterable<array{array<mixed>}>
     */
    public static function malformedCursors(): iterable
    {
        yield 'unknown direction' => [['sideways' => 'c']];
        yield 'invalid node aggregate id' => [['after' => 'Not An Id']];
        yield 'no string' => [['after' => 42]];
    }

    /**
     * @param array<mixed> $cursor
     */
    #[DataProvider('malformedCursors')]
    public function testMalformedCursorsAreInvalidRequests(array $cursor): void
    {
        $this->expectException(InvalidRequest::class);
        $this->pagination->page(NodeAggregateId::fromString('parent'), $cursor, 3);
    }

    /**
     * A subgraph with the parent node "parent", and its children {@see self::CHILDREN}
     */
    private function fakeSubgraph(): ContentSubgraphInterface
    {
        $slice = static function (array $ids, int $limit, int $offset): Nodes {
            return Nodes::fromArray(array_map(self::node(...), array_slice($ids, $offset, $limit)));
        };
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $subgraph->method('findChildNodes')->willReturnCallback(
            static fn (NodeAggregateId $parentId, FindChildNodesFilter $filter) => $slice(self::CHILDREN, $filter->pagination?->limit ?? PHP_INT_MAX, $filter->pagination->offset ?? 0),
        );
        $subgraph->method('findSucceedingSiblingNodes')->willReturnCallback(
            static fn (NodeAggregateId $siblingId, FindSucceedingSiblingNodesFilter $filter) => $slice(
                array_slice(self::CHILDREN, (int)array_search($siblingId->value, self::CHILDREN, true) + 1),
                $filter->pagination?->limit ?? PHP_INT_MAX,
                $filter->pagination->offset ?? 0,
            ),
        );
        // closest first, like the real implementation
        $subgraph->method('findPrecedingSiblingNodes')->willReturnCallback(
            static fn (NodeAggregateId $siblingId, FindPrecedingSiblingNodesFilter $filter) => $slice(
                array_reverse(array_slice(self::CHILDREN, 0, (int)array_search($siblingId->value, self::CHILDREN, true))),
                $filter->pagination?->limit ?? PHP_INT_MAX,
                $filter->pagination->offset ?? 0,
            ),
        );
        return $subgraph;
    }

    private static function node(string $id): Node
    {
        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateId::fromString($id),
            OriginDimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString('Neos.Neos:Document'),
            new PropertyCollection(SerializedPropertyValues::createEmpty(), new PropertyConverter(new Serializer())),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable('@0'), new \DateTimeImmutable('@0'), null, null),
            VisibilityConstraints::createEmpty(),
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(Nodes $nodes): array
    {
        return array_values($nodes->map(static fn (Node $node) => $node->aggregateId->value));
    }
}
