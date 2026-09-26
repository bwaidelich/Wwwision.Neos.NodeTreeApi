<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Wwwision\Neos\NodeTreeApi\Api\Model\ItemsContext;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;
use Wwwision\Neos\NodeTreeApi\Presentation\NodePresenter;

/**
 * Resolves what a request to a tree refers to: the site (detected from the domain), its content repository, the
 * workspace and the dimensions – with their defaults – and what the current user may do there
 */
#[Flow\Scope('singleton')]
final readonly class TreeRequestFactory
{
    public function __construct(
        private TreeDefinitions $treeDefinitions,
        private ResolverRegistry $resolverRegistry,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private SiteRepository $siteRepository,
        private UserService $userService,
        private WorkspaceService $workspaceService,
        private ContentRepositoryAuthorizationService $authorizationService,
        private SecurityContext $securityContext,
        private NodePresenter $nodePresenter,
        private LabelTranslator $labelTranslator,
        private DatetimeFormatter $datetimeFormatter,
    ) {
    }

    /**
     * @throws InvalidRequest | NotFound | Forbidden
     */
    public function create(TreeId $treeId, SiteDetectionResult $detectedSite, string|null $workspaceName, string|null $dimensionSpacePoint): TreeRequest
    {
        $tree = $this->treeDefinitions->get($treeId) ?? throw new NotFound(sprintf('There is no tree "%s"', $treeId->value), 1790060601);
        $site = $this->siteRepository->findOneByNodeName($detectedSite->siteNodeName) ?? throw new NotFound(sprintf('There is no site "%s"', $detectedSite->siteNodeName->value), 1790060612);
        $contentRepository = $this->contentRepositoryRegistry->get($detectedSite->contentRepositoryId);
        $workspace = $workspaceName !== null ? self::workspaceName($workspaceName) : $this->defaultWorkspaceName($contentRepository);
        $dimensions = $dimensionSpacePoint !== null ? self::dimensionSpacePoint($dimensionSpacePoint) : $site->getConfiguration()->defaultDimensionSpacePoint;
        $subgraph = $this->subgraph($contentRepository, $workspace, $dimensions);
        $permissions = $this->permissions($contentRepository, $workspace);
        return new TreeRequest(
            tree: $tree,
            contentRepository: $contentRepository,
            subgraph: $subgraph,
            context: new ItemsContext($detectedSite->siteNodeName->value, $contentRepository->id->value, $workspace->value, $dimensions->coordinates, $permissions->workspaceWritable),
            siteNode: $this->siteNode($subgraph, $detectedSite->siteNodeName),
            permissions: $permissions,
            nodeTypeManager: $contentRepository->getNodeTypeManager(),
            resolverRegistry: $this->resolverRegistry,
            nodePresenter: $this->nodePresenter,
            locale: $this->labelTranslator->locale(),
            datetimeFormatter: $this->datetimeFormatter,
        );
    }

    /**
     * The personal workspace of the current user – or "live" if there is none
     */
    private function defaultWorkspaceName(ContentRepository $contentRepository): WorkspaceName
    {
        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            return WorkspaceName::forLive();
        }
        try {
            return $this->workspaceService->getPersonalWorkspaceForUser($contentRepository->id, $user->getId())->workspaceName;
        } catch (\RuntimeException) {
            return WorkspaceName::forLive();
        }
    }

    private static function workspaceName(string $workspaceName): WorkspaceName
    {
        return WorkspaceName::tryFromString($workspaceName) ?? throw new InvalidRequest(sprintf('"%s" is no valid workspace name', $workspaceName), 1790060606);
    }

    private static function dimensionSpacePoint(string $dimensionSpacePoint): DimensionSpacePoint
    {
        $invalidDimensions = new InvalidRequest('"dimensions" has to be a JSON object with the dimension values, e.g. {"language":"en_US"}', 1790060607);
        try {
            $dimensionValues = json_decode($dimensionSpacePoint, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $invalidDimensions;
        }
        if (!is_array($dimensionValues) || array_filter($dimensionValues, is_string(...)) !== $dimensionValues) {
            throw $invalidDimensions;
        }
        try {
            return DimensionSpacePoint::fromArray($dimensionValues);
        } catch (\InvalidArgumentException) {
            throw $invalidDimensions;
        }
    }

    private function subgraph(ContentRepository $contentRepository, WorkspaceName $workspace, DimensionSpacePoint $dimensions): ContentSubgraphInterface
    {
        if (!$contentRepository->getVariationGraph()->getDimensionSpacePoints()->contains($dimensions)) {
            throw new InvalidRequest(sprintf('The dimensions %s are not configured in content repository "%s"', $dimensions->toJson(), $contentRepository->id->value), 1790060608);
        }
        try {
            return $contentRepository->getContentSubgraph($workspace, $dimensions);
        } catch (AccessDenied $exception) {
            throw new Forbidden($exception->getMessage(), 1790060609, $exception);
        } catch (WorkspaceDoesNotExist) {
            throw new NotFound(sprintf('There is no workspace "%s"', $workspace->value), 1790060610);
        }
    }

    private function permissions(ContentRepository $contentRepository, WorkspaceName $workspace): Permissions
    {
        $roles = $this->securityContext->getRoles();
        $workspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepository->id, $workspace, $roles, $this->userService->getCurrentUser()?->getId());
        return new Permissions($workspacePermissions->write, $this->authorizationService, $roles);
    }

    private function siteNode(ContentSubgraphInterface $subgraph, SiteNodeName $siteNodeName): Node
    {
        $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
        $siteNode = $sitesNode !== null ? $subgraph->findNodeByPath($siteNodeName->toNodeName(), $sitesNode->aggregateId) : null;
        return $siteNode ?? throw new NotFound(sprintf('There is no site node "%s"', $siteNodeName->value), 1790060611);
    }
}
