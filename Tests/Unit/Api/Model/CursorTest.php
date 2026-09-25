<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tests\Unit\Api\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wwwision\Neos\NodeTreeApi\Api\Model\Cursor;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

final class CursorTest extends TestCase
{
    public function testCursorsContainResolverKeyAndPayload(): void
    {
        $cursor = Cursor::fromString(Cursor::create('default', ['after' => 'some-node'])->value);

        self::assertSame('default', $cursor->resolverKey());
        self::assertSame(['after' => 'some-node'], $cursor->payload());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor->value);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function malformedCursors(): iterable
    {
        yield 'no base64' => ['abc'];
        // {"p":{}}
        yield 'no resolver key' => ['eyJwIjp7fX0'];
        // {"r":"default","p":"x"}
        yield 'payload is no object' => ['eyJyIjoiZGVmYXVsdCIsInAiOiJ4In0'];
    }

    #[DataProvider('malformedCursors')]
    public function testMalformedCursorsAreInvalidRequests(string $value): void
    {
        $this->expectException(InvalidRequest::class);
        Cursor::fromString($value)->payload();
    }
}
