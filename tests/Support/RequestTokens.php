<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Contao's token manager that accepts the request token "valid-token" of the
 * token ID "contao_csrf_token" only.
 */
trait RequestTokens
{
    private static string $tokenName = 'contao_csrf_token';

    private static string $token = 'valid-token';

    private function tokenManager(): ContaoCsrfTokenManager
    {
        $tokens = $this->createStub(ContaoCsrfTokenManager::class);
        $tokens->method('getDefaultTokenValue')->willReturn(self::$token);
        $tokens->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => self::$tokenName === $token->getId() && self::$token === $token->getValue(),
        );

        return $tokens;
    }
}
