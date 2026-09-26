<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemId;

/**
 * Moves the items of a tree – and tells where they can be moved to
 */
#[Flow\Scope('singleton')]
final readonly class ItemMover
{
    /**
     * The drop keys of all items the item can be moved into, see {@see ChildConstraints}
     *
     * @return list<string>
     * @throws InvalidRequest | NotFound
     */
    public function dropKeys(TreeRequest $request, ItemId $itemId): array
    {
        $nodeType = $request->nodeTypeManager->getNodeType($request->node($itemId)->nodeTypeName);
        return $nodeType !== null ? $request->childConstraints->dropKeysAccepting($nodeType) : [];
    }
}
