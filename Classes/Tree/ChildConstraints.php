<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

/**
 * @internal the node type constraints for moving nodes, as the content repository checks them for a new parent
 *
 * They are expressed as "drop keys": the key of a node identifies the constraints it imposes on its children. It is the
 * node type – or, for a tethered node, the type of its parent together with its name, since then the parent's
 * configuration of the tethered child applies instead. Whether a node may be moved into another one is then just
 * whether the key of the new parent is one of {@see self::dropKeysAccepting()} for the type of the moved node – which
 * only depends on node types, not on the size of the tree
 */
#[Flow\Proxy(false)]
final readonly class ChildConstraints
{
    private const string TETHERED_SEPARATOR = '/';

    public function __construct(
        private NodeTypeManager $nodeTypeManager,
        private ContentSubgraphInterface $subgraph,
        private TreeDefinition $tree,
    ) {
    }

    /**
     * The key of the constraints $node imposes on its children
     */
    public function dropKeyFor(Node $node): string|null
    {
        if (!$node->classification->isTethered()) {
            return $node->nodeTypeName->value;
        }
        $parent = $this->subgraph->findParentNode($node->aggregateId);
        if ($parent === null || $node->name === null) {
            return null;
        }
        return $parent->nodeTypeName->value . self::TETHERED_SEPARATOR . $node->name->value;
    }

    /**
     * The keys of all nodes of the tree that accept a node of the given type as child
     *
     * @return list<string>
     */
    public function dropKeysAccepting(NodeType $nodeType): array
    {
        $dropKeys = [];
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $parentNodeType) {
            if ($this->tree->contains($parentNodeType) && $parentNodeType->allowsChildNodeType($nodeType)) {
                $dropKeys[] = $parentNodeType->name->value;
            }
            foreach ($parentNodeType->tetheredNodeTypeDefinitions as $tetheredNodeTypeDefinition) {
                $tetheredNodeType = $this->nodeTypeManager->getNodeType($tetheredNodeTypeDefinition->nodeTypeName);
                if ($tetheredNodeType === null || !$this->tree->contains($tetheredNodeType)) {
                    continue;
                }
                if ($this->nodeTypeManager->isNodeTypeAllowedAsChildToTetheredNode($parentNodeType->name, $tetheredNodeTypeDefinition->name, $nodeType->name)) {
                    $dropKeys[] = $parentNodeType->name->value . self::TETHERED_SEPARATOR . $tetheredNodeTypeDefinition->name->value;
                }
            }
        }
        sort($dropKeys);
        return $dropKeys;
    }

    /**
     * Whether a node of the given type may become a child of $parent
     */
    public function allowsChild(Node $parent, NodeType $nodeType): bool
    {
        $dropKey = $this->dropKeyFor($parent);
        return $dropKey !== null && in_array($dropKey, $this->dropKeysAccepting($nodeType), true);
    }
}
