<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Security;

use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function testEverySwitchHasTheAttributeOfTheExtension(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertSame('neo_frontend_editor.'.$permission->value, $permission->attribute());
        }
    }

    public function testTheSwitchesBelongToTheirEditions(): void
    {
        $editions = [];

        foreach (Permission::cases() as $permission) {
            $editions[$permission->value] = $permission->edition();
        }

        $this->assertSame([
            'edit_mode' => '',
            'edit_content' => '',
            'edit_structure' => '',
            'create_articles' => '',
            'edit_languages' => Editions::PLUS,
            'translate' => Editions::PLUS,
            'edit_pages' => Editions::PLUS,
            'edit_posts' => Editions::PLUS,
            'open_modules' => Editions::PLUS,
            'edit_styles' => Editions::PRO,
            'edit_design' => '',
        ], $editions);
    }
}
