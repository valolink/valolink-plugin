// The block editor's own verdict on markup: every fixture in a JSON file
// ({name: markup}, as tests/block-compiler-wp.php writes it with OUT=…) is
// parsed by wp.blocks.parse in a live editor, and any block it marks invalid
// is reported with the editor's reason. That is exactly what an editor user
// would see as "This block contains unexpected or invalid content".
//
//   SITE=https://dev1.example.fi COOKIES=cookies.json [AUTH=basic-auth-file] \
//   NODE_PATH=~/valolink/enginelink/node_modules CHROMIUM=$(command -v chromium) \
//   node tests/editor-validate.cjs compiled.json
//
// COOKIES: [{name, value, path}] of a logged-in user who may edit patterns
// (wp_generate_auth_cookie for the secure_auth and logged_in cookies). AUTH:
// "user password" for HTTP basic auth on staging copies. Uses the pattern
// editor (post-new.php?post_type=wp_block), which is the block editor even
// where a page builder owns posts and pages. Exit 1 when anything is invalid.
const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const fixtures = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const site = new URL(process.env.SITE);
  const auth = process.env.AUTH ? fs.readFileSync(process.env.AUTH, 'utf8').trim().split(/\s+/) : null;
  const cookies = JSON.parse(fs.readFileSync(process.env.COOKIES, 'utf8'))
    .map(c => ({ ...c, domain: site.hostname, secure: site.protocol === 'https:', httpOnly: true, sameSite: 'Lax' }));
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM });
  const ctx = await browser.newContext(auth ? { httpCredentials: { username: auth[0], password: auth[1] } } : {});
  await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  await page.goto(new URL('/wp-admin/post-new.php?post_type=wp_block', site).href, { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.waitForFunction(() => window.wp && wp.blocks && wp.blocks.getBlockTypes().length > 20, null, { timeout: 120000 });

  const result = await page.evaluate((fixtures) => {
    const out = {};
    for (const [name, markup] of Object.entries(fixtures)) {
      const bad = [];
      let count = 0;
      const walk = (blocks, path) => blocks.forEach((b, i) => {
        count++;
        const p = path === '' ? String(i) : path + '.' + i;
        if (!b.isValid) {
          bad.push({ path: p, name: b.name, issues: (b.validationIssues || []).map(x => (x.args || []).filter(a => typeof a === 'string').join(' ').slice(0, 300)) });
        }
        if (b.name === 'core/missing') {
          bad.push({ path: p, name: b.name, issues: ['block type not registered: ' + (b.attributes.originalName || '?')] });
        }
        walk(b.innerBlocks || [], p);
      });
      walk(wp.blocks.parse(markup), '');
      out[name] = { count, bad };
    }
    return out;
  }, fixtures);

  let invalid = 0;
  for (const [name, r] of Object.entries(result)) {
    console.log(`${r.bad.length ? 'FAIL' : 'ok  '} ${name} (${r.count} blocks)`);
    for (const b of r.bad) {
      invalid++;
      console.log(`     ${b.path} ${b.name}: ${b.issues.join(' | ')}`);
    }
  }
  await browser.close();
  process.exit(invalid ? 1 : 0);
})().catch(e => { console.error(e.message); process.exit(2); });
