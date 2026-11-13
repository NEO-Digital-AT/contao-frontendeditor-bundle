<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuFactory;
use Neo\FrontendEditorBundle\EventListener\BackendMenuListener;
use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use PHPUnit\Framework\TestCase;

final class BackendMenuListenerTest extends TestCase
{
    public function testWithoutLicenceTheEntriesOfPlusAndProAreLeftOut(): void
    {
        $tree = $this->menu();

        $this->dispatch($tree, 'www.example.com');

        $this->assertSame([Settings::MODULE, 'neo_access'], array_keys($tree->getChild('neo')->getChildren()));
    }

    public function testOnADevelopmentAddressEveryEntryStays(): void
    {
        $tree = $this->menu();

        $this->dispatch($tree, 'localhost');

        $this->assertSame([Settings::MODULE, 'neo_frontendeditor_translations', 'neo_frontendeditor_styles', 'neo_access'], array_keys($tree->getChild('neo')->getChildren()));
    }

    public function testOtherMenusAreLeftAlone(): void
    {
        $factory = new MenuFactory();
        $tree = $factory->createItem('headerMenu');
        $tree->addChild('neo')->addChild('neo_frontendeditor_styles');

        (new BackendMenuListener(Licences::editions($this->createStub(ContaoFramework::class), 'www.example.com')))(new MenuEvent($factory, $tree));

        $this->assertNotNull($tree->getChild('neo')->getChild('neo_frontendeditor_styles'));
    }

    private function menu(): ItemInterface
    {
        $tree = (new MenuFactory())->createItem('mainMenu');
        $group = $tree->addChild('neo');

        foreach ([Settings::MODULE, 'neo_frontendeditor_translations', 'neo_frontendeditor_styles', 'neo_access'] as $module) {
            $group->addChild($module);
        }

        $tree->addChild('content')->addChild('article');

        return $tree;
    }

    private function dispatch(ItemInterface $tree, string $host): void
    {
        (new BackendMenuListener(Licences::editions($this->createStub(ContaoFramework::class), $host)))(new MenuEvent(new MenuFactory(), $tree));
    }
}
