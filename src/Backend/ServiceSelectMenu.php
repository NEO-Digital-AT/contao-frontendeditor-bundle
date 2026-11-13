<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Backend;

use Contao\SelectMenu;
use Contao\StringUtil;

/**
 * Contao's select menu with options that cannot be chosen: each service whose
 * access is not set up stays in the list, disabled, and the reason (with the
 * way to "Access keys and licences") stands directly below the field.
 *
 * eval: "unavailable" => [value => reason as HTML]; "notes" => [HTML lines]
 * shown below the field as well (e.g. a stored service that lost its access).
 */
final class ServiceSelectMenu extends SelectMenu
{
    public function generate(): string
    {
        $html = parent::generate();

        foreach (array_keys((array) $this->unavailable) as $value) {
            $html = (string) preg_replace('/<option value="'.preg_quote(StringUtil::specialchars((string) $value), '/').'"/', '$0 disabled', $html, 1);
        }

        $lines = [...array_values((array) $this->notes), ...array_values((array) $this->unavailable)];

        if ([] === $lines) {
            return $html;
        }

        return $html.'<ul class="tl_help" id="ctrl_'.$this->strId.'_reasons">'.implode('', array_map(static fn (string $line): string => '<li>'.$line.'</li>', $lines)).'</ul>';
    }

    protected function validator($varInput): mixed
    {
        $varInput = parent::validator($varInput);

        // An option without access cannot be chosen, also not with a crafted request.
        if (\is_string($varInput) && isset(((array) $this->unavailable)[$varInput])) {
            $this->addError(strip_tags((string) ((array) $this->unavailable)[$varInput]));
        }

        return $varInput;
    }
}
