<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Presentation;

use Neos\Flow\Annotations as Flow;
use Wwwision\Neos\NodeTreeApi\Api\Model\Appearances;
use Wwwision\Neos\NodeTreeApi\Api\Model\Badges;

/**
 * How a node is rendered as a tree item
 */
#[Flow\Proxy(false)]
final readonly class NodePresentation
{
    public function __construct(
        public string $label,
        public string|null $icon,
        public string|null $tooltip = null,
        public Appearances $appearance = new Appearances(),
        public Badges $badges = new Badges(),
    ) {
    }
}
