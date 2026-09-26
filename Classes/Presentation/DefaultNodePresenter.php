<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Presentation;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Wwwision\Neos\NodeTreeApi\Api\Model\Appearance;
use Wwwision\Neos\NodeTreeApi\Api\Model\Appearances;
use Wwwision\Neos\NodeTreeApi\Api\Model\Badge;
use Wwwision\Neos\NodeTreeApi\Api\Model\Badges;
use Wwwision\Neos\NodeTreeApi\Tree\LabelTranslator;

/**
 * Renders nodes like the document tree of the Neos UI does:
 *
 * - label from the node label generator, icon and tooltip from the node type
 * - disabled nodes are crossed out and muted, with a "hidden" badge – their descendants are muted
 * - nodes that are hidden in menus get a badge
 * - nodes with a scheduled visibility get a badge with the dates
 * - nodes that are shown as a fallback from other dimensions are italic, with a badge naming the dimensions
 */
#[Flow\Scope('singleton')]
final readonly class DefaultNodePresenter implements NodePresenter
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private NodeLabelGeneratorInterface $nodeLabelGenerator,
        private LabelTranslator $labelTranslator,
        private DatetimeFormatter $datetimeFormatter,
    ) {
    }

    public function present(Node $node): NodePresentation
    {
        $nodeType = $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        $icon = $nodeType?->getConfiguration('ui.icon');
        $appearance = [];
        $badges = [];

        if ($node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled())) {
            $appearance[] = Appearance::muted;
            $appearance[] = Appearance::strikethrough;
            $badges[] = new Badge('eye-slash', $this->labelTranslator->translateOwn('badge.disabled'));
        } elseif ($node->tags->contain(NeosSubtreeTag::disabled())) {
            $appearance[] = Appearance::muted;
        }
        if ($node->getProperty('hiddenInMenu') === true) {
            $badges[] = new Badge('low-vision', $this->labelTranslator->translateOwn('badge.hiddenInMenu'));
        }
        $scheduledVisibility = $this->scheduledVisibility($node);
        if ($scheduledVisibility !== null) {
            $badges[] = new Badge('clock', $scheduledVisibility);
        }
        $fallbackDimensions = $this->fallbackDimensions($node);
        if ($fallbackDimensions !== null) {
            $appearance[] = Appearance::italic;
            $badges[] = new Badge('language', $this->labelTranslator->translateOwn('badge.fallback', [$fallbackDimensions]));
        }

        return new NodePresentation(
            label: $this->nodeLabelGenerator->getLabel($node),
            icon: is_string($icon) ? $icon : null,
            tooltip: $nodeType !== null ? $this->labelTranslator->translate($nodeType->getLabel()) : null,
            appearance: new Appearances(...$appearance),
            badges: new Badges(...$badges),
        );
    }

    /**
     * e.g. "Visible from 10/1/26, 9:00 AM" – null if the node has no scheduled visibility
     */
    private function scheduledVisibility(Node $node): string|null
    {
        $parts = [];
        foreach (['enableAfterDateTime' => 'badge.enableAfter', 'disableAfterDateTime' => 'badge.disableAfter'] as $propertyName => $labelId) {
            $date = $node->getProperty($propertyName);
            if ($date instanceof \DateTimeInterface) {
                $parts[] = $this->labelTranslator->translateOwn($labelId, [$this->datetimeFormatter->formatDateTime($date, $this->labelTranslator->locale(), 'short')]);
            }
        }
        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * The labels of the dimension values the node is shown from, e.g. "English (US)" – null if it is no fallback
     */
    private function fallbackDimensions(Node $node): string|null
    {
        if ($node->originDimensionSpacePoint->equals(OriginDimensionSpacePoint::fromDimensionSpacePoint($node->dimensionSpacePoint))) {
            return null;
        }
        $dimensionSource = $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getContentDimensionSource();
        $labels = [];
        foreach ($node->originDimensionSpacePoint->coordinates as $dimensionId => $value) {
            if (($node->dimensionSpacePoint->coordinates[$dimensionId] ?? null) === $value) {
                continue;
            }
            $label = $dimensionSource->getDimension(new ContentDimensionId($dimensionId))?->getValue($value)?->getConfigurationValue('label');
            $labels[] = is_string($label) ? $this->labelTranslator->translate($label) : $value;
        }
        return implode(', ', $labels);
    }
}
