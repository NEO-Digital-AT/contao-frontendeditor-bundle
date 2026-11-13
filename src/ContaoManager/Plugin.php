<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\HttpKernel\HttpCacheSubscriberPluginInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Neo\BasicBundle\NeoBasicBundle;
use Neo\FrontendEditorBundle\HttpCache\EditModeCookieSubscriber;
use Neo\FrontendEditorBundle\NeoFrontendEditorBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers the bundle after Contao and the shared foundation, its routes, and
 * the subscriber that keeps the editor's switch past the cookie list of the
 * HTTP cache (EditModeCookieSubscriber). The bundle templates extend Contao's
 * preview toolbar, so they must load after the core.
 */
final class Plugin implements BundlePluginInterface, RoutingPluginInterface, HttpCacheSubscriberPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(NeoFrontendEditorBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class, NeoBasicBundle::class, 'Terminal42\ChangeLanguage\Terminal42ChangeLanguageBundle']),
        ];
    }

    public function getHttpCacheSubscribers(): array
    {
        return [new EditModeCookieSubscriber()];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): RouteCollection|null
    {
        $file = __DIR__.'/../../config/routes.yaml';
        $loader = $resolver->resolve($file);

        return false === $loader ? null : $loader->load($file);
    }
}
