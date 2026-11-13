<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content\SourceMap;

use Contao\ContentModel;
use Contao\Controller;
use Contao\Model;
use Contao\ModuleModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The source map of the edit mode: which text on the page comes from which
 * field. A content element or front-end module whose fields hold texts is
 * rendered a second time from a detached copy of its record (never saved:
 * Model::cloneDetached()) whose text values carry invisible markers
 * (Markers). That rendering is used only if, without the markers, it is the
 * same HTML as the first one, character for character: then the markers did
 * not change what the template does with the values (attributes, URLs,
 * comparisons, lengths). If not, each field is tried alone and only the
 * fields that leave the HTML unchanged keep their markers; the others are
 * found in the browser by their text, as before. The markers in text become
 * comments with the source; all other markers are removed.
 *
 * Only for GET requests of the page in the editor's frame. The globals for the
 * page's head and scripts are restored after the second rendering, so it
 * adds nothing to the page.
 */
final class SourceMapRenderer
{
    /** Fields tried one by one at most, when the whole record changes the HTML. */
    private const MAX_SINGLE = 12;

    /** What a rendering adds to the page besides its HTML. */
    private const GLOBALS = ['TL_HEAD', 'TL_BODY', 'TL_JAVASCRIPT', 'TL_CSS', 'TL_USER_CSS', 'TL_STYLE_SHEETS', 'TL_FRAMEWORK_CSS', 'TL_MOOTOOLS', 'TL_JQUERY', 'TL_KEYWORDS'];

    /** Key of the record whose copy is being rendered, null outside of it. */
    private string|null $current = null;

    /** @var array<string, string> final output of each record rendered on this page, by TABLE.ID */
    private array $done = [];

    public function __construct(
        private readonly TextSources $sources,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Whether the copy of a record is being rendered: hooks then return what they returned the first time. */
    public function isRendering(): bool
    {
        return null !== $this->current;
    }

    /** Output of a record during the rendering of a copy (a child element, a module in an element). */
    public function earlier(string $table, int $id, string $buffer): string|null
    {
        return $this->current === "$table.$id" ? $buffer : ($this->done["$table.$id"] ?? null);
    }

    public function remember(string $table, int $id, string $output): string
    {
        return $this->done["$table.$id"] = $output;
    }

    public function element(ContentModel $model, string $buffer, string $column): string
    {
        return $this->map('tl_content', $model, $buffer, static fn (ContentModel $copy): string => self::withoutIndexerStop((string) Controller::getContentElement($copy, $column), $buffer));
    }

    public function module(ModuleModel $model, string $buffer, string $column): string
    {
        return $this->map('tl_module', $model, $buffer, static fn (ModuleModel $copy): string => self::withoutIndexerStop((string) Controller::getFrontendModule($copy, $column), $buffer));
    }

    /** Contao encloses a protected element or module in indexer comments after the hooks, which the first output has not yet. */
    private static function withoutIndexerStop(string $html, string $buffer): string
    {
        $start = "\n<!-- indexer::stop --><!-- indexer::protected -->";
        $end = "<!-- indexer::continue -->\n";

        return str_starts_with($html, $start) && !str_starts_with($buffer, $start) && str_ends_with($html, $end) ? substr($html, \strlen($start), -\strlen($end)) : $html;
    }

    /**
     * @param \Closure(Model): string $render
     */
    private function map(string $table, Model $model, string $buffer, \Closure $render): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->isMethod('GET') || '' === trim($buffer) || Markers::contains($buffer)) {
            return $buffer;
        }

        $sources = $this->sources->of($table, $model->row());

        if ([] === $sources) {
            return $buffer;
        }

        $html = $this->tryWith($table, $model, $sources, $buffer, $render);

        if (null !== $html) {
            return $html;
        }

        if (\count($sources) > self::MAX_SINGLE) {
            return $buffer;
        }

        $kept = array_values(array_filter($sources, fn (array $source): bool => null !== $this->tryWith($table, $model, [$source], $buffer, $render)));

        return ([] === $kept ? null : $this->tryWith($table, $model, $kept, $buffer, $render)) ?? $buffer;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param \Closure(Model): string    $render
     */
    private function tryWith(string $table, Model $model, array $sources, string $buffer, \Closure $render): string|null
    {
        ['changes' => $changes, 'keys' => $keys] = $this->sources->mark($model->row(), $sources);
        $copy = $model->cloneDetached();

        foreach ($changes as $field => $value) {
            $copy->$field = $value;
        }

        $saved = [];

        foreach (self::GLOBALS as $name) {
            $saved[$name] = $GLOBALS[$name] ?? null;
        }

        $this->current = $table.'.'.$model->id;

        try {
            $marked = $render($copy);
        } catch (\Throwable $e) {
            $this->logger->debug('Frontend editor: source map of '.$table.'.'.$model->id.' not built: '.$e->getMessage());

            return null;
        } finally {
            $this->current = null;

            foreach ($saved as $name => $value) {
                if (null === $value) {
                    unset($GLOBALS[$name]);
                } else {
                    $GLOBALS[$name] = $value;
                }
            }
        }

        if (Markers::strip($marked) !== $buffer) {
            return null;
        }

        $converted = Markers::convert($marked, $keys);

        return [] === $converted['found'] ? null : $converted['html'];
    }
}
