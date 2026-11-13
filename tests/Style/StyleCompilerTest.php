<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Style;

use Neo\FrontendEditorBundle\Style\StyleCompiler;
use Neo\FrontendEditorBundle\Style\StyleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The compiler runs with the scssphp of the installation: 1.x with Contao 5.3
 * and 5.4, 2.x from Contao 5.5 on. The plain texts are the same with both.
 */
final class StyleCompilerTest extends TestCase
{
    public function testAnEmptyStyleIsNoCss(): void
    {
        $this->assertSame('', (new StyleCompiler())->compile(" \n\t"));
    }

    public function testScssBecomesCompressedCss(): void
    {
        $css = (new StyleCompiler())->compile("\$accent: #c00;\n.teaser {\n  h2 { color: \$accent; }\n  &:hover { color: darken(\$accent, 10%); }\n}\n");

        $this->assertSame('.teaser h2{color:#c00}.teaser:hover{color:#900}', $css);
    }

    public function testPlainCssStaysWhatItIs(): void
    {
        $this->assertSame('.a{margin:0 auto}.a>.b,.c{color:red}', (new StyleCompiler())->compile(".a { margin: 0 auto; }\n.a > .b, .c { color: red; }"));
    }

    /** @return iterable<string, array{string, int}> */
    public static function imports(): iterable
    {
        yield 'import' => ["\n\n@import 'secrets';", 3];
        yield 'use' => ['@use "sass:math";', 1];
        yield 'forward, upper case' => [".a{}\n@FORWARD 'x';", 2];
    }

    #[DataProvider('imports')]
    public function testNoFileOfTheServerIsRead(string $source, int $line): void
    {
        $exception = $this->failure($source);

        $this->assertSame('import', $exception->key);
        $this->assertSame($line, $exception->sourceLine);
    }

    /** @return iterable<string, array{string, string}> */
    public static function foreignAddresses(): iterable
    {
        yield 'https in url()' => ['.a{background:url(https://tracker.example.com/p.gif)}', 'https://tracker.example.com/p.gif'];
        yield 'quoted http' => [".a{background:url('http://example.com/a.png')}", 'http://example.com/a.png'];
        yield 'protocol-relative' => ['.a{background:url(//example.com/a.png)}', '//example.com/a.png'];
        yield 'in image-set' => ['.a{background-image:image-set("https://example.com/a.png" 1x)}', 'https://example.com/a.png'];
        yield 'font face' => ["@font-face{font-family:x;src:url('https://fonts.example.com/x.woff2')}", 'https://fonts.example.com/x.woff2'];
        yield 'escaped scheme' => ['.a{background:url("\\68 ttps://example.com/a.png")}', 'https://example.com/a.png'];
        yield 'javascript' => ['.a{background:url("javascript:alert(1)")}', 'javascript:alert(1'];
        yield 'scheme without slashes' => ['.a{background:url(https:example.com/a.png)}', 'https:example.com/a.png'];
    }

    #[DataProvider('foreignAddresses')]
    public function testAddressesOfOtherServersAreRefused(string $source, string $url): void
    {
        $exception = $this->failure($source);

        $this->assertSame('address', $exception->key);
        $this->assertSame($url, $exception->parameters['url']);
    }

    public function testTheLineOfAForeignAddressIsNamed(): void
    {
        $exception = $this->failure(".a { color: red; }\n.b {\n  background: url(https://example.com/a.png);\n}");

        $this->assertSame(3, $exception->sourceLine);
    }

    public function testAddressesOfThisWebsiteAndDataUrisAreAllowed(): void
    {
        $css = (new StyleCompiler())->compile(".a{background:url(/files/theme/a.png)}\n.b{background:url('../img/b.svg')}\n.c{background:url(data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=)}\n.d::after{content:\"see https://example.com\"}");

        $this->assertStringContainsString('url(/files/theme/a.png)', $css);
        $this->assertStringContainsString('data:image/svg+xml', $css);
    }

    public function testTheCssCannotEndItsStyleElement(): void
    {
        $css = (new StyleCompiler())->compile('.a::after { content: "</style><script>alert(1)</script>"; }');

        $this->assertStringNotContainsString('<', $css);
        $this->assertStringNotContainsString('</style', strtolower($css));
        $this->assertStringContainsString('\3c /style>', $css);
    }

    public function testTooLargeStylesAreRefused(): void
    {
        $source = $this->failure(str_repeat('/* padding */', 11000));
        $this->assertSame('too_large', $source->key);
        $this->assertSame(32, $source->parameters['max']);

        // Small source, large CSS: a loop.
        $css = $this->failure('@for $i from 1 through 3000 { .item-#{$i} { width: #{$i}px; } }');
        $this->assertSame('too_large', $css->key);
        $this->assertGreaterThan(32, $css->parameters['n']);
    }

    /** @return iterable<string, array{string, string, int|null}> */
    public static function errors(): iterable
    {
        yield 'unknown variable' => [".a {\n  color: \$nope;\n}", 'variable', 2];
        yield 'unknown mixin' => [".a {\n  @include nope;\n}", 'mixin', 2];
        yield 'block left open' => [".a {\n  color: red;\n", 'brace_end', null];
        yield 'closing brace too many' => [".a { color: red; }\n}", 'unmatched', 2];
        yield 'closing brace too many, first line' => ["}\n.a { color: red; }", 'unmatched', 1];
        yield 'closing brace too many, nested' => [".a {\n  b {\n  }\n  }\n}", 'unmatched', 5];
    }

    #[DataProvider('errors')]
    public function testErrorsOfTheTranslatorAreNamedInPlainTextWithTheirLine(string $source, string $key, int|null $line): void
    {
        $exception = $this->failure($source);

        $this->assertSame($key, $exception->key);

        if (null !== $line) {
            $this->assertSame($line, $exception->sourceLine);
        }
    }

    public function testForeignAddressDetectionOnItsOwn(): void
    {
        $this->assertNull(StyleCompiler::foreignAddress('.a{background:url(/a.png)}'));
        $this->assertNull(StyleCompiler::foreignAddress('.a{background:url(data:image/png;base64,AAAA)}'));
        $this->assertSame('ftp://example.com/x', StyleCompiler::foreignAddress('.a{background:url(ftp://example.com/x)}'));
    }

    private function failure(string $source): StyleException
    {
        try {
            (new StyleCompiler())->compile($source);
        } catch (StyleException $exception) {
            return $exception;
        }

        $this->fail('The style was accepted: '.$source);
    }
}
