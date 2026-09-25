<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * Base class for the expected failures of the API: RFC 9457 problem documents, just like the ones neos/openapi emits
 * on its own
 */
abstract readonly class ProblemResponse implements ApiResponse
{
    final public function __construct(
        private string $detail,
    ) {
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(ProblemDocument::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return ProblemDocument::contentType();
    }

    public function body(): ProblemDocument
    {
        return ProblemDocument::create(static::statusCode(), static::description(), $this->detail);
    }
}
