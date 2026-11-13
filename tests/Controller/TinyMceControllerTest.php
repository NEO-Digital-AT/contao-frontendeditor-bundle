<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Controller;

use Contao\Config;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Picker\PickerBuilderInterface;
use Neo\FrontendEditorBundle\Controller\TinyMceController;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\Decisions;
use Neo\FrontendEditorBundle\Tests\Support\MemoryLogger;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The configuration of Contao's TinyMCE is handed out only for a field with
 * TinyMCE that the user may edit, with Contao's rich text editor switched on.
 */
final class TinyMceControllerTest extends TestCase
{
    use Decisions;

    private bool $editContent = true;

    private bool $editField = true;

    private bool $useRte = true;

    protected function setUp(): void
    {
        ContaoState::language('default', ['ERR' => ['general' => 'An error occurred!']]);
        ContaoState::dca('tl_content', ['fields' => [
            'text' => ['inputType' => 'textarea', 'exclude' => true, 'eval' => ['rte' => 'tinyMCE']],
            'html' => ['inputType' => 'textarea', 'exclude' => true, 'eval' => ['rte' => 'ace|html']],
        ]]);
    }

    protected function tearDown(): void
    {
        ContaoState::clear();
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testAnInvalidTableFieldOrSelectorIsABadRequest(): void
    {
        foreach ([
            ['table' => 'tl_content WHERE', 'field' => 'text', 'selector' => 'nfe-tinymce-1'],
            ['table' => 'tl_content', 'field' => 'text-', 'selector' => 'nfe-tinymce-1'],
            ['table' => 'tl_content', 'field' => 'text', 'selector' => '#x'],
        ] as $query) {
            $this->assertSame(400, $this->controller()(new Request($query))->getStatusCode());
        }
    }

    public function testWithoutThePermissionToEditContentItIsForbidden(): void
    {
        $this->editContent = false;

        $this->assertSame(403, $this->controller()($this->request('text'))->getStatusCode());
    }

    public function testAFieldTheUserMayNotEditIsForbidden(): void
    {
        $this->editField = false;

        $this->assertSame(403, $this->controller()($this->request('text'))->getStatusCode());
    }

    public function testAnUnknownFieldOrOneWithoutTinyMceHasNoConfiguration(): void
    {
        $this->assertSame(404, $this->controller()($this->request('nothing'))->getStatusCode());
        $this->assertSame(404, $this->controller()($this->request('html'))->getStatusCode());
    }

    public function testWithoutContaosRichTextEditorThereIsNoConfiguration(): void
    {
        $this->useRte = false;

        $this->assertSame(404, $this->controller()($this->request('text'))->getStatusCode());
    }

    private function request(string $field): Request
    {
        return new Request(['table' => 'tl_content', 'field' => $field, 'id' => '2', 'selector' => 'nfe-tinymce-1']);
    }

    private function controller(): TinyMceController
    {
        $editContent = $this->editContent;
        $editField = $this->editField;
        $security = $this->security(static fn (string $attribute): bool => match (true) {
            Permission::EditContent->attribute() === $attribute => $editContent,
            str_starts_with($attribute, 'neo_frontend_editor.') => true,
            default => $editField,
        }, Users::editor(Permission::EditContent));

        $config = $this->createStub(Adapter::class);
        $config->method('__call')->willReturnCallback(fn (string $method, array $arguments): mixed => 'get' === $method && 'useRTE' === $arguments[0] ? $this->useRte : null);

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(fn (string $class): Adapter => Config::class === $class ? $config : $this->createStub(Adapter::class));

        return new TinyMceController(new ElementAccess($security, $framework), $framework, $this->createStub(PickerBuilderInterface::class), new MemoryLogger());
    }
}
