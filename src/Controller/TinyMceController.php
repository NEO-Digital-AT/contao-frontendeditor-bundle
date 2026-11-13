<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\Backend;
use Contao\BackendTemplate;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Picker\PickerBuilderInterface;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contao's configuration of its rich text editor (TinyMCE) for a field, as the
 * back-end form writes it: the template the field's "rte" names (be_tinyMCE,
 * be_tinyNews …, the website's own version of it included), rendered with the
 * same variables as in DataContainer::row() and handed out as a script. The
 * editor loads it into the page next to Contao's TinyMCE and starts the editor
 * inline on the text where the page shows it.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/tinymce', name: 'neo_frontend_editor_tinymce', defaults: ['_scope' => 'backend'], methods: ['GET'])]
final class TinyMceController
{
    public function __construct(
        private readonly ElementAccess $access,
        private readonly ContaoFramework $framework,
        private readonly PickerBuilderInterface $pickerBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $table = (string) $request->query->get('table', '');
        $field = (string) $request->query->get('field', '');
        $selector = (string) $request->query->get('selector', '');

        if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^\w+$/', $field) || !preg_match('/^[a-z0-9_-]+$/', $selector)) {
            return ErrorResponse::badRequest($this->logger);
        }

        if (!$this->access->has(Permission::EditContent)) {
            return ErrorResponse::forbidden($this->logger);
        }

        try {
            $this->framework->initialize();
            $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
        } catch (\Throwable) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'][$field])) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->access->canEditField($field, $table)) {
            return ErrorResponse::forbidden($this->logger);
        }

        [$file, $type] = explode('|', (string) ($GLOBALS['TL_DCA'][$table]['fields'][$field]['eval']['rte'] ?? ''), 2) + ['', null];

        // Without Contao's rich text editor (System › Settings) or for a field without it, the editor's own toolbar.
        if (!$this->framework->getAdapter(Config::class)->get('useRTE') || !str_starts_with($file, 'tiny') || !preg_match('/^\w+$/', $file)) {
            return ErrorResponse::notFound($this->logger);
        }

        $fileBrowserTypes = [];

        foreach (['file' => 'image', 'link' => 'file'] as $context => $fileBrowserType) {
            if ($this->pickerBuilder->supportsContext($context)) {
                $fileBrowserTypes[] = $fileBrowserType;
            }
        }

        $template = new BackendTemplate('be_'.$file);
        $template->selector = $selector;
        $template->type = $type;
        $template->fileBrowserTypes = implode(' ', $fileBrowserTypes);
        $template->source = $table.'.'.$request->query->getInt('id');
        $template->readonly = false;
        $template->theme = Backend::getTheme();
        $template->enableTinyMce = true;
        $template->tinyMceLanguage = Backend::getTinyMceLanguage();

        // The template writes a script element; its content is the configuration.
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $template->parse(), $scripts);

        $response = new Response(implode("\n", $scripts[1]), Response::HTTP_OK, ['Content-Type' => 'text/javascript; charset=UTF-8']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
