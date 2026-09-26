<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A small icon next to the label of an item, e.g. to mark it as hidden
 */
final readonly class Badge implements ProvidesSchema
{
    public function __construct(
        public string $icon,
        // translated to the interface language of the current user
        public string $tooltip,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
