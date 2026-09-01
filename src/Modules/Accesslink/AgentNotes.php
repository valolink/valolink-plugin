<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Settings;

/**
 * Scratchpad an agent can leave for its future self, or for a different agent
 * working the same site later. "The client insists on 'verkkokauppa', never
 * 'nettikauppa'" is the kind of thing that otherwise gets rediscovered every
 * session.
 *
 * Stored in the module's settings rather than a table of its own: the plugin's
 * rule is to avoid custom tables unless a module truly needs one, and a hard
 * cap of MAX_NOTES × MAX_CHARS keeps the option comfortably small. It also
 * means uninstall is already handled by forget_module().
 *
 * Notes are NOT queued for approval. They are invisible to visitors and
 * bounded, so gating them behind review would only make the memory useless —
 * but they are editable and deletable in wp-admin so the operator can see
 * exactly what agents are telling each other.
 */
final class AgentNotes
{
    public const MAX_NOTES = 40;
    public const MAX_CHARS = 800;

    private const SETTING_KEY = 'notes';

    public function __construct(private readonly Settings $settings) {}

    /** @return array<int, array{id:string,created_at:string,author:?string,text:string}> newest first */
    public function all(): array
    {
        $raw = $this->settings->get_module_setting(AccesslinkModule::MODULE_ID, self::SETTING_KEY, []);

        return is_array($raw) ? array_values($raw) : [];
    }

    public function add(string $text, ?string $author): array|\WP_Error
    {
        $text = trim(sanitize_textarea_field($text));
        if ($text === '') {
            return new \WP_Error('empty_note', 'A note needs some text.', ['status' => 400]);
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            return new \WP_Error(
                'note_too_long',
                sprintf('Notes are capped at %d characters; yours was %d.', self::MAX_CHARS, mb_strlen($text)),
                ['status' => 400],
            );
        }

        $note = [
            'id'         => substr(md5(uniqid('', true)), 0, 12),
            'created_at' => current_time('mysql', true),
            'author'     => $author !== null && $author !== '' ? mb_substr($author, 0, 60) : null,
            'text'       => $text,
        ];

        // Newest first, oldest evicted past the cap. Read-modify-write on a
        // single option, so two agents writing in the same instant could lose
        // one note — acceptable at this volume, and never corrupting.
        $notes = $this->all();
        array_unshift($notes, $note);
        $notes = array_slice($notes, 0, self::MAX_NOTES);

        $this->settings->set_module_setting(AccesslinkModule::MODULE_ID, self::SETTING_KEY, $notes);

        return $note;
    }

    public function delete(string $id): bool
    {
        $notes = $this->all();
        $kept = array_values(array_filter($notes, static fn (array $n): bool => ($n['id'] ?? '') !== $id));
        if (count($kept) === count($notes)) {
            return false;
        }

        $this->settings->set_module_setting(AccesslinkModule::MODULE_ID, self::SETTING_KEY, $kept);

        return true;
    }

    public function clear(): void
    {
        $this->settings->set_module_setting(AccesslinkModule::MODULE_ID, self::SETTING_KEY, []);
    }

    /**
     * Notes rendered for the guide, newest first, stopping once the running
     * total would blow the budget — the guide is meant to be dropped into a
     * prompt whole, so it must not grow without bound as notes accumulate.
     */
    public function for_guide(int $char_budget): array
    {
        $out = 0;
        $picked = [];
        foreach ($this->all() as $note) {
            $len = mb_strlen((string) $note['text']) + 40;
            if ($out + $len > $char_budget) {
                break;
            }
            $out += $len;
            $picked[] = $note;
        }

        return $picked;
    }
}
