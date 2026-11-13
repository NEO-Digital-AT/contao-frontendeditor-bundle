<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\BackendUser;
use Contao\CoreBundle\Security\DataContainer\AbstractAction;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Symfony's security as the tests need it: the logged-in user, and every
 * decision of isGranted() taken by a callback (attribute, subject) and kept,
 * so a test can show which of Contao's permissions were asked.
 */
trait Decisions
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $asked = [];

    /**
     * @param \Closure(string, mixed): bool $decide
     */
    private function security(\Closure $decide, BackendUser|null $user = null): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('getToken')->willReturn(null !== $user ? Users::token($user) : null);
        $security->method('isGranted')->willReturnCallback(function (mixed $attribute, mixed $subject = null) use ($decide): bool {
            $this->asked[] = [(string) $attribute, $subject];

            return $decide((string) $attribute, $subject);
        });

        return $security;
    }

    /** The data container actions (create, update, delete) asked for, as "attribute ClassName". */
    private function askedActions(): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry[0].' '.(new \ReflectionClass($entry[1]))->getShortName(),
            array_filter($this->asked, static fn (array $entry): bool => $entry[1] instanceof AbstractAction),
        ));
    }
}
