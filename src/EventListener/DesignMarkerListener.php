<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\FrontendTemplate;
use Contao\Module;
use Contao\ModuleModel;
use Contao\Template;
use Contao\Widget;
use Neo\FrontendEditorBundle\Content\SourceMap\SourceMapRenderer;
use Neo\FrontendEditorBundle\EditMode;

/**
 * In edit mode, other parts of the page are enclosed in HTML comments like the
 * content elements, so the editor can frame them and offer their forms:
 * front-end modules (fm), fields of forms of Contao's form generator (ff),
 * comments (cm) and posts in news and event lists and readers (lp, with the
 * table; their texts are found by the editor and saved into the post); the
 * texts of a module's own fields in comments with their source
 * (SourceMapRenderer). Outside the edit mode the output stays untouched.
 */
final class DesignMarkerListener
{
    /** News modules that show posts: lists, and the reader with the one post of its page. */
    private const NEWS_MODULES = ['newslist', 'newsarchive', 'newsreader'];

    public function __construct(
        private readonly EditMode $editMode,
        private readonly SourceMapRenderer $sourceMap,
    ) {
    }

    #[AsHook('getFrontendModule', priority: -1024)]
    public function module(ModuleModel $model, string $buffer, mixed $module): string
    {
        $id = (int) $model->id;

        if ($id < 1 || '' === trim($buffer) || !$this->editing()) {
            return $buffer;
        }

        if ($this->sourceMap->isRendering()) {
            return $this->sourceMap->earlier('tl_module', $id, $buffer) ?? $this->wrap('fm:'.$id, $buffer);
        }

        return $this->sourceMap->remember('tl_module', $id, $this->wrap('fm:'.$id, $this->sourceMap->module($model, $buffer, ContentMarkerListener::column($module))));
    }

    #[AsHook('parseWidget')]
    public function widget(string $buffer, Widget $widget): string
    {
        // Fields of Contao's form generator carry the ID of their record and the form as parent.
        $id = $widget->id;

        if (!\is_int($id) && !ctype_digit((string) $id) || empty($widget->pid) || 'hidden' === $widget->type || '' === trim($buffer) || !$this->editing()) {
            return $buffer;
        }

        return $this->wrap('ff:'.(int) $id, $buffer);
    }

    #[AsHook('parseArticles')]
    public function newsItem(FrontendTemplate $template, array $row, Module $module): void
    {
        if (\in_array($module->type, self::NEWS_MODULES, true) && $this->editing()) {
            $template->nfeListItem = 'tl_news:'.(int) $row['id'];
        }
    }

    #[AsHook('parseFrontendTemplate')]
    public function template(string $buffer, string $templateName, Template $template): string
    {
        if ('' === trim($buffer) || !$this->editing()) {
            return $buffer;
        }

        if (\is_string($template->nfeListItem ?? null)) {
            return $this->wrap('lp:'.$template->nfeListItem, $buffer);
        }

        // Comments: Contao gives each one the ID "c" + its ID.
        if (str_starts_with($templateName, 'com_') && preg_match('/^c(\d+)$/', (string) ($template->id ?? ''), $match)) {
            return $this->wrap('cm:'.$match[1], $buffer);
        }

        // Events in lists and in the reader (event_full).
        if (str_starts_with($templateName, 'event_') && ctype_digit((string) ($template->id ?? ''))) {
            return $this->wrap('lp:tl_calendar_events:'.(int) $template->id, $buffer);
        }

        return $buffer;
    }

    private function editing(): bool
    {
        return null !== $this->editMode->editor();
    }

    private function wrap(string $key, string $buffer): string
    {
        return '<!--nfe:'.$key.'-->'.$buffer.'<!--/nfe:'.$key.'-->';
    }
}
