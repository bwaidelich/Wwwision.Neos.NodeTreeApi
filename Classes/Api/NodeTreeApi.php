<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api;

use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Support\ParameterLocation;
use Wwwision\Neos\NodeTreeApi\Api\Model\Cursor;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemsResult;
use Wwwision\Neos\NodeTreeApi\Api\Model\Limit;
use Wwwision\Neos\NodeTreeApi\Api\Model\Tree;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;
use Wwwision\Neos\NodeTreeApi\Api\Model\Trees;
use Wwwision\Neos\NodeTreeApi\Api\Response\BadRequest;
use Wwwision\Neos\NodeTreeApi\Api\Response\Forbidden;
use Wwwision\Neos\NodeTreeApi\Api\Response\NotFound;
use Wwwision\Neos\NodeTreeApi\Security\BackendSessionAuthContextProvider;
use Wwwision\Neos\NodeTreeApi\Tree\Forbidden as ForbiddenException;
use Wwwision\Neos\NodeTreeApi\Tree\InvalidRequest;
use Wwwision\Neos\NodeTreeApi\Tree\LabelTranslator;
use Wwwision\Neos\NodeTreeApi\Tree\NotFound as NotFoundException;
use Wwwision\Neos\NodeTreeApi\Tree\TreeDefinitions;
use Wwwision\Neos\NodeTreeApi\Tree\TreeLoader;

/**
 * The HTTP API for the node trees of the Neos backend
 */
final readonly class NodeTreeApi
{
    public function __construct(
        private TreeDefinitions $treeDefinitions,
        private LabelTranslator $labelTranslator,
        private TreeLoader $treeLoader,
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

    /**
     * Without "parent", this returns the root level: the site node. With "parent", a page of the parent's children.
     * With "expandTo", all levels that are needed to show the given item
     */
    #[Operation(
        path: '/{contentRepository}/{workspace}/trees/{tree}/items',
        method: 'GET',
        summary: 'List the items of one level of a tree',
        security: BackendSessionAuthContextProvider::SCHEME,
    )]
    public function listItems(
        string $contentRepository,
        string $workspace,
        TreeId $tree,
        #[Parameter(in: ParameterLocation::query, description: 'The node name of the site, e.g. "neosdemo"')]
        string $site,
        #[Parameter(in: ParameterLocation::query, description: 'The dimension values as JSON object, e.g. {"language":"en_US"}. Can be omitted if the content repository has no dimensions')]
        string $dimensions = '{}',
        #[Parameter(in: ParameterLocation::query, description: 'The item whose children to list, omit for the root level')]
        ItemId|null $parent = null,
        #[Parameter(in: ParameterLocation::query, description: 'The "previousCursor" or "nextCursor" of a level of the same parent')]
        Cursor|null $cursor = null,
        Limit|null $limit = null,
        #[Parameter(in: ParameterLocation::query, description: 'An item (representing a node) to reveal: returns the root level and all levels down to the one containing it, each around the respective ancestor. Can\'t be combined with "parent" or "cursor"')]
        ItemId|null $expandTo = null,
    ): ItemsResult|BadRequest|Forbidden|NotFound {
        try {
            $levels = $this->treeLoader->load($contentRepository, $workspace, $tree, $site, $dimensions, $parent, $cursor, ($limit ?? Limit::default())->value, $expandTo);
        } catch (InvalidRequest $exception) {
            return new BadRequest($exception->getMessage());
        } catch (ForbiddenException $exception) {
            return new Forbidden($exception->getMessage());
        } catch (NotFoundException $exception) {
            return new NotFound($exception->getMessage());
        }
        return new ItemsResult($levels);
    }
}
