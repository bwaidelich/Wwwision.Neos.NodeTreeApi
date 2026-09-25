<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Information about the node a tree item represents
 */
final readonly class NodeInfo implements ProvidesSchema
{
    public function __construct(
        public string $aggregateId,
        public string $nodeTypeName,
        // whether the node is tethered to its parent, i.e. can't be moved or removed on its own
        public bool $isTethered,
        // whether the node itself is disabled (hidden) – not whether an ancestor is
        public bool $isDisabled,
        public bool $isHiddenInMenu,
        // whether the node is shown in the requested dimensions as a fallback, i.e. is not translated yet
        public bool $isFallback,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
