<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\ContentModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Neo\FrontendEditorBundle\Content\SourceMap\SourceMapRenderer;
use Neo\FrontendEditorBundle\EditMode;

/**
 * In edit mode, every content element is enclosed in two HTML comments with its
 * ID, so the editor finds it on the page without changing the markup of the
 * element, and the texts of its fields in comments with their source
 * (SourceMapRenderer). Runs after the other hooks of other extensions, so the
 * second rendering of the source map sees the same hooks as the first.
 * Outside the edit mode the output stays untouched.
 */
#[AsHook('getContentElement', priority: -1024)]
final class ContentMarkerListener
{
    public function __construct(
        private readonly EditMode $editMode,
        private readonly SourceMapRenderer $sourceMap,
    ) {
    }

    public function __invoke(ContentModel $model, string $buffer, mixed $element): string
    {
        if ('' === trim($buffer) || null === $this->editMode->editor()) {
            return $buffer;
        }

        $id = (int) $model->id;

        // The second rendering of a record for the source map: what this element gave the first time.
        if ($this->sourceMap->isRendering()) {
            return $this->sourceMap->earlier('tl_content', $id, $buffer) ?? $this->wrap($id, $buffer);
        }

        return $this->sourceMap->remember('tl_content', $id, $this->wrap($id, $this->sourceMap->element($model, $buffer, self::column($element))));
    }

    /** The column (section) the element is rendered in, which Contao keeps in the element object. */
    public static function column(mixed $element): string
    {
        if (\is_object($element)) {
            try {
                $property = new \ReflectionProperty($element, 'strColumn');

                return (string) ($property->getValue($element) ?: 'main');
            } catch (\ReflectionException) {
            }
        }

        return 'main';
    }

    private function wrap(int $id, string $buffer): string
    {
        return \sprintf('<!--nfe:ce:%d-->%s<!--/nfe:ce:%d-->', $id, $buffer, $id);
    }
}
