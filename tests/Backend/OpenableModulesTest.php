<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Backend;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Neo\BasicBundle\Access\AccessPage;
use Neo\FrontendEditorBundle\Backend\OpenableModules;
use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Tests\Support\Decisions;
use PHPUnit\Framework\TestCase;

final class OpenableModulesTest extends TestCase
{
    use Decisions;

    protected function setUp(): void
    {
        $GLOBALS['BE_MOD'] = [
            'content' => ['article' => [], 'news' => [], 'catalogue' => ['tables' => ['tl_catalogue']]],
            'neo' => [AccessPage::MODULE => [], Settings::MODULE => [], 'neo_chat' => [], 'neo_hidden' => []],
            'website' => ['offers' => ['tables' => ['tl_offer']], 'partners' => []],
            'system' => ['settings' => [], 'log' => []],
        ];
        $GLOBALS['TL_LANG']['MOD'] = [
            'neo_chat' => ['Chat', 'The assistant'],
            'offers' => ['Offers', ''],
            'website' => 'Website',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_MOD'], $GLOBALS['TL_LANG']);
    }

    public function testWithoutOwnModulesOnlyTheExtensionsGroup(): void
    {
        $this->assertSame(
            [['id' => 'neo_chat', 'label' => 'Chat', 'description' => 'The assistant', 'group' => '']],
            $this->modules()->listed(false),
        );
    }

    public function testOwnModulesOfAnyGroupButNotContaosOwn(): void
    {
        $listed = $this->modules()->listed(true);

        $this->assertSame(['catalogue', 'neo_chat', 'offers', 'partners'], array_column($listed, 'id'));
        // The group's name for the website's modules; its key where it has none; '' for the extensions' group.
        $this->assertSame(['content', '', 'Website', 'Website'], array_column($listed, 'group'));
        $this->assertSame('partners', $listed[3]['label']);
    }

    public function testOnlyModulesTheUserMayOpen(): void
    {
        $this->asked = [];
        $listed = $this->modules(static fn (string $attribute, mixed $module): bool => 'offers' !== $module)->listed(true);

        $this->assertNotContains('offers', array_column($listed, 'id'));
        $this->assertContains([ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'offers'], $this->asked);
    }

    private function modules(\Closure|null $decide = null): OpenableModules
    {
        return new OpenableModules($this->security($decide ?? static fn (string $attribute, mixed $module): bool => 'neo_hidden' !== $module));
    }
}
