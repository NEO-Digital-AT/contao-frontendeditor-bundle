<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Neo\FrontendEditorBundle\Backend\OpenableModules;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Modules: the back-end modules the user may open, shown in the sidebar with
 * Contao's own pages and permissions. Plus lists the extensions' menu group;
 * Pro adds the modules of the website and of other extensions (any module that
 * is not one of Contao's own), so that their records can be translated there.
 * "Access keys and licences" stays in the back end.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class ModuleController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly OpenableModules $modules,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Query: page (for the licence). */
    #[Route('/modules', name: 'neo_frontend_editor_modules', methods: ['GET'])]
    public function modules(Request $request): Response
    {
        $page = $request->query->getInt('page');

        if ($page < 1 || !$this->access->has(Permission::EditMode) || !$this->access->has(Permission::OpenModules) || !$this->editions->hasPlus($page)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $this->framework->initialize();
        System::loadLanguageFile('modules');
        $modules = $this->modules->listed($this->editions->hasPro($page));

        $response = new JsonResponse(['success' => true, 'modules' => $modules]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
