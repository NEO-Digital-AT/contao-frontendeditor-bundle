<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Contao's clipboard for the page tree in create mode ("New" of the page
 * list), on every supported Contao version: through Contao's ClipboardManager
 * from Contao 5.5 on, before that in the session key Contao's DC_Table reads
 * ("CLIPBOARD", the same structure).
 */
final class PageTreeClipboard
{
    public const MODE_CREATE = 'create';

    private const SESSION_KEY = 'CLIPBOARD';

    /**
     * @param object|null $manager Contao\CoreBundle\DataContainer\ClipboardManager (Contao 5.5+)
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly object|null $manager = null,
    ) {
    }

    /** The table in create mode, as Contao's button "New" sets it. */
    public function startCreate(string $table): void
    {
        if (null !== $this->manager) {
            $this->manager->set($table, null, null, self::MODE_CREATE);

            return;
        }

        $session = $this->requestStack->getSession();
        $clipboard = (array) $session->get(self::SESSION_KEY);
        $clipboard[$table] = ['id' => null, 'childs' => null, 'children' => null, 'mode' => self::MODE_CREATE];
        $session->set(self::SESSION_KEY, $clipboard);
    }

    /** Leaves the create mode of the table, if it is in it. */
    public function endCreate(string $table): void
    {
        if (null !== $this->manager) {
            if (self::MODE_CREATE === ($this->manager->get($table)['mode'] ?? null)) {
                $this->manager->clear($table);
            }

            return;
        }

        $session = $this->requestStack->getSession();
        $clipboard = (array) $session->get(self::SESSION_KEY);

        if (self::MODE_CREATE === ($clipboard[$table]['mode'] ?? null)) {
            $clipboard[$table] = [];
            $session->set(self::SESSION_KEY, $clipboard);
        }
    }
}
