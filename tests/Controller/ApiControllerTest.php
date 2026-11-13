<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Controller;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Posts\PostTypes;
use Neo\FrontendEditorBundle\Backend\BackendStyles;
use Neo\FrontendEditorBundle\Content\PageStructure;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Controller\ApiController;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\RecordAccess;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\MemoryLogger;
use Neo\FrontendEditorBundle\Tests\Support\RequestTokens;
use Neo\FrontendEditorBundle\Tests\Support\Unused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiControllerTest extends TestCase
{
    use EditorFixture;
    use RequestTokens;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->logger = new MemoryLogger();
        $this->db->insert('tl_module', ['id' => 5, 'type' => 'search', 'name' => 'Search &amp; find', 'headline' => serialize(['value' => 'Search {{page::title}}', 'unit' => 'h2'])]);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    /** @return iterable<string, array{string, string}> */
    public static function writes(): iterable
    {
        yield 'element' => ['saveElement', '{"fields": {"text": "<p>New</p>"}}'];
        yield 'record' => ['saveRecord', '{"element": 0, "fields": {"headline": {"value": "New", "unit": "h2"}}}'];
    }

    #[DataProvider('writes')]
    public function testEveryWriteNeedsTheRequestTokenOfTheSession(string $endpoint, string $body): void
    {
        foreach ([null, '', 'forged-token'] as $token) {
            $response = $this->call($endpoint, $body, $token);

            $this->assertError($response, 403, 'NFE419');
            $this->assertSame('<p>Old text</p>', $this->stored('text'));
            $this->assertSame('Search {{page::title}}', unserialize($this->db->fetchOne('SELECT headline FROM tl_module WHERE id = 5'))['value']);
        }
    }

    #[DataProvider('writes')]
    public function testABodyThatIsNoChangeIsABadRequest(string $endpoint): void
    {
        foreach (['', 'not json', '[]', '{"fields": "text"}', '{"fields": {}, "rows": {}}', '{"fields": {"text": "x"}, "rows": "x"}', '{}'] as $body) {
            $this->assertError($this->call($endpoint, $body), 400, 'NFE400');
        }

        $this->assertSame('<p>Old text</p>', $this->stored('text'));
    }

    public function testSavingAnElementAnswersWithTheReasonWhyNothingWasSaved(): void
    {
        $this->assertError($this->call('saveElement', '{"fields": {"text": "<p>New</p>"}}', id: 99), 404, 'NFE404');
        $this->assertError($this->call('saveElement', '{"fields": {"type": "html"}}'), 403, 'NFE403');

        $invalid = $this->call('saveElement', '{"fields": {"text": ""}}');
        $data = $this->assertError($invalid, 422, 'NFE422');
        $this->assertSame(['text' => [\sprintf($GLOBALS['TL_LANG']['ERR']['mandatory'], 'text')]], $data['fields']);

        $this->switches = ['edit_mode'];
        $this->assertError($this->call('saveElement', '{"fields": {"text": "<p>New</p>"}}'), 403, 'NFE403');
        $this->assertSame('<p>Old text</p>', $this->stored('text'));
    }

    public function testSavingTheTextOfAModuleChangesItAndAnswersWithSuccess(): void
    {
        $response = $this->call('saveRecord', '{"element": 0, "fields": {"headline": {"value": "Find it", "unit": "h3"}}}', table: 'tl_module', id: 5);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(['value' => 'Find it', 'unit' => 'h3'], unserialize($this->db->fetchOne('SELECT headline FROM tl_module WHERE id = 5')));
    }

    public function testARecordOfAnotherTableOrElementIsForbidden(): void
    {
        $this->db->insert('tl_card', ['id' => 1, 'pid' => 40, 'title' => 'Card of another element']);

        $this->assertError($this->call('saveRecord', '{"element": 12, "fields": {"title": "Mine now"}}', table: 'tl_card', id: 1), 403, 'NFE403');
        $this->assertError($this->call('saveRecord', '{"element": 12, "fields": {"text": "<p>x</p>"}}', table: 'tl_content', id: 12), 403, 'NFE403');
        $this->assertError($this->call('saveRecord', '{"element": 12, "fields": {"title": "x"}}', table: 'tl_card', id: 9), 404, 'NFE404');
        $this->assertSame('Card of another element', $this->db->fetchOne('SELECT title FROM tl_card WHERE id = 1'));
    }

    public function testTheSessionNeedsABackEndUserWithTheSwitchOfTheEditMode(): void
    {
        $this->switches = ['edit_content'];
        $this->assertError($this->controller()->session(), 403, 'NFE403');

        $this->switches = ['edit_mode'];
        $response = $this->controller()->session();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'token' => 'valid-token', 'user' => ['name' => 'Erika Musterfrau', 'language' => 'de']], json_decode((string) $response->getContent(), true));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testReadingNeedsTheSwitchOfTheEditMode(): void
    {
        $this->switches = ['edit_content'];

        $this->assertError($this->controller()->page(1), 403, 'NFE403');
        $this->assertError($this->controller()->texts(Request::create('/contao/frontend-editor/texts', 'GET', ['keys' => 'fm:5'])), 403, 'NFE403');
    }

    public function testTheTextsOfModulesWithWhetherAndWhyTheyCanBeChanged(): void
    {
        $this->switches = ['edit_mode', 'edit_content'];
        $this->contao = static fn (string $attribute, mixed $subject): bool => !(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && 'tl_module::headline' === $subject);
        $GLOBALS['TL_LANG']['FMD']['search'] = ['Search engine', 'Adds a search form.'];

        $response = $this->controller()->texts(Request::create('/contao/frontend-editor/texts', 'GET', ['keys' => 'fm:5,fm:99,ce:12,fm:x']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(['success', 'modules'], array_keys($data));
        $this->assertSame(['fm:5'], array_keys($data['modules']));
        $module = $data['modules']['fm:5'];
        $this->assertSame('Search & find', $module['name']);
        $this->assertSame('search', $module['type']);
        $this->assertSame('Search engine', $module['typeLabel']);
        $this->assertSame([], $module['missing']);
        $this->assertSame(['tl_module.5.name', 'tl_module.5.headline'], array_column($module['sources'], 'key'));

        [$name, $headline] = $module['sources'];
        $this->assertTrue($name['editable']);
        $this->assertNull($name['reason']);
        $this->assertFalse($headline['editable']);
        $this->assertSame('field', $headline['reason']);
        $this->assertSame('module', $headline['scope']);
        // What the page shows: the insert tags replaced.
        $this->assertSame('Search [replaced]', $headline['shown']);
    }

    public function testPlainTextsOfModulesComeAsContaosFormShowsThem(): void
    {
        $this->switches = ['edit_mode', 'edit_content'];
        $this->db->update('tl_module', ['headline' => serialize(['value' => 'Q&amp;A &#34;now&#34;', 'unit' => 'h2'])], ['id' => 5]);

        $data = json_decode((string) $this->controller()->texts(Request::create('/contao/frontend-editor/texts', 'GET', ['keys' => 'fm:5']))->getContent(), true);
        [$name, $headline] = $data['modules']['fm:5']['sources'];

        // The stored value is encoded; the editor compares the value with the text on the page.
        $this->assertSame('Search & find', $name['value']);
        $this->assertSame('Q&A "now"', $headline['value']);
    }

    public function testModulesTheUserMayNotChangeNameTheMissingPermission(): void
    {
        $this->switches = ['edit_mode', 'edit_content'];
        $this->contao = static fn (string $attribute, mixed $subject): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE !== $attribute;

        $data = json_decode((string) $this->controller()->texts(Request::create('/contao/frontend-editor/texts', 'GET', ['keys' => 'fm:5']))->getContent(), true);
        $module = $data['modules']['fm:5'];

        $this->assertSame(['module', 'module'], array_column($module['sources'], 'reason'));
        $this->assertContains(['field' => 'modules', 'value' => 'themes'], $module['missing']);
    }

    public function testNoKeysNoModules(): void
    {
        $this->switches = ['edit_mode'];

        $data = json_decode((string) $this->controller()->texts(Request::create('/contao/frontend-editor/texts'))->getContent(), true);

        $this->assertSame(['success' => true, 'modules' => []], $data);
    }

    /** An endpoint that saves: element 12, or record 5 of tl_module unless given otherwise. */
    private function call(string $endpoint, string $body, string|null $token = 'valid-token', string $table = 'tl_module', int|null $id = null): Response
    {
        $request = Request::create('https://manage.example.com/contao/frontend-editor/x', 'POST', [], [], [], [], $body);

        if (null !== $token) {
            $request->headers->set(ApiController::TOKEN_HEADER, $token);
        }

        return 'saveElement' === $endpoint ? $this->controller()->saveElement($id ?? 12, $request) : $this->controller()->saveRecord($table, $id ?? 5, $request);
    }

    private function controller(): ApiController
    {
        $security = $this->editorSecurity();
        $framework = $this->framework();
        $insertTags = $this->createStub(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnCallback(static fn (string $text): string => (string) preg_replace('/\{\{[^}]+\}\}/', '[replaced]', $text));

        return new ApiController(
            $security,
            new ElementAccess($security, $framework),
            Unused::instance(PageStructure::class),
            $this->writer($security),
            Unused::instance(BackendStyles::class),
            $this->tokenManager(),
            $this->db,
            new ElementLinks($this->db),
            $this->sourceHash(),
            Licences::editions($framework),
            $this->logger,
            new TextSources($framework, $this->db, new RelatedRecords($framework, $this->db)),
            new RecordAccess($security, $this->db, $framework),
            $insertTags,
            new ElementPlaces($this->db, $framework, Unused::instance(LanguageVersions::class), new ElementLinks($this->db)),
            new PostTypes($framework, $security, $this->db),
            self::$tokenName,
        );
    }

    /** @return array<string, mixed> */
    private function assertError(Response $response, int $status, string $code): array
    {
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame($code, $data['errorCode']);
        $this->assertArrayHasKey('sessionCode', $data);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        return $data;
    }
}
