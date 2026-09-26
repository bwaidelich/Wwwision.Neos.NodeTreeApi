<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tests\Functional;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeCreation\Dto\NodeAggregateIdsByNodePaths;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\DisableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Authentication\Provider\TestingProvider;
use Neos\Flow\Security\Authentication\TokenAndProviderFactoryInterface;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Http\Client\InternalRequestEngine;
use Neos\Flow\Mvc\Routing\TestingRoutesProvider;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends requests through Flow to the API, on the content repository "nodetreetest" (see
 * Configuration/Testing/Settings.yaml) with this content (all in en_US, the specialization en_UK falls back to it):
 *
 * - site "Home" (node name "test-site")
 *   - not-found (tethered)
 *   - page-1 … page-5, page-2 has a child page-2-1, page-3 is disabled and has a child page-3-1, page-4 is
 *     scheduled to be visible from 2030, page-5 is hidden in menus
 *   - blog, with the postings post-a (2026-09-10), post-b (2026-09-01), post-c (2026-03-05), post-d (2024-12-24)
 *     and the category "category"
 */
final class NodeTreeApiTest extends FunctionalTestCase
{
    private const string CONTENT_REPOSITORY = 'nodetreetest';
    private const string ITEMS = '/nodetreetest/live/trees/documents/items';

    private static bool $contentCreated = false;

    /**
     * Security is set up in {@see self::request()} rather than with $testableSecurityEnabled: that updates the
     * Doctrine schema before every test, which drops all tables without entities – including the ones of the content
     * repository
     */
    protected function setUp(): void
    {
        parent::setUp();
        ObjectAccess::setProperty($this->securityContext, 'authorizationChecksDisabled', false, true);
        if (!self::$contentCreated) {
            $this->securityContext->withoutAuthorizationChecks($this->createContent(...));
            self::$contentCreated = true;
        }
    }

    /**
     * Like {@see FunctionalTestCase::tearDown()}, but without dropping the database, since the content is created
     * once for all tests
     */
    protected function tearDown(): void
    {
        $this->tearDownSecurity();
        $this->objectManager->forgetInstance(InternalRequestEngine::class);
        $this->objectManager->get(TestingRoutesProvider::class)->reset();
        $this->cleanupPersistentResourcesDirectory();
        $this->emitFunctionalTestTearDown();
    }

    public function testRequestsWithoutBackendSessionAreRejected(): void
    {
        self::assertProblem($this->request('/trees', authenticated: false), 401);
        self::assertProblem($this->items([], authenticated: false), 401);
    }

    public function testTreesAreListedWithTranslatedLabels(): void
    {
        $response = $this->request('/trees');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([
            ['id' => 'documents', 'label' => 'Document', 'icon' => 'sitemap'],
            ['id' => 'withoutBlogs', 'label' => 'Without blogs', 'icon' => 'sitemap'],
        ], self::json($response));
    }

    public function testTheRootLevelIsTheSiteNode(): void
    {
        $levels = $this->levels([]);

        self::assertCount(1, $levels);
        self::assertNull($levels[0]['parentId']);
        self::assertSame(['n.site'], array_column($levels[0]['items'], 'id'));
        self::assertSame([
            'id' => 'n.site',
            'label' => 'Home',
            'icon' => 'globe',
            'tooltip' => 'Homepage',
            'appearance' => [],
            'badges' => [],
            'hasChildren' => true,
            'node' => [
                'aggregateId' => 'site',
                'nodeTypeName' => 'Neos.Demo:Document.Homepage',
            ],
        ], $levels[0]['items'][0]);
        self::assertNull($levels[0]['previousCursor']);
        self::assertNull($levels[0]['nextCursor']);
    }

    public function testChildrenArePagedWithCursors(): void
    {
        $firstPage = $this->levels(['parent' => 'n.site', 'limit' => 4])[0];
        self::assertSame('n.site', $firstPage['parentId']);
        self::assertSame(['not-found', 'page-1', 'page-2', 'page-3'], self::aggregateIds($firstPage));
        self::assertNull($firstPage['previousCursor']);
        self::assertNotNull($firstPage['nextCursor']);
        self::assertSame([false, false, true, true], array_column($firstPage['items'], 'hasChildren'));

        $secondPage = $this->levels(['parent' => 'n.site', 'limit' => 4, 'cursor' => $firstPage['nextCursor']])[0];
        self::assertSame(['page-4', 'page-5', 'blog'], self::aggregateIds($secondPage));
        self::assertNull($secondPage['nextCursor']);

        $backToFirstPage = $this->levels(['parent' => 'n.site', 'limit' => 4, 'cursor' => $secondPage['previousCursor']])[0];
        self::assertSame(self::aggregateIds($firstPage), self::aggregateIds($backToFirstPage));
        self::assertNull($backToFirstPage['previousCursor']);
    }

    public function testNodesAreFilteredByTheBaseNodeTypeOfTheTree(): void
    {
        $response = $this->request('/nodetreetest/live/trees/withoutBlogs/items', ['site' => 'test-site', 'dimensions' => '{"language":"en_US"}', 'parent' => 'n.site']);

        self::assertSame(['not-found', 'page-1', 'page-2', 'page-3', 'page-4', 'page-5'], self::aggregateIds(self::json($response)['levels'][0]));
    }

    public function testNodeStatesAreRenderedAsAppearanceAndBadges(): void
    {
        $items = array_column($this->levels(['parent' => 'n.site'])[0]['items'], null, 'id');

        self::assertSame([], $items['n.page-1']['appearance']);
        self::assertSame([], $items['n.page-1']['badges']);
        self::assertSame('Page', $items['n.page-1']['tooltip']);

        self::assertSame(['muted', 'strikethrough'], $items['n.page-3']['appearance']);
        self::assertSame([['icon' => 'eye-slash', 'tooltip' => 'Hidden']], $items['n.page-3']['badges']);

        self::assertSame([], $items['n.page-4']['appearance']);
        self::assertSame(['clock'], array_column($items['n.page-4']['badges'], 'icon'));
        self::assertStringStartsWith('Visible from ', $items['n.page-4']['badges'][0]['tooltip']);

        self::assertSame([['icon' => 'low-vision', 'tooltip' => 'Hidden in menus']], $items['n.page-5']['badges']);
    }

    public function testDescendantsOfDisabledNodesAreMuted(): void
    {
        $item = $this->levels(['parent' => 'n.page-3'])[0]['items'][0];

        self::assertSame('n.page-3-1', $item['id']);
        self::assertSame(['muted'], $item['appearance']);
        self::assertSame([], $item['badges']);
    }

    public function testFallbackNodesAreRenderedItalicWithBadge(): void
    {
        $response = $this->request(self::ITEMS, ['site' => 'test-site', 'dimensions' => '{"language":"en_UK"}', 'parent' => 'n.site', 'limit' => 2]);
        $item = self::json($response)['levels'][0]['items'][1];

        self::assertSame(['italic'], $item['appearance']);
        self::assertSame([['icon' => 'language', 'tooltip' => 'Not translated yet, shown from English (US)']], $item['badges']);
    }

    public function testExpandToReturnsAllLevelsDownToTheItem(): void
    {
        $levels = $this->levels(['expandTo' => 'n.page-2-1', 'limit' => 3]);

        self::assertSame([null, 'n.site', 'n.page-2'], array_column($levels, 'parentId'));
        // a window around page-2
        self::assertSame(['page-1', 'page-2', 'page-3'], self::aggregateIds($levels[1]));
        self::assertNotNull($levels[1]['previousCursor']);
        self::assertNotNull($levels[1]['nextCursor']);
        self::assertSame(['page-2-1'], self::aggregateIds($levels[2]));
    }

    public function testExpandToWindowsProvideCursorsInBothDirections(): void
    {
        $levels = $this->levels(['expandTo' => 'n.page-4', 'limit' => 3]);

        self::assertSame(['page-3', 'page-4', 'page-5'], self::aggregateIds($levels[1]));
        $previousPage = $this->levels(['parent' => 'n.site', 'limit' => 3, 'cursor' => $levels[1]['previousCursor']])[0];
        self::assertSame(['not-found', 'page-1', 'page-2'], self::aggregateIds($previousPage));
        self::assertNull($previousPage['previousCursor']);
        $nextPage = $this->levels(['parent' => 'n.site', 'limit' => 3, 'cursor' => $levels[1]['nextCursor']])[0];
        self::assertSame(['blog'], self::aggregateIds($nextPage));
    }

    public function testBlogPostingsAreGroupedByYearAndMonth(): void
    {
        $blogLevel = $this->levels(['parent' => 'n.blog'])[0];
        self::assertSame(['2026', '2024', 'Category'], array_column($blogLevel['items'], 'label'));
        self::assertNull($blogLevel['items'][0]['node']);
        self::assertSame('calendar-alt', $blogLevel['items'][0]['icon']);
        self::assertSame([true, true, false], array_column($blogLevel['items'], 'hasChildren'));

        $monthLevel = $this->levels(['parent' => $blogLevel['items'][0]['id']])[0];
        self::assertSame($blogLevel['items'][0]['id'], $monthLevel['parentId']);
        self::assertSame(['September', 'March'], array_column($monthLevel['items'], 'label'));

        $septemberPage = $this->levels(['parent' => $monthLevel['items'][0]['id'], 'limit' => 1])[0];
        self::assertSame(['post-a'], self::aggregateIds($septemberPage));
        $nextSeptemberPage = $this->levels(['parent' => $monthLevel['items'][0]['id'], 'limit' => 1, 'cursor' => $septemberPage['nextCursor']])[0];
        self::assertSame(['post-b'], self::aggregateIds($nextSeptemberPage));
        self::assertNull($nextSeptemberPage['nextCursor']);
    }

    public function testExpandToRevealsItemsBelowVirtualItems(): void
    {
        $levels = $this->levels(['expandTo' => 'n.post-c']);

        self::assertCount(5, $levels);
        self::assertSame(['2026', '2024', 'Category'], array_column($levels[2]['items'], 'label'));
        self::assertSame($levels[2]['items'][0]['id'], $levels[3]['parentId']);
        self::assertSame(['September', 'March'], array_column($levels[3]['items'], 'label'));
        self::assertSame($levels[3]['items'][1]['id'], $levels[4]['parentId']);
        self::assertSame(['post-c'], self::aggregateIds($levels[4]));
    }

    public function testExpandToRevealsOtherChildrenOfGroupingResolvers(): void
    {
        $levels = $this->levels(['expandTo' => 'n.category']);

        self::assertCount(3, $levels);
        self::assertSame(['2026', '2024', 'Category'], array_column($levels[2]['items'], 'label'));
    }

    /**
     * @return iterable<array{array<string, string|int>, int}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'unknown site' => [['site' => 'unknown'], 404];
        yield 'unknown parent' => [['parent' => 'n.unknown'], 404];
        yield 'unknown expandTo' => [['expandTo' => 'n.unknown'], 404];
        yield 'virtual parent of unknown resolver' => [['parent' => 'v.unknown.e30'], 404];
        yield 'dimensions no JSON' => [['dimensions' => 'no json'], 400];
        yield 'dimensions no object' => [['dimensions' => '"en_US"'], 400];
        yield 'dimensions with non-string values' => [['dimensions' => '{"language":{"x":1}}'], 400];
        yield 'unknown dimensions' => [['dimensions' => '{"language":"fr"}'], 400];
        yield 'malformed item id' => [['parent' => 'not an id'], 400];
        yield 'malformed cursor' => [['parent' => 'n.site', 'cursor' => 'abc'], 400];
        yield 'cursor without parent' => [['cursor' => 'abc'], 400];
        yield 'expandTo with parent' => [['parent' => 'n.site', 'expandTo' => 'n.page-1'], 400];
        yield 'limit too high' => [['parent' => 'n.site', 'limit' => 201], 400];
    }

    /**
     * @param array<string, string|int> $parameters
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsAreAnsweredWithProblemDocuments(array $parameters, int $expectedStatus): void
    {
        self::assertProblem($this->items($parameters), $expectedStatus);
    }

    public function testCursorsOfOtherParentsAreRejected(): void
    {
        $cursor = $this->levels(['parent' => 'n.site', 'limit' => 1])[0]['nextCursor'];

        self::assertProblem($this->items(['parent' => 'n.blog', 'cursor' => $cursor]), 400);
    }

    public function testTetheredContentCollectionsAreNotPartOfTheTree(): void
    {
        $main = $this->securityContext->withoutAuthorizationChecks(fn () => $this->contentRepository()
            ->getContentSubgraph(WorkspaceName::forLive(), DimensionSpacePoint::fromArray(['language' => 'en_US']))
            ->findNodeByPath(NodeName::fromString('main'), NodeAggregateId::fromString('site')));
        self::assertNotNull($main);

        self::assertProblem($this->items(['parent' => 'n.' . $main->aggregateId->value]), 404);
    }

    public function testWorkspacesWithoutReadPermissionAreForbidden(): void
    {
        self::assertProblem($this->request('/nodetreetest/restricted/trees/documents/items', ['site' => 'test-site', 'dimensions' => '{"language":"en_US"}']), 403);
    }

    public function testUnknownTreesAreNotFound(): void
    {
        self::assertProblem($this->request('/nodetreetest/live/trees/unknown/items', ['site' => 'test-site']), 404);
    }

    private function createContent(): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString(self::CONTENT_REPOSITORY);
        $contentRepositoryRegistry = $this->objectManager->get(ContentRepositoryRegistry::class);
        $maintainer = $contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());
        $maintainer->setUp();
        $maintainer->prune();
        $metadataAndRoleRepository = $this->objectManager->get(WorkspaceMetadataAndRoleRepository::class);
        $metadataAndRoleRepository->pruneWorkspaceMetadata($contentRepositoryId);
        $metadataAndRoleRepository->pruneRoleAssignments($contentRepositoryId);

        $workspaceService = $this->objectManager->get(WorkspaceService::class);
        $workspaceService->createRootWorkspace($contentRepositoryId, WorkspaceName::forLive(), WorkspaceTitle::fromString('Live'), WorkspaceDescription::createEmpty(), WorkspaceRoleAssignments::createForLiveWorkspace());
        $workspaceService->createRootWorkspace($contentRepositoryId, WorkspaceName::fromString('restricted'), WorkspaceTitle::fromString('Restricted'), WorkspaceDescription::createEmpty(), WorkspaceRoleAssignments::createEmpty());

        $contentRepository = $this->contentRepository();
        $contentRepository->handle(CreateRootNodeAggregateWithNode::create(WorkspaceName::forLive(), NodeAggregateId::fromString('sites'), NodeTypeName::fromString('Neos.Neos:Sites')));
        $this->createNode('site', 'Neos.Demo:Document.Homepage', 'sites', ['title' => 'Home'], 'test-site', ['notfound' => 'not-found']);
        $this->createNode('page-1', 'Neos.Demo:Document.Page', 'site', ['title' => 'Page 1']);
        $this->createNode('page-2', 'Neos.Demo:Document.Page', 'site', ['title' => 'Page 2']);
        $this->createNode('page-3', 'Neos.Demo:Document.Page', 'site', ['title' => 'Page 3']);
        $this->createNode('page-4', 'Neos.Demo:Document.Page', 'site', ['title' => 'Page 4', 'enableAfterDateTime' => new \DateTimeImmutable('2030-01-01T10:00:00+00:00')]);
        $this->createNode('page-5', 'Neos.Demo:Document.Page', 'site', ['title' => 'Page 5', 'hiddenInMenu' => true]);
        $this->createNode('page-2-1', 'Neos.Demo:Document.Page', 'page-2', ['title' => 'Page 2.1']);
        $this->createNode('page-3-1', 'Neos.Demo:Document.Page', 'page-3', ['title' => 'Page 3.1']);
        $this->createNode('blog', 'Neos.Demo:Document.Blog', 'site', ['title' => 'Blog']);
        foreach (['post-a' => '2026-09-10', 'post-b' => '2026-09-01', 'post-c' => '2026-03-05', 'post-d' => '2024-12-24'] as $id => $date) {
            $this->createNode($id, 'Neos.Demo:Document.BlogPosting', 'blog', ['title' => 'Posting ' . $id, 'datePublished' => new \DateTimeImmutable($date . 'T10:00:00+02:00')]);
        }
        $this->createNode('category', 'Neos.Demo:Document.BlogCategory', 'blog', ['title' => 'Category']);
        $contentRepository->handle(DisableNodeAggregate::create(WorkspaceName::forLive(), NodeAggregateId::fromString('page-3'), DimensionSpacePoint::fromArray(['language' => 'en_US']), NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS));
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string> $tetheredNodeIds by node path
     */
    private function createNode(string $id, string $nodeTypeName, string $parentId, array $properties, string|null $nodeName = null, array $tetheredNodeIds = []): void
    {
        $command = CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            NodeAggregateId::fromString($id),
            NodeTypeName::fromString($nodeTypeName),
            OriginDimensionSpacePoint::fromArray(['language' => 'en_US']),
            NodeAggregateId::fromString($parentId),
            initialPropertyValues: PropertyValuesToWrite::fromArray($properties),
        );
        if ($nodeName !== null) {
            $command = $command->withNodeName(NodeName::fromString($nodeName));
        }
        if ($tetheredNodeIds !== []) {
            $command = $command->withTetheredDescendantNodeAggregateIds(NodeAggregateIdsByNodePaths::fromArray($tetheredNodeIds));
        }
        $this->contentRepository()->handle($command);
    }

    private function contentRepository(): ContentRepository
    {
        return $this->objectManager->get(ContentRepositoryRegistry::class)->get(ContentRepositoryId::fromString(self::CONTENT_REPOSITORY));
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function items(array $parameters, bool $authenticated = true): ResponseInterface
    {
        return $this->request(self::ITEMS, [...['site' => 'test-site', 'dimensions' => '{"language":"en_US"}'], ...$parameters], $authenticated);
    }

    /**
     * @param array<string, string|int> $parameters
     * @return list<array{parentId: string|null, items: list<array<string, mixed>>, previousCursor: string|null, nextCursor: string|null}>
     */
    private function levels(array $parameters): array
    {
        $response = $this->items($parameters);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return self::json($response)['levels'];
    }

    /**
     * @param array<string, string|int> $queryParameters
     */
    private function request(string $path, array $queryParameters = [], bool $authenticated = true): ResponseInterface
    {
        // the authentication manager remembers whether it authenticated during the previous request
        $this->inject($this->objectManager->get(AuthenticationManagerInterface::class), 'isAuthenticated', null);
        $testingProvider = $this->objectManager->get(TokenAndProviderFactoryInterface::class)->getProviders()['TestingProvider'];
        self::assertInstanceOf(TestingProvider::class, $testingProvider);
        $testingProvider->reset();
        if ($authenticated) {
            $account = new Account();
            $account->setAccountIdentifier('editor');
            $account->setAuthenticationProviderName('TestingProvider');
            $account->setRoles([$this->objectManager->get(PolicyService::class)->getRole('Neos.Neos:Editor')]);
            $testingProvider->setAccount($account);
            $testingProvider->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
        }
        $this->securityContext->clearContext();
        $request = new ServerRequest('GET', 'http://localhost/neos/node-tree-api' . $path . ($queryParameters !== [] ? '?' . http_build_query($queryParameters) : ''));
        return $this->browser->sendRequest($request->withQueryParams(array_map('strval', $queryParameters)));
    }

    /**
     * @param array{items: list<array<string, mixed>>} $level
     * @return list<string>
     */
    private static function aggregateIds(array $level): array
    {
        return array_map(static fn (array $item) => $item['node']['aggregateId'] ?? $item['id'], $level['items']);
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function assertProblem(ResponseInterface $response, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }
}
