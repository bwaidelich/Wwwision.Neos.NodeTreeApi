<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * @internal what the current user may change in the workspace of one request
 *
 * Node permissions only depend on the subtree tags of a node, so they are evaluated once per combination of tags
 */
#[Flow\Proxy(false)]
final class Permissions
{
    /**
     * @var array<string, bool> by the (sorted) subtree tags of a node
     */
    private array $editableByTags = [];

    /**
     * @param array<Role> $roles
     */
    public function __construct(
        public readonly bool $workspaceWritable,
        private readonly ContentRepositoryAuthorizationService $authorizationService,
        private readonly array $roles,
    ) {
    }

    /**
     * Whether the node may be changed – which includes that the workspace is writable
     */
    public function canEdit(Node $node): bool
    {
        if (!$this->workspaceWritable) {
            return false;
        }
        $tags = $node->tags->all()->toStringArray();
        sort($tags);
        return $this->editableByTags[implode(',', $tags)] ??= $this->authorizationService->getNodePermissions($node, $this->roles)->edit;
    }
}
