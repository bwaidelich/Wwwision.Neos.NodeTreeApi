<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tests\Unit\Tree;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use PHPUnit\Framework\TestCase;
use Wwwision\Neos\NodeTreeApi\Api\Model\TreeId;
use Wwwision\Neos\NodeTreeApi\Tree\TreeDefinition;
use Wwwision\Neos\NodeTreeApi\Tree\TreeDefinitionsFactory;

final class TreeDefinitionsFactoryTest extends TestCase
{
    private NodeTypeManager $nodeTypeManager;

    protected function setUp(): void
    {
        $this->nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([
            'Neos.Neos:Document' => [],
            'Some.Package:Folder' => ['superTypes' => ['Neos.Neos:Document' => true]],
            'Some.Package:Blog' => ['superTypes' => ['Neos.Neos:Document' => true]],
            'Some.Package:SpecialBlog' => ['superTypes' => ['Some.Package:Blog' => true]],
            'Some.Package:Content' => [],
        ]);
    }

    public function testTreesAreCreatedFromTheSettings(): void
    {
        $definitions = $this->create([
            'default' => ['label' => 'Neos.Neos:Main:document', 'icon' => 'sitemap', 'baseNodeType' => 'Neos.Neos:Document'],
            'removed' => null,
            'blog' => ['label' => 'Blog', 'icon' => 'newspaper', 'baseNodeType' => 'Some.Package:Blog'],
        ]);

        self::assertSame(['default', 'blog'], $this->treeIds($definitions));
        $blog = $definitions->get(TreeId::fromString('blog'));
        self::assertNotNull($blog);
        self::assertSame('Blog', $blog->label);
        self::assertSame('newspaper', $blog->icon);
        self::assertSame('Some.Package:Blog', $blog->nodeTypes->toFilterString());
    }

    public function testTreesFallBackToDefaults(): void
    {
        $folders = $this->create(['folders' => []])->get(TreeId::fromString('folders'));

        self::assertNotNull($folders);
        self::assertSame('folders', $folders->label);
        self::assertSame('sitemap', $folders->icon);
        self::assertSame('Neos.Neos:Document', $folders->nodeTypes->toFilterString());
    }

    public function testNoTreesCanBeConfigured(): void
    {
        self::assertSame([], $this->treeIds($this->create(null)));
    }

    public function testChildrenResolversAreMatchedInTheOrderOfTheirPosition(): void
    {
        $definitions = $this->create(['default' => ['childrenResolvers' => [
            'blog' => ['nodeType' => 'Some.Package:Blog', 'resolver' => 'blogResolver'],
            'special' => ['nodeType' => 'Some.Package:SpecialBlog', 'resolver' => 'specialResolver', 'position' => 'start'],
        ]]]);
        $default = $definitions->get(TreeId::fromString('default'));
        self::assertNotNull($default);

        self::assertSame('specialResolver', $default->resolverKeyFor($this->nodeType('Some.Package:SpecialBlog')));
        self::assertSame('blogResolver', $default->resolverKeyFor($this->nodeType('Some.Package:Blog')));
        self::assertSame(TreeDefinition::DEFAULT_RESOLVER, $default->resolverKeyFor($this->nodeType('Some.Package:Folder')));
        self::assertSame(['default', 'specialResolver', 'blogResolver'], $default->resolverKeys());
    }

    public function testChildrenResolversNeedNodeTypeAndResolver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->create(['default' => ['childrenResolvers' => ['broken' => ['nodeType' => 'Some.Package:Blog']]]]);
    }

    public function testTreesContainNodesOfAllowedButNotOfDisallowedTypes(): void
    {
        $definitions = $this->create([
            'default' => ['baseNodeType' => 'Neos.Neos:Document,!Some.Package:Blog'],
            'everything' => ['baseNodeType' => '!Some.Package:Folder'],
        ]);
        $default = $definitions->get(TreeId::fromString('default'));
        $everything = $definitions->get(TreeId::fromString('everything'));
        self::assertNotNull($default);
        self::assertNotNull($everything);

        self::assertTrue($default->contains($this->nodeType('Some.Package:Folder')));
        self::assertFalse($default->contains($this->nodeType('Some.Package:SpecialBlog')));
        self::assertFalse($default->contains($this->nodeType('Some.Package:Content')));
        self::assertTrue($everything->contains($this->nodeType('Some.Package:Content')));
        self::assertFalse($everything->contains($this->nodeType('Some.Package:Folder')));
    }

    /**
     * @param array<mixed>|null $treeConfigurations
     */
    private function create(array|null $treeConfigurations): \Wwwision\Neos\NodeTreeApi\Tree\TreeDefinitions
    {
        return (new TreeDefinitionsFactory($treeConfigurations))->create();
    }

    /**
     * @return list<string>
     */
    private function treeIds(\Wwwision\Neos\NodeTreeApi\Tree\TreeDefinitions $definitions): array
    {
        return array_map(static fn (TreeDefinition $definition) => $definition->id->value, iterator_to_array($definitions, false));
    }

    private function nodeType(string $name): \Neos\ContentRepository\Core\NodeType\NodeType
    {
        return $this->nodeTypeManager->getNodeType(NodeTypeName::fromString($name)) ?? throw new \RuntimeException(sprintf('Unknown node type "%s"', $name));
    }
}
