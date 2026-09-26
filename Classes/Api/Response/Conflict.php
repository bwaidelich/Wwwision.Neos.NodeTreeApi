<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Response;

use Neos\OpenApi\Support\HttpStatusCode;

final readonly class Conflict extends ProblemResponse
{
    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(409);
    }

    public static function description(): string
    {
        return 'The item can\'t be moved there – maybe the content changed in the meantime';
    }
}
