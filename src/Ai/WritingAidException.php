<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Ai;

/** Why the writing aid gave no proposals; the reason names the text the editor shows. */
final class WritingAidException extends \RuntimeException
{
    public const FORBIDDEN = 'forbidden';

    public const NOT_CONFIGURED = 'not_configured';

    public const LIMIT_REACHED = 'limit_reached';

    public const UNREACHABLE = 'unreachable';

    public const UNUSABLE = 'unusable';

    private function __construct(public readonly string $reason)
    {
        parent::__construct('Writing aid: '.$reason);
    }

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED);
    }

    public static function limitReached(): self
    {
        return new self(self::LIMIT_REACHED);
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE);
    }

    public static function unusable(): self
    {
        return new self(self::UNUSABLE);
    }
}
