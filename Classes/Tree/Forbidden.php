<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

/**
 * The current user may not read the requested workspace, or not change it as requested (answered with a
 * "403 Forbidden")
 */
final class Forbidden extends \RuntimeException
{
}
