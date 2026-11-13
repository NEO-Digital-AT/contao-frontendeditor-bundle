<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged, as "level: message".
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = $level.': '.$message;
    }
}
