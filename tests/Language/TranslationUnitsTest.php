<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\StringUtil;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use PHPUnit\Framework\TestCase;

/**
 * Element 50 (English) and its German version 60 are of a type of another
 * extension ("stage"): own text fields, a choice, a link, an image and entries
 * of a child table with slots (tl_card: "trust" with title and text, "scene"
 * with a title). The image is file 7, with metadata in English and German.
 */
final class TranslationUnitsTest extends TestCase
{
    use EditorFixture;

    /** A version-1 UUID as Contao's file system makes them (Validator::isBinaryUuid()). */
    private const UUID = "\x9e\x47\xa0\xf6\x1f\x5e\x11\xef\x8d\x2c\x02\x42\xac\x12\x00\x02";

    protected function setUp(): void
    {
        $this->setUpEditor();

        $dca = &$GLOBALS['TL_DCA']['tl_content'];
        $dca['palettes']['stage'] = '{type_legend},type,headline,sub;{text_legend},text,hint;{link_legend},url,cta_label,variant;{image_legend},singleSRC;{expert_legend:hide},cssID';
        $dca['fields'] += [
            'sub' => ['label' => ['Subtitle'], 'inputType' => 'text', 'eval' => ['maxlength' => 20]],
            'hint' => ['label' => ['Note'], 'inputType' => 'textarea'],
            'cta_label' => ['label' => ['Button text'], 'inputType' => 'text', 'eval' => ['maxlength' => 255]],
            'variant' => ['inputType' => 'select', 'options' => ['light', 'dark']],
        ];
        unset($dca);

        ContaoState::dca('tl_card', [
            'config' => ['ptable' => 'tl_content'],
            'palettes' => ['__selector__' => ['slot'], 'default' => '{slot_legend},slot', 'trust' => '{slot_legend},slot;{text_legend},title,text', 'scene' => '{slot_legend},slot;{text_legend},title'],
            'fields' => [
                'slot' => ['inputType' => 'select', 'options' => ['trust', 'scene'], 'reference' => ['trust' => ['Trust row'], 'scene' => ['Scene']]],
                'title' => ['label' => ['Title'], 'inputType' => 'text', 'eval' => ['maxlength' => 255]],
                'text' => ['label' => ['Text'], 'inputType' => 'textarea', 'eval' => ['rte' => 'tinyMCE']],
            ],
        ]);
        ContaoState::dca('tl_files', [
            'config' => [],
            'fields' => ['meta' => ['inputType' => 'metaWizard', 'eval' => ['allowHtml' => true, 'multiple' => true, 'metaFields' => ['title' => 'maxlength="255"', 'alt' => 'maxlength="255"', 'link' => ['attributes' => 'maxlength="2048"', 'dcaPicker' => true], 'caption' => ['type' => 'textarea', 'basicEntities' => true]]]]],
        ]);
        ContaoState::language('tl_files');

        foreach (["sub varchar(255) NOT NULL DEFAULT ''", 'hint text NULL', "cta_label varchar(255) NOT NULL DEFAULT ''", "variant varchar(16) NOT NULL DEFAULT ''", 'cssID varchar(255) NULL', 'size varchar(64) NULL'] as $column) {
            $this->db->executeStatement('ALTER TABLE tl_content ADD COLUMN '.$column);
        }

        foreach (["slot varchar(32) NOT NULL DEFAULT ''", 'text text NULL'] as $column) {
            $this->db->executeStatement('ALTER TABLE tl_card ADD COLUMN '.$column);
        }

        $this->db->insert('tl_files', ['id' => 7, 'uuid' => self::UUID, 'type' => 'file', 'path' => 'files/media/hotel.jpg', 'extension' => 'jpg', 'name' => 'hotel.jpg', 'meta' => serialize([
            'en' => ['title' => 'Hotel', 'alt' => 'Hotel at night', 'link' => '', 'caption' => 'Our house'],
            'de' => ['title' => '', 'alt' => 'Hotel bei Nacht', 'link' => '', 'caption' => ''],
        ])]);

        $this->db->insert('tl_content', ['id' => 50, 'pid' => 3, 'type' => 'stage', 'headline' => serialize(['value' => 'Welcome', 'unit' => 'h1']), 'sub' => 'Since 1902', 'text' => '<p>Rooms &amp; suites</p>', 'hint' => "Line one\nLine two", 'url' => '{{link_url::5}}', 'cta_label' => 'Book now', 'variant' => 'dark', 'cssID' => serialize(['', 'hero']), 'singleSRC' => self::UUID]);
        $this->db->insert('tl_content', ['id' => 60, 'pid' => 4, 'type' => 'stage', 'headline' => serialize(['value' => 'Willkommen', 'unit' => 'h2']), 'text' => '', 'hint' => '', 'url' => '{{link_url::6}}', 'cta_label' => 'Jetzt buchen', 'variant' => 'dark', 'singleSRC' => self::UUID]);

        $this->db->insert('tl_card', ['id' => 1, 'pid' => 50, 'ptable' => 'tl_content', 'sorting' => 1, 'slot' => 'trust', 'title' => 'Family run', 'text' => '<p>Since 1902</p>']);
        $this->db->insert('tl_card', ['id' => 2, 'pid' => 50, 'ptable' => 'tl_content', 'sorting' => 2, 'slot' => 'scene', 'title' => 'Lake']);
        $this->db->insert('tl_card', ['id' => 3, 'pid' => 50, 'ptable' => 'tl_content', 'sorting' => 3, 'slot' => 'trust', 'title' => 'Quiet', 'text' => '']);
        $this->db->insert('tl_card', ['id' => 11, 'pid' => 60, 'ptable' => 'tl_content', 'sorting' => 1, 'slot' => 'trust', 'title' => 'Familiengeführt', 'text' => '']);
        $this->db->insert('tl_card', ['id' => 12, 'pid' => 60, 'ptable' => 'tl_content', 'sorting' => 2, 'slot' => 'scene', 'title' => '']);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testEveryTextOfTheElementItsEntriesAndItsImageIsCovered(): void
    {
        $units = array_column($this->translationUnits()->pair($this->row(50), $this->row(60), 'en', 'de'), null, 'address');

        $this->assertSame([
            'e|headline' => ['Welcome', 'Willkommen', true],
            'e|sub' => ['Since 1902', '', true],
            'e|text' => ['<p>Rooms &amp; suites</p>', '', true],
            'e|hint' => ["Line one\nLine two", '', true],
            'e|cta_label' => ['Book now', 'Jetzt buchen', true],
            'e|@singleSRC.0.title' => ['Hotel', '', true],
            'e|@singleSRC.0.alt' => ['Hotel at night', 'Hotel bei Nacht', true],
            'e|@singleSRC.0.caption' => ['Our house', '', true],
            'c.tl_card.trust.0|title' => ['Family run', 'Familiengeführt', true],
            'c.tl_card.trust.0|text' => ['<p>Since 1902</p>', '', true],
            'c.tl_card.scene.0|title' => ['Lake', '', true],
            // The version has no second entry of this kind.
            'c.tl_card.trust.1|title' => ['Quiet', '', false],
        ], array_map(static fn (array $unit): array => [$unit['source'], $unit['target'], $unit['place']], $units));

        // Not covered: the choice, the link and the CSS ID.
        $this->assertSame('rich', $units['e|text']['kind']);
        $this->assertTrue($units['e|text']['html']);
        $this->assertFalse($units['e|sub']['html']);
        $this->assertSame('Subtitle', $units['e|sub']['label']);
        $this->assertSame('', $units['e|sub']['group']);
        $this->assertSame('Trust row 1', $units['c.tl_card.trust.0|title']['group']);
        $this->assertSame('Scene 1', $units['c.tl_card.scene.0|title']['group']);
        $this->assertSame('tl_card', $units['c.tl_card.scene.0|title']['table']);
        $this->assertStringStartsWith('hotel.jpg: ', $units['e|@singleSRC.0.alt']['label']);
        $this->assertSame(7, $units['e|@singleSRC.0.alt']['file']);
        $this->assertSame('meta', $units['e|@singleSRC.0.alt']['kind']);
    }

    public function testWithoutAVersionNothingHasAPlace(): void
    {
        $units = $this->translationUnits()->pair($this->row(50), null, 'en', 'de');

        $this->assertCount(12, $units);
        $this->assertSame([false], array_values(array_unique(array_column($units, 'place'))));
        $this->assertSame([''], array_values(array_unique(array_column($units, 'target'))));
    }

    public function testAnImageWithItsOwnTextsInTheElementKeepsThemThere(): void
    {
        $this->db->insert('tl_content', ['id' => 51, 'pid' => 3, 'type' => 'image', 'singleSRC' => self::UUID, 'overwriteMeta' => 1, 'alt' => 'Own alt', 'caption' => 'Own caption']);
        $this->db->insert('tl_content', ['id' => 52, 'pid' => 3, 'type' => 'image', 'singleSRC' => self::UUID]);

        $this->assertSame(['e|alt', 'e|caption'], array_column($this->translationUnits()->pair($this->row(51), null, 'en', 'de'), 'address'));
        $this->assertSame(['e|@singleSRC.0.title', 'e|@singleSRC.0.alt', 'e|@singleSRC.0.caption'], array_column($this->translationUnits()->pair($this->row(52), null, 'en', 'de'), 'address'));
    }

    public function testListsTablesCodeAndMarkup(): void
    {
        $this->db->insert('tl_content', ['id' => 70, 'pid' => 3, 'type' => 'list', 'headline' => serialize(['value' => 'Hotel<br>by the lake', 'unit' => 'h2']), 'listitems' => serialize(['One', 'Two'])]);
        $this->db->insert('tl_content', ['id' => 71, 'pid' => 3, 'type' => 'table', 'tableitems' => serialize([['A', 'B'], ['C', '']])]);
        $this->db->insert('tl_content', ['id' => 72, 'pid' => 3, 'type' => 'html', 'html' => '<p>Opening hours</p>']);
        $this->db->insert('tl_content', ['id' => 73, 'pid' => 3, 'type' => 'code', 'code' => 'body { color: red; }']);

        $list = array_column($this->translationUnits()->pair($this->row(70), null, 'en', 'de'), null, 'address');
        $this->assertSame(['e|headline', 'e|listitems.0', 'e|listitems.1'], array_keys($list));
        $this->assertTrue($list['e|headline']['html'], 'a line break stays a tag');
        $this->assertSame('line', $list['e|listitems.1']['kind']);

        $this->assertSame(['e|tableitems.0.0', 'e|tableitems.0.1', 'e|tableitems.1.0'], array_column($this->translationUnits()->pair($this->row(71), null, 'en', 'de'), 'address'));

        [$html] = $this->translationUnits()->pair($this->row(72), null, 'en', 'de');
        $this->assertSame(['code', 'html', true, true], [$html['kind'], $html['mode'], $html['machine'], $html['html']]);

        [$code] = $this->translationUnits()->pair($this->row(73), null, 'en', 'de');
        $this->assertSame(['code', 'text', false], [$code['kind'], $code['mode'], $code['machine']]);
    }

    public function testTheTextsOfAnElementAreSortedWithoutEmptyOnes(): void
    {
        $texts = $this->translationUnits()->texts($this->row(60), 'de');

        $this->assertSame(['c.tl_card.trust.0|title', 'e|@singleSRC.0.alt', 'e|cta_label', 'e|headline'], array_keys($texts));
        $this->assertSame('Hotel bei Nacht', $texts['e|@singleSRC.0.alt']);
    }

    public function testSavingWritesEveryTextWhereItBelongs(): void
    {
        $result = $this->translationUnits()->save($this->row(50), $this->row(60), 'en', 'de', [
            'e|headline' => 'Herzlich willkommen',
            'e|sub' => 'Seit 1902',
            'e|@singleSRC.0.caption' => 'Unser Haus',
            'c.tl_card.trust.0|text' => '<p>Seit 1902</p>',
            'c.tl_card.trust.1|title' => 'Ruhig',
            'e|unknown' => 'nothing',
        ]);

        $this->assertSame(['saved' => 4, 'failed' => ['Trust row 2: Title'], 'invalid' => []], $result);
        // The level of the version's headline stays.
        $this->assertSame(['value' => 'Herzlich willkommen', 'unit' => 'h2'], array_intersect_key(StringUtil::deserialize($this->stored('headline', 60), true), ['value' => 1, 'unit' => 1]));
        $this->assertSame('Seit 1902', $this->stored('sub', 60));
        $this->assertSame('<p>Seit 1902</p>', $this->db->fetchOne('SELECT text FROM tl_card WHERE id = 11'));
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_card WHERE pid = 60'), 'no entry is created');

        $meta = StringUtil::deserialize($this->db->fetchOne('SELECT meta FROM tl_files WHERE id = 7'), true);
        $this->assertSame('Unser Haus', $meta['de']['caption']);
        $this->assertSame('Hotel bei Nacht', $meta['de']['alt']);
        $this->assertSame('Our house', $meta['en']['caption']);
        $this->assertContains('contao.db.tl_files.7', $this->cacheTags->invalidated);
    }

    /**
     * An element written in the version first and taken over into the main
     * language: the copy still has the texts of the version, the translation
     * goes from the version into it.
     */
    public function testTheOtherWayRoundTheTextsOfTheVersionAreSavedIntoTheMainLanguage(): void
    {
        $this->db->insert('tl_content', ['id' => 90, 'pid' => 4, 'type' => 'text', 'headline' => serialize(['value' => 'Nur auf Deutsch', 'unit' => 'h2']), 'text' => '<p>Zuerst geschrieben</p>']);
        $this->db->insert('tl_content', ['id' => 91, 'pid' => 3, 'type' => 'text', 'headline' => serialize(['value' => 'Nur auf Deutsch', 'unit' => 'h2']), 'text' => '<p>Zuerst geschrieben</p>']);

        $units = array_column($this->translationUnits()->pair($this->row(90), $this->row(91), 'de', 'en'), null, 'address');
        $this->assertSame(['Nur auf Deutsch', 'Nur auf Deutsch', true], [$units['e|headline']['source'], $units['e|headline']['target'], $units['e|headline']['place']]);

        $result = $this->translationUnits()->save($this->row(90), $this->row(91), 'de', 'en', ['e|headline' => 'Only in German', 'e|text' => '<p>Written first</p>']);

        $this->assertSame(['saved' => 2, 'failed' => [], 'invalid' => []], $result);
        $this->assertSame('Only in German', StringUtil::deserialize($this->stored('headline', 91), true)['value']);
        $this->assertSame('<p>Written first</p>', $this->stored('text', 91));
        $this->assertSame('<p>Zuerst geschrieben</p>', $this->stored('text', 90), 'the version keeps its texts');
    }

    public function testRowsTheVersionLacksComeFromTheMainLanguage(): void
    {
        $this->db->insert('tl_content', ['id' => 80, 'pid' => 3, 'type' => 'list', 'listitems' => serialize(['One', 'Two', 'Three'])]);
        $this->db->insert('tl_content', ['id' => 81, 'pid' => 4, 'type' => 'list', 'listitems' => serialize(['Eins'])]);
        $this->db->insert('tl_content', ['id' => 82, 'pid' => 3, 'type' => 'table', 'tableitems' => serialize([['A', 'B'], ['C', 'D']])]);
        $this->db->insert('tl_content', ['id' => 83, 'pid' => 4, 'type' => 'table', 'tableitems' => serialize([['X', 'Y']])]);

        $units = array_column($this->translationUnits()->pair($this->row(80), $this->row(81), 'en', 'de'), 'place', 'address');
        $this->assertSame(['e|listitems.0' => true, 'e|listitems.1' => true, 'e|listitems.2' => true], $units);

        $this->assertSame(1, $this->translationUnits()->save($this->row(80), $this->row(81), 'en', 'de', ['e|listitems.2' => 'Drei'])['saved']);
        $this->assertSame(['Eins', 'Two', 'Drei'], StringUtil::deserialize($this->stored('listitems', 81)));

        $this->translationUnits()->save($this->row(82), $this->row(83), 'en', 'de', ['e|tableitems.1.1' => 'De']);
        $this->assertSame([['X', 'Y'], ['C', 'De']], StringUtil::deserialize($this->stored('tableitems', 83)));
    }

    public function testWhatTheWidgetRefusesIsNotSaved(): void
    {
        $result = $this->translationUnits()->save($this->row(50), $this->row(60), 'en', 'de', ['e|sub' => 'A subtitle far longer than twenty characters', 'e|cta_label' => 'Buchen']);

        $this->assertSame(0, $result['saved']);
        $this->assertNotSame([], $result['invalid']);
        $this->assertSame('Jetzt buchen', $this->stored('cta_label', 60));
    }

    public function testImageTextsNeedTheRightsOfTheFileManager(): void
    {
        $this->contao = static fn (string $attribute, mixed $subject): bool => ContaoCorePermissions::USER_CAN_RENAME_FILE !== $attribute;

        $result = $this->translationUnits()->save($this->row(50), $this->row(60), 'en', 'de', ['e|@singleSRC.0.title' => 'Hotel', 'e|sub' => 'Seit 1902']);

        $this->assertSame(['saved' => 1, 'failed' => ['hotel.jpg'], 'invalid' => []], $result);
        $this->assertSame('', StringUtil::deserialize($this->db->fetchOne('SELECT meta FROM tl_files WHERE id = 7'), true)['de']['title']);
    }

    /** @return array<string, mixed> */
    public function testTheRecordsAnElementShowsFromAnotherTableAreTranslatedWithIt(): void
    {
        $this->addFaq();
        $units = array_column($this->translationUnits()->pair($this->row(70), $this->row(80), 'en', 'de'), null, 'address');

        $this->assertSame([
            'c.tl_faq_category..0|title' => ['FAQ', 'Häufige Fragen', true],
            'c.tl_faq..0|question' => ['How?', 'Wie?', true],
            'c.tl_faq..0|answer' => ['<p>So.</p>', '', true],
            // The version has no second question; the alias and the page of the link are no texts.
            'c.tl_faq..1|question' => ['Why?', '', false],
            'c.tl_faq..1|answer' => ['<p>Because.</p>', '', false],
        ], array_map(static fn (array $unit): array => [$unit['source'], $unit['target'], $unit['place']], $units));
        $this->assertSame('How?', $units['c.tl_faq..0|question']['group'], 'an entry without kinds is named by its title');

        $result = $this->translationUnits()->save($this->row(70), $this->row(80), 'en', 'de', ['c.tl_faq..0|answer' => '<p>So geht es.</p>']);

        $this->assertSame(['saved' => 1, 'failed' => [], 'invalid' => []], $result);
        $this->assertSame('<p>So geht es.</p>', $this->db->fetchOne('SELECT answer FROM tl_faq WHERE id = 3'));
    }

    public function testARecordBothVersionsShowIsNeverOverwritten(): void
    {
        $this->addFaq();

        $units = $this->translationUnits()->pair($this->row(70), $this->row(90), 'en', 'de');
        $result = $this->translationUnits()->save($this->row(70), $this->row(90), 'en', 'de', ['c.tl_faq..0|question' => 'Wie?']);

        $this->assertSame([false], array_values(array_unique(array_column($units, 'place'))));
        $this->assertSame(0, $result['saved']);
        $this->assertSame('How?', $this->db->fetchOne('SELECT question FROM tl_faq WHERE id = 2'));
    }

    private function row(int $id): array
    {
        return (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);
    }
}
