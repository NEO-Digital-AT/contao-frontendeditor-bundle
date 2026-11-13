<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Design;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\DcaRunner;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Fields of Contao's form generator on the page: a new field after a field,
 * and deleting a field. Carried out by DC_Table in the back-end module "form"
 * with the query the back end would send, so Contao's voters (form, field
 * type, table permissions), callbacks, versions and the undo list apply. A new
 * field is a draft (tstamp 0) until Contao's form saves it; the editor removes
 * it when its form is left without saving.
 */
final class FormFieldEditor
{
    private const PASTE_AFTER = 1;

    public function __construct(
        private readonly DcaRunner $runner,
        private readonly Connection $db,
        private readonly RequestStack $requestStack,
        private readonly ElementAccess $access,
    ) {
    }

    public function createAfter(int $afterId): StructureResult
    {
        $after = $this->field($afterId);

        if (!$this->access->has(Permission::EditDesign)) {
            return StructureResult::forbidden();
        }

        if (null === $after) {
            return StructureResult::notFound();
        }

        $query = ['act' => 'create', 'mode' => self::PASTE_AFTER, 'pid' => $afterId, 'id' => (int) $after['pid']];

        return $this->runner->run('form', 'tl_form_field', $query, function (DC_Table $dc): StructureResult {
            $before = $this->newRecords();

            try {
                $dc->create();
            } catch (RedirectResponseException) {
                // Contao switches to its form after creating; the editor opens that form itself.
            }

            $created = array_values(array_diff($this->newRecords(), $before));

            return [] === $created ? StructureResult::forbidden() : StructureResult::done((int) end($created));
        });
    }

    /** Deletes a field; Contao keeps it in its undo list ("Restore" in the back end). */
    public function delete(int $id): StructureResult
    {
        $field = $this->field($id);

        if (!$this->access->has(Permission::EditDesign)) {
            return StructureResult::forbidden();
        }

        if (null === $field) {
            return StructureResult::notFound();
        }

        return $this->runner->run('form', 'tl_form_field', ['act' => 'delete', 'id' => $id, 'pid' => (int) $field['pid']], static function (DC_Table $dc) use ($id): StructureResult {
            $dc->delete(true);

            return StructureResult::done($id);
        });
    }

    /** @return array<string, mixed>|null a field that is not a draft, in a form that exists */
    private function field(int $id): array|null
    {
        $row = $this->db->fetchAssociative('SELECT f.* FROM tl_form_field f JOIN tl_form t ON t.id = f.pid WHERE f.id = ? AND f.tstamp > 0', [$id]);

        return false === $row ? null : $row;
    }

    /** @return list<int> IDs Contao keeps as "new" in the back-end session (see DC_Table::create) */
    private function newRecords(): array
    {
        $records = $this->requestStack->getSession()->getBag('contao_backend')->get('new_records') ?? [];

        return array_map('intval', $records['tl_form_field'] ?? []);
    }
}
