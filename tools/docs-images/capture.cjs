// Screenshots of the documentation (docs/<lang>/usage/bilder/*.png, docs/<lang>/bilder/*.png), taken in the
// real editor of a test installation and marked with the NEO annotation layer. See README.md in this folder.
// The editor view is one document with the sidebar and the page in a frame (iframe.nfe-page); the frame, the
// buttons and the text toolbar at an element belong to the page's document, everything else to the view.
//
//   node capture.cjs <en|de> [name,name,...]
//
// Environment: NFE_BASE (default http://127.0.0.1:8000), NFE_ADMIN_USER / NFE_ADMIN_PASSWORD,
// NFE_EDITOR_USER / NFE_EDITOR_PASSWORD, NFE_MYSQL (default "mysql contao"), NFE_ANNOTATE (path to annotate.js of
// the plugin neo-doku), NFE_FORM (alias of the sample form, default "anfrage").
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');

const path = require('path');

const lang = process.argv[2] || 'en';
const only = process.argv[3] ? process.argv[3].split(',') : null;
const env = (name, fallback) => process.env[name] || fallback;
const base = env('NFE_BASE', 'http://127.0.0.1:8000');
const ADMIN = { user: env('NFE_ADMIN_USER', 'admin'), password: env('NFE_ADMIN_PASSWORD', '') };
const EDITOR = { user: env('NFE_EDITOR_USER', 'redakteur'), password: env('NFE_EDITOR_PASSWORD', '') };
const MYSQL = env('NFE_MYSQL', 'mysql contao');
const ANNOTATE = env('NFE_ANNOTATE', '');
const FORM = env('NFE_FORM', 'anfrage');
const docs = path.resolve(__dirname, '../../docs', lang) + '/';
// The editor's own texts in the language of the back-end user, read from the page (see open()).
let T = {};
const t = (key) => T[key] ?? key;
const pagePath = lang === 'en' ? 'en/offer' : 'de/angebot';
// Double quotes and backticks escaped for the shell; column names that are reserved words in MySQL 8 (groups) in backticks.
const sql = (s) => execSync(MYSQL + ' -N -e "' + s.replace(/["`$]/g, '\\$&') + '"').toString().trim();
const text = { en: { first: 'Prices on request', nested: 'The station is ten minutes', group: 'A text inside an element group', email: 'email' }, de: { first: 'Preise auf Anfrage', nested: 'Der Bahnhof liegt', group: 'Ein Text in einer Elementgruppe', email: 'email' } }[lang];

const users = "'" + ADMIN.user + "','" + EDITOR.user + "'";
const languages = sql('SELECT CONCAT(username, ":", language) FROM tl_user WHERE username IN (' + users + ')').split('\n').filter(Boolean);
sql("UPDATE tl_user SET language='" + lang + "' WHERE username IN (" + users + ")");

async function open(browser, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2, reducedMotion: 'reduce', colorScheme: 'light', locale: lang === 'en' ? 'en-GB' : 'de-AT', timezoneId: 'Europe/Vienna' });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  const login = opts.user || ADMIN;
  await page.goto(base + '/contao/login');
  await page.fill('#username', login.user); await page.fill('#password', login.password);
  await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');
  if (opts.backend) {
    await page.goto(base + opts.backend); await page.waitForLoadState('networkidle');
    return { ctx, page };
  }
  await ctx.addCookies([{ name: 'contao_frontend_editor', value: '1', url: base }]);
  await page.goto(base + '/preview.php/' + (opts.path || pagePath));
  // A cookie hint of the website (consent.js, "ncc-consent") would cover the page: chosen for this browser only, so
  // no consent is logged.
  const consent = await (await (await page.waitForSelector('iframe.nfe-page')).contentFrame()).evaluate(() => {
    try { return JSON.parse(document.getElementById('ncc-config')?.textContent || 'null')?.v ?? null; } catch (e) { return null; }
  }).catch(() => null);
  await page.evaluate((v) => {
    localStorage.clear();
    localStorage.setItem('nfe:collapsed', '0');
    // The note on the quick guide shows once per browser; not in the images.
    localStorage.setItem('nfe:guide', '1');
    if (v !== null) localStorage.setItem('ncc-consent', JSON.stringify({ v, id: 'docs-images', ts: Date.now(), cats: {}, svc: {} }));
  }, consent);
  await page.reload();
  // The view, the page in its frame and the structure of the page in the sidebar.
  const frame = await (await page.waitForSelector('iframe.nfe-page')).contentFrame();
  await frame.waitForFunction(() => document.querySelector('nfe-editor[data-nfe="page"]'));
  await tidy(frame);
  await page.waitForFunction(() => document.querySelector('nfe-editor')?.shadowRoot?.querySelector('.nfe-tree .nfe-row[data-id]'));
  await page.evaluate(() => document.fonts.ready);
  await frame.evaluate(() => document.fonts.ready);
  T = await page.evaluate(() => JSON.parse(document.getElementById('nfe-config').textContent).texts);
  await page.waitForTimeout(600);
  const s = { ctx, page, frame };
  await rest(s);
  return s;
}

// The button of the website's cookie hint that opens it again floats over the page; not in the images.
const tidy = (frame) => frame.addStyleTag({ content: '.ncc-reopen { display: none !important; }' }).catch(() => {});

const ed = (s, sel) => s.page.locator('nfe-editor ' + sel);
const esc = (v) => v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// A visible button by label or text: at the element in the page first, then in the view.
async function btn(s, label) {
  for (const scope of [s.frame, s.page]) {
    const found = scope.locator('nfe-editor button[aria-label="' + label + '"]').or(scope.locator('nfe-editor button').filter({ hasText: new RegExp('^\\W*' + esc(label) + '\\W*$') }));
    for (let i = 0, n = await found.count(); i < n; i++) {
      if (await found.nth(i).isVisible()) return found.nth(i);
    }
  }
  throw new Error('no visible button "' + label + '"');
}

// An entry of the menu in the tab of the selected part (data-action of the entry: edit, form, info …); the menu opens.
async function tabEntry(s, action) {
  await s.frame.locator('nfe-editor .nfe-type').click();
  await s.page.waitForTimeout(250);
  return s.page.locator('nfe-editor .nfe-menu [data-action="' + action + '"]');
}

// The pointer away from the page (on the sidebar's title), so no outline of the part under it is in the image.
async function rest(s) {
  const box = await s.page.locator('nfe-editor .nfe-panel__title').first().boundingBox().catch(() => null);
  await s.page.mouse.move(box ? box.x + box.width / 2 : 1, box ? box.y + box.height / 2 : 1);
  await s.page.waitForTimeout(250);
}

// Selects a part of the page by click, as an editor does.
async function pick(s, locator) {
  await locator.scrollIntoViewIfNeeded();
  await locator.click();
  await s.page.waitForTimeout(400);
  await rest(s);
}

// Marks: inside page.evaluate, R = shadow root of the view, D = the page's document in the frame, P = shadow root of
// the editor in it, B(label) = a button by label or text (view or page), F(node) = a stand-in in the view for a node
// of the page, cut to the visible part of the frame (the annotation layer lives in the view), V(node) = a stand-in
// for a node of the view, cut to the visible part of the list it scrolls in, N(node, n) = the number n at the top
// right corner of a node framed with that padding (where the one at the left would cover something), M(node, n) =
// the number n beside the right side of a node.
async function mark(s, fn, arg) {
  await s.page.addScriptTag({ path: ANNOTATE });
  await s.page.evaluate(([src, a]) => {
    const R = document.querySelector('nfe-editor').shadowRoot;
    const frame = R.querySelector('iframe.nfe-page');
    const D = frame.contentDocument;
    const P = D.querySelector('nfe-editor[data-nfe="page"]').shadowRoot;
    const B = (label) => [...R.querySelectorAll('button, [role=menuitem], [role=tab]'), ...P.querySelectorAll('button')].find((b) => b.getAttribute('aria-label') === label || b.textContent.trim() === label);
    const F = (node) => {
      if (!node) return null;
      const r = node.getBoundingClientRect();
      const o = frame.getBoundingClientRect();
      const x = o.left + frame.clientLeft;
      const y = o.top + frame.clientTop;
      const left = Math.max(x, x + r.left);
      const top = Math.max(y, y + r.top);
      const right = Math.min(x + frame.clientWidth, x + r.right);
      const bottom = Math.min(y + frame.clientHeight, y + r.bottom);
      const el = document.createElement('div');
      el.style.cssText = 'position:fixed;pointer-events:none;left:' + left + 'px;top:' + top + 'px;width:' + Math.max(0, right - left) + 'px;height:' + Math.max(0, bottom - top) + 'px';
      document.body.append(el);
      return el;
    };
    const box = (left, top, right, bottom) => {
      const el = document.createElement('div');
      el.style.cssText = 'position:fixed;pointer-events:none;left:' + left + 'px;top:' + top + 'px;width:' + Math.max(0, right - left) + 'px;height:' + Math.max(0, bottom - top) + 'px';
      document.body.append(el);
      return el;
    };
    const V = (node) => {
      if (!node) return null;
      const r = node.getBoundingClientRect();
      let list = node.parentElement;
      while (list && !(list.scrollHeight > list.clientHeight && /auto|scroll/.test(getComputedStyle(list).overflowY))) list = list.parentElement;
      const v = list ? list.getBoundingClientRect() : { top: 0, bottom: innerHeight };
      return box(r.left, Math.max(r.top, v.top + 8), r.right, Math.min(r.bottom, v.bottom - 8));
    };
    const N = (node, n, padding = 6) => {
      const r = node.getBoundingClientRect();
      const at = box(r.right + padding, r.top - padding, r.right + padding + 1, r.top - padding + 1);
      window.neoAnnotate.number(at, n);
    };
    // The number n at the middle of the right side of a node framed with that padding (at the top of the page).
    const M = (node, n, padding = 6) => {
      const r = node.getBoundingClientRect();
      window.neoAnnotate.number(box(r.right + padding + 18, (r.top + r.bottom) / 2, r.right + padding + 19, (r.top + r.bottom) / 2 + 1), n);
    };
    // eslint-disable-next-line no-new-func
    new Function('R', 'D', 'P', 'B', 'F', 'V', 'N', 'M', 'A', 'neoAnnotate', src)(R, D, P, B, F, V, N, M, a, window.neoAnnotate);
  }, [fn, arg]);
  await s.page.waitForTimeout(150);
}

async function markFrame(frame, fn) {
  await frame.addScriptTag({ path: ANNOTATE });
  await frame.evaluate((src) => { new Function('neoAnnotate', src)(window.neoAnnotate); }, fn);
}

async function shot(s, file) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await s.page.screenshot({ path: file });
  console.log('  ', file.replace(docs, 'docs/' + lang + '/'), Math.round(fs.statSync(file).size / 1024) + ' KB');
}

const formFrame = async (s) => {
  await s.page.waitForFunction(() => document.querySelector('nfe-editor')?.shadowRoot?.querySelector('iframe.nfe-panel__form'));
  const handle = await s.page.locator('nfe-editor iframe.nfe-panel__form').elementHandle();
  const frame = await handle.contentFrame();
  await frame.waitForLoadState('networkidle');
  await s.page.waitForTimeout(700);
  return frame;
};
const first = (s) => s.frame.getByText(text.first).first();
// What the AI returned for an image, printed so that the documentation can quote it as an example.
const quote = (label, list) => console.log('   ' + label + ':\n' + list.map((line) => '     - ' + line.replace(/\s+/g, ' ').trim()).join('\n'));

const shots = {
  'editor-overview': async (s) => {
    await pick(s, first(s));
    await mark(s, `
      const bar = document.querySelector('cto-toolbar')?.shadowRoot?.querySelector('[data-nfe-switch]');
      if (bar) {
        neoAnnotate.frame(bar, { padding: 3 });
        const r = bar.getBoundingClientRect();
        const d = document.createElement('div');
        d.style.cssText = 'position:absolute;width:1px;height:1px;left:' + (r.left - 20 + scrollX) + 'px;top:' + (r.top + r.height / 2 + scrollY) + 'px';
        document.body.appendChild(d);
        neoAnnotate.number(d, 1);
      }
      neoAnnotate.frame(R.querySelector('.nfe-panel'), { number: 2, padding: 0 });
      neoAnnotate.frame(F(P.querySelector('.nfe-type')), { number: 3 });`);
    await shot(s, docs + 'usage/bilder/editor-overview.png');
  },
  'inline-editing': async (s) => {
    await pick(s, first(s));
    await (await tabEntry(s, 'edit')).click(); await s.page.waitForTimeout(400);
    await first(s).click(); await s.page.keyboard.press('End'); await s.page.keyboard.type(lang === 'en' ? ' Breakfast included.' : ' Frühstück inklusive.');
    await rest(s);
    await mark(s, `
      neoAnnotate.frame(F(P.querySelector('.nfe-format')), { number: 1 });
      // The number of "Save" at its top right corner: at the left one it would cover the status line.
      neoAnnotate.frame(B(A.save));
      N(B(A.save), 2);`, { save: t('save') });
    await shot(s, docs + 'usage/bilder/inline-editing.png');
  },
  // The open menu of the tab: every action of the element, with the keys that do the same.
  'element-menu': async (s) => {
    await pick(s, first(s));
    await s.frame.locator('nfe-editor .nfe-type').click(); await s.page.waitForTimeout(400);
    await mark(s, `
      neoAnnotate.frame(F(P.querySelector('.nfe-type')), { number: 1 });
      // The number of the menu at its top right corner: at the left one it would sit on the number of the tab.
      const menu = R.querySelector('.nfe-menu');
      neoAnnotate.frame(menu);
      N(menu, 2);`);
    await shot(s, docs + 'usage/bilder/element-menu.png');
  },
  // A right click into the text being written: the editor's menu, with the hint on the browser's spelling menu on top.
  'context-menu': async (s) => {
    await pick(s, first(s));
    await (await tabEntry(s, 'edit')).click(); await s.page.waitForTimeout(400);
    await first(s).click(); await s.page.waitForTimeout(300);
    const box = await first(s).boundingBox();
    await s.page.mouse.click(box.x + Math.min(120, box.width / 2), box.y + box.height / 2, { button: 'right' });
    await s.page.waitForTimeout(500);
    await mark(s, `neoAnnotate.frame(R.querySelector('.nfe-menu .nfe-menu__hint'), { number: 1 });`);
    await shot(s, docs + 'usage/bilder/context-menu.png');
  },
  'all-fields': async (s) => {
    await pick(s, first(s));
    await (await tabEntry(s, 'form')).click();
    await formFrame(s);
    await rest(s);
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-panel__frame'), { number: 1, padding: 0 });
      const save = R.querySelector('.nfe-panel__head button');
      neoAnnotate.frame(save, { number: 2 });
      const tr = R.querySelector('[data-action="form-translate"]');
      if (tr) neoAnnotate.frame(tr, { number: 3 });`);
    await shot(s, docs + 'usage/bilder/all-fields.png');
  },
  // The strip at the right edge and the grip of the sidebar.
  'sidebar-strip': async (s) => {
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-rail [data-action="panel"]'), { number: 1 });
      neoAnnotate.frame(R.querySelector('.nfe-rail [data-action="pin"]'), { number: 2 });
      const grip = R.querySelector('.nfe-resize');
      if (grip) neoAnnotate.frame(grip, { number: 3, padding: 2 });`);
    await shot(s, docs + 'usage/bilder/sidebar-strip.png');
  },
  // Not pinned: the sidebar lies over the page, which keeps its whole width.
  'sidebar-over-page': async (s) => {
    await ed(s, '.nfe-rail [data-action="pin"]').click(); await s.page.waitForTimeout(400);
    if (await ed(s, '.nfe-panel').isHidden()) { await ed(s, '.nfe-rail [data-action="panel"]').click(); await s.page.waitForTimeout(500); }
    await rest(s);
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-panel'), { number: 2, padding: 0 });
      neoAnnotate.frame(R.querySelector('.nfe-rail [data-action="pin"]'), { number: 1 });`);
    await shot(s, docs + 'usage/bilder/sidebar-over-page.png');
  },
  // Collapsed: the page has the whole width, the bar below it keeps the state and Save.
  'sidebar-collapsed': async (s) => {
    await ed(s, '.nfe-rail [data-action="panel"]').click(); await s.page.waitForTimeout(600);
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-rail [data-action="panel"]'), { number: 1 });
      const bar = R.querySelector('.nfe-float');
      neoAnnotate.frame(bar, { padding: 2 });
      const r = bar.getBoundingClientRect();
      const at = document.createElement('div');
      at.style.cssText = 'position:absolute;width:1px;height:1px;left:' + (r.left + 28 + scrollX) + 'px;top:' + (r.top - 2 + scrollY) + 'px';
      document.body.append(at);
      neoAnnotate.number(at, 2);`);
    await shot(s, docs + 'usage/bilder/sidebar-collapsed.png');
  },
  'insert-element': async (s) => {
    await pick(s, first(s));
    await mark(s, `
      const insert = [...P.querySelectorAll('.nfe-insert')].find((n) => !n.hidden);
      neoAnnotate.frame(F(insert.querySelector('button') || insert), { number: 1 });
      neoAnnotate.frame(R.querySelector('.nfe-row--insert'), { number: 2 });`);
    await shot(s, docs + 'usage/bilder/insert-element.png');
  },
  'nested-elements': async (s) => {
    // Both groups at the top of the scrolling list; one frame around them, cut to the visible part of the list.
    await mark(s, `
      const groups = [...R.querySelectorAll('.nfe-article--nested')].map((n) => n.parentElement);
      let box = groups[0].parentElement;
      while (box && !(box.scrollHeight > box.clientHeight && /auto|scroll/.test(getComputedStyle(box).overflowY))) box = box.parentElement;
      if (box) box.scrollTop += groups[0].getBoundingClientRect().top - box.getBoundingClientRect().top - 8;
      const view = box ? box.getBoundingClientRect() : document.documentElement.getBoundingClientRect();
      const first = groups[0].getBoundingClientRect();
      const last = groups[groups.length - 1].getBoundingClientRect();
      const el = document.createElement('div');
      const top = Math.max(first.top, view.top) + 4;
      const bottom = Math.min(last.bottom, view.bottom) - 4;
      el.style.cssText = 'position:absolute;pointer-events:none;left:' + (first.left + 4 + scrollX) + 'px;top:' + (top + scrollY) + 'px;width:' + (first.width - 8) + 'px;height:' + (bottom - top) + 'px';
      document.body.append(el);
      neoAnnotate.frame(el, { number: 1, padding: 0 });`);
    await shot(s, docs + 'usage/bilder/nested-elements.png');
  },
  'page-bar': async (s) => {
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-pagebar .nfe-state'), { number: 1 });
      neoAnnotate.frame(B(A.newPage), { number: 2 });
      neoAnnotate.frame(B(A.more), { number: 3 });
      neoAnnotate.frame(B(A.withdraw) || B(A.publish), { number: 4 });`, { withdraw: t('withdraw'), publish: t('publish'), newPage: t('new_page'), more: t('page_more') });
    await shot(s, docs + 'usage/bilder/page-bar.png');
  },
  'new-page-tree': async (s) => {
    await (await btn(s, t('new_page'))).click();
    const frame = await formFrame(s);
    await markFrame(frame, `neoAnnotate.frame(document.querySelector('.nfe-current .operations') || document.querySelector('.nfe-current'), { number: 1 });`);
    await s.page.waitForTimeout(200);
    await shot(s, docs + 'usage/bilder/new-page-tree.png');
    await (await btn(s, t('close'))).click().catch(() => {});
  },
  posts: async (s) => {
    await ed(s, '.nfe-views__item[data-view=posts]').click(); await s.page.waitForTimeout(900);
    const choose = async (index, label) => {
      const sel = ed(s, '.nfe-posts__filters select').nth(index);
      const v = await sel.locator('option', { hasText: label }).first().getAttribute('value').catch(() => null);
      if (v !== null) { await sel.selectOption(v); await s.page.waitForTimeout(900); }
    };
    await choose(0, lang === 'en' ? 'News' : 'Nachrichten');
    await choose(1, lang === 'en' ? 'News' : 'Blog');
    await rest(s);
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-posts__filters'), { number: 1 });
      neoAnnotate.frame(R.querySelector('.nfe-posts__search'), { number: 2 });
      neoAnnotate.frame(R.querySelector('.nfe-list__item'), { number: 3 });
      const add = [...R.querySelectorAll('.nfe-posts__head button, .nfe-panel__body button')].find((b) => /^(${t('new_news')}|${t('new_events')}|${t('new_faq')})$/.test(b.textContent.trim()));
      if (add) neoAnnotate.frame(add, { number: 4 });`);
    await shot(s, docs + 'usage/bilder/posts.png');
  },
  // The English text shows as an outdated translation for this image; its link is restored afterwards.
  languages: async (s) => {
    const link = sql("SELECT CONCAT(l.id, ':', l.source_hash, ':', l.outdated) FROM tl_neo_fe_link l JOIN tl_content c ON c.id = l.element JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article' JOIN tl_page p ON p.id = a.pid WHERE p.alias = 'offer' AND c.type = 'text' LIMIT 1").split(':');
    if (link[0]) sql("UPDATE tl_neo_fe_link SET source_hash = '', outdated = '1' WHERE id = " + link[0]);
    try {
      await ed(s, '.nfe-views__item[data-view=languages]').click(); await s.page.waitForTimeout(1500);
      await rest(s);
      await mark(s, `
        neoAnnotate.frame(R.querySelector('.nfe-lang-bar__pair select'), { number: 1 });
        neoAnnotate.frame(R.querySelector('[data-action="lang-translate-page"]'), { number: 2 });
        const chip = R.querySelector('.nfe-chip--outdated');
        if (chip) { neoAnnotate.frame(chip, { padding: 3 }); N(chip, 3, 3); }
        const card = chip?.closest('.nfe-card');
        const open = card ? [...card.querySelectorAll('.nfe-card__actions button')].find((b) => b.textContent.trim() === A.open) : null;
        if (open) neoAnnotate.frame(open, { number: 4 });`, { open: t('tr_window') });
      await shot(s, docs + 'usage/bilder/languages.png');
      // While the whole page is translated: how far it is, the card being translated, the others locked.
      await ed(s, '[data-action="lang-translate-page"]').click();
      await s.page.waitForSelector('nfe-editor .nfe-card--busy', { timeout: 10000 }).catch(() => null);
      await s.page.evaluate(() => window.neoAnnotate?.clear());
      await s.page.waitForTimeout(150);
      await s.page.waitForTimeout(200);
      await mark(s, `
        const note = R.querySelector('.nfe-busy-cover__note');
        if (note) neoAnnotate.frame(note, { number: 1 });
        const bar = R.querySelector('.nfe-lang-bar__summary.nfe-loading');
        if (bar) neoAnnotate.frame(bar, { number: 2 });`);
      await shot(s, docs + 'usage/bilder/translate-page-progress.png');
      await s.page.waitForFunction(() => !document.querySelector('nfe-editor').shadowRoot.querySelector('.nfe-lang-bar__summary.nfe-loading'), null, { timeout: 120000 }).catch(() => null);
    } finally {
      if (link[0]) sql("UPDATE tl_neo_fe_link SET source_hash = '" + link[1] + "', outdated = '" + (link[2] || '') + "' WHERE id = " + link[0]);
    }
  },
  // The translation window of the image on the page, opened as an editor does: right click, "Open translation window".
  // For this image the image has a headline, a title and a caption in both languages; restored afterwards.
  'translation-window': async (s) => {
    const image = (alias) => sql("SELECT c.id FROM tl_content c JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article' JOIN tl_page p ON p.id = a.pid WHERE p.alias = '" + alias + "' AND c.type = 'image' ORDER BY a.sorting, c.sorting LIMIT 1");
    const texts = { angebot: ['Die Terrasse', 'Terrasse am See', 'Die Terrasse am Abend'], offer: ['The terrace', 'Terrace by the lake', 'The terrace in the evening'] };
    const saved = [];
    for (const [alias, [headline, title, caption]] of Object.entries(texts)) {
      const id = image(alias);
      if (!id) continue;
      saved.push([id, sql('SELECT CONCAT_WS(",", HEX(headline), HEX(imageTitle), HEX(caption)) FROM tl_content WHERE id = ' + id).split(',')]);
      const serialized = 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:' + Buffer.byteLength(headline) + ':"' + headline + '";}';
      sql("UPDATE tl_content SET headline = '" + serialized + "', imageTitle = '" + title + "', caption = '" + caption + "' WHERE id = " + id);
    }
    try {
      await s.page.reload();
      s.frame = await (await s.page.waitForSelector('iframe.nfe-page')).contentFrame();
      await s.frame.waitForFunction(() => document.querySelector('nfe-editor[data-nfe="page"]'));
      await tidy(s.frame);
      await s.page.waitForTimeout(800);
      const target = s.frame.locator('img').first();
      await target.scrollIntoViewIfNeeded();
      await target.click({ button: 'right' }); await s.page.waitForTimeout(500);
      await s.page.locator('nfe-editor [role^=menuitem]').filter({ hasText: t('tr_window') }).first().click();
      await s.page.waitForSelector('nfe-editor .nfe-dialog--translate .nfe-tr__row');
      await s.page.waitForTimeout(800);
      await mark(s, `
        const d = R.querySelector('.nfe-dialog--translate');
        neoAnnotate.frame(d.querySelector('.nfe-tr__pair'), { number: 1 });
        const all = [...d.querySelectorAll('.nfe-tr__bar button')].find((b) => b.textContent.trim() === A.all);
        if (all) neoAnnotate.frame(all, { number: 2 });
        const one = [...d.querySelectorAll('button[aria-label]')].find((b) => b.getAttribute('aria-label').startsWith(A.one));
        if (one) neoAnnotate.frame(one, { number: 3 });`, { all: t('tr_propose_all'), one: t('tr_propose').split('{')[0] });
      await shot(s, docs + 'usage/bilder/translation-window.png');
      // The proposals for all fields, marked as such; Save takes them over.
      await s.page.evaluate(() => window.neoAnnotate?.clear());
      await s.page.locator('nfe-editor [data-action="tr-propose-all"]').click();
      await s.page.waitForSelector('nfe-editor .nfe-tr__row--proposed', { timeout: 60000 });
      await s.page.waitForFunction(() => !document.querySelector('nfe-editor').shadowRoot.querySelector('.nfe-dialog--translate[aria-busy="true"]'), null, { timeout: 60000 });
      await s.page.waitForTimeout(500);
      quote('proposals', await s.page.evaluate(() => [...document.querySelector('nfe-editor').shadowRoot.querySelectorAll('.nfe-tr__row--proposed')].map((row) => row.querySelector('.nfe-tr__label').textContent + ': ' + row.querySelector('.nfe-tr__source').textContent + ' -> ' + (row.querySelector('.nfe-tr__target input, .nfe-tr__target textarea')?.value || row.querySelector('.nfe-tr__target [contenteditable]')?.textContent || ''))));
      await mark(s, `
        const d = R.querySelector('.nfe-dialog--translate');
        const row = d.querySelector('.nfe-tr__row--proposed');
        if (row) neoAnnotate.frame(row.querySelector('.nfe-tr__target'), { number: 1 });
        const note = row?.querySelector('.nfe-tr__note');
        if (note) neoAnnotate.frame(note, { number: 2 });
        neoAnnotate.frame(d.querySelector('[data-action="tr-save"]'), { number: 3 });`);
      await shot(s, docs + 'usage/bilder/translation-proposals.png');
    } finally {
      for (const [id, [headline, title, caption]] of saved) {
        const value = (hex) => (hex ? 'UNHEX("' + hex + '")' : "''");
        sql('UPDATE tl_content SET headline = ' + value(headline) + ', imageTitle = ' + value(title) + ', caption = ' + value(caption) + ' WHERE id = ' + id);
      }
    }
  },
  'writing-aid': async (s) => {
    await pick(s, first(s));
    await (await tabEntry(s, 'edit')).click(); await s.page.waitForTimeout(400);
    await first(s).click();
    await (await btn(s, t('writing_aid'))).click(); await s.page.waitForTimeout(500);
    await ed(s, '#nfe-aid-term').fill(lang === 'en' ? 'holiday apartment by the lake' : 'Ferienwohnung am See');
    await (await btn(s, t('create_proposals'))).click();
    await s.page.waitForSelector('nfe-editor .nfe-aid__item', { timeout: 90000 });
    await s.page.waitForTimeout(600);
    await rest(s);
    quote('original', [await first(s).textContent()]);
    quote('proposals', await s.page.evaluate(() => [...document.querySelector('nfe-editor').shadowRoot.querySelectorAll('.nfe-aid__text')].map((n) => n.textContent)));
    await mark(s, `
      const pick = (area) => [...area.querySelectorAll('.nfe-format button')].find((b) => (b.getAttribute('aria-label') || b.textContent).includes(A.aid));
      const inPage = pick(P);
      const aid = inPage ? F(inPage) : pick(R);
      if (aid) neoAnnotate.frame(aid, { number: 1 });
      neoAnnotate.frame(R.querySelector('#nfe-aid-term'), { number: 2 });
      neoAnnotate.frame(R.querySelector('[data-action="aid-create"]'), { number: 3 });
      neoAnnotate.frame(V(R.querySelector('.nfe-aid__list')), { number: 4 });`, { aid: t('writing_aid') });
    await shot(s, docs + 'usage/bilder/writing-aid.png');
  },
  'check-seo': async (s) => {
    await ed(s, '.nfe-views__item[data-view=check]').click(); await s.page.waitForTimeout(2500);
    await rest(s);
    await mark(s, `
      neoAnnotate.frame(R.querySelector('.nfe-score'), { number: 1 });
      const kw = R.querySelector('input[type=search], .nfe-check__bar ~ * input, input[name=keyword]') || [...R.querySelectorAll('input')].find((i) => i.closest('.nfe-panel__body'));
      if (kw) neoAnnotate.frame(kw, { number: 2 });
      neoAnnotate.frame(R.querySelector('.nfe-serp'), { number: 3 });
      neoAnnotate.frame(V(R.querySelector('.nfe-findings')), { number: 4 });`);
    await shot(s, docs + 'usage/bilder/check-seo.png');
  },
  'check-accessibility': async (s) => {
    await ed(s, '.nfe-views__item[data-view=check]').click(); await s.page.waitForTimeout(1500);
    await (await btn(s, t('check_a11y'))).click(); await s.page.waitForTimeout(3500);
    await s.frame.evaluate(() => [...document.querySelectorAll('h4')].find((n) => /Getting here|Anfahrt/.test(n.textContent))?.scrollIntoView({ block: 'center' }));
    await s.page.waitForTimeout(500);
    await rest(s);
    await mark(s, `
      const groups = R.querySelectorAll('.nfe-findings');
      if (groups[0]) neoAnnotate.frame(groups[0], { number: 1 });
      const heading = [...D.querySelectorAll('h4')].find((n) => /Getting here|Anfahrt/.test(n.textContent));
      // The number at the right: the check's own number of the finding sits at the heading's left.
      if (heading) { const stand = F(heading); neoAnnotate.frame(stand, { padding: 18 }); N(stand, 2, 18); }`);
    await shot(s, docs + 'usage/bilder/check-accessibility.png');
  },
  'screen-size': async (s) => {
    await (await btn(s, t('screen_size'))).click(); await s.page.waitForTimeout(400);
    await mark(s, `
      neoAnnotate.frame(B(A.size), { number: 1 });
      neoAnnotate.frame(R.querySelector('.nfe-menu'), { number: 2 });`, { size: t('screen_size') });
    await shot(s, docs + 'usage/bilder/screen-size-menu.png');
    await s.page.evaluate(() => window.neoAnnotate?.clear());
    const phone = s.page.locator('nfe-editor [role=menuitemradio], nfe-editor [role=menuitem]').filter({ hasText: t('screen_phone_name') }).first();
    await phone.click(); await s.page.waitForTimeout(2500);
    await rest(s);
    await mark(s, `
      const bar = R.querySelector('.nfe-screen__bar');
      neoAnnotate.frame(bar.querySelector('select'), { number: 1 });
      neoAnnotate.frame(B(A.rotate), { number: 2 });
      neoAnnotate.frame(bar.querySelector('button[aria-label="' + A.close + '"]'), { number: 3 });`, { rotate: t('screen_rotate'), close: t('close') });
    await shot(s, docs + 'usage/bilder/screen-size.png');
  },
  'page-style': async (s) => {
    await (await btn(s, t('page_more'))).click(); await s.page.waitForTimeout(250);
    await s.page.locator('nfe-editor .nfe-menu [role=menuitem]').filter({ hasText: t('style_menu') }).first().click();
    const frame = await formFrame(s);
    const css = (lang === 'en' ? '// Only on this page' : '// Nur auf dieser Seite') + '\n$accent: #0b5cad;\n\nh1 {\n  color: $accent;\n}\n\n.content-text p {\n  font-size: 1.125rem;\n}\n';
    await frame.evaluate((v) => { const e = document.querySelector('#ctrl_scss').parentNode.querySelector('.ace_editor').env.editor; e.setValue(v, 1); e.gotoLine(5, 16); e.blur(); }, css);
    await s.page.waitForTimeout(1800); await rest(s);
    await markFrame(frame, `
      const scope = document.querySelector('#ctrl_scope') || document.querySelector('[name=scope]');
      if (scope) neoAnnotate.frame(scope.closest('.widget') || scope, { number: 1 });
      const ace = document.querySelector('.ace_editor');
      if (ace) neoAnnotate.frame(ace, { number: 2 });`);
    await mark(s, `const status = R.querySelector('.nfe-style-status'); if (status) neoAnnotate.frame(status, { number: 3 });`);
    await shot(s, docs + 'usage/bilder/page-style.png');
  },
  'info-card': async (s) => {
    await pick(s, s.frame.locator('.content-image img, figure img').first());
    await (await tabEntry(s, 'info')).click(); await s.page.waitForTimeout(900);
    await shot(s, docs + 'usage/bilder/info-card.png');
  },
  'templates-of-page': async (s) => {
    await (await btn(s, t('page_more'))).click(); await s.page.waitForTimeout(250);
    await s.page.locator('nfe-editor .nfe-menu [role=menuitem]').filter({ hasText: t('page_templates') }).first().click(); await s.page.waitForTimeout(1500);
    await rest(s);
    await shot(s, docs + 'usage/bilder/templates-of-page.png');
  },
  'module-frame': async (s) => {
    await pick(s, s.frame.locator('#header nav a').nth(1));
    // The number beside the tab: above it is the preview bar.
    await mark(s, `const tab = F(P.querySelector('.nfe-type')); neoAnnotate.frame(tab); M(tab, 1);`);
    await shot(s, docs + 'usage/bilder/module-frame.png');
  },
  'missing-permission': async (s) => {
    await pick(s, s.frame.locator('#header nav a').nth(1));
    await (await tabEntry(s, 'info')).click(); await s.page.waitForTimeout(900);
    await mark(s, `neoAnnotate.frame(R.querySelector('.nfe-info-card .nfe-missing'), { number: 1 });`);
    await shot(s, docs + 'usage/bilder/missing-permission.png');
  },
  // A new German page (German is the main language): the step offers English. The page is removed afterwards.
  'language-versions': async (s) => {
    await (await btn(s, t('new_page'))).click();
    const frame = await formFrame(s);
    await frame.locator('.nfe-current form[action*="mode=1"] button').first().click();
    await frame.waitForURL(/act=edit/); await frame.waitForSelector('#ctrl_title');
    await frame.fill('#ctrl_title', 'Herbstangebot'); await frame.fill('#ctrl_alias', 'herbst');
    await frame.click('#save'); await s.page.waitForTimeout(2500);
    const row = ed(s, '.nfe-versions__row').first();
    await row.locator('input.tl_text').first().fill('Autumn offer'); await row.locator('input.tl_text').nth(1).fill('autumn');
    await ed(s, '.nfe-versions__actions .nfe-button--primary').focus(); await rest(s);
    // Numbers at the top right corner of the frames: the left one would cover the first letters.
    await mark(s, `
      [R.querySelector('.nfe-versions__row legend'), R.querySelector('.nfe-versions__row input[id$="-alias"]'), R.querySelector('.nfe-versions__place'), R.querySelector('.nfe-versions__actions .nfe-button--primary')].forEach((el, i) => {
        neoAnnotate.frame(el, { padding: 3 });
        const r = el.getBoundingClientRect();
        const at = document.createElement('div');
        at.style.cssText = 'position:absolute;width:1px;height:1px;left:' + (r.right + 3 + scrollX) + 'px;top:' + (r.top - 3 + scrollY) + 'px';
        document.body.append(at);
        neoAnnotate.number(at, i + 1);
      });`);
    await shot(s, docs + 'usage/bilder/language-versions.png');
    const id = sql("SELECT id FROM tl_page WHERE alias = 'herbst' AND title = 'Herbstangebot'");
    if (id) sql('DELETE FROM tl_article WHERE pid IN (' + id + '); DELETE FROM tl_page WHERE id IN (' + id + ')');
  },
  'group-switches': async ({ page }) => {
    const w = page.locator('#ctrl_nfe_permissions').first();
    await w.scrollIntoViewIfNeeded(); await page.waitForTimeout(300);
    await markFrame(page.mainFrame(), `const el = document.querySelector('#ctrl_nfe_permissions'); neoAnnotate.frame(el.closest('.widget') || el, { number: 1 });`);
    const box = await w.evaluate((el) => { const r = (el.closest('fieldset') || el.closest('.widget') || el).getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
    fs.mkdirSync(docs + 'bilder', { recursive: true });
    await page.screenshot({ path: docs + 'bilder/group-switches.png', clip: { x: 0, y: Math.max(0, box.y - 60), width: 1280, height: Math.min(800, box.h + 140) } });
    console.log('  ', 'bilder/group-switches.png');
  },
  settings: async ({ page }) => {
    await markFrame(page.mainFrame(), `
      const u = document.querySelector('#ctrl_backend_url'); if (u) neoAnnotate.frame(u.closest('.widget') || u, { number: 1 });
      const t = document.querySelector('#ctrl_translation_service'); if (t) neoAnnotate.frame(t.closest('fieldset') || t, { number: 2 });
      const m = document.querySelector('#ctrl_ai_model'); if (m) neoAnnotate.frame(m.closest('fieldset') || m, { number: 3 });`);
    fs.mkdirSync(docs + 'bilder', { recursive: true });
    await page.screenshot({ path: docs + 'bilder/settings.png' });
    console.log('  ', 'bilder/settings.png');
  },
};

// PHP serialisation of a list of strings, as Contao stores permission fields.
const ser = (list) => 'a:' + list.length + ':{' + list.map((v, i) => 'i:' + i + ';s:' + Buffer.byteLength(v) + ':"' + v + '";').join('') + '}';
const RIGHTS = ['alexf', 'cud', 'modules', 'fields', 'themes', 'frontendModules', 'forms', 'nfe_permissions'];

// Leaves some rights out on purpose, so "Info" names what is missing; returns a function that restores the group.
function withoutSomeRights(group) {
  const saved = sql('SELECT CONCAT_WS(",", ' + RIGHTS.map((f) => 'HEX(IFNULL(' + f + ', ""))').join(', ') + ') FROM tl_user_group WHERE id = ' + group).split(',');
  const form = sql("SELECT id FROM tl_form WHERE alias = '" + FORM + "'");
  const values = {
    alexf: ser(['tl_module::name', 'tl_content::headline', 'tl_content::text', 'tl_form_field::label']),
    cud: ser(['tl_content::update', 'tl_article::update', 'tl_layout::update']),
    modules: ser(['article', 'themes', 'form', 'tpl_editor']),
    fields: ser(['textarea', 'submit']),
    themes: ser(['modules', 'layout']),
    frontendModules: ser(['navigation', 'changelanguage']),
    forms: ser(form ? [form] : []),
    nfe_permissions: ser(['edit_mode', 'edit_content', 'edit_structure', 'edit_design']),
  };
  sql('UPDATE tl_user_group SET ' + RIGHTS.map((f) => f + " = '" + values[f].replace(/'/g, "''") + "'").join(', ') + ' WHERE id = ' + group);

  return () => sql('UPDATE tl_user_group SET ' + RIGHTS.map((f, i) => f + ' = ' + (saved[i] ? 'UNHEX("' + saved[i] + '")' : "''")).join(', ') + ' WHERE id = ' + group);
}

(async () => {
  if (!ADMIN.password) {
    console.error('Set NFE_ADMIN_PASSWORD (and NFE_EDITOR_PASSWORD for missing-permission).');
    process.exit(2);
  }
  if (!ANNOTATE || !fs.existsSync(ANNOTATE)) {
    console.error('Set NFE_ANNOTATE to the path of annotate.js (annotation layer of the plugin neo-doku).');
    process.exit(2);
  }
  const browser = await chromium.launch();
  const group = sql('SELECT `groups` FROM tl_user WHERE username = \'' + EDITOR.user + '\'').match(/"(\d+)"/)?.[1] || sql('SELECT id FROM tl_user_group LIMIT 1');
  for (const [name, run] of Object.entries(shots)) {
    if (only && !only.includes(name)) continue;
    let opts = {};
    let restore = null;
    if (name === 'missing-permission') {
      restore = withoutSomeRights(group);
      opts = { user: EDITOR };
    }
    if (name === 'language-versions') opts = { path: 'de/angebot' };
    if (name === 'group-switches') opts = { backend: '/contao?do=group&act=edit&id=' + group };
    if (name === 'settings') opts = { backend: '/contao?do=neo_frontendeditor_settings&act=edit&id=1' };
    const s = await open(browser, opts);
    try { await run(s); } catch (e) { console.log('FAILED', name, e.message.split('\n')[0]); }
    await s.ctx.close();
    if (restore) restore();
  }
  await browser.close();
  for (const entry of languages) {
    const [user, language] = entry.split(':');
    sql("UPDATE tl_user SET language = '" + language + "' WHERE username = '" + user + "'");
  }
})();
