<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Style;

use Contao\BackendUser;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Style\StyleCallbacks;
use Neo\FrontendEditorBundle\Style\StyleCompiler;
use Neo\FrontendEditorBundle\Style\StyleMessages;
use Neo\FrontendEditorBundle\Tests\Support\Decisions;
use Neo\FrontendEditorBundle\Tests\Support\EditorTranslations;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\RecordingCacheTags;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class StyleCallbacksTest extends TestCase
{
    use Decisions;
    use Pages;

    private Connection $db;

    private RecordingCacheTags $cacheTags;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create('tl_page', PageStyles::TABLE);
        $this->cacheTags = new RecordingCacheTags();

        // 1 › 2 › (3, 4 › 5); 6 elsewhere.
        foreach ([[1, 0], [2, 1], [3, 2], [4, 2], [5, 4], [6, 0]] as [$id, $pid]) {
            $this->db->insert('tl_page', ['id' => $id, 'pid' => $pid, 'title' => 'Page '.$id]);
        }

        $this->db->insert(PageStyles::TABLE, ['id' => 10, 'pid' => 2, 'tstamp' => 1700000000, 'scope' => 'subpages', 'scss' => '$c: red; .a { color: $c; }', 'css' => '']);
        $this->db->insert(PageStyles::TABLE, ['id' => 11, 'pid' => 6, 'tstamp' => 1700000000, 'scope' => 'page', 'scss' => '', 'css' => '']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testAStyleThatCannotBeUsedIsNotSavedAndExplainedUnderTheField(): void
    {
        try {
            $this->callbacks()->compile('.a { color: $nope; }', $this->dc(10));
            $this->fail('An undefined variable was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Error in line 1: The variable $nope is not defined.', $exception->getMessage());
        }

        // The message is shown as HTML: what the user typed is escaped.
        try {
            $this->callbacks()->compile('.a{background:url(https://example.com/<b>.png)}', $this->dc(10));
            $this->fail('A foreign address was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('<b>', $exception->getMessage());
            $this->assertStringContainsString('&lt;b&gt;', $exception->getMessage());
        }
    }

    public function testTheCssIsStoredNextToTheStyleAndThePagesBelowAreBuiltAgain(): void
    {
        $callbacks = $this->callbacks();

        $this->assertSame('.b { color: blue; }', $callbacks->compile('.b { color: blue; }', $this->dc(10)));
        $callbacks->storeCss($this->dc(10));

        $this->assertSame('.b{color:blue}', $this->db->fetchOne('SELECT css FROM '.PageStyles::TABLE.' WHERE id = 10'));
        $this->assertSame(['contao.db.tl_page.2', 'contao.db.tl_page.3', 'contao.db.tl_page.4', 'contao.db.tl_page.5'], $this->cacheTags->invalidated);
    }

    public function testARestoredVersionGetsItsCssFromItsStyle(): void
    {
        // No compile() in this request (Contao restores a version): the stored style is translated.
        $this->callbacks()->storeCss($this->dc(10));

        $this->assertSame('.a{color:red}', $this->db->fetchOne('SELECT css FROM '.PageStyles::TABLE.' WHERE id = 10'));
    }

    public function testDeletingAStyleBuildsItsPagesAgain(): void
    {
        $this->callbacks()->invalidate($this->dc(11));
        $this->assertSame(['contao.db.tl_page.6'], $this->cacheTags->invalidated);

        $this->cacheTags->invalidated = [];
        $this->callbacks()->invalidate($this->dc(99));
        $this->assertSame([], $this->cacheTags->invalidated);
    }

    public function testTheListShowsOnlyTheStylesOfPagesTheUserMayStyle(): void
    {
        $this->callbacks(Users::editor(Permission::EditStyles), static fn (int $pageId): bool => 6 === $pageId)->limitList();
        $this->assertSame([11], $GLOBALS['TL_DCA'][PageStyles::TABLE]['list']['sorting']['root']);

        $this->callbacks(Users::editor(Permission::EditStyles), static fn (): bool => false)->limitList();
        $this->assertSame([0], $GLOBALS['TL_DCA'][PageStyles::TABLE]['list']['sorting']['root']);

        unset($GLOBALS['TL_DCA']);
        $this->callbacks(Users::admin(), static fn (): bool => false)->limitList();
        $this->assertArrayNotHasKey('TL_DCA', $GLOBALS);
    }

    /**
     * @param (\Closure(int): bool)|null $mayEditPage
     */
    private function callbacks(BackendUser|null $user = null, \Closure|null $mayEditPage = null): StyleCallbacks
    {
        $user ??= Users::admin();
        $mayEditPage ??= static fn (): bool => true;
        $manager = $this->createStub(AccessDecisionManagerInterface::class);
        $manager->method('decide')->willReturnCallback(static fn (TokenInterface $token, array $attributes, mixed $object = null): bool => ['contao_dc.tl_page'] === $attributes ? $mayEditPage((int) $object->getCurrent()['id']) : true);
        $pages = [];

        foreach (range(1, 6) as $id) {
            $pages[$id] = ['domain' => 'www.example.test'];
        }

        return new StyleCallbacks(
            new StyleCompiler(),
            new StyleMessages(EditorTranslations::translator()),
            $this->db,
            $this->cacheTags->service,
            new StyleVoter($manager, Licences::editions($this->framework($pages)), $this->db),
            $this->security(static fn (): bool => true, $user),
        );
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}
