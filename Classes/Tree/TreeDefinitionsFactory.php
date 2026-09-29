<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\Utility\PositionalArraySorter;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;

/**
 * Creates the {@see TreeDefinitions} from the "Wwwision.Neos.NodeTreeApi.trees" settings
 */
final readonly class TreeDefinitionsFactory
{
    private const string DEFAULT_BASE_NODE_TYPE = 'Neos.Neos:Document';

    /**
     * @param array<mixed>|null $treeConfigurations "Wwwision.Neos.NodeTreeApi.trees"
     */
    public function __construct(
        private array|null $treeConfigurations,
    ) {
    }

    public function create(): TreeDefinitions
    {
        $definitions = [];
        foreach ($this->treeConfigurations ?? [] as $treeId => $treeConfiguration) {
            if (!is_array($treeConfiguration)) {
                // removed via "~"
                continue;
            }
            $definitions[] = $this->createDefinition((string)$treeId, $treeConfiguration);
        }
        return new TreeDefinitions(...$definitions);
    }

    /**
     * @param array<mixed> $configuration
     */
    private function createDefinition(string $treeId, array $configuration): TreeDefinition
    {
        $childrenResolvers = [];
        $childrenResolverConfigurations = is_array($configuration['childrenResolvers'] ?? null) ? $configuration['childrenResolvers'] : [];
        foreach ((new PositionalArraySorter(array_filter($childrenResolverConfigurations, is_array(...))))->toArray() as $key => $mapping) {
            if (!is_array($mapping) || !is_string($mapping['nodeType'] ?? null) || !is_string($mapping['resolver'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('The children resolver "%s" of tree "%s" needs a "nodeType" and a "resolver"', $key, $treeId), 1790060001);
            }
            $childrenResolvers[] = ['nodeType' => $mapping['nodeType'], 'resolver' => $mapping['resolver']];
        }
        return new TreeDefinition(
            id: TreeId::fromString($treeId),
            label: is_string($configuration['label'] ?? null) ? $configuration['label'] : $treeId,
            icon: is_string($configuration['icon'] ?? null) ? $configuration['icon'] : 'sitemap',
            nodeTypes: NodeTypeCriteria::fromFilterString(is_string($configuration['baseNodeType'] ?? null) ? $configuration['baseNodeType'] : self::DEFAULT_BASE_NODE_TYPE),
            childrenResolvers: $childrenResolvers,
        );
    }
}
