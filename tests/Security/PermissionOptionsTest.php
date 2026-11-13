<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Security;

use Contao\DataContainer;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\PermissionOptions;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use PHPUnit\Framework\TestCase;

final class PermissionOptionsTest extends TestCase
{
    use Pages;

    public function testWithoutLicenceOnlyTheFreeSwitchesAreOffered(): void
    {
        $options = (new PermissionOptions(Licences::editions($this->framework(), 'manage.example.com')))();

        $this->assertSame(['edit_mode', 'edit_content', 'edit_structure', 'create_articles', 'edit_design'], $options);
    }

    public function testOnADevelopmentAddressEverySwitchIsOffered(): void
    {
        $options = (new PermissionOptions(Licences::editions($this->framework(), 'localhost')))();

        $this->assertSame(array_map(static fn (Permission $permission): string => $permission->value, Permission::cases()), $options);
    }

    public function testSavingKeepsTheTickedSwitchesTheLicenceDoesNotShow(): void
    {
        $options = new PermissionOptions(Licences::editions($this->framework(), 'manage.example.com'));
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn([Permission::FIELD => serialize(['edit_mode', 'edit_styles', 'translate'])]);

        // The form posts the shown switches only: edit_mode was unticked, edit_content ticked.
        $saved = $options->keepHidden(serialize(['edit_content']), $dc);

        $this->assertSame(['edit_content', 'edit_styles', 'translate'], unserialize($saved));
    }

    public function testWithoutHiddenSwitchesTheValueIsSavedAsPosted(): void
    {
        $options = new PermissionOptions(Licences::editions($this->framework(), 'manage.example.com'));
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn([Permission::FIELD => serialize(['edit_mode'])]);

        $this->assertSame('a:0:{}', $options->keepHidden('a:0:{}', $dc));
        $this->assertNull($options->keepHidden(null, $dc));
    }
}
