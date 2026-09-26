<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The result of the "listDropKeys" operation
 *
 * While an item is dragged, it can be dropped
 * - "into" an item whose "dropKey" is one of these
 * - "before" or "after" a "sortable" item, if the "dropKey" of the parent of its level is one of these
 */
final readonly class DropKeysResult implements ProvidesSchema
{
    public function __construct(
        public DropKeys $dropKeys,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
