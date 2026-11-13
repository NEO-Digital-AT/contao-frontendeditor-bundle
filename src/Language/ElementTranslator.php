<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

/**
 * Translation proposals for an element of the main language into one of its
 * versions: every text a translation covers (TranslationUnits) that a
 * translation service may get, translated by the service of the settings
 * (TranslationService: Azure Translator, DeepL or an AI model). Rich text and
 * HTML go as HTML (the tags stay), the rest as plain text; code other than
 * HTML and Markdown is left out. Nothing is saved; the editor shows the
 * proposals, marked as machine translations, and saves what the user confirms.
 */
final class ElementTranslator
{
    /** Texts per request: within what every service takes at once. */
    private const BATCH = 25;

    public function __construct(
        private readonly TranslationUnits $units,
        private readonly TranslationService $translation,
    ) {
    }

    /**
     * @param array<string, mixed>      $main
     * @param array<string, mixed>|null $target
     * @param list<string>|null         $addresses only these texts; null: all
     *
     * @return array<string, string> proposals by address
     *
     * @throws \Neo\BasicBundle\Translation\TranslationException
     */
    public function proposal(array $main, array|null $target, string $from, string $to, array|null $addresses = null, string $table = 'tl_content'): array
    {
        $plain = [];
        $html = [];

        foreach ($this->units->pair($main, $target, $from, $to, $table) as $unit) {
            if (!$unit['machine'] || (null !== $addresses && !\in_array($unit['address'], $addresses, true))) {
                continue;
            }

            if ($unit['html']) {
                $html[$unit['address']] = $unit['source'];
            } else {
                $plain[$unit['address']] = $unit['source'];
            }
        }

        if ([] === $plain && [] === $html) {
            return [];
        }

        $translator = $this->translation->translator();
        $proposal = [];

        foreach ([[$plain, false], [$html, true]] as [$texts, $isHtml]) {
            foreach (array_chunk($texts, self::BATCH, true) as $batch) {
                $proposal += array_combine(array_keys($batch), $translator->translate(array_values($batch), $from, $to, $isHtml));
            }
        }

        return $proposal;
    }
}
