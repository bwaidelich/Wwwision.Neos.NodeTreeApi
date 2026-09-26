<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * How the label of an item is to be rendered – the client decides how that looks exactly
 */
enum Appearance: string implements ProvidesSchema
{
    // less prominent, e.g. for hidden items
    case muted = 'muted';
    // crossed out, e.g. for items that are disabled themselves
    case strikethrough = 'strikethrough';
    // set apart, e.g. for items that are shown as a fallback
    case italic = 'italic';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
