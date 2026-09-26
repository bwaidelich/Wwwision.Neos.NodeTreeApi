<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The "dropKey"s of all items an item can be moved into – opaque to clients
 */
final readonly class DropKeys implements ProvidesSchema
{
    /**
     * @var list<string>
     */
    public array $dropKeys;

    public function __construct(string ...$dropKeys)
    {
        $this->dropKeys = array_values($dropKeys);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
