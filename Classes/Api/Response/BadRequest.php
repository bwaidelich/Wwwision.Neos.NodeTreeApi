<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Response;

use Neos\OpenApi\Support\HttpStatusCode;

final readonly class BadRequest extends ProblemResponse
{
    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(400);
    }

    public static function description(): string
    {
        return 'The request could not be understood';
    }
}
