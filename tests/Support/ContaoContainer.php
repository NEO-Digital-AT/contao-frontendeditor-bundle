<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The few services and parameters Contao's static helpers take from the
 * container (Config, Input, Widget, Versions), for a back-end request, plus the global
 * state of ContaoState. tearDownContao() removes both.
 */
trait ContaoContainer
{
    /** With $db, Contao's models read the column types from that database (Model::convertToPhpValue()). */
    private function setUpContao(Request|null $request = null, Connection|null $db = null): RequestStack
    {
        $stack = new RequestStack();

        if (null !== $request) {
            $stack->push($request);
        }

        $matcher = $this->createStub(ScopeMatcher::class);
        $matcher->method('isBackendRequest')->willReturn(true);
        $matcher->method('isFrontendRequest')->willReturn(false);

        // No configuration files of other bundles and no local configuration (Config::getInstance()).
        $locator = $this->createStub(FileLocatorInterface::class);
        $locator->method('locate')->willThrowException(new \InvalidArgumentException('No files in the tests.'));

        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('contao.routing.scope_matcher', $matcher);
        $container->set('contao.resource_locator', $locator);
        $container->set('database_connection', $db ?? TestDatabase::create());
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir().'/neo-frontend-editor-tests');
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir().'/neo-frontend-editor-tests/cache');
        $container->setParameter('contao.sanitizer.allowed_url_protocols', ['http', 'https', 'ftp', 'mailto', 'tel', 'data', 'skype', 'whatsapp']);

        System::setContainer($container);
        ContaoState::forRequest();
        ContaoState::defaultConfig();
        ContaoState::contaoTexts('ERR');
        ContaoState::contaoTexts('MSC');
        ContaoState::language('default');

        return $stack;
    }

    /**
     * A page as Contao's model, detached from the database (for code that
     * takes a PageModel, e.g. with the trail of findWithDetails()).
     *
     * @param array<string, mixed> $data
     */
    private function pageModel(array $data): PageModel
    {
        $page = (new \ReflectionClass(PageModel::class))->newInstanceWithoutConstructor();

        foreach ($data as $key => $value) {
            $page->$key = $value;
        }

        return $page;
    }

    private function tearDownContao(): void
    {
        ContaoState::clear();
        System::setContainer(new Container());
    }
}
