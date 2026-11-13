<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Neo\FrontendEditorBundle\EditMode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The edit mode with a back-end user logged in for the preview (or none), and
 * Contao's decision on the switch "Use the editor" for that user.
 */
trait EditModes
{
    private function editMode(BackendUser|null $user = null, bool $switch = true, RequestStack|null $stack = null): EditMode
    {
        $tokens = $this->createStub(TokenChecker::class);
        $tokens->method('getBackendUsername')->willReturn($user?->username);

        $users = $this->createStub(UserProviderInterface::class);
        $users->method('loadUserByIdentifier')->willReturnCallback(static fn (string $name) => $name === $user?->username ? $user : throw new UserNotFoundException());

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturn($switch);

        return new EditMode($stack ?? new RequestStack(), $tokens, $users, $decisions);
    }

    /** A page of Contao's preview with the editor switched on (cookie), in the frame of the editor view. */
    private function editedPage(string $uri = 'https://www.example.com/about.html?nfe_frame=1', bool $cookie = true, bool $preview = true): Request
    {
        $request = Request::create($uri, 'GET', [], $cookie ? [EditMode::COOKIE => '1'] : []);
        $request->attributes->set('_preview', $preview);
        $request->headers->set('Accept', 'text/html');

        return $request;
    }
}
