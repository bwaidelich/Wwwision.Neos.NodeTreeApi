<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Wwwision\Neos\NodeTreeApi\Resolver\ChildrenResolver;

/**
 * The configured resolvers, by key (see "Wwwision.Neos.NodeTreeApi.resolvers")
 */
final class ResolverRegistry
{
    /**
     * @var array<string, ChildrenResolver>
     */
    private array $instances = [];

    /**
     * @param array<mixed> $resolverConfigurations
     */
    public function __construct(
        private readonly array $resolverConfigurations,
    ) {
    }

    public function get(string $resolverKey): ChildrenResolver
    {
        return $this->instances[$resolverKey] ??= $this->create($resolverKey);
    }

    private function create(string $resolverKey): ChildrenResolver
    {
        $configuration = $this->resolverConfigurations[$resolverKey] ?? null;
        if (!is_array($configuration) || !is_string($configuration['className'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('There is no resolver "%s" configured in "Wwwision.Neos.NodeTreeApi.resolvers"', $resolverKey), 1790060501);
        }
        $className = $configuration['className'];
        if (!is_subclass_of($className, ChildrenResolver::class)) {
            throw new \InvalidArgumentException(sprintf('The class "%s" of resolver "%s" does not implement %s', $className, $resolverKey, ChildrenResolver::class), 1790060502);
        }
        $options = is_array($configuration['options'] ?? null) ? $configuration['options'] : [];
        return new $className(...$options);
    }
}
