<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;

/**
 * Points to the previous or next page of a level – opaque to clients
 *
 * Internally, it is the base64url encoded JSON {"r": <resolver key>, "c": <context hash>, "p": <payload of that
 * resolver>} – the context hash binds it to the site, workspace and dimensions it was created for
 */
final readonly class Cursor implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * @param array<mixed> $payload
     */
    public static function create(string $resolverKey, string $contextHash, array $payload): self
    {
        return self::fromString(Base64Url::encodeJson(['r' => $resolverKey, 'c' => $contextHash, 'p' => $payload]));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'An opaque cursor, as returned in the "previousCursor" or "nextCursor" of a level',
            maxLength: 2000,
            pattern: '^[A-Za-z0-9_-]+$',
        );
    }

    public function resolverKey(): string
    {
        return $this->decode()['r'];
    }

    public function contextHash(): string
    {
        return $this->decode()['c'];
    }

    /**
     * @return array<mixed>
     */
    public function payload(): array
    {
        return $this->decode()['p'];
    }

    /**
     * @return array{r: string, c: string, p: array<mixed>}
     */
    private function decode(): array
    {
        $data = Base64Url::decodeJson($this->value);
        if (!is_string($data['r'] ?? null) || !is_string($data['c'] ?? null) || !is_array($data['p'] ?? null)) {
            throw new InvalidRequest(sprintf('Cursor "%s" is malformed', $this->value), 1790060201);
        }
        return ['r' => $data['r'], 'c' => $data['c'], 'p' => $data['p']];
    }
}
