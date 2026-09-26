<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Where the items of a "listItems" result come from – including the values that were not part of the request
 */
final readonly class ItemsContext implements ProvidesSchema
{
    /**
     * @param array<string, string> $dimensions
     */
    public function __construct(
        public string $site,
        public string $contentRepository,
        public string $workspace,
        public array $dimensions,
        // whether the current user may change the content of the workspace – otherwise no item can be moved
        public bool $writable,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Where the items come from, including the defaults for values that were not part of the request',
            properties: ObjectProperties::create(
                site: StringSchema::create(description: 'The node name of the site, detected from the domain of the request'),
                contentRepository: StringSchema::create(description: 'The id of the content repository of the site'),
                workspace: StringSchema::create(description: 'The name of the workspace'),
                dimensions: ObjectSchema::create(
                    description: 'The dimension values by dimension name',
                    examples: [['language' => 'en_US']],
                    additionalProperties: true,
                ),
                writable: BooleanSchema::create(description: 'Whether the current user may change the content of the workspace'),
            ),
            additionalProperties: false,
            required: ['site', 'contentRepository', 'workspace', 'dimensions', 'writable'],
        );
    }

    /**
     * A short hash of the context that cursors are bound to
     */
    public function hash(): string
    {
        $dimensions = $this->dimensions;
        ksort($dimensions);
        return substr(sha1(json_encode([$this->site, $this->contentRepository, $this->workspace, $dimensions], JSON_THROW_ON_ERROR)), 0, 10);
    }
}
