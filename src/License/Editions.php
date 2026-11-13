<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\License;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Neo\BasicBundle\License\LicenseService;
use Neo\BasicBundle\License\LicenseState;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The paid editions for a website, from Basic's licence check (signed
 * certificate, checked locally):
 *
 * - Plus: language versions with translation, new pages and entries.
 * - Pro: everything of Plus, plus the writing aid and the checks.
 *
 * On a development address (localhost, loopback addresses, *.localhost,
 * *.test, *.local) everything is available, so the extension can be tried
 * out before going live.
 */
final class Editions
{
    public const MODULE = 'frontendeditor';

    public const PACKAGE = 'neo-digital/contao-frontendeditor-bundle';

    /** Name of the extension on Basic's page "Access keys and licences". */
    public const EXTENSION = 'NEO Frontend Editor';

    public const PLUS = 'plus';

    public const PRO = 'pro';

    public function __construct(
        private readonly LicenseService $licenses,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
    ) {
    }

    /** Plus or Pro for the website of the page. */
    public function hasPlus(int $pageId): bool
    {
        return \in_array($this->edition($pageId), [self::PLUS, self::PRO], true);
    }

    public function hasPro(int $pageId): bool
    {
        return self::PRO === $this->edition($pageId);
    }

    /** The edition for the website of a page: its root page's domain, else the address of the current request; '' without licence. */
    public function edition(int $pageId): string
    {
        $page = $this->framework->getAdapter(PageModel::class)->findWithDetails($pageId);
        $host = strtolower(trim((string) ($page?->domain ?: $this->requestStack->getMainRequest()?->getHost()), '[]. '));

        if ('' === $host) {
            return '';
        }

        if (self::isDevelopmentHost($host)) {
            return self::PRO;
        }

        return self::editionOf($this->licenses->state(self::MODULE, $host));
    }

    /**
     * The edition for settings in the back end, which apply to every website
     * of the installation: Pro on a development address, as in the front end;
     * otherwise the licence without the domain check, since the back end may
     * run under another address than the websites; '' without licence.
     */
    public function forBackend(): string
    {
        $host = strtolower(trim((string) $this->requestStack->getMainRequest()?->getHost(), '[]. '));

        if ('' !== $host && self::isDevelopmentHost($host)) {
            return self::PRO;
        }

        return self::editionOf($this->licenses->state(self::MODULE, ''));
    }

    /** Whether an edition ('' without licence) includes the functions of another ('' = free). */
    public static function includes(string $edition, string $required): bool
    {
        return match ($required) {
            '' => true,
            self::PLUS => \in_array($edition, [self::PLUS, self::PRO], true),
            default => $edition === $required,
        };
    }

    private static function editionOf(LicenseState $state): string
    {
        return match (true) {
            $state->hasEdition(self::PRO) => self::PRO,
            $state->hasEdition(self::PLUS) => self::PLUS,
            default => '',
        };
    }

    public static function isDevelopmentHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]. '));

        return \in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }
}
