<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\Controller;
use Contao\CoreBundle\Picker\PickerBuilderInterface;
use Contao\StringUtil;
use Contao\System;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\Palette;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\SiteOrigins;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A back-end page that opens Contao's own picker (page and link picker, file
 * picker with upload, also for the TinyMCE of a text) in its modal window and
 * hands the chosen value to the editor on the website (postMessage, only to an
 * address of the website). The editor shows this page over the whole window
 * while the picker is open.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/picker', name: 'neo_frontend_editor_picker', defaults: ['_scope' => 'backend'], methods: ['GET'])]
final class PickerController extends BackendController
{
    /**
     * @param list<string> $imageExtensions
     */
    public function __construct(
        private readonly PickerBuilderInterface $pickerBuilder,
        private readonly SiteOrigins $origins,
        private readonly ElementAccess $access,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.image.valid_extensions%')]
        private readonly array $imageExtensions,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $origin = (string) $request->query->get('origin', '');
        $context = (string) $request->query->get('context', '');
        $value = (string) $request->query->get('value', '');

        if (!$this->access->has(Permission::EditContent) || !$this->origins->allows($origin)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $url = match ($context) {
            'link' => $this->pickerBuilder->getUrl('link', ['fieldType' => 'radio'], $value),
            'image' => $this->imagePickerUrl((int) $request->query->get('element'), $value, (string) $request->query->get('field', 'singleSRC')),
            'file' => $this->filePickerUrl((string) $request->query->get('source', ''), $value),
            default => null,
        };

        if (null === $url) {
            return ErrorResponse::forbidden($this->logger);
        }

        return $this->render('@Contao/neo_frontend_editor/picker.html.twig', [
            'picker_url' => $url,
            'picker_title' => (string) $request->query->get('title', ''),
            'target_origin' => $origin,
        ]);
    }

    /** The file picker of Contao's rich text editor (an image or a file in a text), as its TinyMCE opens it. */
    private function filePickerUrl(string $source, string $value): string|null
    {
        if (!preg_match('/^[a-z0-9_]+\.\d+$/', $source)) {
            return null;
        }

        return $this->pickerBuilder->getUrl('file', ['fieldType' => 'radio', 'filesOnly' => true, 'source' => $source], $value);
    }

    /** The file picker of an image field of the element, as the back-end form opens it (radio, files only, image types). */
    private function imagePickerUrl(int $id, string $value, string $field): string|null
    {
        $record = $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);

        if (false === $record) {
            return null;
        }

        Controller::loadDataContainer('tl_content');
        System::loadLanguageFile('tl_content');

        $fields = [...Palette::inlineFields((string) $record['type']), ...Palette::imageFields('tl_content', $record, $this->imageExtensions)];

        if (!\in_array($field, $fields, true) || 'fileTree' !== ($GLOBALS['TL_DCA']['tl_content']['fields'][$field]['inputType'] ?? null) || !$this->access->canUpdate($record) || !$this->access->canEditField($field)) {
            return null;
        }

        $extras = [
            'fieldType' => 'radio',
            'filesOnly' => true,
            'source' => 'tl_content.'.$id,
            'extensions' => implode(',', $this->imageExtensions),
        ];

        $stored = (string) ($record[$field] ?? '');

        return $this->pickerBuilder->getUrl('file', $extras, Validator::isUuid($value) ? $value : ('' !== $stored ? StringUtil::binToUuid($stored) : ''));
    }
}
