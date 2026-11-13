<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Style;

use Neo\FrontendEditorBundle\Style\StyleCompiler;
use Neo\FrontendEditorBundle\Style\StyleException;
use Neo\FrontendEditorBundle\Style\StyleMessages;
use Neo\FrontendEditorBundle\Tests\Support\EditorTranslations;
use PHPUnit\Framework\TestCase;

final class StyleMessagesTest extends TestCase
{
    public function testTheMessageNamesTheLine(): void
    {
        $text = (new StyleMessages(EditorTranslations::translator()))->text(new StyleException('variable', ['name' => '$accent'], 4));

        $this->assertSame('Error in line 4: The variable $accent is not defined.', $text);
    }

    public function testABlockLeftOpenIsNoticedAtTheEnd(): void
    {
        $text = (new StyleMessages(EditorTranslations::translator()))->text(new StyleException('brace_end', [], 9));

        $this->assertSame('Error at the end of the style: A closing brace "}" is missing; a block further up is not closed.', $text);
    }

    public function testWithoutLineTheTextAlone(): void
    {
        $text = (new StyleMessages(EditorTranslations::translator()))->text(new StyleException('too_large', ['n' => 40, 'max' => 32]));

        $this->assertSame('The style is too large (40 KB, at most 32 KB). Extensive styles belong in the style sheet of the layout.', $text);
    }

    public function testInTheLanguageOfTheUser(): void
    {
        $text = (new StyleMessages(EditorTranslations::translator('de')))->text(new StyleException('import', [], 1), 'de');

        $this->assertStringStartsWith('Fehler in Zeile 1: ', $text);
    }

    public function testEveryErrorOfTheCompilerHasAText(): void
    {
        $messages = new StyleMessages(EditorTranslations::translator());
        $sources = ['@import "x";', '.a{background:url(https://example.com/a.png)}', ".a {\n color: \$nope;\n}", ".a {\n", ".a{}\n}", '.a{@include nope;}', str_repeat('/* x */', 20000)];

        foreach ($sources as $source) {
            try {
                (new StyleCompiler())->compile($source);
                $this->fail('Accepted: '.$source);
            } catch (StyleException $exception) {
                $this->assertStringNotContainsString('editor.style_error', $messages->text($exception), $exception->key);
            }
        }
    }
}
