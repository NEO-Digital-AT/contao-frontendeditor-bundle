<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

/**
 * Outcome of a change to the structure of a page: the record concerned (the
 * new one after creating or duplicating), or why nothing was changed.
 */
final readonly class StructureResult
{
    /**
     * @param array<string, mixed> $data further values for the answer
     */
    private function __construct(
        public WriteStatus $status,
        public int $id = 0,
        public array $data = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function done(int $id, array $data = []): self
    {
        return new self(WriteStatus::Saved, $id, $data);
    }

    public static function notFound(): self
    {
        return new self(WriteStatus::NotFound);
    }

    public static function forbidden(): self
    {
        return new self(WriteStatus::Forbidden);
    }

    public static function invalid(): self
    {
        return new self(WriteStatus::Invalid);
    }
}
