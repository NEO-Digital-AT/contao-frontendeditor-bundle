<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Backend;

use Contao\Backend;
use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\DataContainer;
use Contao\StringUtil;
use Neo\BasicBundle\Access\AccessPage;
use Neo\BasicBundle\Ai\ModelCatalog;
use Neo\BasicBundle\Translation\Translators;
use Neo\FrontendEditorBundle\Language\TranslationService;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Settings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The settings are one record: the module opens it directly. The models of
 * the writing aid and of translations are chosen from the models of the AI
 * route; when the route lists none the field is a text field, and a stored
 * model always stays selectable. A setting that cannot have an effect is
 * disabled with the reason at the field: a translation service whose access
 * is not set up on "Access keys and licences", and the AI settings without the
 * AI connection, each with the way to its section. The settings of the
 * translation appear only with Plus, those of the writing aid only with Pro.
 */
final class SettingsCallbacks
{
    /** Settings of the translation proposals (Plus). */
    private const PLUS_FIELDS = ['translation_service', 'translation_model'];

    /** Settings of the writing aid (Pro). */
    private const PRO_FIELDS = ['ai_model', 'ai_daily_limit'];

    /** @var array<string, string> field => reason (HTML) of a disabled field, see disableWithReason() */
    private array $reasons = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly ModelCatalog $models,
        private readonly Editions $editions,
        private readonly Translators $translators,
        private readonly TranslationService $translation,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Without Plus or Pro the settings of their functions are left out of the
     * form (palette, "Edit multiple", details view); their stored values stay
     * and they appear again with a licence.
     */
    public function hideUnlicensed(): void
    {
        $edition = $this->editions->forBackend();
        $hidden = [];

        if (!Editions::includes($edition, Editions::PLUS)) {
            $hidden = [...$hidden, ...self::PLUS_FIELDS];
        }

        if (!Editions::includes($edition, Editions::PRO)) {
            $hidden = [...$hidden, ...self::PRO_FIELDS];
        }

        if ([] === $hidden) {
            return;
        }

        PaletteManipulator::create()
            ->removeField($hidden)
            ->applyToPalette('default', Settings::TABLE)
        ;

        foreach ($hidden as $field) {
            $GLOBALS['TL_DCA'][Settings::TABLE]['fields'][$field]['eval']['doNotShow'] = true;
        }
    }

    /**
     * All services: those without access are shown disabled, with the reason
     * at the field (ServiceSelectMenu, see adjustTranslationFields()).
     *
     * @return list<string>
     */
    public function translationServiceOptions(): array
    {
        return Translators::SERVICES;
    }

    /** @return array<string, string> */
    public function translationModelOptions(): array
    {
        return $this->models->optionsWith($this->settings->translationModel());
    }

    /**
     * The choice of the service: a service without access is disabled, with
     * the reason and the way to its section below the field; a stored one
     * that lost its access stays selectable and says so. Without any service
     * the whole field is disabled. The model field appears only for the
     * language model, disabled without the AI connection, a text field when
     * the route lists no models.
     */
    public function adjustTranslationFields(): void
    {
        $fields = &$GLOBALS['TL_DCA'][Settings::TABLE]['fields'];

        if ($fields['translation_service']['eval']['doNotShow'] ?? false) {
            return;
        }

        $lang = $GLOBALS['TL_LANG'][Settings::TABLE];
        $names = $lang['translation_service_options'] ?? [];
        $stored = $this->settings->translationService();
        $unavailable = [];
        $notes = [];

        foreach (Translators::SERVICES as $service) {
            if ($this->translators->hasAccess($service)) {
                continue;
            }

            $reason = StringUtil::specialchars(\sprintf($lang[$service === $stored ? 'translation_lost' : 'translation_not_set_up'] ?? '%s', $names[$service] ?? $service)).$this->accessLink($service);

            if ($service === $stored) {
                $notes[] = $reason;
            } else {
                $unavailable[$service] = $reason;
            }
        }

        if (\count($unavailable) === \count(Translators::SERVICES)) {
            // Nothing to choose: the field is disabled and keeps what is stored.
            $fields['translation_service']['eval']['disabled'] = true;
            $notes = [StringUtil::specialchars($lang['translation_none'] ?? '').$this->accessLink(Translators::AZURE)];
            $unavailable = [];
        }

        $fields['translation_service']['eval']['unavailable'] = $unavailable;
        $fields['translation_service']['eval']['notes'] = $notes;

        if (Translators::AI !== $this->translation->service()) {
            PaletteManipulator::create()
                ->removeField('translation_model')
                ->applyToPalette('default', Settings::TABLE)
            ;

            return;
        }

        if (!$this->translators->hasAccess(Translators::AI)) {
            $this->disableWithReason('translation_model', $lang['ai_connection_missing'] ?? '', Translators::AI);
        } elseif ([] === $this->translationModelOptions()) {
            $fields['translation_model']['inputType'] = 'text';
            unset($fields['translation_model']['options_callback']);
        }
    }

    /**
     * xlabel callback: the reason why a field is disabled, directly at its label.
     */
    public function reason(DataContainer $dc): string
    {
        return $this->reasons[(string) $dc->field] ?? '';
    }

    /** @return array<string, string> */
    public function modelOptions(): array
    {
        return $this->models->optionsWith($this->settings->aiModel());
    }

    public function adjustModelField(): void
    {
        // Hidden without Pro: no request to the AI route.
        if ($GLOBALS['TL_DCA'][Settings::TABLE]['fields']['ai_model']['eval']['doNotShow'] ?? false) {
            return;
        }

        // Without the AI connection the writing aid cannot work: its settings are disabled, with the reason.
        if (!$this->translators->hasAccess(Translators::AI)) {
            foreach (self::PRO_FIELDS as $field) {
                $this->disableWithReason($field, $GLOBALS['TL_LANG'][Settings::TABLE]['ai_connection_missing'] ?? '', Translators::AI);
            }

            return;
        }

        if ([] === $this->modelOptions()) {
            $GLOBALS['TL_DCA'][Settings::TABLE]['fields']['ai_model']['inputType'] = 'text';
            unset($GLOBALS['TL_DCA'][Settings::TABLE]['fields']['ai_model']['options_callback']);
        }
    }

    public function openRecord(): void
    {
        $id = $this->settings->ensureRow();

        // The request, not Contao's Input (deprecated in Contao 6).
        if ($this->requestStack->getCurrentRequest()?->query->get('act')) {
            return;
        }

        // nb=1: without a list there is nowhere to go back to; Contao then shows no "Go back".
        Backend::redirect(Backend::addToUrl('act=edit&id='.$id.'&nb=1'));
    }

    /**
     * A field without effect: disabled (Contao neither submits nor saves it, the
     * stored value stays) with the reason and the way to the section at its label.
     */
    private function disableWithReason(string $field, string $reason, string $service): void
    {
        $dca = &$GLOBALS['TL_DCA'][Settings::TABLE]['fields'][$field];
        $dca['eval']['disabled'] = true;
        $dca['xlabel'][] = [self::class, 'reason'];

        $this->reasons[$field] = \sprintf('<span class="tl_help" style="display:block;font-weight:normal">%s%s</span>', StringUtil::specialchars($reason), $this->accessLink($service));
    }

    /**
     * "Set it up under …", linked to the section for users who may open the page.
     */
    private function accessLink(string $service): string
    {
        $text = $GLOBALS['TL_LANG'][Settings::TABLE]['translation_set_up'] ?? '';

        if (!AccessPage::canOpen()) {
            return '' !== $text ? ' '.StringUtil::specialchars(strip_tags($text)) : '';
        }

        return \sprintf(' <a href="%s">%s</a>', StringUtil::specialchars(AccessPage::url(Translators::legend($service))), StringUtil::specialchars($text));
    }

    /**
     * Only "Save": "Save and close" would lead to the list, which opens this form again.
     *
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    public function editButtons(array $buttons): array
    {
        unset($buttons['saveNclose']);

        return $buttons;
    }
}
