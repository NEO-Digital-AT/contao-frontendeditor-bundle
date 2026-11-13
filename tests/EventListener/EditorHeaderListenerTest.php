<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuFactory;
use Neo\FrontendEditorBundle\EventListener\EditorHeaderListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditorHeaderListenerTest extends TestCase
{
    public function testTheEntryFollowsThePreviewWithItsAddressAndTheSwitch(): void
    {
        $tree = $this->header('/contao/preview?page=4');

        $this->dispatch($tree, true);

        $this->assertSame(['manual', 'preview', EditorHeaderListener::ITEM, 'submenu', 'burger'], array_keys($tree->getChildren()));

        $item = $tree->getChild(EditorHeaderListener::ITEM);

        $this->assertSame('/contao/preview?page=4&nfe_editor=1', $item->getUri());
        $this->assertSame('backend.header', $item->getLabel());
        $this->assertSame('icon-frontend-editor', $item->getLinkAttribute('class'));
        $this->assertSame('backend.header_title', $item->getLinkAttribute('title'));
        $this->assertSame('_blank', $item->getLinkAttribute('target'));
    }

    public function testAPreviewWithoutQueryGetsTheSwitchAsItsOnlyParameter(): void
    {
        $tree = $this->header('/contao/preview');

        $this->dispatch($tree, true);

        $this->assertSame('/contao/preview?nfe_editor=1', $tree->getChild(EditorHeaderListener::ITEM)->getUri());
    }

    public function testWithoutThePermissionThereIsNoEntry(): void
    {
        $tree = $this->header('/contao/preview');

        $this->dispatch($tree, false);

        $this->assertNull($tree->getChild(EditorHeaderListener::ITEM));
    }

    public function testWithoutContaosPreviewThereIsNoEntry(): void
    {
        $tree = (new MenuFactory())->createItem('headerMenu');
        $tree->addChild('submenu');

        $this->dispatch($tree, true);

        $this->assertNull($tree->getChild(EditorHeaderListener::ITEM));
    }

    public function testOtherMenusAreLeftAlone(): void
    {
        $tree = (new MenuFactory())->createItem('mainMenu');
        $tree->addChild('preview')->setUri('/contao/preview');

        $this->dispatch($tree, true);

        $this->assertNull($tree->getChild(EditorHeaderListener::ITEM));
    }

    private function header(string $previewUri): ItemInterface
    {
        $tree = (new MenuFactory())->createItem('headerMenu');
        $tree->addChild('manual');
        $tree->addChild('preview')->setUri($previewUri);
        $tree->addChild('submenu');
        $tree->addChild('burger');

        return $tree;
    }

    private function dispatch(ItemInterface $tree, bool $granted): void
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        (new EditorHeaderListener($checker, $translator))(new MenuEvent(new MenuFactory(), $tree));
    }
}
