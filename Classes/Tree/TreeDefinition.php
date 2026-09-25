<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;

/**
 * A configured tree (see "Wwwision.Neos.NodeTreeApi.trees")
 */
#[Flow\Proxy(false)]
final readonly class TreeDefinition
{
    public const string DEFAULT_RESOLVER = 'default';

    /**
     * @param list<array{nodeType: string, resolver: string}> $childrenResolvers ordered, the first match wins
     */
    public function __construct(
        public TreeId $id,
        public string $label,
        public string $icon,
        public NodeTypeCriteria $nodeTypes,
        private array $childrenResolvers,
    ) {
    }

    /**
     * The key of the resolver that lists the children of nodes of the given type
     */
    public function resolverKeyFor(NodeType $nodeType): string
    {
        foreach ($this->childrenResolvers as $mapping) {
            if ($nodeType->isOfType($mapping['nodeType'])) {
                return $mapping['resolver'];
            }
        }
        return self::DEFAULT_RESOLVER;
    }

    /**
     * @return list<string>
     */
    public function resolverKeys(): array
    {
        return array_values(array_unique([self::DEFAULT_RESOLVER, ...array_column($this->childrenResolvers, 'resolver')]));
    }

    /**
     * Whether nodes of the given type are part of the tree
     *
     * Disallowed types win over allowed ones. Without allowed types, all types but the disallowed ones are part of it
     */
    public function contains(NodeType $nodeType): bool
    {
        foreach ($this->nodeTypes->explicitlyDisallowedNodeTypeNames as $disallowedNodeTypeName) {
            if ($nodeType->isOfType($disallowedNodeTypeName)) {
                return false;
            }
        }
        if ($this->nodeTypes->explicitlyAllowedNodeTypeNames->isEmpty()) {
            return true;
        }
        foreach ($this->nodeTypes->explicitlyAllowedNodeTypeNames as $allowedNodeTypeName) {
            if ($nodeType->isOfType($allowedNodeTypeName)) {
                return true;
            }
        }
        return false;
    }
}
