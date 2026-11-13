<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\Decisions;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use PHPUnit\Framework\TestCase;

final class ElementAccessTest extends TestCase
{
    use Decisions;
    use Pages;

    private const ELEMENT = ['id' => 12, 'pid' => 3, 'ptable' => 'tl_article', 'sorting' => 128, 'type' => 'text', 'invisible' => ''];

    protected function setUp(): void
    {
        ContaoState::dca('tl_content', [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'type' => ['inputType' => 'select', 'sql' => ['default' => 'text']],
                'headline' => ['inputType' => 'inputUnit', 'exclude' => false],
                'text' => ['inputType' => 'textarea'],
                'invisible' => ['inputType' => 'checkbox'],
                'cssID' => ['inputType' => 'text', 'exclude' => true],
                'sorting' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
            ],
        ]);
        $GLOBALS['TL_CTE'] = ['texts' => ['headline' => 'Class', 'text' => 'Class'], 'media' => ['image' => 'Class']];
    }

    protected function tearDown(): void
    {
        ContaoState::clear();
    }

    public function testUpdatingNeedsTheSwitchAndContaosDecision(): void
    {
        $allowed = $this->access(Permission::EditContent);

        $this->assertTrue($allowed->canUpdate(self::ELEMENT, ['text' => 'New']));
        $this->assertSame(['contao_dc.tl_content UpdateAction'], $this->askedActions());
        $action = $this->asked[1][1];
        $this->assertInstanceOf(UpdateAction::class, $action);
        $this->assertSame(self::ELEMENT, $action->getCurrent());
        $this->assertSame(['text' => 'New'], $action->getNew());

        // Contao says no: the switch does not widen it.
        $this->assertFalse($this->access(Permission::EditContent, deny: 'contao_dc.tl_content')->canUpdate(self::ELEMENT));
    }

    public function testWithoutTheSwitchContaoIsNotEvenAsked(): void
    {
        $access = $this->access(Permission::EditMode);

        $this->assertFalse($access->canUpdate(self::ELEMENT));
        $this->assertSame([], $this->askedActions());
    }

    public function testAFieldIsEditableWhenNotExcludedOrAllowedForTheGroup(): void
    {
        $access = $this->access(deny: ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE);

        // Not excluded, explicitly.
        $this->assertTrue($access->canEditField('headline'));
        // A field with an input type counts as excluded (as in Contao's back-end form) and is not allowed.
        $this->assertFalse($access->canEditField('text'));
        $this->assertFalse($access->canEditField('cssID'));

        $allowed = $this->access();
        $this->assertTrue($allowed->canEditField('text'));
        $this->assertContains([ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_content::text'], $this->asked);
    }

    public function testWithoutTheStructureSwitchNothingCanBeMovedCopiedHiddenOrDeleted(): void
    {
        $rights = $this->access(Permission::EditContent)->structureRights(self::ELEMENT);

        $this->assertSame(['canMove' => false, 'canCopy' => false, 'canToggle' => false, 'canDelete' => false], $rights);
        $this->assertSame([], $this->askedActions());
        $this->assertFalse($this->access(Permission::EditContent)->canCreateIn(3));
    }

    public function testStructureRightsAreContaosDecisionsOnTheSameActionsAsTheBackEnd(): void
    {
        $access = $this->access(Permission::EditStructure);

        $this->assertSame(['canMove' => true, 'canCopy' => true, 'canToggle' => true, 'canDelete' => true], $access->structureRights(self::ELEMENT));

        $actions = array_values(array_filter(array_column($this->asked, 1), static fn (mixed $subject): bool => \is_object($subject)));
        $this->assertInstanceOf(UpdateAction::class, $actions[0]);
        $this->assertSame(['pid' => 3, 'sorting' => 128, 'ptable' => 'tl_article'], $actions[0]->getNew());
        $this->assertInstanceOf(CreateAction::class, $actions[1]);
        $this->assertArrayNotHasKey('id', $actions[1]->getNew());
        $this->assertInstanceOf(UpdateAction::class, $actions[2]);
        $this->assertSame(['invisible' => 1], $actions[2]->getNew());
        $this->assertInstanceOf(DeleteAction::class, $actions[3]);
    }

    public function testTheTableConfigurationAndEachVoterNarrowTheStructureRights(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['config'] += ['notSortable' => true, 'notCopyable' => true];
        $rights = $this->access(Permission::EditStructure)->structureRights(self::ELEMENT);

        $this->assertFalse($rights['canMove']);
        $this->assertFalse($rights['canCopy']);
        $this->assertTrue($rights['canToggle']);

        unset($GLOBALS['TL_DCA']['tl_content']['config']['notSortable'], $GLOBALS['TL_DCA']['tl_content']['config']['notCopyable']);
        $access = $this->access(Permission::EditStructure, deny: ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, denyActions: [DeleteAction::class]);
        $rights = $access->structureRights(['invisible' => '1'] + self::ELEMENT);

        // The field "invisible" is not allowed for the group, deleting is not allowed by Contao.
        $this->assertFalse($rights['canToggle']);
        $this->assertFalse($rights['canDelete']);
        $this->assertTrue($rights['canMove']);
    }

    public function testAnElementCanBeCreatedWhenContaoAllowsAtLeastOneType(): void
    {
        $types = [];
        $access = $this->access(Permission::EditStructure, decide: static function (string $attribute, mixed $subject) use (&$types): bool {
            if ($subject instanceof CreateAction) {
                $types[] = $subject->getNew()['type'];

                return 'image' === $subject->getNew()['type'];
            }

            return true;
        });

        $this->assertTrue($access->canCreateIn(3));
        $this->assertSame(['text', 'headline', 'image'], $types);

        $none = $this->access(Permission::EditStructure, denyActions: [CreateAction::class]);
        $this->assertFalse($none->canCreateIn(3));
        $this->assertFalse($none->canCreateInParent('tl_news', 4));

        $GLOBALS['TL_DCA']['tl_content']['config']['closed'] = true;
        $this->assertFalse($this->access(Permission::EditStructure)->canCreateIn(3));
    }

    public function testCopyingIntoAnotherArticleIsContaosDecisionOnTheCopyThere(): void
    {
        $this->assertFalse($this->access(Permission::EditContent)->canCopyInto(self::ELEMENT, 9), 'without the structure switch');
        $this->assertSame([], $this->askedActions());

        $this->assertTrue($this->access(Permission::EditStructure)->canCopyInto(self::ELEMENT, 9));
        $action = end($this->asked)[1];
        $this->assertInstanceOf(CreateAction::class, $action);
        // The copy as Contao's copy creates it: in the other article, without the ID of the original.
        $this->assertSame(['pid' => 9, 'ptable' => 'tl_article', 'sorting' => 128, 'type' => 'text', 'invisible' => ''], $action->getNew());

        $this->assertFalse($this->access(Permission::EditStructure, denyActions: [CreateAction::class])->canCopyInto(self::ELEMENT, 9));

        $GLOBALS['TL_DCA']['tl_content']['config']['notCopyable'] = true;
        $this->assertFalse($this->access(Permission::EditStructure)->canCopyInto(self::ELEMENT, 9));
    }

    public function testCreatingAnArticleNeedsItsSwitchAndContao(): void
    {
        ContaoState::dca('tl_article', ['config' => []]);

        $this->assertFalse($this->access(Permission::EditStructure)->canCreateArticle(5));
        $this->assertTrue($this->access(Permission::CreateArticles)->canCreateArticle(5, 'left'));
        $this->assertSame(['pid' => 5, 'inColumn' => 'left'], end($this->asked)[1]->getNew());
        $this->assertFalse($this->access(Permission::CreateArticles, deny: 'contao_dc.tl_article')->canCreateArticle(5));

        $GLOBALS['TL_DCA']['tl_article']['config']['notCreatable'] = true;
        $this->assertFalse($this->access(Permission::CreateArticles)->canCreateArticle(5));
    }

    /**
     * @param list<class-string>                 $denyActions
     * @param (\Closure(string, mixed): bool)|null $decide
     */
    private function access(Permission|null $switch = null, string|null $deny = null, array $denyActions = [], \Closure|null $decide = null): ElementAccess
    {
        $this->asked = [];

        $security = $this->security(static function (string $attribute, mixed $subject) use ($switch, $deny, $denyActions, $decide): bool {
            if (str_starts_with($attribute, 'neo_frontend_editor.')) {
                return null !== $switch && $switch->attribute() === $attribute;
            }

            if ($attribute === $deny || (\is_object($subject) && \in_array($subject::class, $denyActions, true))) {
                return false;
            }

            return null === $decide || $decide($attribute, $subject);
        });

        return new ElementAccess($security, $this->framework());
    }
}
