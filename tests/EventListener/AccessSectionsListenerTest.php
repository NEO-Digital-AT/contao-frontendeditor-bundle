<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
use Neo\BasicBundle\Access\AccessPage;
use Neo\FrontendEditorBundle\EventListener\AccessSectionsListener;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use PHPUnit\Framework\TestCase;

final class AccessSectionsListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'][AccessPage::TABLE]);
    }

    public function testWithoutLicenceTheEditorLeavesTheTranslationServicesAndTheAiConnection(): void
    {
        $this->register(['ai' => [Editions::EXTENSION, 'Other extension'], 'azure_translator' => [Editions::EXTENSION], 'deepl' => [Editions::EXTENSION]]);

        $this->listener('www.example.com')(AccessPage::TABLE);

        $config = $GLOBALS['TL_DCA'][AccessPage::TABLE]['config']['neo_access'];
        $this->assertSame(['Other extension'], $config['ai']);
        $this->assertArrayNotHasKey('azure_translator', $config);
        $this->assertArrayNotHasKey('deepl', $config);
        $this->assertSame(['Other extension'], $config['sections']['x']['users']);
    }

    public function testOnADevelopmentAddressEverythingStays(): void
    {
        $sections = ['ai' => [Editions::EXTENSION], 'azure_translator' => [Editions::EXTENSION], 'deepl' => [Editions::EXTENSION]];
        $this->register($sections);

        $this->listener('localhost')(AccessPage::TABLE);

        foreach ($sections as $kind => $users) {
            $this->assertSame($users, $GLOBALS['TL_DCA'][AccessPage::TABLE]['config']['neo_access'][$kind]);
        }
    }

    public function testOtherTablesAreLeftAlone(): void
    {
        $this->register(['deepl' => [Editions::EXTENSION]]);

        $this->listener('www.example.com')('tl_content');

        $this->assertSame([Editions::EXTENSION], $GLOBALS['TL_DCA'][AccessPage::TABLE]['config']['neo_access']['deepl']);
    }

    /** @param array<string, list<string>> $kinds */
    private function register(array $kinds): void
    {
        $GLOBALS['TL_DCA'][AccessPage::TABLE]['config']['neo_access'] = $kinds + ['sections' => ['x' => ['fields' => ['x_key'], 'users' => ['Other extension']]]];
    }

    private function listener(string $host): AccessSectionsListener
    {
        return new AccessSectionsListener(Licences::editions($this->createStub(ContaoFramework::class), $host));
    }
}
