<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\BackendUser;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Back-end users as Contao loads them, without the database: an administrator
 * or an editor with switches of the extension (merged from user and groups).
 */
final class Users
{
    public static function admin(): BackendUser
    {
        return self::user(['id' => 1, 'username' => 'admin', 'name' => 'Max Mustermann', 'admin' => '1', 'language' => 'en']);
    }

    public static function editor(Permission ...$switches): BackendUser
    {
        return self::user([
            'id' => 2,
            'username' => 'editor',
            'name' => 'Erika Musterfrau',
            'admin' => '',
            'language' => 'de',
            Permission::FIELD => array_map(static fn (Permission $permission): string => $permission->value, $switches),
        ]);
    }

    public static function token(BackendUser $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'contao_backend', $user->getRoles());
    }

    /** @param array<string, mixed> $data */
    private static function user(array $data): BackendUser
    {
        $user = (new \ReflectionClass(BackendUser::class))->newInstanceWithoutConstructor();

        foreach ($data as $key => $value) {
            $user->$key = $value;
        }

        return $user;
    }
}
