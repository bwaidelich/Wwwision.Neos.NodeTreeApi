<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Where an item is moved to, relative to the target item
 */
enum DropPosition: string implements ProvidesSchema
{
    // as last child of the target
    case into = 'into';
    // as sibling, right before the target
    case before = 'before';
    // as sibling, right after the target
    case after = 'after';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
