<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Neo\BasicBundle\Translation\AiTranslator;
use Neo\BasicBundle\Translation\AzureTranslator;
use Neo\BasicBundle\Translation\DeepLTranslator;
use Neo\BasicBundle\Translation\TranslationException;
use Neo\FrontendEditorBundle\Language\TranslationService;
use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\TranslationServices;
use PHPUnit\Framework\TestCase;

final class TranslationServiceTest extends TestCase
{
    public function testWithoutAnyAccessThereIsNoService(): void
    {
        $service = $this->service(new TranslationServices());

        $this->assertSame('', $service->service());
        $this->assertFalse($service->isReady());

        $this->expectException(TranslationException::class);
        $service->translator();
    }

    public function testWithoutChoiceTheFirstServiceWithAccess(): void
    {
        $service = $this->service(new TranslationServices(['deepl_key' => 'deepl-key:fx', 'azure_translator_key' => 'azure-key']));

        $this->assertSame('azure', $service->service());
        $this->assertTrue($service->isReady());
        $this->assertInstanceOf(AzureTranslator::class, $service->translator());
    }

    public function testTheChosenServiceCountsEvenWithoutAccess(): void
    {
        $service = $this->service(new TranslationServices(['azure_translator_key' => 'azure-key']), ['translation_service' => 'deepl']);

        $this->assertSame('deepl', $service->service());
        $this->assertInstanceOf(DeepLTranslator::class, $service->translator());
        // DeepL has no key: no proposal can be requested.
        $this->assertFalse($service->isReady());
    }

    public function testTheLanguageModelNeedsAModel(): void
    {
        $services = new TranslationServices(['ai_api_key' => 'ai-key']);

        $withoutModel = $this->service($services, ['translation_service' => 'ai']);
        $this->assertFalse($withoutModel->isReady());

        $withModel = $this->service($services, ['translation_service' => 'ai', 'translation_model' => 'provider/translation-model']);
        $this->assertTrue($withModel->isReady());
        $translator = $withModel->translator();
        $this->assertInstanceOf(AiTranslator::class, $translator);
        $this->assertSame('provider/translation-model', $translator->model());
    }

    public function testAnUnknownChoiceFallsBackToTheFirstWithAccess(): void
    {
        $this->assertSame('ai', $this->service(new TranslationServices(['ai_api_key' => 'ai-key']), ['translation_service' => 'babelfish'])->service());
    }

    /** @param array<string, string> $settings */
    private function service(TranslationServices $services, array $settings = []): TranslationService
    {
        $db = TestDatabase::create(Settings::TABLE);

        if ([] !== $settings) {
            $db->insert(Settings::TABLE, $settings);
        }

        return new TranslationService(new Settings($db), $services->translators);
    }
}
