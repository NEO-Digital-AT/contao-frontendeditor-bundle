<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Neo\BasicBundle\Cache\PageCacheTags;
use Symfony\Component\DependencyInjection\Container;

/**
 * Basic's cache tags over a tag manager that keeps what it was given:
 * $tagged (tags added to the response) and $invalidated (tags whose pages are
 * built again).
 */
final class RecordingCacheTags
{
    /** @var list<string> */
    public array $tagged = [];

    /** @var list<string> */
    public array $invalidated = [];

    public readonly PageCacheTags $service;

    public function __construct()
    {
        $container = new Container();
        $container->set('tag_manager', $this);
        $this->service = new PageCacheTags($container);
    }

    /** @param list<string> $tags */
    public function tagWith(array $tags): void
    {
        array_push($this->tagged, ...$tags);
    }

    /** @param list<string> $tags */
    public function invalidateTags(array $tags): void
    {
        array_push($this->invalidated, ...$tags);
    }
}
