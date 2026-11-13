<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Backend;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Neo\BasicBundle\Access\AccessPage;
use Neo\FrontendEditorBundle\Settings;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The back-end modules the editor offers in its sidebar: the extensions' menu
 * group, and with $own also the modules of the website and of other
 * extensions (any module that is not one of Contao's own). Only modules the
 * user may open; never the access page and the settings of this editor.
 */
final class OpenableModules
{
    /** The extensions' menu group. */
    public const GROUP = 'neo';

    /** Contao's own back-end modules (core and its bundles): edited in Contao's back end, not listed here. */
    private const CONTAO_MODULES = [
        'page', 'article', 'files', 'form', 'themes', 'tpl_editor', 'member', 'mgroup', 'user', 'group', 'login', 'security',
        'favorites', 'settings', 'maintenance', 'log', 'preview_link', 'opt_in', 'undo', 'jobs', 'debug',
        'news', 'calendar', 'faq', 'newsletter', 'comments',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    /**
     * Expects Contao's language file "modules" loaded.
     *
     * @return list<array{id: string, label: string, description: string, group: string}> group: '' for the extensions' group, else its name
     */
    public function listed(bool $own): array
    {
        $modules = [];

        foreach ($GLOBALS['BE_MOD'] ?? [] as $group => $entries) {
            if (self::GROUP !== $group && !$own) {
                continue;
            }

            foreach (array_keys((array) $entries) as $module) {
                $module = (string) $module;

                if (\in_array($module, self::CONTAO_MODULES, true) || AccessPage::MODULE === $module || Settings::MODULE === $module || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module)) {
                    continue;
                }

                $label = (array) ($GLOBALS['TL_LANG']['MOD'][$module] ?? []);
                $modules[] = [
                    'id' => $module,
                    'label' => (string) ($label[0] ?? $module),
                    'description' => (string) ($label[1] ?? ''),
                    'group' => self::GROUP === $group ? '' : (string) (((array) ($GLOBALS['TL_LANG']['MOD'][$group] ?? []))[0] ?? $group),
                ];
            }
        }

        return $modules;
    }
}
