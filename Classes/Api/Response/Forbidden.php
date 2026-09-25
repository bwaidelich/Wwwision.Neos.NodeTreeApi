<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Response;

use Neos\OpenApi\Support\HttpStatusCode;

final readonly class Forbidden extends ProblemResponse
{
    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(403);
    }

    public static function description(): string
    {
        return 'The workspace may not be read';
    }
}
