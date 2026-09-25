<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\Utility\Arrays;
use Neos\Utility\PositionalArraySorter;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;

/**
 * Creates the {@see TreeDefinitions} from the "Wwwision.Neos.NodeTreeApi" settings and the node tree presets of Neos
 */
final readonly class TreeDefinitionsFactory
{
    private const string DEFAULT_BASE_NODE_TYPE = 'Neos.Neos:Document';

    /**
     * @param array<mixed> $settings "Wwwision.Neos.NodeTreeApi"
     * @param array<mixed>|null $nodeTreePresets "Neos.Neos.userInterface.navigateComponent.nodeTree.presets"
     */
    public function __construct(
        private array $settings,
        private array|null $nodeTreePresets,
    ) {
    }

    public function create(): TreeDefinitions
    {
        $treeConfigurations = [];
        if (($this->settings['derivedFromPresets'] ?? true) === true) {
            foreach ($this->nodeTreePresets ?? [] as $presetName => $preset) {
                if (!is_array($preset)) {
                    continue;
                }
                $treeConfigurations[$presetName] = [
                    'label' => $preset['ui']['label'] ?? ($presetName === 'default' ? 'Neos.Neos:Main:document' : (string)$presetName),
                    'icon' => $preset['ui']['icon'] ?? ($presetName === 'default' ? 'sitemap' : 'filter'),
                    'baseNodeType' => $preset['baseNodeType'] ?? self::DEFAULT_BASE_NODE_TYPE,
                ];
            }
        }
        $treeConfigurations = Arrays::arrayMergeRecursiveOverrule($treeConfigurations, is_array($this->settings['trees'] ?? null) ? $this->settings['trees'] : [], false, true);

        $definitions = [];
        foreach ($treeConfigurations as $treeId => $treeConfiguration) {
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
            if (!is_string($mapping['nodeType'] ?? null) || !is_string($mapping['resolver'] ?? null)) {
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
