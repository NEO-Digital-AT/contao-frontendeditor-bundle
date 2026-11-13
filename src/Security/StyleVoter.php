<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * The style of a page (Pro) may be read, changed or deleted by a user with the
 * switch "Edit page styles" who may edit that page in Contao, on a website
 * with Pro. Otherwise this voter denies; when all holds it abstains, so
 * Contao's own voters (access to the back-end module) still decide. New
 * records are only created by the editor (the table is closed).
 */
final class StyleVoter implements CacheableVoterInterface
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly Editions $editions,
        private readonly Connection $db,
    ) {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return ContaoCorePermissions::DC_PREFIX.PageStyles::TABLE === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return \in_array($subjectType, [CreateAction::class, ReadAction::class, UpdateAction::class, DeleteAction::class], true);
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, Vote|null $vote = null): int
    {
        if (!$subject instanceof CreateAction && !$subject instanceof ReadAction && !$subject instanceof UpdateAction && !$subject instanceof DeleteAction) {
            return self::ACCESS_ABSTAIN;
        }

        foreach ($attributes as $attribute) {
            if (!$this->supportsAttribute($attribute)) {
                continue;
            }

            if ($subject instanceof CreateAction) {
                return self::ACCESS_DENIED;
            }

            return $this->mayStyle($token, (int) ($subject->getCurrent()['pid'] ?? 0)) ? self::ACCESS_ABSTAIN : self::ACCESS_DENIED;
        }

        return self::ACCESS_ABSTAIN;
    }

    /** The switch, Pro for the page's website and Contao's permission to edit the page. */
    public function mayStyle(TokenInterface $token, int $pageId): bool
    {
        if (!$token->getUser() instanceof BackendUser || $pageId < 1) {
            return false;
        }

        if (!$this->accessDecisionManager->decide($token, ['neo_frontend_editor.'.Permission::EditStyles->value]) || !$this->editions->hasPro($pageId)) {
            return false;
        }

        $page = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$pageId]);

        return false !== $page && $this->accessDecisionManager->decide($token, [ContaoCorePermissions::DC_PREFIX.'tl_page'], new UpdateAction('tl_page', $page));
    }
}
