<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Style;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\Exception\SassException;
use ScssPhp\ScssPhp\OutputStyle;

/**
 * Turns the style of a page (CSS or SCSS) into the CSS that visitors get,
 * with scssphp, which Contao itself requires. Without import paths it reads no
 * file of the server; @import, @use and @forward are refused before. The CSS
 * may only point to this website (relative paths, /files/…) or contain data:
 * URIs: an address of another server would pass the visitors' data on without
 * their consent. "<" is escaped, so the CSS cannot end its <style> element.
 */
final class StyleCompiler
{
    /** Largest CSS a page may carry inline in its HTML. */
    public const MAX_BYTES = 32 * 1024;

    /** Largest style the editor sends for a preview or the form saves. */
    public const MAX_SOURCE_BYTES = 128 * 1024;

    /** Frequent messages of scssphp and the key of their plain text. */
    private const MESSAGES = [
        'expected "}".' => 'brace',
        'Undefined variable.' => 'variable',
        'Undefined mixin.' => 'mixin',
        'expected ";".' => 'semicolon',
        'expected ":".' => 'colon',
        'Expected identifier.' => 'syntax',
        'expected selector.' => 'syntax',
        'unmatched "}".' => 'unmatched',
    ];

    /**
     * @throws StyleException
     */
    public function compile(string $source): string
    {
        if ('' === trim($source)) {
            return '';
        }

        if (\strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new StyleException('too_large', ['n' => (int) ceil(\strlen($source) / 1024), 'max' => (int) (self::MAX_BYTES / 1024)]);
        }

        if (preg_match('/@(import|use|forward)\b/i', $source, $match, PREG_OFFSET_CAPTURE)) {
            throw new StyleException('import', [], self::lineAt($source, (int) $match[0][1]));
        }

        $compiler = new Compiler();
        $compiler->setImportPaths([]);
        $compiler->setOutputStyle(OutputStyle::COMPRESSED);
        $compiler->setCharset(false);

        try {
            $css = trim($compiler->compileString($source)->getCss());
        } catch (SassException $exception) {
            // scssphp 1.x (Contao 5.3 and 5.4) has neither the message without position nor the span.
            if (!\is_callable([$exception, 'getSpan'])) {
                throw self::fromVersion1($exception);
            }

            $message = $exception->getOriginalMessage();
            $span = $exception->getSpan();
            $line = $span->getStart()->getLine() + 1;
            $key = self::MESSAGES[$message] ?? null;

            // A missing "}" is noticed at the end of the style; the open block is further up.
            if ('brace' === $key && $span->getStart()->getOffset() >= \strlen(rtrim($source)) - 1) {
                throw new StyleException('brace_end', [], $line, $exception);
            }

            if (null !== $key) {
                throw new StyleException($key, ['name' => $span->getText()], $line, $exception);
            }

            throw new StyleException('other', ['message' => $message], $line, $exception);
        }

        if ($url = self::foreignAddress($css)) {
            throw new StyleException('address', ['url' => $url], self::lineOf($source, $url));
        }

        if (\strlen($css) > self::MAX_BYTES) {
            throw new StyleException('too_large', ['n' => (int) ceil(\strlen($css) / 1024), 'max' => (int) (self::MAX_BYTES / 1024)]);
        }

        // "<" never has a meaning in CSS outside strings; in strings \3c is the same character.
        return str_replace('<', '\3c ', $css);
    }

    /**
     * The first address in the CSS that points to another server: in url(…),
     * in image-set(…) or as a string, with a scheme (https:, http:, ftp: …) or
     * starting with "//". CSS escapes are resolved first.
     */
    public static function foreignAddress(string $css): string|null
    {
        $plain = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static fn (array $m): string => mb_chr((int) hexdec($m[1])) ?: '', $css);
        $plain = (string) preg_replace('/\\\\(.)/s', '$1', $plain);

        if (preg_match('~(?:url\(\s*|["\'])\s*((?:[a-z][a-z0-9+.\-]*:)?//[^\s"\')]*)~i', $plain, $match)) {
            return $match[1];
        }

        // url(…) with a scheme other than data: (e.g. url(https:example.com) or url(javascript:…)).
        if (preg_match('~url\(\s*["\']?\s*((?!data:)[a-z][a-z0-9+.\-]*:[^\s"\')]*)~i', $plain, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * The same plain texts for the messages of scssphp 1.x, which carry the
     * line in the message: "parse error: failed at `…` (stdin) on line 2, at
     * column 2", "Undefined variable $nope: line: 2, column: 2".
     */
    private static function fromVersion1(\Throwable $previous): StyleException
    {
        $message = $previous->getMessage();
        $line = preg_match('/(?:on line |line: )(\d+)/', $message, $match) ? (int) $match[1] : null;
        $text = trim((string) preg_replace('/(?:: failed at `.*| on line \d+.*|: line: \d+.*)$/s', '', $message));

        if (preg_match('/^Undefined (variable|mixin) (\S+)$/', $text, $match)) {
            return new StyleException($match[1], ['name' => $match[2]], $line, $previous);
        }

        $key = ['unclosed block' => 'brace_end', 'unexpected }' => 'unmatched', 'parse error' => 'syntax'][$text] ?? null;

        // scssphp 1.x names the line after a closing brace too many (its position after the brace).
        if ('unmatched' === $key && null !== $line) {
            $line = max(1, $line - 1);
        }

        if (null !== $key) {
            return new StyleException($key, [], $line, $previous);
        }

        return new StyleException('other', ['message' => $text], $line, $previous);
    }

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    private static function lineOf(string $source, string $needle): int|null
    {
        $offset = strpos($source, $needle);

        return false === $offset ? null : self::lineAt($source, $offset);
    }
}
