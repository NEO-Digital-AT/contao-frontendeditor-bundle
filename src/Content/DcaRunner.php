<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\Controller;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\NotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DC_Table;
use Contao\Input;
use Contao\System;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Runs one of DC_Table's operations (create, cut, copy, toggle, delete) with
 * the query parameters the back end would send, in the context of a back-end
 * module: defaults, sorting, callbacks, versions, the undo list and Contao's
 * voters work as in the back end.
 */
final class DcaRunner
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    /**
     * @param string                          $module    back-end module, e.g. "article", "page", "news"
     * @param array<string, int|string>       $query
     * @param \Closure(DC_Table): StructureResult $operation
     * @param string|null                     $ptable    parent table of a table with a dynamic parent (tl_content)
     */
    public function run(string $module, string $table, array $query, \Closure $operation, string|null $ptable = null): StructureResult
    {
        $this->framework->initialize();
        System::loadLanguageFile('default');
        System::loadLanguageFile($table);

        foreach (['do' => $module, 'table' => $table] + $query as $key => $value) {
            Input::setGet($key, (string) $value);
        }

        Controller::loadDataContainer($table);

        if (null !== $ptable) {
            $GLOBALS['TL_DCA'][$table]['config']['ptable'] = $ptable;
        }

        $config = self::moduleConfig($module);

        try {
            return $operation(new DC_Table($table, $config));
        } catch (AccessDeniedException) {
            return StructureResult::forbidden();
        } catch (NotFoundException) {
            return StructureResult::notFound();
        } catch (UnprocessableEntityHttpException) {
            return StructureResult::invalid();
        }
    }

    /**
     * Configuration of a back-end module, from any menu group.
     *
     * @return array<string, mixed>
     */
    public static function moduleConfig(string $module): array
    {
        foreach ($GLOBALS['BE_MOD'] ?? [] as $modules) {
            if (isset($modules[$module])) {
                return $modules[$module];
            }
        }

        return [];
    }
}
