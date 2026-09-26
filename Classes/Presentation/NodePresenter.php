<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Presentation;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Decides how nodes are rendered as tree items – replace the {@see DefaultNodePresenter} via Objects.yaml to change it
 *
 * Labels and tooltips are expected to be translated to the interface language of the current user
 */
interface NodePresenter
{
    public function present(Node $node): NodePresentation;
}
