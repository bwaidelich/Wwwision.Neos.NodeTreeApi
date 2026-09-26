<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

final readonly class Appearances implements ProvidesSchema
{
    /**
     * @var list<Appearance>
     */
    public array $appearances;

    public function __construct(Appearance ...$appearances)
    {
        $this->appearances = array_values(array_unique($appearances, SORT_REGULAR));
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
