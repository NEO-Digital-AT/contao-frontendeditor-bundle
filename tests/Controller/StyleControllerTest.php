<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Controller;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Controller\ApiController;
use Neo\FrontendEditorBundle\Controller\StyleController;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Style\StyleCompiler;
use Neo\FrontendEditorBundle\Style\StyleMessages;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\Decisions;
use Neo\FrontendEditorBundle\Tests\Support\EditorTranslations;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\MemoryLogger;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\RequestTokens;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Page 3 is on a development address (Pro), page 4 on a website without
 * licence; the editor has the switches of the edit mode and of page styles.
 */
final class StyleControllerTest extends TestCase
{
    use Decisions;
    use Pages;
    use RequestTokens;

    private Connection $db;

    /** @var list<Permission> */
    private array $switches = [Permission::EditMode, Permission::EditStyles];

    private bool $module = true;

    private bool $page = true;

    protected function setUp(): void
    {
        ContaoState::language('default', ['ERR' => ['general' => 'An error occurred!'], 'MSC' => ['invalidToken' => 'Invalid token!']]);
        $this->db = TestDatabase::create('tl_page', PageStyles::TABLE);
        $this->db->insert('tl_page', ['id' => 3, 'title' => 'Chairs']);
        $this->db->insert('tl_page', ['id' => 4, 'title' => 'Tables']);
    }

    protected function tearDown(): void
    {
        ContaoState::clear();
    }

    public function testShowingAPageWithoutStyle(): void
    {
        $this->assertSame(['success' => true, 'id' => 0, 'saved' => false, 'tstamp' => 0, 'css' => ''], $this->data($this->controller()->show(3)));
    }

    public function testADraftIsOpenedOnceAndShowsNoCssUntilSaved(): void
    {
        $opened = $this->data($this->controller()->open(3, $this->post()));

        $this->assertSame(['success', 'id', 'created', 'tstamp'], array_keys($opened));
        $this->assertTrue($opened['created']);
        $this->assertSame(0, $opened['tstamp']);
        $this->assertFalse($this->data($this->controller()->open(3, $this->post()))['created']);

        $this->db->update(PageStyles::TABLE, ['css' => '.draft{}'], ['id' => $opened['id']]);
        $shown = $this->data($this->controller()->show(3));
        $this->assertSame($opened['id'], $shown['id']);
        $this->assertFalse($shown['saved']);
        $this->assertSame('', $shown['css']);

        $this->db->update(PageStyles::TABLE, ['tstamp' => 1700000000], ['id' => $opened['id']]);
        $this->assertSame(['success' => true, 'id' => $opened['id'], 'saved' => true, 'tstamp' => 1700000000, 'css' => '.draft{}'], $this->data($this->controller()->show(3)));
    }

    public function testThePreviewTranslatesWithoutSaving(): void
    {
        $response = $this->controller()->preview(3, $this->post('{"scss": "$c: red; .a { color: $c; }"}'));

        $this->assertSame(['success' => true, 'css' => '.a{color:red}'], $this->data($response));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.PageStyles::TABLE));
    }

    public function testThePreviewOfAStyleThatCannotBeUsedNamesTheErrorAndItsLine(): void
    {
        $response = $this->controller()->preview(3, $this->post('{"scss": ".a {\n  color: $nope;\n}"}'));
        $data = $this->data($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('NFE422', $data['errorCode']);
        $this->assertSame(['scss' => ['Error in line 2: The variable $nope is not defined.']], $data['fields']);
        $this->assertSame(2, $data['line']);

        $open = $this->data($this->controller()->preview(3, $this->post('{"scss": ".a {\n  color: red;\n"}')));
        $this->assertNull($open['line']);
    }

    public function testThePreviewNeedsAStyle(): void
    {
        foreach (['', '[]', '{"scss": 5}', '{"css": ".a{}"}'] as $body) {
            $this->assertError($this->controller()->preview(3, $this->post($body)), 400, 'NFE400');
        }
    }

    public function testEveryWriteNeedsTheRequestToken(): void
    {
        foreach ([null, 'forged-token'] as $token) {
            $this->assertError($this->controller()->open(3, $this->post('', $token)), 403, 'NFE419');
            $this->assertError($this->controller()->preview(3, $this->post('{"scss": ".a{}"}', $token)), 403, 'NFE419');
            $this->assertError($this->controller()->discard(1, $this->post('', $token)), 403, 'NFE419');
        }

        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.PageStyles::TABLE));
    }

    public function testEachMissingConditionIsForbidden(): void
    {
        $this->assertForbiddenEverywhere(4, 'a website without Pro');

        $this->switches = [Permission::EditStyles];
        $this->assertForbiddenEverywhere(3, 'without the switch of the edit mode');

        $this->switches = [Permission::EditMode];
        $this->assertForbiddenEverywhere(3, 'without the switch of page styles');

        $this->switches = [Permission::EditMode, Permission::EditStyles];
        $this->module = false;
        $this->assertForbiddenEverywhere(3, 'without the back-end module');

        $this->module = true;
        $this->page = false;
        $this->assertForbiddenEverywhere(3, 'without the right to edit the page');

        $this->page = true;
        $this->assertForbiddenEverywhere(99, 'a page that does not exist');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.PageStyles::TABLE));
    }

    public function testOnlyADraftOfAPageTheUserMayStyleIsDiscarded(): void
    {
        $this->db->insert(PageStyles::TABLE, ['id' => 1, 'pid' => 3, 'tstamp' => 0, 'scope' => 'page']);
        $this->db->insert(PageStyles::TABLE, ['id' => 2, 'pid' => 4, 'tstamp' => 0, 'scope' => 'page']);
        $this->db->insert(PageStyles::TABLE, ['id' => 5, 'pid' => 3, 'tstamp' => 1700000000, 'scope' => 'page']);

        $this->assertError($this->controller()->discard(2, $this->post()), 403, 'NFE403');
        $this->assertError($this->controller()->discard(9, $this->post()), 403, 'NFE403');
        $this->assertSame(['success' => true, 'removed' => false], $this->data($this->controller()->discard(5, $this->post())));
        $this->assertSame(['success' => true, 'removed' => true], $this->data($this->controller()->discard(1, $this->post())));
        $this->assertSame([2, 5], array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM '.PageStyles::TABLE.' ORDER BY id')));
    }

    private function assertForbiddenEverywhere(int $page, string $case): void
    {
        $this->assertError($this->controller()->show($page), 403, 'NFE403', $case);
        $this->assertError($this->controller()->open($page, $this->post()), 403, 'NFE403', $case);
        $this->assertError($this->controller()->preview($page, $this->post('{"scss": ".a{}"}')), 403, 'NFE403', $case);
    }

    private function assertError(Response $response, int $status, string $code, string $case = ''): void
    {
        $data = $this->data($response);

        $this->assertSame($status, $response->getStatusCode(), $case);
        $this->assertFalse($data['success'], $case);
        $this->assertSame($code, $data['errorCode'], $case);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        return json_decode((string) $response->getContent(), true);
    }

    private function post(string $body = '', string|null $token = 'valid-token'): Request
    {
        $request = Request::create('https://manage.example.com/contao/frontend-editor/style/3', 'POST', [], [], [], [], $body);

        if (null !== $token) {
            $request->headers->set(ApiController::TOKEN_HEADER, $token);
        }

        return $request;
    }

    private function controller(): StyleController
    {
        $user = Users::editor(...$this->switches);
        $switches = array_map(static fn (Permission $permission): string => $permission->attribute(), $this->switches);
        $module = $this->module;
        $page = $this->page;

        $security = $this->security(static fn (string $attribute, mixed $subject): bool => match (true) {
            str_starts_with($attribute, 'neo_frontend_editor.') => \in_array($attribute, $switches, true),
            ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute => $module && StyleController::MODULE === $subject,
            default => true,
        }, $user);

        $manager = $this->createStub(AccessDecisionManagerInterface::class);
        $manager->method('decide')->willReturnCallback(static fn (TokenInterface $token, array $attributes): bool => match ($attributes[0]) {
            'contao_dc.tl_page' => $page,
            default => \in_array($attributes[0], $switches, true),
        });

        $framework = $this->framework([3 => ['domain' => 'www.example.test'], 4 => ['domain' => 'www.example.com'], 99 => ['domain' => 'www.example.test']]);

        return new StyleController(
            new PageStyles($this->db),
            new StyleCompiler(),
            new StyleMessages(EditorTranslations::translator()),
            new StyleVoter($manager, Licences::editions($framework), $this->db),
            new ElementAccess($security, $framework),
            $security,
            $this->tokenManager(),
            new MemoryLogger(),
            self::$tokenName,
        );
    }
}
