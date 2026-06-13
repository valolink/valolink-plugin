<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Scripts;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Module: Scripts — admin-managed JavaScript snippets (inline or external URL)
 * with fine-grained loading strategy (head/async/defer/footer/on-interaction/on-scroll).
 */
final class ScriptsModule implements Module
{
    public const MODULE_ID     = 'scripts';
    public const SUBPAGE_SLUG  = 'valolink-scripts';
    public const NONCE_SAVE    = 'valolink_save_script';
    public const NONCE_DELETE  = 'valolink_delete_script';
    public const NONCE_TOGGLE  = 'valolink_toggle_script';
    public const SAVE_ACTION   = 'valolink_save_script';
    public const DELETE_ACTION = 'valolink_delete_script';
    public const TOGGLE_ACTION = 'valolink_toggle_script';

    public const STRATEGY_HEAD           = 'head';
    public const STRATEGY_HEAD_ASYNC     = 'head_async';
    public const STRATEGY_HEAD_DEFER     = 'head_defer';
    public const STRATEGY_FOOTER         = 'footer';
    public const STRATEGY_ON_INTERACTION = 'on_interaction';
    public const STRATEGY_ON_SCROLL      = 'on_scroll';

    public const TYPE_INLINE   = 'inline';
    public const TYPE_EXTERNAL = 'external';

    /** Events that count as "user interaction" for lazy-load strategies. */
    private const INTERACTION_EVENTS = ['scroll', 'mousemove', 'touchstart', 'keydown', 'click'];

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        return true;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION,   [$this, 'handle_save']);
        add_action('admin_post_' . self::DELETE_ACTION, [$this, 'handle_delete']);
        add_action('admin_post_' . self::TOGGLE_ACTION, [$this, 'handle_toggle']);

        // Emit on the right hooks. Priority 99 = after WP's own scripts.
        add_action('wp_head',       [$this, 'emit_frontend_head'],   99);
        add_action('wp_footer',     [$this, 'emit_frontend_footer'], 99);
        add_action('admin_head',    [$this, 'emit_admin_head'],      99);
        add_action('admin_print_footer_scripts', [$this, 'emit_admin_footer'], 99);

        // CodeMirror for the inline-code textarea, on the edit screen only.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_code_editor']);
    }

    public function enqueue_code_editor(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::SUBPAGE_SLUG) return;
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if (!in_array($action, ['new', 'edit'], true)) return;

        $cm = wp_enqueue_code_editor(['type' => 'application/javascript']);
        if ($cm === false) return; // user has disabled the syntax editor in their profile

        wp_enqueue_script('jquery');
        wp_add_inline_script('code-editor', sprintf(
            "jQuery(function(){ if (window.wp && wp.codeEditor) { wp.codeEditor.initialize('vl-code', %s); } });",
            wp_json_encode($cm),
        ));
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Snippet storage
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function get_snippets(): array
    {
        $stored = (array) $this->settings->get_module_setting(self::MODULE_ID, 'snippets', []);
        return array_values(array_map([$this, 'normalise_snippet'], $stored));
    }

    private function get_snippet(string $id): ?array
    {
        foreach ($this->get_snippets() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $snippets */
    private function save_snippets(array $snippets): void
    {
        $this->settings->set_module_setting(self::MODULE_ID, 'snippets', array_values($snippets));
    }

    /** @return array<string, mixed> */
    private function normalise_snippet(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }
        $include = (array) ($raw['include_urls'] ?? []);
        $exclude = (array) ($raw['exclude_urls'] ?? []);
        return [
            'id'              => (string) ($raw['id'] ?? wp_generate_uuid4()),
            'name'            => (string) ($raw['name'] ?? ''),
            'enabled'         => !empty($raw['enabled']),
            'type'            => in_array($raw['type'] ?? '', [self::TYPE_INLINE, self::TYPE_EXTERNAL], true)
                ? $raw['type']
                : self::TYPE_EXTERNAL,
            'url'             => (string) ($raw['url'] ?? ''),
            'code'            => (string) ($raw['code'] ?? ''),
            'strategy'        => in_array($raw['strategy'] ?? '', self::strategies(), true)
                ? $raw['strategy']
                : self::STRATEGY_FOOTER,
            'placement'       => [
                'frontend'        => !empty($raw['placement']['frontend']) || !isset($raw['placement']),
                'admin'           => !empty($raw['placement']['admin']),
                'logged_in_only'  => !empty($raw['placement']['logged_in_only']),
                'logged_out_only' => !empty($raw['placement']['logged_out_only']),
            ],
            'include_urls'    => array_values(array_filter(array_map([$this, 'normalise_pattern'], $include), static fn($v) => $v !== '')),
            'exclude_urls'    => array_values(array_filter(array_map([$this, 'normalise_pattern'], $exclude), static fn($v) => $v !== '')),
            'consent_enabled' => !empty($raw['consent_enabled']),
            'consent_event'   => (string) ($raw['consent_event'] ?? ''),
        ];
    }

    private function normalise_pattern(mixed $pattern): string
    {
        $pattern = trim((string) $pattern);
        if ($pattern === '') return '';
        // Strip protocol+host if user pasted a full URL.
        if (preg_match('#^https?://#i', $pattern)) {
            $parts   = parse_url($pattern);
            $pattern = ($parts['path'] ?? '/');
        }
        // Strip query string.
        $pattern = explode('?', $pattern, 2)[0];
        // Ensure leading slash.
        if ($pattern === '' || $pattern[0] !== '/') $pattern = '/' . $pattern;
        // Strip trailing slash unless it's root.
        if ($pattern !== '/' && str_ends_with($pattern, '/')) $pattern = rtrim($pattern, '/');
        return $pattern;
    }

    private function current_path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return $this->normalise_pattern(explode('?', $uri, 2)[0]);
    }

    /** @param array<int, string> $patterns */
    private function url_matches(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') continue;
            if ($pattern === $path) return true;
            // Glob: * matches any sequence of chars
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $path)) return true;
        }
        return false;
    }

    /** @return list<string> */
    private static function strategies(): array
    {
        return [
            self::STRATEGY_HEAD,
            self::STRATEGY_HEAD_ASYNC,
            self::STRATEGY_HEAD_DEFER,
            self::STRATEGY_FOOTER,
            self::STRATEGY_ON_INTERACTION,
            self::STRATEGY_ON_SCROLL,
        ];
    }

    // -------------------------------------------------------------------------
    // Emit
    // -------------------------------------------------------------------------

    public function emit_frontend_head(): void
    {
        $this->emit(false, [self::STRATEGY_HEAD, self::STRATEGY_HEAD_ASYNC, self::STRATEGY_HEAD_DEFER]);
    }

    public function emit_frontend_footer(): void
    {
        $this->emit(false, [self::STRATEGY_FOOTER, self::STRATEGY_ON_INTERACTION, self::STRATEGY_ON_SCROLL]);
    }

    public function emit_admin_head(): void
    {
        $this->emit(true, [self::STRATEGY_HEAD, self::STRATEGY_HEAD_ASYNC, self::STRATEGY_HEAD_DEFER]);
    }

    public function emit_admin_footer(): void
    {
        $this->emit(true, [self::STRATEGY_FOOTER, self::STRATEGY_ON_INTERACTION, self::STRATEGY_ON_SCROLL]);
    }

    /** @param array<int, string> $strategies */
    private function emit(bool $is_admin, array $strategies): void
    {
        foreach ($this->get_snippets() as $snippet) {
            if (!$snippet['enabled']) {
                continue;
            }
            if (!in_array($snippet['strategy'], $strategies, true)) {
                continue;
            }
            if (!$this->placement_matches($snippet, $is_admin)) {
                continue;
            }
            $html = $this->render_snippet($snippet);
            if ($html === '') {
                continue;
            }
            // Mark our snippets for easy debugging in DOM.
            echo "<!-- valolink-script: {$this->escape_comment($snippet['name'])} -->\n";
            echo $html . "\n";
        }
    }

    private function placement_matches(array $snippet, bool $is_admin): bool
    {
        $p = $snippet['placement'];
        if ($is_admin && empty($p['admin']))      return false;
        if (!$is_admin && empty($p['frontend'])) return false;

        if (!empty($p['logged_in_only'])  && !is_user_logged_in()) return false;
        if (!empty($p['logged_out_only']) &&  is_user_logged_in()) return false;

        // URL include/exclude
        $include = $snippet['include_urls'] ?? [];
        $exclude = $snippet['exclude_urls'] ?? [];
        if (!empty($include) || !empty($exclude)) {
            $path = $this->current_path();
            if (!empty($include) && !$this->url_matches($include, $path)) return false;
            if (!empty($exclude) &&  $this->url_matches($exclude, $path)) return false;
        }

        return true;
    }

    private function render_snippet(array $snippet): string
    {
        $is_external    = $snippet['type'] === self::TYPE_EXTERNAL;
        $strategy       = $snippet['strategy'];
        $consent_event  = !empty($snippet['consent_enabled']) ? trim((string) $snippet['consent_event']) : '';
        $events         = match ($strategy) {
            self::STRATEGY_ON_INTERACTION => self::INTERACTION_EVENTS,
            self::STRATEGY_ON_SCROLL      => ['scroll'],
            default                       => [],
        };
        $needs_loader = $consent_event !== '' || !empty($events);

        if ($is_external) {
            $url = esc_url($snippet['url']);
            if ($url === '') {
                return '';
            }
            if ($needs_loader) {
                $url_js    = wp_json_encode($url);
                $action_js = 'var s=document.createElement("script");s.src=' . $url_js . ';s.async=true;document.head.appendChild(s);';
                return $this->loader_unified($action_js, $events, $consent_event);
            }
            switch ($strategy) {
                case self::STRATEGY_HEAD:
                case self::STRATEGY_FOOTER:    return '<script src="' . $url . '"></script>';
                case self::STRATEGY_HEAD_ASYNC: return '<script async src="' . $url . '"></script>';
                case self::STRATEGY_HEAD_DEFER: return '<script defer src="' . $url . '"></script>';
            }
            return '';
        }

        // Inline
        $code = $this->safe_inline_js($snippet['code']);
        if ($code === '') {
            return '';
        }
        if ($needs_loader) {
            return $this->loader_unified($code, $events, $consent_event);
        }
        return '<script>' . $code . '</script>';
    }

    /**
     * Unified loader that fires $action_js only after every configured gate is met:
     *  - $events:        first user interaction from this list (passive, once)
     *  - $consent_event: dataLayer push with `{ event: '<name>' }`
     *
     * Pass empty $events / empty $consent_event to skip that gate. If both are
     * empty, the caller should emit the script directly — don't use this wrapper.
     */
    private function loader_unified(string $action_js, array $events, string $consent_event): string
    {
        $events_js   = wp_json_encode($events);
        $consent_js  = $consent_event === '' ? 'null' : wp_json_encode($consent_event);
        $need_inter  = empty($events)        ? 'false' : 'true';
        $need_consent = $consent_event === '' ? 'false' : 'true';

        return '<script>(function(){' .
            'var fired=false,needInter=' . $need_inter . ',needConsent=' . $need_consent . ';' .
            'var EV=' . $events_js . ',CE=' . $consent_js . ';' .
            'function go(){' .
                'if(fired||needInter||needConsent)return;' .
                'fired=true;' .
                'EV.forEach(function(x){document.removeEventListener(x,oi)});' .
                'try{' . $action_js . '}catch(e){if(window.console)console.error(e)}' .
            '}' .
            'function oi(){needInter=false;go()}' .
            'EV.forEach(function(x){document.addEventListener(x,oi,{once:true,passive:true})});' .
            'if(CE){' .
                'window.dataLayer=window.dataLayer||[];' .
                'function cc(){' .
                    'for(var i=0;i<window.dataLayer.length;i++){' .
                        'var d=window.dataLayer[i];' .
                        'if(d&&d.event===CE){needConsent=false;go();return true}' .
                    '}' .
                    'return false' .
                '}' .
                'if(!cc()){' .
                    'var op=window.dataLayer.push;' .
                    'window.dataLayer.push=function(){' .
                        'var r=op.apply(this,arguments);cc();return r' .
                    '}' .
                '}' .
            '}' .
        '})();</script>';
    }

    /** Defang `</script>` so user inline code can't break out of the wrapper. */
    private function safe_inline_js(string $code): string
    {
        return str_ireplace('</script>', '<\/script>', $code);
    }

    private function escape_comment(string $text): string
    {
        return str_replace(['<', '>', '--'], ['&lt;', '&gt;', '- -'], $text);
    }

    // -------------------------------------------------------------------------
    // Admin: page + actions
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Scripts', 'valolink-plugin'),
            __('Scripts', 'valolink-plugin'),
            'manage_options',
            self::SUBPAGE_SLUG,
            [$this, 'render_settings_page'],
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $id     = isset($_GET['id'])     ? sanitize_text_field(wp_unslash($_GET['id'])) : '';

        if ($action === 'new' || $action === 'edit') {
            $this->render_edit_form($action === 'edit' ? $id : '');
            return;
        }

        $this->render_list();
    }

    private function render_list(): void
    {
        $snippets = $this->get_snippets();
        $updated  = isset($_GET['updated']) && $_GET['updated'] === '1';
        $deleted  = isset($_GET['deleted']) && $_GET['deleted'] === '1';
        $new_url  = esc_url(add_query_arg(['page' => self::SUBPAGE_SLUG, 'action' => 'new'], admin_url('admin.php')));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Scripts', 'valolink-plugin'); ?></h1>
            <a href="<?php echo $new_url; ?>" class="page-title-action"><?php esc_html_e('Add New', 'valolink-plugin'); ?></a>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Script saved.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>
            <?php if ($deleted) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Script deleted.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>

            <p class="description" style="margin-top: 12px;">
                <?php esc_html_e('Inline JavaScript snippets and external script URLs, with control over when they load. Frontend/admin and logged-in/logged-out filters per snippet.', 'valolink-plugin'); ?>
            </p>

            <table class="widefat striped" style="margin-top: 12px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e('On', 'valolink-plugin'); ?></th>
                        <th><?php esc_html_e('Name', 'valolink-plugin'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Type', 'valolink-plugin'); ?></th>
                        <th style="width:160px;"><?php esc_html_e('Strategy', 'valolink-plugin'); ?></th>
                        <th><?php esc_html_e('Target', 'valolink-plugin'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Actions', 'valolink-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($snippets)) : ?>
                        <tr><td colspan="6"><em><?php esc_html_e('No scripts yet. Add one to get started.', 'valolink-plugin'); ?></em></td></tr>
                    <?php endif; ?>

                    <?php foreach ($snippets as $s) :
                        $edit_url   = esc_url(add_query_arg(['page' => self::SUBPAGE_SLUG, 'action' => 'edit', 'id' => $s['id']], admin_url('admin.php')));
                        $toggle_url = esc_url(wp_nonce_url(
                            admin_url('admin-post.php?action=' . self::TOGGLE_ACTION . '&id=' . urlencode($s['id'])),
                            self::NONCE_TOGGLE,
                        ));
                        $delete_url = esc_url(wp_nonce_url(
                            admin_url('admin-post.php?action=' . self::DELETE_ACTION . '&id=' . urlencode($s['id'])),
                            self::NONCE_DELETE,
                        ));
                        $targets = [];
                        if (!empty($s['placement']['frontend'])) $targets[] = __('frontend', 'valolink-plugin');
                        if (!empty($s['placement']['admin']))    $targets[] = __('admin',    'valolink-plugin');
                        if (!empty($s['placement']['logged_in_only']))  $targets[] = __('logged-in only',  'valolink-plugin');
                        if (!empty($s['placement']['logged_out_only'])) $targets[] = __('logged-out only', 'valolink-plugin');
                        if (!empty($s['include_urls'])) $targets[] = sprintf(_n('%d URL filter', '%d URL filters', count($s['include_urls']) + count($s['exclude_urls']), 'valolink-plugin'), count($s['include_urls']) + count($s['exclude_urls']));
                        elseif (!empty($s['exclude_urls'])) $targets[] = sprintf(_n('%d URL filter', '%d URL filters', count($s['exclude_urls']), 'valolink-plugin'), count($s['exclude_urls']));
                        if (!empty($s['consent_enabled']) && !empty($s['consent_event'])) {
                            $targets[] = sprintf(__('consent: %s', 'valolink-plugin'), $s['consent_event']);
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo $toggle_url; ?>"
                                   title="<?php echo $s['enabled']
                                       ? esc_attr__('Click to disable', 'valolink-plugin')
                                       : esc_attr__('Click to enable',  'valolink-plugin'); ?>">
                                    <?php echo $s['enabled']
                                        ? '<span style="color:#118a4c;font-weight:600;">●</span>'
                                        : '<span style="color:#a7aaad;">○</span>'; ?>
                                </a>
                            </td>
                            <td>
                                <strong><a href="<?php echo $edit_url; ?>"><?php echo esc_html($s['name'] ?: '(untitled)'); ?></a></strong>
                            </td>
                            <td><?php echo esc_html(self::type_label($s['type'])); ?></td>
                            <td><?php echo esc_html(self::strategy_label($s['strategy'])); ?></td>
                            <td><?php echo esc_html(implode(', ', $targets) ?: '—'); ?></td>
                            <td>
                                <a href="<?php echo $edit_url; ?>"><?php esc_html_e('Edit', 'valolink-plugin'); ?></a>
                                &nbsp;|&nbsp;
                                <a href="<?php echo $delete_url; ?>"
                                   onclick="return confirm('<?php echo esc_js(__('Delete this script?', 'valolink-plugin')); ?>')"
                                   style="color:#b32d2e;">
                                    <?php esc_html_e('Delete', 'valolink-plugin'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_edit_form(string $id): void
    {
        $editing = $id !== '';
        $snippet = $editing ? $this->get_snippet($id) : null;

        if ($editing && $snippet === null) {
            echo '<div class="wrap"><h1>' . esc_html__('Script', 'valolink-plugin') . '</h1>';
            echo '<p>' . esc_html__('Not found.', 'valolink-plugin') . '</p></div>';
            return;
        }

        $snippet ??= $this->normalise_snippet(['enabled' => true]);
        $back_url = esc_url(add_query_arg(['page' => self::SUBPAGE_SLUG], admin_url('admin.php')));
        ?>
        <div class="wrap">
            <h1><?php echo $editing
                ? esc_html__('Edit Script', 'valolink-plugin')
                : esc_html__('Add Script', 'valolink-plugin'); ?></h1>

            <p><a href="<?php echo $back_url; ?>">← <?php esc_html_e('Back to list', 'valolink-plugin'); ?></a></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr($snippet['id']); ?>">
                <?php wp_nonce_field(self::NONCE_SAVE); ?>

                <table class="form-table"><tbody>
                    <tr>
                        <th scope="row"><label for="vl-name"><?php esc_html_e('Name', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-name" type="text" name="name" class="regular-text" required
                                   value="<?php echo esc_attr($snippet['name']); ?>">
                            <p class="description"><?php esc_html_e('For your own reference (not output anywhere visible).', 'valolink-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enabled', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($snippet['enabled']); ?>>
                                <?php esc_html_e('Output this script', 'valolink-plugin'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Type', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="radio" name="type" value="<?php echo esc_attr(self::TYPE_EXTERNAL); ?>"
                                       <?php checked($snippet['type'], self::TYPE_EXTERNAL); ?>>
                                <?php esc_html_e('External script (URL)', 'valolink-plugin'); ?>
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="type" value="<?php echo esc_attr(self::TYPE_INLINE); ?>"
                                       <?php checked($snippet['type'], self::TYPE_INLINE); ?>>
                                <?php esc_html_e('Inline code', 'valolink-plugin'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-url"><?php esc_html_e('Script URL', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-url" type="url" name="url" class="regular-text"
                                   value="<?php echo esc_attr($snippet['url']); ?>"
                                   placeholder="https://example.com/script.js">
                            <p class="description"><?php esc_html_e('Used when type is "External script".', 'valolink-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-code"><?php esc_html_e('Inline code', 'valolink-plugin'); ?></label></th>
                        <td>
                            <textarea id="vl-code" name="code" rows="14" class="large-text code"
                                      spellcheck="false"
                                      placeholder="(function(){ /* your code */ })();"><?php echo esc_textarea($snippet['code']); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Used when type is "Inline code". Do NOT include <script> tags — only the JS itself.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-strategy"><?php esc_html_e('Loading strategy', 'valolink-plugin'); ?></label></th>
                        <td>
                            <select id="vl-strategy" name="strategy">
                                <?php foreach (self::strategies() as $strategy_key) : ?>
                                    <option value="<?php echo esc_attr($strategy_key); ?>"
                                            <?php selected($snippet['strategy'], $strategy_key); ?>>
                                        <?php echo esc_html(self::strategy_label($strategy_key)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Async and Defer apply to external scripts only — for inline code they collapse to a plain <script> in head.', 'valolink-plugin'); ?>
                                <br>
                                <?php esc_html_e('On-interaction listens for scroll/move/touch/key/click; on-scroll listens for scroll only. First event fires the script and removes the listeners.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Placement', 'valolink-plugin'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="placement_frontend" value="1"
                                           <?php checked($snippet['placement']['frontend']); ?>>
                                    <?php esc_html_e('Frontend pages', 'valolink-plugin'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="placement_admin" value="1"
                                           <?php checked($snippet['placement']['admin']); ?>>
                                    <?php esc_html_e('Admin pages', 'valolink-plugin'); ?>
                                </label>
                            </fieldset>
                            <br>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="placement_logged_in_only" value="1"
                                           <?php checked($snippet['placement']['logged_in_only']); ?>>
                                    <?php esc_html_e('Only for logged-in users', 'valolink-plugin'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="placement_logged_out_only" value="1"
                                           <?php checked($snippet['placement']['logged_out_only']); ?>>
                                    <?php esc_html_e('Only for logged-out visitors', 'valolink-plugin'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-include"><?php esc_html_e('Include URLs', 'valolink-plugin'); ?></label></th>
                        <td>
                            <textarea id="vl-include" name="include_urls" rows="3" class="large-text code"
                                      placeholder="/checkout&#10;/products/*&#10;/blog/*"><?php
                                echo esc_textarea(implode("\n", $snippet['include_urls']));
                            ?></textarea>
                            <p class="description">
                                <?php esc_html_e('One pattern per line. * matches any characters. Empty = all URLs. Leading slash auto-added; query strings ignored.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-exclude"><?php esc_html_e('Exclude URLs', 'valolink-plugin'); ?></label></th>
                        <td>
                            <textarea id="vl-exclude" name="exclude_urls" rows="3" class="large-text code"
                                      placeholder="/wp-admin/*&#10;/cart"><?php
                                echo esc_textarea(implode("\n", $snippet['exclude_urls']));
                            ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Same syntax. If a URL matches an exclude pattern, the script is skipped — even if it also matches an include pattern.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Consent gating', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="consent_enabled" value="1"
                                       <?php checked($snippet['consent_enabled']); ?>>
                                <?php esc_html_e('Wait for a dataLayer event before firing', 'valolink-plugin'); ?>
                            </label>
                            <br><br>
                            <label>
                                <?php esc_html_e('Event name', 'valolink-plugin'); ?>:
                                <input type="text" name="consent_event" class="regular-text"
                                       value="<?php echo esc_attr($snippet['consent_event']); ?>"
                                       placeholder="cookie_consent_accepted">
                            </label>
                            <p class="description">
                                <?php esc_html_e('Fires only after dataLayer.push({event: "name"}) with the given name. Compatible with most cookie banners that push to GTM dataLayer. Works alongside the loading strategy — if you also pick "On user interaction", both conditions must be met.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <?php submit_button($editing
                    ? __('Save Changes', 'valolink-plugin')
                    : __('Add Script', 'valolink-plugin')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_SAVE);

        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        if ($id === '') {
            $id = wp_generate_uuid4();
        }

        $type = (isset($_POST['type']) && $_POST['type'] === self::TYPE_INLINE)
            ? self::TYPE_INLINE
            : self::TYPE_EXTERNAL;

        $url  = isset($_POST['url'])  ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $code = isset($_POST['code']) ? (string) wp_unslash($_POST['code'])    : '';

        // Keep raw inline JS — admins authored it. We only defang at emit time.

        $strategy = isset($_POST['strategy']) && in_array($_POST['strategy'], self::strategies(), true)
            ? $_POST['strategy']
            : self::STRATEGY_FOOTER;

        $split_lines = static function (mixed $raw): array {
            if (!is_string($raw)) return [];
            $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($raw)) ?: [];
            return array_values(array_filter(array_map('trim', $lines), static fn($v) => $v !== ''));
        };

        $snippet = $this->normalise_snippet([
            'id'              => $id,
            'name'            => sanitize_text_field((string) ($_POST['name'] ?? '')),
            'enabled'         => !empty($_POST['enabled']),
            'type'            => $type,
            'url'             => $url,
            'code'            => $code,
            'strategy'        => $strategy,
            'placement'       => [
                'frontend'        => !empty($_POST['placement_frontend']),
                'admin'           => !empty($_POST['placement_admin']),
                'logged_in_only'  => !empty($_POST['placement_logged_in_only']),
                'logged_out_only' => !empty($_POST['placement_logged_out_only']),
            ],
            'include_urls'    => $split_lines($_POST['include_urls'] ?? ''),
            'exclude_urls'    => $split_lines($_POST['exclude_urls'] ?? ''),
            'consent_enabled' => !empty($_POST['consent_enabled']),
            'consent_event'   => sanitize_text_field((string) ($_POST['consent_event'] ?? '')),
        ]);

        $snippets = $this->get_snippets();
        $replaced = false;
        foreach ($snippets as $i => $existing) {
            if ($existing['id'] === $id) {
                $snippets[$i] = $snippet;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $snippets[] = $snippet;
        }
        $this->save_snippets($snippets);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    public function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_DELETE);

        $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
        if ($id !== '') {
            $snippets = array_values(array_filter(
                $this->get_snippets(),
                static fn(array $s) => $s['id'] !== $id,
            ));
            $this->save_snippets($snippets);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'deleted' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    public function handle_toggle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_TOGGLE);

        $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
        if ($id !== '') {
            $snippets = $this->get_snippets();
            foreach ($snippets as $i => $s) {
                if ($s['id'] === $id) {
                    $snippets[$i]['enabled'] = !$s['enabled'];
                    break;
                }
            }
            $this->save_snippets($snippets);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Labels
    // -------------------------------------------------------------------------

    private static function strategy_label(string $key): string
    {
        return match ($key) {
            self::STRATEGY_HEAD           => __('Head (sync)', 'valolink-plugin'),
            self::STRATEGY_HEAD_ASYNC     => __('Head — async', 'valolink-plugin'),
            self::STRATEGY_HEAD_DEFER     => __('Head — defer', 'valolink-plugin'),
            self::STRATEGY_FOOTER         => __('Footer', 'valolink-plugin'),
            self::STRATEGY_ON_INTERACTION => __('On user interaction', 'valolink-plugin'),
            self::STRATEGY_ON_SCROLL      => __('On scroll', 'valolink-plugin'),
            default                       => $key,
        };
    }

    private static function type_label(string $key): string
    {
        return match ($key) {
            self::TYPE_EXTERNAL => __('URL', 'valolink-plugin'),
            self::TYPE_INLINE   => __('Inline', 'valolink-plugin'),
            default             => $key,
        };
    }
}
