<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\DataContainer;
use Contao\System;
use Doctrine\DBAL\Connection;

/**
 * Gives a page without alias its address with Contao's own alias callbacks
 * (field alias, save_callback), as the back-end form does when the alias
 * field is left empty: from the page name, unique, with folder URLs.
 */
final class PageAlias
{
    public static function ensure(DataContainer $dc, Connection $db): void
    {
        $id = (int) $dc->id;

        if ($id < 1 || '' !== (string) $db->fetchOne('SELECT alias FROM tl_page WHERE id = ?', [$id])) {
            return;
        }

        $value = '';

        foreach ($GLOBALS['TL_DCA']['tl_page']['fields']['alias']['save_callback'] ?? [] as $callback) {
            $value = (string) (\is_array($callback) ? System::importStatic($callback[0])->{$callback[1]}($value, $dc) : $callback($value, $dc));
        }

        if ('' !== $value) {
            $db->update('tl_page', ['alias' => $value], ['id' => $id]);
        }
    }
}
