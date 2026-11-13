<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Seo;

use Neo\FrontendEditorBundle\Seo\FocusKeyword;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class FocusKeywordTest extends TestCase
{
    public function testATermIsOneLineWithoutMarkupAndOfLimitedLength(): void
    {
        $this->assertSame('garden chairs', FocusKeyword::clean("  garden \n\t chairs "));
        $this->assertSame('garden chairs', FocusKeyword::clean('<b>garden</b> <em>chairs</em>'));
        $this->assertSame(128, mb_strlen(FocusKeyword::clean(str_repeat('ü', 200))));
    }

    public function testTheTermOfAPageIsSetChangedAndRemoved(): void
    {
        $db = TestDatabase::create('tl_neo_fe_seo');
        $keywords = new FocusKeyword($db, $this->createStub(Security::class));

        $this->assertSame('', $keywords->get(3));

        $keywords->set(3, ' garden  chairs ');
        $this->assertSame('garden chairs', $keywords->get(3));

        $keywords->set(3, 'terrace chairs');
        $this->assertSame('terrace chairs', $keywords->get(3));
        $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM tl_neo_fe_seo'));

        $keywords->set(3, '<br>');
        $this->assertSame('', $keywords->get(3));
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM tl_neo_fe_seo'));
    }

    public function testBeforeTheDatabaseUpdateThereIsNoTerm(): void
    {
        $keywords = new FocusKeyword(TestDatabase::create(), $this->createStub(Security::class));

        $this->assertSame('', $keywords->get(3));
        $this->assertSame([], $keywords->usedOn(3, 'chairs'));
        $this->assertSame([], $keywords->usedOn(3, ' '));
    }
}
