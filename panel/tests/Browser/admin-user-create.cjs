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


async function checkKeyboardViewport(page, dialog, translated) {
  await page.waitForTimeout(600); // Wait for Vaul's opening animation.
  const original = await dialog.boundingBox();
  await dialog.locator('input[name="email_prefix"]').focus();
  for (const [height, top, event] of [[500, 0, 'resize'], [500, 60, 'scroll'], [900, 0, 'resize'], [820, 0, 'resize'], [500, 0, 'resize'], [900, 0, 'resize']]) {
    // Keyboard dismissal may happen after the field has lost focus.
    if (height === 900) await dialog.locator('input[name="email_prefix"]').blur();
    await page.evaluate(({height, top, event}) => window.setTestViewport(height, top, event), {height, top, event});
    const box = await dialog.boundingBox();
    assert.ok(Math.abs(box.y + box.height - height - top) <= 2, 'Drawer follows visible viewport bottom without a blank gap');
    assert.ok(box.y >= top, 'Header stays within visible viewport');
    const footer = await dialog.getByRole('button', { name: '确认', exact: true }).boundingBox();
    assert.ok(footer.y >= top && footer.y + footer.height <= height + top, 'Submit remains above keyboard');
    const canScroll = await dialog.evaluate((node, constrained) => [...node.querySelectorAll('*')].some(child => {
      return getComputedStyle(child).overflowY === 'auto' && child.clientHeight > 0 && (!constrained || child.scrollHeight > child.clientHeight);
    }), height === 500);
    assert.ok(canScroll, 'Form retains a usable scroll region');
    assert.equal(await dialog.evaluate(node => getComputedStyle(node, '::after').content), 'none');
    if (height === 900) assert.ok(Math.abs(box.height - original.height) <= 2, 'Keyboard dismissal restores natural height');
    if (process.env.SCREENSHOT_DIR && top === 60) await page.screenshot({
      path: path.join(process.env.SCREENSHOT_DIR, 'drawer-keyboard-' + translated + '.png'),
      clip: {x: 0, y: top, width: 390, height},
    });
  }
  await page.evaluate(() => window.setTestViewport(null, null, 'resize'));
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
        let viewportHeight = null, viewportTop = null;
        const actualHeight = Object.getOwnPropertyDescriptor(VisualViewport.prototype, 'height').get;
        const actualTop = Object.getOwnPropertyDescriptor(VisualViewport.prototype, 'offsetTop').get;
        Object.defineProperty(window.visualViewport, 'height', { get: () => viewportHeight ?? actualHeight.call(window.visualViewport) });
        Object.defineProperty(window.visualViewport, 'offsetTop', { get: () => viewportTop ?? actualTop.call(window.visualViewport) });
        window.setTestViewport = (height, top, event) => {
          viewportHeight = height; viewportTop = top;
          window.visualViewport.dispatchEvent(new Event(event));
        };

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
          const fullEmail = dialog.getByLabel('完整邮箱（自动识别）', { exact: true });
          assert.equal(await fullEmail.inputValue(), '', 'Helper resets when reopened');
          if (round === 1) {
            await page.locator('input[name="email_suffix"]').fill('example.invalid');
          } else {
            if (round === 2) await page.locator('input[name="generate_count"]').fill('5');
            await fullEmail.fill(round === 2 ? ' mailto:fixture+tag@example.invalid ' : 'fixture@example.invalid');
            assert.equal(await page.locator('input[name="email_prefix"]').inputValue(), round === 2 ? 'fixture+tag' : 'fixture');
            assert.equal(await page.locator('input[name="email_suffix"]').inputValue(), 'example.invalid');
            await fullEmail.fill('not-an-email');
            await fullEmail.blur();
            assert.equal(await fullEmail.getAttribute('aria-invalid'), 'true');
            assert.equal(await page.locator('input[name="email_suffix"]').inputValue(), 'example.invalid', 'Invalid input never overwrites the form');
            await fullEmail.fill(round === 2 ? 'mailto:fixture+tag@example.invalid' : 'fixture@example.invalid');
          }
          if (process.env.SCREENSHOT_DIR && round === 0) await page.screenshot({ path: path.join(process.env.SCREENSHOT_DIR, 'email-autofill-' + width + '-' + translated + '.png') });
          if (translated) assert.ok(await translate(page) > 0);
          if (width < 600 && round === 0) await checkKeyboardViewport(page, dialog, translated);
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
          assert.equal(submissions[round].email_suffix, 'example.invalid');
          if (round !== 1) {
            assert.equal(submissions[round].email_prefix, round === 2 ? 'fixture+tag' : 'fixture');
            assert.ok(!submissions[round].generate_count, 'Full email selects single-user creation');
            assert.equal(submissions[round].download_csv, false);
          }
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
