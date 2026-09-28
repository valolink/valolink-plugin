<?php
/**
 * Plain-PHP test of who sees the plugin's admin notices, no WordPress needed:
 *   php tests/notice-audience.php
 */
declare(strict_types=1);

// --- WordPress stand-ins -----------------------------------------------------
class WP_User
{
    public function __construct(public int $ID, public string $user_email, public string $display_name, public bool $admin) {}
    public function exists(): bool { return $this->ID > 0; }
}
$GLOBALS['wp_options'] = [];
$GLOBALS['users']      = [];
$GLOBALS['current']    = 0;
function get_option(string $key, $default = false) { return $GLOBALS['wp_options'][$key] ?? $default; }
function update_option(string $key, $value, $autoload = null): bool { $GLOBALS['wp_options'][$key] = $value; return true; }
function wp_get_current_user(): WP_User { return $GLOBALS['users'][$GLOBALS['current']] ?? new WP_User(0, '', '', false); }
function get_users(array $args): array
{
    return array_values(array_filter($GLOBALS['users'], static function (WP_User $u) use ($args): bool {
        if (isset($args['capability'])) return $u->admin;
        if (isset($args['include']))    return in_array($u->ID, $args['include'], true);
        if (isset($args['search']))     return str_ends_with(strtolower($u->user_email), strtolower(ltrim($args['search'], '*'))); // LIKE is case-insensitive
        return true;
    }));
}

require dirname(__DIR__) . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();
use Valolink\Plugin\Admin\NoticeAudience;
use Valolink\Plugin\Settings;

$fails = 0;
function check(string $label, bool $ok): void { global $fails; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fails++; }
function sees(int $id): bool { $GLOBALS['current'] = $id; return (new NoticeAudience(new Settings()))->includes_current_user(); }

foreach ([
    new WP_User(1, 'reima.kokko@valolink.fi', 'Reima', true),
    new WP_User(2, 'owner@client.fi',          'Client', true),
    new WP_User(3, 'Dev@Valolink.FI',          'Dev', false),
    new WP_User(4, 'someone@notvalolink.fi',   'Lookalike', true),
] as $u) $GLOBALS['users'][$u->ID] = $u;

// --- default: the valolink.fi domain -----------------------------------------
check('default: valolink.fi admin sees notices',       sees(1));
check('default: client admin does not',                !sees(2));
check('default: domain match is case-insensitive',     sees(3));
check('default: a lookalike domain does not match',    !sees(4));
check('default: logged-out user does not',             !sees(0));
check('candidates: admins plus valolink.fi users',     count((new NoticeAudience(new Settings()))->candidates()) === 4);

// --- saving the default keeps it live ----------------------------------------
(new NoticeAudience(new Settings()))->save([3, 1]);
check('saving the default selection stores nothing',   (new NoticeAudience(new Settings()))->chosen() === null);
$GLOBALS['users'][5] = new WP_User(5, 'new@valolink.fi', 'New colleague', true);
check('a colleague added later is included',           sees(5));

// --- an explicit list ---------------------------------------------------------
(new NoticeAudience(new Settings()))->save([2, 1]);
check('explicit: stored',                               (new NoticeAudience(new Settings()))->chosen() === [1, 2]);
check('explicit: chosen client admin sees notices',    sees(2));
check('explicit: unticked valolink.fi user does not',  !sees(3));
check('explicit: new colleague no longer implied',     !sees(5));

(new NoticeAudience(new Settings()))->save([]);
check('empty selection is stored, nobody sees them',   (new NoticeAudience(new Settings()))->chosen() === [] && !sees(1));

exit($fails === 0 ? 0 : 1);
