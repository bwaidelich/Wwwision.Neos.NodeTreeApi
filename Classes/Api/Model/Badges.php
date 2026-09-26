<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

final readonly class Badges implements ProvidesSchema
{
    /**
     * @var list<Badge>
     */
    public array $badges;

    public function __construct(Badge ...$badges)
    {
        $this->badges = array_values($badges);
    }

    public static function none(): self
    {
        return new self();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
