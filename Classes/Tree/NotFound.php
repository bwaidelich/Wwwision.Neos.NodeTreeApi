<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

/**
 * A tree, site, item, … the request refers to doesn't exist (answered with a "404 Not Found")
 */
final class NotFound extends \RuntimeException
{
}
