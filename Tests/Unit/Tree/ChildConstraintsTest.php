<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tests\Unit\Tree;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use PHPUnit\Framework\TestCase;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;
use Wwwision\Neos\NodeTreeApi\Tree\ChildConstraints;
use Wwwision\Neos\NodeTreeApi\Tree\TreeDefinition;

final class ChildConstraintsTest extends TestCase
{
    private NodeTypeManager $nodeTypeManager;

    protected function setUp(): void
    {
        $this->nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([
            'Neos.Neos:Document' => ['abstract' => true],
            'Neos.Neos:Content' => ['abstract' => true],
            'Some.Package:Page' => [
                'superTypes' => ['Neos.Neos:Document' => true],
                'constraints' => ['nodeTypes' => ['Neos.Neos:Document' => true]],
            ],
            'Some.Package:Posting' => [
                'superTypes' => ['Neos.Neos:Document' => true],
                'constraints' => ['nodeTypes' => ['*' => false]],
            ],
            'Some.Package:Folder' => [
                'superTypes' => ['Neos.Neos:Document' => true],
                'constraints' => ['nodeTypes' => ['*' => false]],
            ],
            // its tethered "archive" only accepts postings (the constraints of the parent and the ones of the tethered
            // type are merged)
            'Some.Package:Blog' => [
                'superTypes' => ['Neos.Neos:Document' => true],
                'constraints' => ['nodeTypes' => ['Some.Package:Posting' => true, '*' => false]],
                'childNodes' => [
                    'archive' => [
                        'type' => 'Some.Package:Folder',
                        'constraints' => ['nodeTypes' => ['Some.Package:Posting' => true, '*' => false]],
                    ],
                    'main' => ['type' => 'Some.Package:ContentCollection'],
                ],
            ],
            'Some.Package:ContentCollection' => [
                'superTypes' => ['Neos.Neos:Content' => true],
                'constraints' => ['nodeTypes' => ['*' => true]],
            ],
        ]);
    }

    public function testDropKeysAreTheNodeTypesThatAcceptTheMovedType(): void
    {
        self::assertSame(['Some.Package:Page'], $this->dropKeysAccepting('Some.Package:Page'));
    }

    public function testDropKeysOfTetheredNodesAreTheParentTypeAndTheirName(): void
    {
        self::assertSame(['Some.Package:Blog', 'Some.Package:Blog/archive', 'Some.Package:Page'], $this->dropKeysAccepting('Some.Package:Posting'));
    }

    public function testDropKeysOnlyIncludeNodeTypesThatArePartOfTheTree(): void
    {
        // the content collection accepts everything, but is not part of a document tree
        self::assertNotContains('Some.Package:ContentCollection', $this->dropKeysAccepting('Some.Package:Posting'));
        self::assertNotContains('Some.Package:Blog/main', $this->dropKeysAccepting('Some.Package:Posting'));
    }

    /**
     * @return list<string>
     */
    private function dropKeysAccepting(string $nodeTypeName): array
    {
        $tree = new TreeDefinition(TreeId::fromString('documents'), 'Documents', 'sitemap', NodeTypeCriteria::fromFilterString('Neos.Neos:Document'), []);
        $childConstraints = new ChildConstraints($this->nodeTypeManager, $this->createStub(ContentSubgraphInterface::class), $tree);
        $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);
        self::assertInstanceOf(NodeType::class, $nodeType);
        return $childConstraints->dropKeysAccepting($nodeType);
    }
}
