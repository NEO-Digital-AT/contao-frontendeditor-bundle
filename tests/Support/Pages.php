<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;

/**
 * A Contao framework whose PageModel adapter knows the given pages (as
 * findWithDetails() returns them: with the domain and language of their root
 * page). Every other adapter does nothing.
 */
trait Pages
{
    /**
     * @param array<int, array<string, mixed>> $pages page ID => details
     */
    private function framework(array $pages = []): ContaoFramework
    {
        $pageAdapter = $this->createStub(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $arguments): object|null => \in_array($method, ['findWithDetails', 'findById', 'findByPk'], true) && isset($pages[(int) $arguments[0]])
                ? (object) ($pages[(int) $arguments[0]] + ['id' => (int) $arguments[0]])
                : null,
        );

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            fn (string $class): Adapter => PageModel::class === $class ? $pageAdapter : $this->createStub(Adapter::class),
        );

        return $framework;
    }
}
