<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

/**
 * An item can't be moved as requested (answered with a "409 Conflict") – its message is meant for the user
 */
final class Conflict extends \RuntimeException
{
}
