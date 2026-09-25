<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tests\Unit\Api\Model;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

final class ItemIdTest extends TestCase
{
    public function testNodeItemIdsContainTheNodeAggregateId(): void
    {
        $itemId = ItemId::forNode(NodeAggregateId::fromString('some-node'));

        self::assertSame('n.some-node', $itemId->value);
        self::assertTrue($itemId->isNode());
        self::assertSame('some-node', $itemId->nodeAggregateId()->value);
    }

    public function testVirtualItemIdsContainResolverKeyAndPayload(): void
    {
        $itemId = ItemId::forVirtualItem('byDate', ['p' => 'some-node', 'y' => 2026, 'm' => 9]);

        self::assertFalse($itemId->isNode());
        self::assertSame('byDate', $itemId->resolverKey());
        self::assertSame(['p' => 'some-node', 'y' => 2026, 'm' => 9], $itemId->payload());
        self::assertTrue(ItemId::fromString($itemId->value)->equals($itemId));
    }

    public function testVirtualItemIdsAreUrlSafe(): void
    {
        $itemId = ItemId::forVirtualItem('byDate', ['label' => 'Ümlaut & more?/+=']);

        self::assertSame($itemId->value, rawurlencode($itemId->value));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidItemIds(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown prefix' => ['x.some-node'];
        yield 'invalid node aggregate id' => ['n.Some Node'];
        yield 'virtual without payload separator' => ['v.byDate'];
        yield 'virtual with invalid characters' => ['v.byDate.a+b'];
    }

    #[DataProvider('invalidItemIds')]
    public function testInvalidItemIdsAreRejected(string $value): void
    {
        $this->expectException(\Throwable::class);
        ItemId::fromString($value);
    }

    public function testMalformedPayloadsAreInvalidRequests(): void
    {
        $this->expectException(InvalidRequest::class);
        ItemId::fromString('v.byDate.bm90LWpzb24')->payload();
    }

    public function testNodeItemIdsHaveNoPayload(): void
    {
        $this->expectException(\RuntimeException::class);
        ItemId::fromString('n.some-node')->payload();
    }
}
