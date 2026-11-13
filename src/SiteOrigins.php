<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The addresses (origins) under which the website runs: the domains of the
 * root pages with their protocol, and the address of the current request.
 * Only these may embed the editor's back-end pages and call its endpoints
 * when the back end runs under its own address.
 */
final class SiteOrigins
{
    /** @var list<string>|null */
    private array|null $origins = null;

    public function __construct(
        private readonly Connection $db,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<string> */
    public function all(): array
    {
        if (null === $this->origins) {
            $origins = [];

            try {
                foreach ($this->db->fetchAllAssociative("SELECT dns, useSSL FROM tl_page WHERE type = 'root' AND dns <> ''") as $root) {
                    $origins[] = ($root['useSSL'] ? 'https://' : 'http://').strtolower(trim((string) $root['dns']));
                }
            } catch (\Throwable) {
                // No pages yet: only the current address.
            }

            $request = $this->requestStack->getMainRequest();

            if (null !== $request) {
                $origins[] = strtolower($request->getSchemeAndHttpHost());
            }

            $this->origins = array_values(array_unique($origins));
        }

        return $this->origins;
    }

    public function allows(string $origin): bool
    {
        return \in_array(strtolower(rtrim($origin, '/')), $this->all(), true);
    }
}
