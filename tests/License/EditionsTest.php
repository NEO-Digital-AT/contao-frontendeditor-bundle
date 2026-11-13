<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\License;

use Neo\BasicBundle\License\PublicKeys;
use Neo\BasicBundle\Security\SecretCipher;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditionsTest extends TestCase
{
    use Pages;

    /** @return iterable<string, array{string, bool}> */
    public static function hosts(): iterable
    {
        yield 'localhost' => ['localhost', true];
        yield 'loopback IPv4' => ['127.0.0.1', true];
        yield 'loopback IPv6 in brackets' => ['[::1]', true];
        yield 'subdomain of localhost' => ['shop.localhost', true];
        yield 'test domain' => ['www.example.test', true];
        yield 'local domain, upper case and dot' => ['Website.LOCAL.', true];
        yield 'public domain' => ['www.example.com', false];
        yield 'public domain containing localhost' => ['localhost.example.com', false];
        yield 'public domain ending in test as a word' => ['contest.example.org', false];
        yield 'public domain ending in local as a word' => ['www.vocal', false];
        yield 'another loopback address' => ['127.0.0.2', false];
    }

    #[DataProvider('hosts')]
    public function testDevelopmentHosts(string $host, bool $development): void
    {
        $this->assertSame($development, Editions::isDevelopmentHost($host));
    }

    public function testWhichEditionIncludesWhich(): void
    {
        $this->assertTrue(Editions::includes('', ''));
        $this->assertFalse(Editions::includes('', Editions::PLUS));
        $this->assertFalse(Editions::includes('', Editions::PRO));
        $this->assertTrue(Editions::includes(Editions::PLUS, Editions::PLUS));
        $this->assertFalse(Editions::includes(Editions::PLUS, Editions::PRO));
        $this->assertTrue(Editions::includes(Editions::PRO, Editions::PLUS));
        $this->assertTrue(Editions::includes(Editions::PRO, Editions::PRO));
        $this->assertTrue(Editions::includes(Editions::PRO, ''));
    }

    public function testAWebsiteWithoutLicenceHasNoPaidEdition(): void
    {
        $editions = Licences::editions($this->framework([5 => ['domain' => 'www.example.com']]));

        $this->assertSame('', $editions->edition(5));
        $this->assertFalse($editions->hasPlus(5));
        $this->assertFalse($editions->hasPro(5));
        $this->assertSame('', $editions->forBackend());
    }

    public function testADevelopmentAddressHasEverything(): void
    {
        $editions = Licences::editions($this->framework([5 => ['domain' => 'www.example.test']]));

        $this->assertSame(Editions::PRO, $editions->edition(5));
        $this->assertTrue($editions->hasPlus(5));
        $this->assertTrue($editions->hasPro(5));
    }

    public function testTheDomainOfTheRootPageCountsBeforeTheAddressOfTheRequest(): void
    {
        // The back end runs on a development address, the website does not.
        $editions = Licences::editions($this->framework([5 => ['domain' => 'www.example.com'], 6 => ['domain' => '']]), 'localhost');

        $this->assertSame('', $editions->edition(5));
        // A page whose root has no domain: the address of the request.
        $this->assertSame(Editions::PRO, $editions->edition(6));
        $this->assertSame(Editions::PRO, $editions->edition(99));
        // Settings of the back end follow the address of the back end.
        $this->assertSame(Editions::PRO, $editions->forBackend());
    }

    public function testWithoutDomainAndRequestThereIsNoEdition(): void
    {
        $editions = Licences::editions($this->framework([5 => ['domain' => '']]), null);

        $this->assertSame('', $editions->edition(5));
        $this->assertSame('', $editions->forBackend());
    }

    public function testAKeyWithoutCertificateOrWithAForgedOneGivesNoEdition(): void
    {
        $db = TestDatabase::create('tl_neo_license');
        $cipher = new SecretCipher(Licences::SECRET);
        $db->insert('tl_neo_license', ['module' => Editions::MODULE, 'package' => Editions::PACKAGE, 'license_key' => $cipher->encrypt('NEO-TEST-KEY')]);
        $editions = Licences::editions($this->framework([5 => ['domain' => 'www.example.com']]), 'www.example.com', $db);

        $this->assertSame('', $editions->edition(5));

        // A certificate in the format of the licence service that claims Pro for the domain, with a
        // known key ID but without the service's signature.
        $keyId = (string) array_key_first(PublicKeys::KEYS);
        $payload = rtrim(strtr(base64_encode((string) json_encode(['version' => 1, 'keyId' => $keyId, 'licenseId' => 'L-1', 'module' => Editions::MODULE, 'edition' => Editions::PRO, 'domains' => ['www.example.com'], 'paidUntil' => date(DATE_ATOM, time() + 86400), 'graceUntil' => date(DATE_ATOM, time() + 86400), 'status' => 'active', 'issuedAt' => date(DATE_ATOM)])), '+/', '-_'), '=');
        $db->update('tl_neo_license', ['payload' => $payload, 'signature' => rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '='), 'key_id' => $keyId], ['module' => Editions::MODULE]);

        $this->assertSame('', Licences::editions($this->framework([5 => ['domain' => 'www.example.com']]), 'www.example.com', $db)->edition(5));
        $this->assertSame('', Licences::editions($this->framework(), 'www.example.com', $db)->forBackend());
    }
}
