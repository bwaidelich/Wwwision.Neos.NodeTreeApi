<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

/**
 * The id of a tree item – opaque to clients
 *
 * Internally, it is "n.<node aggregate id>" for items that represent a node and "v.<resolver key>.<payload>" for
 * virtual items, with the base64url encoded JSON payload of the resolver that created it
 */
final readonly class ItemId implements ProvidesSchema
{
    private const string NODE_PREFIX = 'n.';
    private const string VIRTUAL_PREFIX = 'v.';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function forNode(NodeAggregateId $nodeAggregateId): self
    {
        return self::fromString(self::NODE_PREFIX . $nodeAggregateId->value);
    }

    /**
     * @param array<mixed> $payload
     */
    public static function forVirtualItem(string $resolverKey, array $payload): self
    {
        return self::fromString(self::VIRTUAL_PREFIX . $resolverKey . '.' . Base64Url::encodeJson($payload));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The opaque id of a tree item',
            examples: ['n.a3e8c9d2-5f1b-4c7e-9a2d-8b6f4e1c3a5d'],
            maxLength: 2000,
            pattern: '^(n\.[a-z0-9-]{1,64}|v\.[a-zA-Z0-9_-]{1,100}\.[A-Za-z0-9_-]*)$',
        );
    }

    public function isNode(): bool
    {
        return str_starts_with($this->value, self::NODE_PREFIX);
    }

    public function nodeAggregateId(): NodeAggregateId
    {
        if (!$this->isNode()) {
            throw new \RuntimeException(sprintf('Item "%s" is virtual, it has no node aggregate id', $this->value), 1790060101);
        }
        return NodeAggregateId::fromString(substr($this->value, strlen(self::NODE_PREFIX)));
    }

    public function resolverKey(): string
    {
        return explode('.', $this->virtualPart(), 2)[0];
    }

    /**
     * @return array<mixed>
     */
    public function payload(): array
    {
        $payload = Base64Url::decodeJson(explode('.', $this->virtualPart(), 2)[1] ?? '');
        if ($payload === null) {
            throw new InvalidRequest(sprintf('Item id "%s" is malformed', $this->value), 1790060102);
        }
        return $payload;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private function virtualPart(): string
    {
        if ($this->isNode()) {
            throw new \RuntimeException(sprintf('Item "%s" represents a node, it has no resolver payload', $this->value), 1790060103);
        }
        return substr($this->value, strlen(self::VIRTUAL_PREFIX));
    }
}
