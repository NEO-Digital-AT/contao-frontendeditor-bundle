<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\StringUtil;

/**
 * The hash links stored before SourceHash: headline (text only, not the level),
 * rich text, alternative text and caption. Only SourceHashMigration uses it, to
 * convert the links that were current.
 */
final class ContentHash
{
    public const FIELDS = ['headline', 'text', 'alt', 'caption'];

    /** @param array<string, mixed> $row */
    public static function legacy(array $row): string
    {
        $headline = StringUtil::deserialize($row['headline'] ?? '', true);
        $values = [
            'headline' => trim((string) ($headline['value'] ?? '')),
            'text' => trim((string) ($row['text'] ?? '')),
            'alt' => trim((string) ($row['alt'] ?? '')),
            'caption' => trim((string) ($row['caption'] ?? '')),
        ];

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
