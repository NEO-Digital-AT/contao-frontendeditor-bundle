<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Controller;

use Neo\FrontendEditorBundle\Controller\ApiController;
use Neo\FrontendEditorBundle\Controller\LanguageSyncController;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\ElementSync;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\MemoryLogger;
use Neo\FrontendEditorBundle\Tests\Support\RequestTokens;
use Neo\FrontendEditorBundle\Tests\Support\Unused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny by default: every endpoint that passes an action on to the language
 * versions (ElementSync) answers with the error envelope before anything is
 * read or written, unless the request token, edit mode, the switch "Language
 * versions", for structural actions also "Create, move and delete elements",
 * and Plus for the element's page are there. ElementSync itself is not built
 * (Unused): a denied request never reaches it.
 */
#[CoversClass(LanguageSyncController::class)]
final class LanguageSyncControllerTest extends TestCase
{
    use EditorFixture;
    use RequestTokens;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->logger = new MemoryLogger();
        // Element 12 stands in article 3 of page 2.
        $this->db->insert('tl_article', ['id' => 3, 'pid' => 2, 'tstamp' => 1, 'title' => 'Start']);
        $this->switches = ['edit_mode', 'edit_content', 'edit_languages', 'edit_structure'];
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    /** @return iterable<string, array{string, string}> */
    public static function writes(): iterable
    {
        yield 'new element' => ['create', '{"choices": {"24": "create"}}'];
        yield 'image' => ['image', '{"field": "singleSRC", "pages": [24]}'];
        yield 'move' => ['move', '{"pages": [24]}'];
        yield 'visibility' => ['visibility', '{"pages": [24]}'];
        yield 'delete' => ['delete', '{"pages": [24]}'];
    }

    #[DataProvider('writes')]
    public function testEveryWriteNeedsTheRequestTokenOfTheSession(string $action, string $body): void
    {
        foreach ([null, '', 'forged-token'] as $token) {
            $this->assertError($this->write($action, $body, $token), 403, 'NFE419');
        }
    }

    #[DataProvider('writes')]
    public function testEveryWriteNeedsEditModeAndTheSwitchForLanguageVersions(string $action, string $body): void
    {
        foreach (['edit_mode', 'edit_languages'] as $missing) {
            $this->switches = array_values(array_diff(['edit_mode', 'edit_content', 'edit_languages', 'edit_structure'], [$missing]));

            $this->assertError($this->write($action, $body), 403, 'NFE403');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function structural(): iterable
    {
        yield 'new element' => ['create', '{"choices": {"24": "create"}}'];
        yield 'move' => ['move', '{"pages": [24]}'];
        yield 'visibility' => ['visibility', '{"pages": [24]}'];
        yield 'delete' => ['delete', '{"pages": [24]}'];
    }

    #[DataProvider('structural')]
    public function testCreatingMovingHidingAndDeletingNeedTheSwitchForTheStructure(string $action, string $body): void
    {
        $this->switches = ['edit_mode', 'edit_content', 'edit_languages'];

        $this->assertError($this->write($action, $body), 403, 'NFE403');
    }

    #[DataProvider('writes')]
    public function testEveryWriteNeedsPlusForThePageOfTheElement(string $action, string $body): void
    {
        $this->assertError($this->write($action, $body, host: 'www.example.com'), 403, 'NFE403');
    }

    #[DataProvider('writes')]
    public function testAnUnknownElementOrADraftIsNotFound(string $action, string $body): void
    {
        $this->db->insert('tl_content', ['id' => 13, 'pid' => 3, 'ptable' => 'tl_article', 'tstamp' => 0, 'type' => 'text']);

        foreach ([99, 13] as $id) {
            $this->assertError($this->write($action, $body, id: $id), 404, 'NFE404');
        }
    }

    public function testTheOverviewNeedsEditModeTheSwitchAndPlus(): void
    {
        $this->assertError($this->overview(99), 404, 'NFE404');

        $this->switches = ['edit_mode', 'edit_content'];
        $this->assertError($this->overview(12), 403, 'NFE403');

        $this->switches = ['edit_mode', 'edit_content', 'edit_languages'];
        $this->assertError($this->overview(12, 'www.example.com'), 403, 'NFE403');
    }

    private function write(string $action, string $body, string|null $token = 'valid-token', string $host = 'localhost', int $id = 12): Response
    {
        $request = Request::create('https://'.$host.'/contao/frontend-editor/element/'.$id.'/versions/'.$action, 'POST', [], [], [], [], $body);

        if (null !== $token) {
            $request->headers->set(ApiController::TOKEN_HEADER, $token);
        }

        return $this->controller($host)->{$action}($id, $request);
    }

    private function overview(int $id, string $host = 'localhost'): Response
    {
        return $this->controller($host)->overview($id, Request::create('https://'.$host.'/contao/frontend-editor/element/'.$id.'/versions'));
    }

    private function controller(string $host): LanguageSyncController
    {
        $security = $this->editorSecurity();
        $framework = $this->framework([2 => ['domain' => $host, 'language' => 'de']]);

        return new LanguageSyncController(
            Unused::instance(ElementSync::class),
            new ElementPlaces($this->db, $framework, Unused::instance(LanguageVersions::class), new ElementLinks($this->db)),
            new ElementAccess($security, $framework),
            Licences::editions($framework, $host),
            $this->tokenManager(),
            $this->logger,
            self::$tokenName,
        );
    }

    private function assertError(Response $response, int $status, string $code): void
    {
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame($code, $data['errorCode']);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
