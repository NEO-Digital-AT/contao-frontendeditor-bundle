<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\DataContainer;

/**
 * The data container handed to widgets and DCA callbacks when the editor saves
 * a field or asks for the options of a field: the same table, record, field
 * and active record as in the back-end form, without rendering a form.
 */
final class EditorDataContainer extends DataContainer
{
    /**
     * @param array<string, mixed> $record
     */
    public function __construct(string $table, array $record, string $field = '')
    {
        $this->strTable = $table;
        $this->intId = (int) $record['id'];
        $this->strField = $field;
        $this->strInputName = $field;
        $this->objActiveRecord = (object) $record;
    }

    public function forField(string $field): self
    {
        $dc = clone $this;
        $dc->strField = $field;
        $dc->strInputName = $field;

        return $dc;
    }

    /** The record handed in, without asking the database again; other records as usual. */
    public function getCurrentRecord(int|string|null $id = null, string|null $table = null): array|null
    {
        if ((null === $id || (int) $id === (int) $this->intId) && (null === $table || $table === $this->strTable)) {
            return (array) $this->objActiveRecord;
        }

        return parent::getCurrentRecord($id, $table);
    }

    public function getPalette(): string
    {
        return '';
    }

    protected function save($varValue): void
    {
        throw new \LogicException('The editor saves through FieldWriter.');
    }
}
