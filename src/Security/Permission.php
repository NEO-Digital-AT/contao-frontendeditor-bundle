<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Security;

use Neo\FrontendEditorBundle\License\Editions;

/**
 * The switches of the extension per user group and user (field nfe_permissions).
 * They only narrow what Contao's own permissions allow, never widen it.
 */
enum Permission: string
{
    case EditMode = 'edit_mode';
    case EditContent = 'edit_content';
    case EditStructure = 'edit_structure';
    case CreateArticles = 'create_articles';
    case EditLanguages = 'edit_languages';
    case Translate = 'translate';
    case EditPages = 'edit_pages';
    case EditPosts = 'edit_posts';
    case OpenModules = 'open_modules';
    case EditStyles = 'edit_styles';
    case EditDesign = 'edit_design';

    public const FIELD = 'nfe_permissions';

    /** Edition the switch belongs to: '' (free), Editions::PLUS or Editions::PRO. */
    public function edition(): string
    {
        return match ($this) {
            self::EditLanguages, self::Translate, self::EditPages, self::EditPosts, self::OpenModules => Editions::PLUS,
            self::EditStyles => Editions::PRO,
            default => '',
        };
    }

    /** Security attribute, e.g. "neo_frontend_editor.edit_content". */
    public function attribute(): string
    {
        return 'neo_frontend_editor.'.$this->value;
    }
}
