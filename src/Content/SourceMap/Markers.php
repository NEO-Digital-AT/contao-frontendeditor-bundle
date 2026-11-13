<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content\SourceMap;

/**
 * Invisible markers around the values of text fields, for the source map of
 * the edit mode: characters of Unicode's private use plane 16 that no text of
 * a website contains. A value becomes OPEN n END value CLOSE n END, n the
 * number of the value in its record, in digits of the same plane.
 *
 * Markers exist only in a detached copy of a record while it is rendered a
 * second time for the editor (SourceMapRenderer). In the rendered text they
 * become HTML comments with the source of the value; everywhere else (tags,
 * attributes, scripts, styles, comments) they are removed. Nothing with a
 * marker is saved (strip() before every write) or delivered.
 */
final class Markers
{
    private const OPEN = "\u{10FF01}";

    private const CLOSE = "\u{10FF02}";

    private const END = "\u{10FF03}";

    /** Every character of the markers. */
    private const ANY = '/[\x{10FF00}-\x{10FF1F}]/u';

    /** Raw text (not markup) of these elements is not a place for comments. */
    private const TOKENS = '/<!--.*?-->|<(script|style|textarea|title)\b[^>]*>.*?<\/\1\s*>|<[!?\/]?[a-zA-Z][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>/si';

    public static function wrap(string $value, int $number): string
    {
        $digits = self::digits($number);

        return self::OPEN.$digits.self::END.$value.self::CLOSE.$digits.self::END;
    }

    public static function strip(string $value): string
    {
        return str_contains($value, "\xF4\x8F\xBC") ? (string) preg_replace(self::ANY, '', $value) : $value;
    }

    public static function contains(string $value): bool
    {
        return str_contains($value, "\xF4\x8F\xBC") && 1 === preg_match(self::ANY, $value);
    }

    /**
     * The rendered HTML with the markers in text as comments <!--nfe:f:KEY-->
     * and <!--/nfe:f:KEY--> (KEY from $keys by number) and every other marker
     * removed. Also returns the keys whose value was found in text.
     *
     * @param array<int, string> $keys
     *
     * @return array{html: string, found: list<string>}
     */
    public static function convert(string $html, array $keys): array
    {
        $output = '';
        $offset = 0;
        $found = [];

        preg_match_all(self::TOKENS, $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$token, $position]) {
            $output .= self::text(substr($html, $offset, $position - $offset), $keys, $found);
            $output .= self::strip($token);
            $offset = $position + \strlen($token);
        }

        $output .= self::text(substr($html, $offset), $keys, $found);

        return ['html' => $output, 'found' => array_values(array_map('strval', array_keys(array_filter($found, static fn (int $count): bool => $count >= 2))))];
    }

    /** @param array<string, int> $found */
    private static function text(string $text, array $keys, array &$found): string
    {
        if (!str_contains($text, "\xF4\x8F\xBC")) {
            return $text;
        }

        $text = (string) preg_replace_callback('/(\x{10FF01}|\x{10FF02})([\x{10FF10}-\x{10FF19}]+)\x{10FF03}/u', static function (array $match) use ($keys, &$found): string {
            $key = $keys[self::number($match[2])] ?? null;

            if (null === $key) {
                return '';
            }

            $found[$key] = ($found[$key] ?? 0) + 1;

            return self::OPEN === $match[1] ? '<!--nfe:f:'.$key.'-->' : '<!--/nfe:f:'.$key.'-->';
        }, $text);

        return self::strip($text);
    }

    private static function digits(int $number): string
    {
        return implode('', array_map(static fn (string $digit): string => mb_chr(0x10FF10 + (int) $digit, 'UTF-8'), str_split((string) $number)));
    }

    private static function number(string $digits): int
    {
        return (int) implode('', array_map(static fn (string $char): string => (string) (mb_ord($char, 'UTF-8') - 0x10FF10), mb_str_split($digits, 1, 'UTF-8')));
    }
}
