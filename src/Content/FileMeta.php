<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\MetaWizard;
use Contao\StringUtil;
use Contao\System;
use Contao\Validator;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\Cache\PageCacheTags;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The metadata of the images a record shows (title, alternative text, caption
 * per language, as the file manager keeps them in tl_files.meta): which images
 * a record has, their texts in a language, and saving a language's texts.
 *
 * Saving checks what the file manager checks: access to the module "Files",
 * the permission to edit files, the path within the user's file mounts and the
 * field "Metadata" (tl_files::meta). The texts go through Contao's widget of
 * the field (MetaWizard), as when they are typed in the file manager, and a
 * version is created as there.
 */
final class FileMeta
{
    /** Texts of the metadata; the link is an address, not a text. */
    public const TEXTS = ['title', 'alt', 'caption'];

    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
        private readonly PageCacheTags $cacheTags,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%contao.image.valid_extensions%')]
        private readonly array $imageExtensions = [],
    ) {
    }

    /**
     * The images of a record's palette in their order: per file field the files
     * it holds. An element that overwrites the metadata of its image (alt text
     * and caption of its own) keeps them in its fields, not here.
     *
     * @param array<string, mixed> $record
     *
     * @return list<array{field: string, index: int, id: int, uuid: string, path: string}>
     */
    public function imagesOf(string $table, array $record): array
    {
        $images = [];

        foreach (Palette::fileFields($table, $record) as $field) {
            if ('tl_content' === $table && 'singleSRC' === $field && !empty($record['overwriteMeta'])) {
                continue;
            }

            $values = StringUtil::deserialize($record[$field] ?? null, true);
            $index = 0;

            foreach ($values as $value) {
                $uuid = \is_string($value) && Validator::isBinaryUuid($value) ? $value : (\is_string($value) && Validator::isStringUuid($value) ? StringUtil::uuidToBin($value) : null);
                $file = null !== $uuid ? $this->db->fetchAssociative("SELECT id, uuid, path, extension FROM tl_files WHERE uuid = ? AND type = 'file'", [$uuid]) : false;

                if (false === $file || !\in_array(strtolower((string) $file['extension']), $this->imageExtensions, true)) {
                    continue;
                }

                $images[] = ['field' => $field, 'index' => $index++, 'id' => (int) $file['id'], 'uuid' => (string) $file['uuid'], 'path' => (string) $file['path']];
            }
        }

        return $images;
    }

    /**
     * The texts of a file's metadata in a language.
     *
     * @return array{title: string, alt: string, caption: string}
     */
    public function texts(int $fileId, string $language): array
    {
        $meta = StringUtil::deserialize($this->db->fetchOne('SELECT meta FROM tl_files WHERE id = ?', [$fileId]), true);
        $own = (array) ($meta[$language] ?? []);

        return ['title' => (string) ($own['title'] ?? ''), 'alt' => (string) ($own['alt'] ?? ''), 'caption' => (string) ($own['caption'] ?? '')];
    }

    /** Whether the user may change the metadata of the file, as in the file manager. */
    public function canEdit(int $fileId): bool
    {
        $path = $this->db->fetchOne('SELECT path FROM tl_files WHERE id = ?', [$fileId]);

        return \is_string($path)
            && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'files')
            && $this->security->isGranted(ContaoCorePermissions::USER_CAN_RENAME_FILE)
            && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PATH, $path)
            && $this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_files::meta');
    }

    /**
     * Saves texts of the metadata in a language; the other languages and the
     * link stay. The file manager's form posts every language of the field
     * with its texts as the user reads them; so does this, with the texts of
     * $language replaced, through the field's widget.
     *
     * @param array<string, string> $texts by "title", "alt", "caption"
     *
     * @return bool false when the user may not change the file or the widget refuses the texts
     */
    public function save(int $fileId, string $language, array $texts): bool
    {
        $file = $this->db->fetchAssociative('SELECT path, meta FROM tl_files WHERE id = ?', [$fileId]);
        $request = $this->requestStack->getCurrentRequest();

        if (false === $file || null === $request || !$this->canEdit($fileId) || 1 !== preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $language)) {
            return false;
        }

        $this->framework->initialize();
        Controller::loadDataContainer('tl_files');
        System::loadLanguageFile('tl_files');
        $config = $GLOBALS['TL_DCA']['tl_files']['fields']['meta'] ?? null;

        if (!\is_array($config)) {
            return false;
        }

        $stored = StringUtil::deserialize($file['meta'], true);
        $posted = [];

        foreach ($stored as $locale => $fields) {
            $posted[$locale] = array_map(static fn (mixed $value): string => StringUtil::decodeEntities((string) $value), (array) $fields);
        }

        $posted[$language] = array_replace(['title' => '', 'alt' => '', 'link' => '', 'caption' => ''], $posted[$language] ?? [], array_map('strval', array_intersect_key($texts, array_flip(self::TEXTS))));

        $before = $request->request->all();
        $request->request->set('meta', $posted);

        try {
            $widget = new MetaWizard(MetaWizard::getAttributesFromDca($config, 'meta', $file['meta'], 'meta', 'tl_files'));
            $widget->validate();
        } finally {
            $request->request->replace($before);
        }

        if ($widget->hasErrors()) {
            return false;
        }

        $versions = new Versions('tl_files', $fileId);
        $versions->setEditUrl(ltrim($this->router->generate('contao_backend', ['do' => 'files', 'act' => 'edit', 'id' => (string) $file['path']]), '/'));
        $versions->initialize();
        $this->db->update('tl_files', ['meta' => serialize($widget->value), 'tstamp' => time()], ['id' => $fileId]);
        $versions->create();
        $this->cacheTags->invalidate(['contao.db.tl_files.'.$fileId]);

        return true;
    }
}
