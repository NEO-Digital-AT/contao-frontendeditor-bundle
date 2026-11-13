<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\System;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one shape of every error answer of the editor's endpoints:
 * {"success": false, "errorCode": "…", "message": "…", "sessionCode": "…"}.
 * The session code is logged with the error, so support can find it.
 * Messages are Contao's own texts in the language of the back end.
 */
final class ErrorResponse
{
    public static function badRequest(LoggerInterface $logger): JsonResponse
    {
        return self::create($logger, Response::HTTP_BAD_REQUEST, 'NFE400', self::general());
    }

    public static function forbidden(LoggerInterface $logger): JsonResponse
    {
        return self::create($logger, Response::HTTP_FORBIDDEN, 'NFE403', self::general());
    }

    public static function notFound(LoggerInterface $logger): JsonResponse
    {
        return self::create($logger, Response::HTTP_NOT_FOUND, 'NFE404', self::general());
    }

    public static function invalidToken(LoggerInterface $logger): JsonResponse
    {
        System::loadLanguageFile('default');

        return self::create($logger, Response::HTTP_FORBIDDEN, 'NFE419', (string) ($GLOBALS['TL_LANG']['MSC']['invalidToken'] ?? 'Invalid token'));
    }

    /** @param array<string, list<string>> $errors messages of Contao's widgets by field */
    public static function invalid(LoggerInterface $logger, array $errors): JsonResponse
    {
        $response = self::create($logger, Response::HTTP_UNPROCESSABLE_ENTITY, 'NFE422', implode(' ', array_merge(...array_values($errors))));
        $response->setData(json_decode((string) $response->getContent(), true) + ['fields' => $errors]);

        return $response;
    }

    /**
     * A service the function needs is not available (not configured, not
     * reachable, limit of the day reached); the message says which.
     */
    public static function unavailable(LoggerInterface $logger, string $code, string $message): JsonResponse
    {
        return self::create($logger, Response::HTTP_SERVICE_UNAVAILABLE, $code, $message);
    }

    private static function general(): string
    {
        System::loadLanguageFile('default');

        return (string) ($GLOBALS['TL_LANG']['ERR']['general'] ?? 'An error occurred!');
    }

    private static function create(LoggerInterface $logger, int $status, string $code, string $message): JsonResponse
    {
        $sessionCode = strtoupper(bin2hex(random_bytes(6)));
        $logger->info(\sprintf('Frontend editor: %s (%d) – session code %s', $code, $status, $sessionCode));

        $response = new JsonResponse(['success' => false, 'errorCode' => $code, 'message' => $message, 'sessionCode' => $sessionCode], $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
