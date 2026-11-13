<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle;

use Doctrine\DBAL\Connection;

/**
 * Settings of the extension: one record in tl_neo_frontendeditor_settings,
 * edited under the menu group of the extensions.
 */
final class Settings
{
    public const TABLE = 'tl_neo_frontendeditor_settings';

    public const MODULE = 'neo_frontendeditor_settings';

    /** @var array<string, mixed>|null */
    private array|null $row = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Address of the back end when it runs under its own domain, e.g.
     * "https://manage.example.com", without trailing slash; '' when the back
     * end runs under the address of the website.
     */
    public function backendUrl(): string
    {
        return rtrim(trim((string) ($this->row()['backend_url'] ?? '')), '/');
    }

    /** Translation service of the translation proposals (Plus): "azure", "deepl", "ai", '' for the first one set up. */
    public function translationService(): string
    {
        return trim((string) ($this->row()['translation_service'] ?? ''));
    }

    /** Model of the AI connection for translations, '' when none is chosen. */
    public function translationModel(): string
    {
        return trim(html_entity_decode((string) ($this->row()['translation_model'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Model of the writing aid (Pro), '' when none is chosen. */
    public function aiModel(): string
    {
        return trim(html_entity_decode((string) ($this->row()['ai_model'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Requests of the writing aid per day for all editors together. */
    public function aiDailyLimit(): int
    {
        return (int) ($this->row()['ai_daily_limit'] ?? 100);
    }

    /**
     * Counts one request of the writing aid for today. False when the limit of
     * the day is reached; then nothing is counted. One statement, so parallel
     * requests cannot pass the limit together.
     */
    public function takeAiRequest(): bool
    {
        $limit = $this->aiDailyLimit();

        if ($limit < 1) {
            return false;
        }

        $id = $this->ensureRow();
        $today = date('Y-m-d');

        $affected = $this->db->executeStatement(
            'UPDATE '.self::TABLE.' SET ai_usage_count = IF(ai_usage_day = ?, ai_usage_count + 1, 1), ai_usage_day = ? WHERE id = ? AND (ai_usage_day <> ? OR ai_usage_count < ?)',
            [$today, $today, $id, $today, $limit],
        );

        $this->row = null;

        return $affected > 0;
    }

    /** Requests of the writing aid left today. */
    public function aiRequestsLeft(): int
    {
        $row = $this->row();
        $used = ($row['ai_usage_day'] ?? '') === date('Y-m-d') ? (int) ($row['ai_usage_count'] ?? 0) : 0;

        return max(0, $this->aiDailyLimit() - $used);
    }

    /** Id of the one record, created when missing. */
    public function ensureRow(): int
    {
        $id = $this->db->fetchOne('SELECT id FROM '.self::TABLE.' ORDER BY id LIMIT 1');

        if (false !== $id) {
            return (int) $id;
        }

        $this->db->insert(self::TABLE, ['tstamp' => time()]);
        $this->row = null;

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        if (null === $this->row) {
            try {
                $this->row = $this->db->fetchAssociative('SELECT * FROM '.self::TABLE.' ORDER BY id LIMIT 1') ?: [];
            } catch (\Throwable) {
                // Table not created yet (before the database update): defaults.
                $this->row = [];
            }
        }

        return $this->row;
    }
}
