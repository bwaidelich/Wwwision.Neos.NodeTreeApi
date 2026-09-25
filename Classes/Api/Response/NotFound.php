<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Response;

use Neos\OpenApi\Support\HttpStatusCode;

final readonly class NotFound extends ProblemResponse
{
    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(404);
    }

    public static function description(): string
    {
        return 'Not found';
    }
}
