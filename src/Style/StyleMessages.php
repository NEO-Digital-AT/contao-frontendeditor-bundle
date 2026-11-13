<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Style;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The plain text of a style that cannot be used, with its place: "Error in
 * line 4: …", or "Error at the end of the style: …" when the translator only
 * notices it there (a block left open further up).
 */
final class StyleMessages
{
    private const DOMAIN = 'neo_frontend_editor';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function text(StyleException $exception, string|null $locale = null): string
    {
        $parameters = [];

        foreach ($exception->parameters as $name => $value) {
            $parameters['{'.$name.'}'] = (string) $value;
        }

        $key = 'brace_end' === $exception->key ? 'brace' : $exception->key;
        $text = $this->translator->trans('editor.style_error_'.$key, $parameters, self::DOMAIN, $locale);

        if ('brace_end' === $exception->key) {
            return $this->translator->trans('editor.style_error_end', ['{text}' => $text], self::DOMAIN, $locale);
        }

        if (null !== $exception->sourceLine) {
            return $this->translator->trans('editor.style_error_line', ['{n}' => (string) $exception->sourceLine, '{text}' => $text], self::DOMAIN, $locale);
        }

        return $text;
    }
}
