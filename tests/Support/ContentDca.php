<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\DC_Table;

/**
 * DCA of tl_content and tl_module as Contao 5 builds them, reduced to the
 * element types, fields and palettes the tests need: the same input types,
 * evaluation options and subpalettes as Contao's own definitions.
 */
final class ContentDca
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg'];

    /** @return array<string, mixed> */
    public static function content(): array
    {
        return [
            'config' => ['dataContainer' => DC_Table::class, 'ctable' => ['tl_content', 'tl_card']],
            'palettes' => [
                '__selector__' => ['type', 'addImage', 'overwriteMeta'],
                'default' => '{type_legend},type,headline',
                'headline' => '{type_legend},type,headline;{invisible_legend:hide},invisible,start',
                'text' => '{type_legend},type,headline;{text_legend},text;{image_legend},addImage;{expert_legend:hide},cssID',
                'image' => '{type_legend},type,headline;{source_legend},singleSRC,size,overwriteMeta;{expert_legend:hide},cssID',
                'html' => '{type_legend},type;{text_legend},html',
                'code' => '{type_legend},type,headline;{text_legend},code',
                'list' => '{type_legend},type,headline;{list_legend},listitems',
                'table' => '{type_legend},type,headline;{table_legend},tableitems',
                'hyperlink' => '{type_legend},type,headline;{link_legend},url,linkTitle,titleText',
                'video' => '{type_legend},type,headline;{source_legend},playerSRC,posterSRC',
                'markdown' => '{type_legend},type,headline;{text_legend},text',
                // An element of a website that shows the questions of a FAQ category (a relation to another table).
                'faq_list' => '{type_legend},type,headline;{faq_legend},faq_category,jumpTo',
            ],
            'subpalettes' => [
                'addImage' => 'singleSRC,size,overwriteMeta',
                'overwriteMeta' => 'alt,imageTitle,imageUrl,caption',
            ],
            'fields' => [
                'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
                'type' => ['inputType' => 'select', 'sql' => ['name' => 'type', 'type' => 'string', 'length' => 64, 'default' => 'text']],
                'headline' => ['inputType' => 'inputUnit', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'eval' => ['maxlength' => 200, 'basicEntities' => true]],
                'text' => ['inputType' => 'textarea', 'eval' => ['mandatory' => true, 'rte' => 'tinyMCE', 'helpwizard' => true, 'basicEntities' => true]],
                'html' => ['inputType' => 'textarea', 'eval' => ['mandatory' => true, 'allowHtml' => true, 'class' => 'monospace', 'rte' => 'ace|html']],
                'code' => ['inputType' => 'textarea', 'eval' => ['mandatory' => true, 'preserveTags' => true, 'decodeEntities' => true, 'class' => 'monospace', 'rte' => 'ace']],
                'addImage' => ['inputType' => 'checkbox', 'eval' => ['submitOnChange' => true]],
                'singleSRC' => ['inputType' => 'fileTree', 'eval' => ['fieldType' => 'radio', 'filesOnly' => true, 'extensions' => implode(',', self::IMAGE_EXTENSIONS), 'mandatory' => true]],
                'size' => ['inputType' => 'imageSize', 'eval' => ['rgxp' => 'natural', 'includeBlankOption' => true]],
                'overwriteMeta' => ['inputType' => 'checkbox', 'eval' => ['submitOnChange' => true]],
                'alt' => ['inputType' => 'text', 'eval' => ['maxlength' => 255]],
                'imageTitle' => ['inputType' => 'text', 'eval' => ['maxlength' => 255]],
                'imageUrl' => ['inputType' => 'text', 'eval' => ['rgxp' => 'url', 'decodeEntities' => true, 'maxlength' => 2048, 'dcaPicker' => true]],
                'caption' => ['inputType' => 'text', 'eval' => ['maxlength' => 255, 'allowHtml' => true]],
                'listitems' => ['inputType' => 'listWizard', 'eval' => ['multiple' => true, 'allowHtml' => true]],
                'tableitems' => ['inputType' => 'tableWizard', 'eval' => ['multiple' => true, 'allowHtml' => true]],
                'url' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'rgxp' => 'url', 'decodeEntities' => true, 'maxlength' => 2048, 'dcaPicker' => true]],
                'linkTitle' => ['inputType' => 'text', 'eval' => ['maxlength' => 255]],
                'titleText' => ['inputType' => 'text', 'eval' => ['maxlength' => 255, 'readonly' => true]],
                'playerSRC' => ['inputType' => 'fileTree', 'eval' => ['multiple' => true, 'fieldType' => 'checkbox', 'filesOnly' => true, 'extensions' => 'mp4,m4v,mov,wmv,webm,ogv']],
                'posterSRC' => ['inputType' => 'fileTree', 'eval' => ['fieldType' => 'radio', 'filesOnly' => true, 'extensions' => 'pdf,zip']],
                'cssID' => ['inputType' => 'text', 'eval' => ['multiple' => true, 'size' => 2]],
                'invisible' => ['inputType' => 'checkbox'],
                'start' => ['inputType' => 'text', 'eval' => ['rgxp' => 'datim', 'datepicker' => true]],
                'faq_category' => ['inputType' => 'select', 'foreignKey' => 'tl_faq_category.title', 'relation' => ['type' => 'hasOne', 'load' => 'lazy']],
                'jumpTo' => ['inputType' => 'pageTree', 'foreignKey' => 'tl_page.title', 'relation' => ['type' => 'hasOne', 'load' => 'lazy']],
            ],
        ];
    }

    /**
     * A FAQ category with its questions (Contao's FAQ bundle, shortened).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function faq(): array
    {
        return [
            'tl_faq_category' => [
                'config' => ['dataContainer' => DC_Table::class, 'ctable' => ['tl_faq']],
                'list' => ['label' => ['fields' => ['title']]],
                'palettes' => ['default' => '{title_legend},title'],
                'fields' => ['title' => ['inputType' => 'text', 'eval' => ['maxlength' => 255]]],
            ],
            'tl_faq' => [
                'config' => ['dataContainer' => DC_Table::class, 'ptable' => 'tl_faq_category'],
                'list' => ['label' => ['fields' => ['question']]],
                'palettes' => ['default' => '{title_legend},question,alias;{answer_legend},answer'],
                'fields' => [
                    'question' => ['inputType' => 'text', 'eval' => ['maxlength' => 255]],
                    'alias' => ['inputType' => 'text', 'eval' => ['rgxp' => 'alias', 'maxlength' => 255]],
                    'answer' => ['inputType' => 'textarea', 'eval' => ['rte' => 'tinyMCE']],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function module(): array
    {
        return [
            'config' => ['dataContainer' => DC_Table::class],
            'palettes' => [
                '__selector__' => ['type'],
                'default' => '{title_legend},name,type',
                'html' => '{title_legend},name,type;{html_legend},html',
                'search' => '{title_legend},name,headline,type;{config_legend},queryType,fuzzy',
                'secret' => '{title_legend},name,headline,type;{config_legend},password',
            ],
            'fields' => [
                'name' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'maxlength' => 255]],
                'type' => ['inputType' => 'select'],
                'headline' => ['inputType' => 'inputUnit', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'eval' => ['maxlength' => 200, 'basicEntities' => true]],
                'html' => ['inputType' => 'textarea', 'eval' => ['mandatory' => true, 'allowHtml' => true, 'class' => 'monospace', 'rte' => 'ace|html']],
                'queryType' => ['inputType' => 'select', 'options' => ['and', 'or']],
                'fuzzy' => ['inputType' => 'checkbox'],
                'password' => ['inputType' => 'text', 'eval' => ['hideInput' => true]],
            ],
        ];
    }
}
