<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Style;

/**
 * A style that cannot be used: the key of the plain text that explains it
 * (translation domain neo_frontend_editor, prefix "style_error."), its
 * parameters and, where known, the line of the entered style.
 */
final class StyleException extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(
        public readonly string $key,
        public readonly array $parameters = [],
        public readonly int|null $sourceLine = null,
        \Throwable|null $previous = null,
    ) {
        parent::__construct($key, 0, $previous);
    }
}
