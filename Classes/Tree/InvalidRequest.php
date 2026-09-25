<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

/**
 * The request can't be served as it is, e.g. because of a malformed cursor (answered with a "400 Bad Request")
 */
final class InvalidRequest extends \RuntimeException
{
}
