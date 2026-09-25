<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Security;

use Neos\Flow\Annotations as Flow;

/**
 * The caller of the API: an authenticated Neos backend user
 */
#[Flow\Proxy(false)]
final readonly class BackendUser
{
    public function __construct(
        public string $accountIdentifier,
    ) {
    }
}
