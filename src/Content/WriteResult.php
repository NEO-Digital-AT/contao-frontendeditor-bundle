<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

/**
 * Outcome of saving fields: the saved record, or why nothing was saved.
 */
final readonly class WriteResult
{
    /**
     * @param array<string, mixed>        $record the record after saving
     * @param array<string, list<string>> $errors messages of Contao's widgets by field
     */
    private function __construct(
        public WriteStatus $status,
        public array $record = [],
        public array $errors = [],
    ) {
    }

    /** @param array<string, mixed> $record */
    public static function saved(array $record): self
    {
        return new self(WriteStatus::Saved, $record);
    }

    public static function notFound(): self
    {
        return new self(WriteStatus::NotFound);
    }

    public static function forbidden(): self
    {
        return new self(WriteStatus::Forbidden);
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors): self
    {
        return new self(WriteStatus::Invalid, [], $errors);
    }
}
