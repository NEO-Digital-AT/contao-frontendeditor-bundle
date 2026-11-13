<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Security;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the current back-end user may do with a content element, decided by
 * Contao's own voters (table, module, page mounts, article permissions,
 * element types, field permissions) and narrowed by the switches of the
 * extension. Nothing is rebuilt by hand.
 */
final class ElementAccess
{
    /** Fields the editor changes on the page itself. */
    public const INLINE_FIELDS = ['headline', 'text', 'singleSRC', 'alt', 'caption'];

    public function __construct(
        private readonly Security $security,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function has(Permission $permission): bool
    {
        return $this->security->isGranted($permission->attribute());
    }

    /**
     * May the user change this record, optionally with these new values?
     *
     * @param array<string, mixed>      $record
     * @param array<string, mixed>|null $new
     */
    public function canUpdate(array $record, array|null $new = null): bool
    {
        return $this->has(Permission::EditContent)
            && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_content', new UpdateAction('tl_content', $record, $new));
    }

    /** The same rule as the back-end form: a field is shown when it is not excluded or explicitly allowed. */
    public function canEditField(string $field, string $table = 'tl_content'): bool
    {
        // Without the loaded DCA every field would count as not excluded, i.e. allowed.
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);

        return !DataContainer::isFieldExcluded($table, $field)
            || $this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, $table.'::'.$field);
    }

    /**
     * What the user may do with the element in the structure of the page: the
     * switch "create, move and delete elements" and the same voter decisions
     * as Contao's cut, copy, toggle and delete operations.
     *
     * @param array<string, mixed> $record
     *
     * @return array{canMove: bool, canCopy: bool, canToggle: bool, canDelete: bool}
     */
    public function structureRights(array $record): array
    {
        if (!$this->has(Permission::EditStructure)) {
            return ['canMove' => false, 'canCopy' => false, 'canToggle' => false, 'canDelete' => false];
        }

        $config = $GLOBALS['TL_DCA']['tl_content']['config'] ?? [];
        $copy = $record;
        unset($copy['id']);

        return [
            'canMove' => !($config['notSortable'] ?? false)
                && $this->isGranted(new UpdateAction('tl_content', $record, ['pid' => $record['pid'], 'sorting' => $record['sorting'], 'ptable' => $record['ptable']])),
            'canCopy' => !($config['closed'] ?? false) && !($config['notCopyable'] ?? false)
                && $this->isGranted(new CreateAction('tl_content', $copy)),
            'canToggle' => !($config['notEditable'] ?? false) && $this->canEditField('invisible')
                && $this->isGranted(new UpdateAction('tl_content', $record, ['invisible' => $record['invisible'] ? 0 : 1])),
            'canDelete' => !($config['notDeletable'] ?? false)
                && $this->isGranted(new DeleteAction('tl_content', $record)),
        ];
    }

    /**
     * May the user copy this element into another container of the same kind
     * (an article, an element that contains elements, a post), as when it is
     * taken over into another language? The switch "create, move and delete
     * elements" and the voter decision on Contao's copy into that container.
     *
     * @param array<string, mixed> $record
     */
    public function canCopyInto(array $record, int $pid): bool
    {
        $config = $GLOBALS['TL_DCA']['tl_content']['config'] ?? [];
        $copy = ['pid' => $pid, 'ptable' => (string) ($record['ptable'] ?? 'tl_article')] + $record;
        unset($copy['id']);

        return $this->has(Permission::EditStructure)
            && !($config['closed'] ?? false) && !($config['notCopyable'] ?? false)
            && $this->isGranted(new CreateAction('tl_content', $copy));
    }

    /**
     * May the user create an element in this article? True when at least one
     * element type may be created there, as Contao's type selection decides.
     */
    public function canCreateIn(int $articleId): bool
    {
        return $this->canCreateInParent('tl_article', $articleId);
    }

    /** The same for any parent of content elements (an article, a news, an event …). */
    public function canCreateInParent(string $ptable, int $pid): bool
    {
        $config = $GLOBALS['TL_DCA']['tl_content']['config'] ?? [];

        if (!$this->has(Permission::EditStructure) || ($config['closed'] ?? false) || ($config['notCreatable'] ?? false)) {
            return false;
        }

        $default = (string) ($GLOBALS['TL_DCA']['tl_content']['fields']['type']['sql']['default'] ?? 'text');
        $types = array_unique([$default, ...array_merge(...array_values(array_map('array_keys', $GLOBALS['TL_CTE'] ?? [])))]);

        foreach ($types as $type) {
            if ($this->isGranted(new CreateAction('tl_content', ['ptable' => $ptable, 'pid' => $pid, 'type' => $type]))) {
                return true;
            }
        }

        return false;
    }

    /** May the user create an article on this page (in this column)? */
    public function canCreateArticle(int $pageId, string $column = 'main'): bool
    {
        $config = $GLOBALS['TL_DCA']['tl_article']['config'] ?? [];

        return $this->has(Permission::CreateArticles)
            && !($config['closed'] ?? false) && !($config['notCreatable'] ?? false)
            && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_article', new CreateAction('tl_article', ['pid' => $pageId, 'inColumn' => $column]));
    }

    private function isGranted(CreateAction|UpdateAction|DeleteAction $action): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_content', $action);
    }
}
