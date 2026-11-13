<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Neo\BasicBundle\Translation\TranslationException;
use Neo\BasicBundle\Translation\TranslatorInterface;
use Neo\BasicBundle\Translation\Translators;
use Neo\FrontendEditorBundle\Settings;

/**
 * The translation service of the translation proposals (Plus), chosen in the
 * settings of the editor: Azure Translator, DeepL or a language model of the
 * AI connection (with the model chosen there). Without a choice the first
 * service whose access is set up on "Access keys and licences" is used. The
 * accesses themselves come from Basic.
 */
final class TranslationService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Translators $translators,
    ) {
    }

    /**
     * Code of the service in use ("azure", "deepl", "ai"): the chosen one, else
     * the first one with access; '' when none is set up.
     */
    public function service(): string
    {
        $chosen = $this->settings->translationService();

        if (\in_array($chosen, Translators::SERVICES, true)) {
            return $chosen;
        }

        return $this->translators->withAccess()[0] ?? '';
    }

    /**
     * Whether a translation can be requested: the service is set up (for the language model also a model is chosen).
     */
    public function isReady(): bool
    {
        $service = $this->service();

        return '' !== $service && $this->translators->get($service, $this->settings->translationModel())->isConfigured();
    }

    /**
     * The translator of the service in use.
     *
     * @throws TranslationException when no service is set up
     */
    public function translator(): TranslatorInterface
    {
        $service = $this->service();

        if ('' === $service) {
            throw TranslationException::notConfiguredFor('translation service');
        }

        return $this->translators->get($service, $this->settings->translationModel());
    }
}
