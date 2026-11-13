<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests;

use Neo\FrontendEditorBundle\SiteOrigins;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class SiteOriginsTest extends TestCase
{
    public function testTheDomainsOfTheRootPagesAndTheCurrentAddress(): void
    {
        $db = TestDatabase::create('tl_page');
        $db->insert('tl_page', ['type' => 'root', 'dns' => 'www.Example.com', 'useSSL' => '1']);
        $db->insert('tl_page', ['type' => 'root', 'dns' => 'shop.example.org', 'useSSL' => '']);
        $db->insert('tl_page', ['type' => 'root', 'dns' => '']);
        $db->insert('tl_page', ['type' => 'regular', 'dns' => 'not.a.root.example.net', 'useSSL' => '1']);

        $origins = new SiteOrigins($db, Licences::stack('manage.example.com'));

        $this->assertSame(['https://www.example.com', 'http://shop.example.org', 'https://manage.example.com'], $origins->all());
        $this->assertTrue($origins->allows('https://www.example.com/'));
        $this->assertTrue($origins->allows('HTTP://shop.example.org'));
        $this->assertFalse($origins->allows('http://www.example.com'), 'another protocol');
        $this->assertFalse($origins->allows('https://www.example.com.evil.example'));
        $this->assertFalse($origins->allows('https://not.a.root.example.net'));
        $this->assertFalse($origins->allows('null'));
    }

    public function testWithoutPagesOnlyTheCurrentAddress(): void
    {
        $origins = new SiteOrigins(TestDatabase::create(), Licences::stack('www.example.com'));

        $this->assertSame(['https://www.example.com'], $origins->all());
        $this->assertSame([], (new SiteOrigins(TestDatabase::create(), Licences::stack(null)))->all());
    }
}
