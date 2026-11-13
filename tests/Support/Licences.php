<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\License\LicenseService;
use Neo\BasicBundle\Security\SecretCipher;
use Neo\FrontendEditorBundle\License\Editions;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Basic's licence check over a database without a valid certificate: a
 * website has no paid edition, except on a development address (Editions).
 * A certificate signed by the licence service cannot be made in a test.
 */
final class Licences
{
    public const SECRET = 'app-secret-for-tests';

    public static function editions(ContaoFramework $framework, string|null $host = 'www.example.com', Connection|null $db = null): Editions
    {
        $stack = self::stack($host);

        return new Editions(self::service($stack, $db), $stack, $framework);
    }

    public static function service(RequestStack $stack, Connection|null $db = null): LicenseService
    {
        return new LicenseService($db ?? TestDatabase::create('tl_neo_license'), new SecretCipher(self::SECRET), new MockHttpClient(), $stack, new NullLogger(), 'https://licence.example.com');
    }

    public static function stack(string|null $host): RequestStack
    {
        $stack = new RequestStack();

        if (null !== $host) {
            $stack->push(Request::create('https://'.$host.'/contao'));
        }

        return $stack;
    }
}
