<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Controller\AbstractBackendController as LegacyBackendController;
use Contao\CoreBundle\Controller\Backend\AbstractBackendController as BackendControllerSince57;

/*
 * Base of the editor's back-end pages: Contao's controller for back-end pages
 * with the context of "be_main". Contao 5.7 moved it to Controller\Backend
 * (with the Twig template be_main.html.twig); Contao 5.3 to 5.6 have it in
 * Controller (with the template be_main.html5). The page itself extends the
 * template that exists (picker.html.twig).
 */
if (class_exists(BackendControllerSince57::class)) {
    /** @internal */
    abstract class BackendController extends BackendControllerSince57
    {
    }
} else {
    /** @internal */
    abstract class BackendController extends LegacyBackendController
    {
    }
}
