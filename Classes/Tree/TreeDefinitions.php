<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;

/**
 * @implements \IteratorAggregate<TreeDefinition>
 */
#[Flow\Proxy(false)]
final readonly class TreeDefinitions implements \IteratorAggregate
{
    /**
     * @var array<string, TreeDefinition>
     */
    private array $definitions;

    public function __construct(TreeDefinition ...$definitions)
    {
        $definitionsById = [];
        foreach ($definitions as $definition) {
            $definitionsById[$definition->id->value] = $definition;
        }
        $this->definitions = $definitionsById;
    }

    public function get(TreeId $id): TreeDefinition|null
    {
        return $this->definitions[$id->value] ?? null;
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->definitions);
    }
}
