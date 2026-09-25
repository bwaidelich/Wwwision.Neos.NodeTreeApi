<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api;

use Neos\OpenApi\Attributes\Operation;
use Wwwision\Neos\NodeTreeApi\Api\Model\Tree;
use Wwwision\Neos\NodeTreeApi\Api\Model\Trees;
use Wwwision\Neos\NodeTreeApi\Security\BackendSessionAuthContextProvider;
use Wwwision\Neos\NodeTreeApi\Tree\LabelTranslator;
use Wwwision\Neos\NodeTreeApi\Tree\TreeDefinitions;

/**
 * The HTTP API for the node trees of the Neos backend
 */
final readonly class NodeTreeApi
{
    public function __construct(
        private TreeDefinitions $treeDefinitions,
        private LabelTranslator $labelTranslator,
    ) {
    }

    #[Operation(
        path: '/trees',
        method: 'GET',
        summary: 'List the available trees',
        security: BackendSessionAuthContextProvider::SCHEME,
    )]
    public function listTrees(): Trees
    {
        $trees = [];
        foreach ($this->treeDefinitions as $definition) {
            $trees[] = new Tree($definition->id, $this->labelTranslator->translate($definition->label), $definition->icon);
        }
        return new Trees(...$trees);
    }
}
