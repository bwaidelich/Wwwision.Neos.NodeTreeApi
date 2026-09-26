<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The node a tree item represents, e.g. to open it when the item is selected
 *
 * How the node is rendered is part of the item itself (label, icon, appearance, badges, …)
 */
final readonly class NodeInfo implements ProvidesSchema
{
    public function __construct(
        public string $aggregateId,
        public string $nodeTypeName,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
