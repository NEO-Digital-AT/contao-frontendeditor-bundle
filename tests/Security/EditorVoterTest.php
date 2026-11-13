<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Security;

use Contao\FrontendUser;
use Neo\FrontendEditorBundle\Security\EditorVoter;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class EditorVoterTest extends TestCase
{
    public function testAnAdministratorHasEverySwitch(): void
    {
        $token = Users::token(Users::admin());

        foreach (Permission::cases() as $permission) {
            $this->assertSame(VoterInterface::ACCESS_GRANTED, (new EditorVoter())->vote($token, null, [$permission->attribute()]));
        }
    }

    public function testAnEditorHasOnlyTheGrantedSwitches(): void
    {
        $token = Users::token(Users::editor(Permission::EditMode, Permission::EditContent));
        $voter = new EditorVoter();

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [Permission::EditMode->attribute()]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [Permission::EditContent->attribute()]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [Permission::EditStructure->attribute()]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [Permission::EditStyles->attribute()]));
    }

    public function testAnEditorWithoutSwitchesHasNone(): void
    {
        $token = Users::token(Users::editor());

        $this->assertSame(VoterInterface::ACCESS_DENIED, (new EditorVoter())->vote($token, null, [Permission::EditMode->attribute()]));
    }

    public function testOtherAttributesAreLeftToOtherVoters(): void
    {
        $token = Users::token(Users::admin());
        $voter = new EditorVoter();

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['contao_user.modules']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['neo_frontend_editor.unknown_switch']));
        // The value of a switch alone is no attribute of the extension.
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['edit_content']));
    }

    public function testOnlyBackEndUsersGetSwitches(): void
    {
        $member = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $voter = new EditorVoter();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [Permission::EditMode->attribute()]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($member, 'contao_frontend', ['ROLE_MEMBER']), null, [Permission::EditMode->attribute()]));
    }

    public function testASwitchStoredAsTextIsNoSwitch(): void
    {
        $user = Users::editor();
        $user->{Permission::FIELD} = 'edit_mode';

        $this->assertSame(VoterInterface::ACCESS_DENIED, (new EditorVoter())->vote(Users::token($user), null, [Permission::EditMode->attribute()]));
    }
}
