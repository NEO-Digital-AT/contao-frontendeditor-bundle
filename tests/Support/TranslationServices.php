<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Neo\BasicBundle\Access\AccessSettings;
use Neo\BasicBundle\Ai\AiConnection;
use Neo\BasicBundle\Security\SecretCipher;
use Neo\BasicBundle\Translation\AiTranslator;
use Neo\BasicBundle\Translation\AzureConnection;
use Neo\BasicBundle\Translation\AzureTranslator;
use Neo\BasicBundle\Translation\DeepLConnection;
use Neo\BasicBundle\Translation\DeepLTranslator;
use Neo\BasicBundle\Translation\Translators;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Basic's translation services with the accesses of "Access keys and
 * licences" given here, over a mock HTTP client. The Azure Translator of the
 * tests answers every text with "[to] " before it and keeps every request.
 */
final class TranslationServices
{
    /** @var list<array{url: string, body: mixed}> */
    public array $requests = [];

    public readonly Translators $translators;

    /**
     * @param array<string, string> $values columns of tl_neo_access (keys are stored encrypted)
     */
    public function __construct(array $values = [])
    {
        $settings = new AccessSettings(TestDatabase::create('tl_neo_access'), new SecretCipher(Licences::SECRET));

        if ([] !== $values) {
            $settings->store($values, array_values(array_intersect(array_keys($values), ['azure_translator_key', 'deepl_key', 'ai_api_key'])));
        }

        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $this->requests[] = ['url' => $url, 'body' => $body];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return new MockResponse((string) json_encode(array_map(
                static fn (array $item): array => ['translations' => [['text' => '['.$query['to'].'] '.$item['Text'], 'to' => $query['to']]]],
                (array) $body,
            )), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });

        $logger = new NullLogger();
        $ai = new AiConnection($settings);
        $this->translators = new Translators(
            new AzureTranslator(new AzureConnection($settings), $client, $logger),
            new DeepLTranslator(new DeepLConnection($settings), $client, $logger),
            new AiTranslator($ai, $client, $logger),
            $ai,
        );
    }
}
