<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class StyleVoterTest extends TestCase
{
    use Pages;

    private const ATTRIBUTE = 'contao_dc.'.PageStyles::TABLE;

    /** @var list<array{0: list<string>, 1: mixed}> */
    private array $decided = [];

    public function testItVotesOnlyOnTheTableOfPageStyles(): void
    {
        $voter = $this->voter();

        $this->assertTrue($voter->supportsAttribute(self::ATTRIBUTE));
        $this->assertFalse($voter->supportsAttribute('contao_dc.tl_page'));
        $this->assertTrue($voter->supportsType(UpdateAction::class));
        $this->assertFalse($voter->supportsType('string'));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(), new UpdateAction('tl_page', ['id' => 5]), ['contao_dc.tl_page']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(), 5, [self::ATTRIBUTE]));
    }

    public function testNewRecordsAreNeverCreatedInTheBackEnd(): void
    {
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token(), new CreateAction(PageStyles::TABLE, ['pid' => 5]), [self::ATTRIBUTE]));
    }

    public function testWithSwitchProAndPagePermissionContaoDecidesTheRest(): void
    {
        $voter = $this->voter();

        foreach ([new ReadAction(PageStyles::TABLE, ['id' => 1, 'pid' => 5]), new UpdateAction(PageStyles::TABLE, ['id' => 1, 'pid' => 5]), new DeleteAction(PageStyles::TABLE, ['id' => 1, 'pid' => 5])] as $action) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(), $action, [self::ATTRIBUTE]));
        }

        // The page permission is Contao's decision on updating the page record.
        [$attributes, $subject] = end($this->decided);
        $this->assertSame(['contao_dc.tl_page'], $attributes);
        $this->assertInstanceOf(UpdateAction::class, $subject);
        $this->assertSame(5, (int) $subject->getCurrent()['id']);
    }

    public function testEachMissingConditionDenies(): void
    {
        $action = new UpdateAction(PageStyles::TABLE, ['id' => 1, 'pid' => 5]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter(switch: false)->vote($this->token(), $action, [self::ATTRIBUTE]), 'without the switch');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter(domain: 'www.example.com')->vote($this->token(), $action, [self::ATTRIBUTE]), 'without Pro');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter(page: false)->vote($this->token(), $action, [self::ATTRIBUTE]), 'without permission to edit the page');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token(), new UpdateAction(PageStyles::TABLE, ['id' => 1, 'pid' => 99]), [self::ATTRIBUTE]), 'a page that does not exist');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token(), new UpdateAction(PageStyles::TABLE, ['id' => 1]), [self::ATTRIBUTE]), 'a record without page');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), $action, [self::ATTRIBUTE]), 'without back-end user');
    }

    private function voter(bool $switch = true, bool $page = true, string $domain = 'www.example.test'): StyleVoter
    {
        $this->decided = [];
        $db = TestDatabase::create('tl_page');
        $db->insert('tl_page', ['id' => 5, 'title' => 'Start', 'type' => 'regular']);

        $manager = $this->createStub(AccessDecisionManagerInterface::class);
        $manager->method('decide')->willReturnCallback(function (TokenInterface $token, array $attributes, mixed $object = null) use ($switch, $page): bool {
            $this->decided[] = [$attributes, $object];

            return match ($attributes) {
                ['neo_frontend_editor.'.Permission::EditStyles->value] => $switch,
                ['contao_dc.tl_page'] => $page,
                default => false,
            };
        });

        return new StyleVoter($manager, Licences::editions($this->framework([5 => ['domain' => $domain], 99 => ['domain' => $domain]])), $db);
    }

    private function token(): TokenInterface
    {
        return Users::token(Users::editor(Permission::EditStyles));
    }
}
