<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\SourceHash;

/**
 * Keeps the links between the languages (Pro) right when elements are changed
 * in the back end: saving an element of the main language marks its
 * translations as outdated when its texts changed; deleting an element removes
 * its links.
 */
final class LinkCallbacks
{
    public function __construct(
        private readonly Connection $db,
        private readonly ElementLinks $links,
        private readonly SourceHash $hashes,
    ) {
    }

    #[AsCallback(table: 'tl_content', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $record = $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [(int) $dc->id]);

        try {
            if (false !== $record) {
                $this->links->refreshOutdated((int) $dc->id, $this->hashes->of($record));
            }
        } catch (TableNotFoundException) {
            // Before the database update there are no links yet.
        }
    }

    #[AsCallback(table: 'tl_content', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc): void
    {
        try {
            $this->links->removeElement((int) $dc->id);
        } catch (TableNotFoundException) {
            // Before the database update there are no links yet.
        }
    }
}
