<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Security;

use Contao\BackendUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides the switches of the extension: administrators have all of them,
 * other back-end users the ones granted to them or their groups.
 *
 * @extends Voter<string, mixed>
 */
final class EditorVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== self::permission($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, Vote|null $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof BackendUser) {
            return false;
        }

        if ($user->isAdmin) {
            return true;
        }

        // The switches of the user merged with those of the groups (BackendUser::hasAccess() is deprecated).
        $granted = $user->{Permission::FIELD};

        return \is_array($granted) && \in_array(self::permission($attribute)?->value, $granted, true);
    }

    private static function permission(string $attribute): Permission|null
    {
        return str_starts_with($attribute, 'neo_frontend_editor.') ? Permission::tryFrom(substr($attribute, 20)) : null;
    }
}
