<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Controller;

use Neo\FrontendEditorBundle\Controller\ErrorResponse;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        ContaoState::language('default', ['ERR' => ['general' => 'An error occurred!'], 'MSC' => ['invalidToken' => 'Invalid request token. Please reload the page and try again.']]);
    }

    protected function tearDown(): void
    {
        ContaoState::clear();
    }

    /** @return iterable<string, array{\Closure(MemoryLogger): JsonResponse, int, string, string}> */
    public static function errors(): iterable
    {
        yield 'bad request' => [ErrorResponse::badRequest(...), 400, 'NFE400', 'An error occurred!'];
        yield 'forbidden' => [ErrorResponse::forbidden(...), 403, 'NFE403', 'An error occurred!'];
        yield 'not found' => [ErrorResponse::notFound(...), 404, 'NFE404', 'An error occurred!'];
        yield 'invalid request token' => [ErrorResponse::invalidToken(...), 403, 'NFE419', 'Invalid request token. Please reload the page and try again.'];
        yield 'service unavailable' => [static fn (MemoryLogger $logger): JsonResponse => ErrorResponse::unavailable($logger, 'NFE503_AI', 'The writing aid is not set up.'), 503, 'NFE503_AI', 'The writing aid is not set up.'];
    }

    #[DataProvider('errors')]
    public function testEveryErrorHasTheSameShape(\Closure $create, int $status, string $code, string $message): void
    {
        $logger = new MemoryLogger();
        $response = $create($logger);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame(['success', 'errorCode', 'message', 'sessionCode'], array_keys($data));
        $this->assertFalse($data['success']);
        $this->assertSame($code, $data['errorCode']);
        $this->assertSame($message, $data['message']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{12}$/', $data['sessionCode']);
        $this->assertSame([\sprintf('info: Frontend editor: %s (%d) – session code %s', $code, $status, $data['sessionCode'])], $logger->records);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testInvalidInputNamesTheFieldsWithTheMessagesOfContaosWidgets(): void
    {
        $response = ErrorResponse::invalid(new MemoryLogger(), ['text' => ['Field "text" must not be empty!'], 'headline' => ['Invalid option value!', 'Too long.']]);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['success', 'errorCode', 'message', 'sessionCode', 'fields'], array_keys($data));
        $this->assertSame('NFE422', $data['errorCode']);
        $this->assertSame('Field "text" must not be empty! Invalid option value! Too long.', $data['message']);
        $this->assertSame(['text' => ['Field "text" must not be empty!'], 'headline' => ['Invalid option value!', 'Too long.']], $data['fields']);
    }

    public function testEachAnswerHasItsOwnSessionCode(): void
    {
        $first = json_decode((string) ErrorResponse::forbidden(new MemoryLogger())->getContent(), true);
        $second = json_decode((string) ErrorResponse::forbidden(new MemoryLogger())->getContent(), true);

        $this->assertNotSame($first['sessionCode'], $second['sessionCode']);
    }
}
