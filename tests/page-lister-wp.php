<?php
/**
 * The content list EngineLink's site reading asks for, against a real site:
 *   wp --path=/path/to/wp eval-file tests/page-lister-wp.php
 *
 * Read-only: lists pages (the default), then posts and pages together, and
 * checks the shape EngineLink reads — each item's type, categories and word
 * count, the site's public post types with counts, unknown types ignored.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!class_exists(\Valolink\Plugin\Modules\EngineLink\PageLister::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
use Valolink\Plugin\Modules\EngineLink\PageLister;

$GLOBALS['failures'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $GLOBALS['failures']++;
    }
}

$lister = new PageLister();

$pages = $lister->collect();
$types = array_unique(array_column($pages['pages'], 'type'));
check('pages by default, and only pages', $types === ['page'], implode(',', $types));
check('pages count matches wp_count_posts', $pages['total'] === min(500, (int) wp_count_posts('page')->publish) || $pages['truncated'], (string) $pages['total']);
$names = array_column($pages['types'], 'name');
check('public post types listed, attachments left out', in_array('page', $names, true) && in_array('post', $names, true) && !in_array('attachment', $names, true), implode(',', $names));
$first = $pages['pages'][0] ?? [];
foreach (['id', 'type', 'url', 'title', 'parent', 'menu_order', 'date', 'modified', 'lang', 'front', 'categories', 'words'] as $key) {
    check("item has $key", array_key_exists($key, $first));
}
check('the front page is marked', count(array_filter($pages['pages'], static fn ($p) => $p['front'])) <= 1);

$both = $lister->collect(['post', 'page', 'nosuchtype', 'attachment']);
$bothTypes = array_values(array_unique(array_column($both['pages'], 'type')));
sort($bothTypes);
$posts = array_values(array_filter($both['pages'], static fn ($p) => $p['type'] === 'post'));
$expectPosts = (int) wp_count_posts('post')->publish;
check('posts and pages, unknown and attachment ignored', $expectPosts === 0 ? $bothTypes === ['page'] : $bothTypes === ['page', 'post'], implode(',', $bothTypes));
if ($posts) {
    check('posts newest first', $posts[0]['date'] >= end($posts)['date']);
    check('a post has its categories', count(array_filter($posts, static fn ($p) => $p['categories'] !== [])) > 0);
    check('word counts are numbers, and some posts have words', count(array_filter($posts, static fn ($p) => is_int($p['words']) && $p['words'] > 0)) > 0);
    $sample = $posts[0];
    echo "     e.g. {$sample['title']} — {$sample['date']} — " . implode(', ', $sample['categories']) . " — {$sample['words']} words\n";
}
check('nothing of the text is in the answer', !str_contains(wp_json_encode($both), 'post_content'));

echo $GLOBALS['failures'] ? "{$GLOBALS['failures']} failed\n" : "all ok\n";
exit($GLOBALS['failures'] ? 1 : 0);
