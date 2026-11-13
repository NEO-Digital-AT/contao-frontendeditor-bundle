<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\CheckBox;
use Contao\FileTree;
use Contao\InputUnit;
use Contao\KeyValueWizard;
use Contao\ListWizard;
use Contao\TableWizard;
use Contao\TextArea;
use Contao\TextField;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Content\FieldWriter;
use Neo\FrontendEditorBundle\Content\FileMeta;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Language\SourceHash;
use Neo\FrontendEditorBundle\Language\TranslationUnits;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\RecordAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The editor's saving of fields with Contao's real widgets over an SQLite
 * database: an editor with the switches in $switches, whose decisions of
 * Contao's voters $contao takes (all yes by default).
 */
trait EditorFixture
{
    use ContaoContainer;
    use Decisions;
    use Pages;

    private Connection $db;

    private RequestStack $stack;

    private RecordingCacheTags $cacheTags;

    /** @var list<string> */
    private array $switches = ['edit_content'];

    /** @var \Closure(string, mixed): bool */
    private \Closure $contao;

    /** Contao's DCA, widgets and back-end modules for the editor, a database with element 12 (a text element). */
    private function setUpEditor(): void
    {
        $this->stack = $this->setUpContao(Request::create('https://manage.example.com/contao/frontend-editor/element/12', 'POST'));
        ContaoState::dca('tl_content', ContentDca::content());
        ContaoState::dca('tl_module', ContentDca::module());
        ContaoState::dca('tl_card', [
            'config' => ['ptable' => 'tl_content'],
            'palettes' => ['default' => '{title_legend},title'],
            'fields' => ['title' => ['inputType' => 'text', 'eval' => ['maxlength' => 255]]],
        ]);
        ContaoState::dca('tl_news_card', [
            'config' => ['ptable' => 'tl_news'],
            'palettes' => ['default' => '{title_legend},title'],
            'fields' => ['title' => ['inputType' => 'text']],
        ]);

        foreach (['tl_content', 'tl_module', 'tl_card', 'tl_news_card', 'modules', 'tl_user', 'tl_user_group', 'tl_page'] as $file) {
            ContaoState::language($file);
        }

        $GLOBALS['BE_FFL'] = [
            'text' => TextField::class,
            'textarea' => TextArea::class,
            'inputUnit' => InputUnit::class,
            'listWizard' => ListWizard::class,
            'tableWizard' => TableWizard::class,
            'keyValueWizard' => KeyValueWizard::class,
            'checkbox' => CheckBox::class,
            'fileTree' => FileTree::class,
        ];
        $GLOBALS['BE_MOD'] = ['content' => ['article' => ['tables' => ['tl_article', 'tl_content']]], 'design' => ['themes' => ['tables' => ['tl_theme', 'tl_module']]]];

        $this->db = TestDatabase::create('tl_content', 'tl_module', 'tl_card', 'tl_article', 'tl_files', 'tl_neo_fe_link', 'tl_neo_fe_pairing');
        $this->db->insert('tl_content', ['id' => 12, 'pid' => 3, 'ptable' => 'tl_article', 'tstamp' => 100, 'type' => 'text', 'headline' => serialize(['value' => 'Old headline', 'unit' => 'h2']), 'text' => '<p>Old text</p>']);
        $this->cacheTags = new RecordingCacheTags();
        $this->contao = static fn (string $attribute, mixed $subject): bool => true;
    }

    /** The security of an editor with $switches, whose other decisions $contao takes. */
    private function editorSecurity(): Security
    {
        $this->asked = [];
        $switches = $this->switches;
        $contao = $this->contao;

        return $this->security(static fn (string $attribute, mixed $subject): bool => str_starts_with($attribute, 'neo_frontend_editor.')
            ? \in_array(substr($attribute, 20), $switches, true)
            : $contao($attribute, $subject), Users::editor(...array_map(Permission::from(...), $switches)));
    }

    private function writer(Security|null $security = null): FieldWriter
    {
        $security ??= $this->editorSecurity();
        $framework = $this->framework();

        return new FieldWriter(
            $this->db,
            $framework,
            $this->stack,
            new ElementAccess($security, $framework),
            $this->cacheTags->service,
            new RecordAccess($security, $this->db, $framework),
            $security,
            $this->router(),
            new RelatedRecords($framework, $this->db),
            ContentDca::IMAGE_EXTENSIONS,
        );
    }

    /** Contao's back-end route as "/contao?<parameters>". */
    private function router(): UrlGeneratorInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters = []): string => '/contao?'.http_build_query($parameters));

        return $router;
    }

    private function fileMeta(Security|null $security = null): FileMeta
    {
        return new FileMeta($this->db, $security ?? $this->editorSecurity(), $this->cacheTags->service, $this->framework(), $this->stack, $this->router(), ContentDca::IMAGE_EXTENSIONS);
    }

    /** Everything of an element a translation covers, with this fixture's writer and rights. */
    private function translationUnits(Security|null $security = null): TranslationUnits
    {
        $security ??= $this->editorSecurity();
        $framework = $this->framework();

        return new TranslationUnits($framework, new TextSources($framework, $this->db, new RelatedRecords($framework, $this->db)), $this->fileMeta($security), $this->writer($security), new RelatedRecords($framework, $this->db));
    }

    /** @param array<int, array<string, mixed>> $pages page ID => details (with the language) */
    private function sourceHash(array $pages = []): SourceHash
    {
        return new SourceHash($this->db, $this->framework($pages), $this->translationUnits());
    }

    /**
     * Elements that show the questions of a FAQ category: 70 (category 1, in
     * English), 80 (category 2, its German version) and 90 (category 1 as well).
     */
    private function addFaq(): void
    {
        foreach (ContentDca::faq() as $table => $dca) {
            ContaoState::dca($table, $dca);
            ContaoState::language($table);
            $this->db->executeStatement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT \'\', question varchar(255) NOT NULL DEFAULT \'\', alias varchar(255) NOT NULL DEFAULT \'\', answer text NULL)');
        }

        $this->db->insert('tl_faq_category', ['id' => 1, 'title' => 'FAQ']);
        $this->db->insert('tl_faq_category', ['id' => 2, 'title' => 'Häufige Fragen']);
        $this->db->insert('tl_faq', ['id' => 1, 'pid' => 1, 'sorting' => 2, 'question' => 'Why?', 'alias' => 'why', 'answer' => '<p>Because.</p>']);
        $this->db->insert('tl_faq', ['id' => 2, 'pid' => 1, 'sorting' => 1, 'question' => 'How?', 'alias' => 'how', 'answer' => '<p>So.</p>']);
        $this->db->insert('tl_faq', ['id' => 3, 'pid' => 2, 'sorting' => 1, 'question' => 'Wie?', 'alias' => 'wie', 'answer' => '']);

        foreach ([70 => 1, 80 => 2, 90 => 1] as $id => $category) {
            $this->db->insert('tl_content', ['id' => $id, 'pid' => $id, 'ptable' => 'tl_article', 'type' => 'faq_list', 'faq_category' => $category, 'jumpTo' => 1]);
        }
    }

    private function stored(string $field, int $id = 12): mixed
    {
        return $this->db->fetchOne("SELECT $field FROM tl_content WHERE id = ?", [$id]);
    }
}
