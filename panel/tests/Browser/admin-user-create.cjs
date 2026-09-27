// Run with Node.js and Playwright (Chromium installed). All APIs are mocked.
// NODE_PATH=/path/to/node_modules node panel/tests/Browser/admin-user-create.cjs
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const panel = path.resolve(__dirname, '../..');
const manifest = JSON.parse(fs.readFileSync(path.join(panel, 'public/assets/admin/manifest.json')));
const blade = fs.readFileSync(path.join(panel, 'resources/views/admin.blade.php'), 'utf8');
const htmlTag = blade.match(/<html[^>]*>/)[0].replace(/\{\{.*?\}\}/g, 'Xboard');
let html = '<!doctype html>' + htmlTag + '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
html += '<script>window.settings={secure_path:"666",base_url:"/",title:"Test",version:"test",logo:""};</script>';
for (const file of manifest['index.html'].css || []) html += '<link rel="stylesheet" href="/assets/admin/' + file + '">';
for (const file of fs.readdirSync(path.join(panel, 'public/assets/admin/locales'))) {
  if (file.endsWith('.js')) html += '<script src="/assets/admin/locales/' + file + '"></script>';
}
html += '<script type="module" src="/assets/admin/' + manifest['index.html'].file + '"></script></head><body><div id="root"></div>';
for (const match of blade.matchAll(/file_get_contents\(resource_path\('([^']+)'\)\)/g)) {
  const file = match[1], content = fs.readFileSync(path.join(panel, 'resources', file), 'utf8');
  if (file.endsWith('.js')) html += '<script>' + content + '</script>';
  if (file.endsWith('.css')) html += '<style>' + content + '</style>';
}
html += '</body></html>';

// Model translation engines replacing React-owned text nodes with translated FONT trees.
// Deliberately ignore translate=no: success must not depend on disabling translation.
async function translate(page) {
  return page.evaluate(() => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT), nodes = [];
    while (walker.nextNode()) {
      const node = walker.currentNode;
      if (node.textContent.trim() && !node.parentElement.closest('script,style,textarea,font')) nodes.push(node);
    }
    for (const node of nodes) {
      const outer = document.createElement('font'), inner = document.createElement('font');
      inner.textContent = node.textContent; outer.append(inner); node.replaceWith(outer);
    }
    return nodes.length;
  });
}

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    for (const width of [390, 1366]) for (const translated of [false, true]) {
      const page = await browser.newPage({
        viewport: { width, height: 900 }, isMobile: width < 600, hasTouch: width < 600, locale: 'zh-CN',
      });
      page.setDefaultTimeout(10000);
      const errors = [], users = [], submissions = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.addInitScript(() => {
        localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'fixture', expire: Date.now() + 3600000 }));
        localStorage.setItem('i18nextLng', 'zh-CN');
      });
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.hostname !== 'admin-fixture.test') return route.abort();
        if (url.pathname === '/666') return route.fulfill({ contentType: 'text/html', body: html });
        if (url.pathname.startsWith('/assets/')) {
          const file = path.join(panel, 'public', url.pathname);
          return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        }
        let body = { data: [] };
        if (url.pathname.endsWith('/user/info')) body = { data: { id: 1, email: 'admin@example.invalid', is_admin: true } };
        if (url.pathname.endsWith('/user/fetch')) body = { data: users, total: users.length, current_page: 1, per_page: 20 };
        if (url.pathname.endsWith('/plan/fetch')) body = { data: [{ id: 1, name: '测试套餐', prices: { monthly: 10 } }] };
        if (url.pathname.endsWith('/user/generate')) {
          const payload = route.request().postDataJSON(); submissions.push(payload);
          if (translated) assert.ok(await translate(page) > 0);
          const count = payload.generate_count || 1;
          for (let i = 0; i < count; i++) users.push({
            id: users.length + 2, email: 'created-' + (users.length + 2) + '@example.invalid',
            banned: false, plan: null, group: null, subscriptions_count: 0, subscription_summaries: [],
            u: 0, d: 0, transfer_enable: 0, created_at: Math.floor(Date.now() / 1000),
          });
          if (payload.download_csv) return route.fulfill({ contentType: 'text/csv', body: 'email,password\nfixture@example.invalid,test\n' });
          body = { data: true };
        }
        return route.fulfill({ json: body });
      });
      try {
        await page.goto('http://admin-fixture.test/666#/user/manage');
        await page.getByRole('button', { name: '创建用户', exact: true }).waitFor();
        assert.equal(await page.locator('html').getAttribute('lang'), 'zh-CN');
        for (let round = 0; round < 3; round++) {
          await page.getByRole('button', { name: '创建用户', exact: true }).click();
          const dialog = page.getByRole('dialog');
          await dialog.waitFor();
          await page.locator('input[name="email_prefix"]').fill(round === 1 ? '' : 'fixture');
          await page.locator('input[name="email_suffix"]').fill('example.invalid');
          if (translated) assert.ok(await translate(page) > 0);
          // Replace the selected value after translation and submit a plan-bearing package.
          await dialog.getByRole('combobox').click();
          await page.getByRole('option', { name: '测试套餐', exact: true }).click();
          if (round === 1) await page.locator('input[name="generate_count"]').fill('2');
          const download = round === 1 ? page.waitForEvent('download') : null;
          if (round === 1) await dialog.getByRole('switch').check();
          await dialog.getByRole('button', { name: '确认', exact: true }).click();
          if (download) await download;
          await dialog.waitFor({ state: 'hidden' });
          await page.getByText('created-2@example.invalid', { exact: true }).first().waitFor();
          assert.equal(submissions.length, round + 1, 'No duplicate create requests');
          assert.equal(submissions[round].plan_id, 1);
          assert.ok(!(await page.locator('body').innerText()).includes('removeChild'));
          assert.ok(!(await page.locator('body').innerText()).includes('Something went wrong'));
        }
        assert.equal(users.length, 4);
        assert.deepEqual(errors, []);
        console.log('PASS', { width, translated, submissions: submissions.length, users: users.length });
        if (process.env.SCREENSHOT_DIR) await page.screenshot({ path: path.join(process.env.SCREENSHOT_DIR, 'create-' + width + '-' + translated + '.png') });
      } catch(error) { console.log((await page.locator('body').innerText()).slice(-2500)); throw error; } finally { await page.close(); }
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
