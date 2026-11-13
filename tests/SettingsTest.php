<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests;

use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testBeforeTheDatabaseUpdateTheDefaultsApply(): void
    {
        $settings = new Settings(TestDatabase::create());

        $this->assertSame('', $settings->backendUrl());
        $this->assertSame('', $settings->translationService());
        $this->assertSame('', $settings->aiModel());
        $this->assertSame(100, $settings->aiDailyLimit());
        $this->assertSame(100, $settings->aiRequestsLeft());
    }

    public function testTheValuesOfTheOneRecord(): void
    {
        $db = TestDatabase::create(Settings::TABLE);
        $db->insert(Settings::TABLE, ['backend_url' => ' https://manage.example.com/ ', 'translation_service' => 'deepl', 'translation_model' => 'provider/translation&#45;model', 'ai_model' => ' provider&#47;writing-model ', 'ai_daily_limit' => 20]);

        $settings = new Settings($db);

        $this->assertSame('https://manage.example.com', $settings->backendUrl());
        $this->assertSame('deepl', $settings->translationService());
        $this->assertSame('provider/translation-model', $settings->translationModel());
        $this->assertSame('provider/writing-model', $settings->aiModel());
        $this->assertSame(20, $settings->aiDailyLimit());
    }

    public function testTheWritingAidStopsAtTheLimitOfTheDay(): void
    {
        $db = TestDatabase::create(Settings::TABLE);
        $db->insert(Settings::TABLE, ['ai_daily_limit' => 2]);
        $settings = new Settings($db);

        $this->assertTrue($settings->takeAiRequest());
        $this->assertSame(1, $settings->aiRequestsLeft());
        $this->assertTrue($settings->takeAiRequest());
        $this->assertSame(0, $settings->aiRequestsLeft());
        $this->assertFalse($settings->takeAiRequest());
        $this->assertSame(2, (int) $db->fetchOne('SELECT ai_usage_count FROM '.Settings::TABLE));
        $this->assertSame(date('Y-m-d'), $db->fetchOne('SELECT ai_usage_day FROM '.Settings::TABLE));
    }

    public function testANewDayStartsAgain(): void
    {
        $db = TestDatabase::create(Settings::TABLE);
        $db->insert(Settings::TABLE, ['ai_daily_limit' => 2, 'ai_usage_day' => '2000-01-01', 'ai_usage_count' => 2]);
        $settings = new Settings($db);

        $this->assertSame(2, $settings->aiRequestsLeft());
        $this->assertTrue($settings->takeAiRequest());
        $this->assertSame(1, (int) $db->fetchOne('SELECT ai_usage_count FROM '.Settings::TABLE));
    }

    public function testALimitOfZeroAllowsNothingAndTheRecordIsCreatedWhenMissing(): void
    {
        $db = TestDatabase::create(Settings::TABLE);
        $db->insert(Settings::TABLE, ['ai_daily_limit' => 0]);
        $this->assertFalse((new Settings($db))->takeAiRequest());

        $empty = TestDatabase::create(Settings::TABLE);
        $this->assertTrue((new Settings($empty))->takeAiRequest());
        $this->assertSame(1, (int) $empty->fetchOne('SELECT COUNT(*) FROM '.Settings::TABLE));
        $this->assertSame((new Settings($empty))->ensureRow(), (new Settings($empty))->ensureRow());
    }
}
