/*
 * Front end editor: runs only in the editor view of Contao's preview in edit
 * mode (EditorViewListener). The view is a document of its own: Contao's
 * preview bar at the top, the sidebar on the right and the page in a frame
 * next to it (same address, EditMode::FRAME_PARAMETER), so the page becomes
 * narrower and nothing covers it. This one script works in both documents:
 *
 * - in the view: the sidebar and its views, the bar of a screen size, the
 *   collapsed bar, menus, the card "Info", Contao's pickers, messages;
 * - in the page of the frame (same origin, reached through the frame's
 *   document): finding the elements and parts, the frame, type and buttons at
 *   them, editing on the page, the marks of the check.
 *
 * Each document has its own closed area (shadow DOM) with the back end's
 * stylesheets, so the look follows the back end and its skin and nothing leaks
 * into the website. The page in the frame gets no script of the editor.
 */
(() => {
    'use strict';

    const configNode = document.getElementById('nfe-config');

    if (!configNode || window.NeoFrontendEditor) {
        return;
    }

    // An editor view in the frame of an editor view: a browser without Fetch Metadata
    // (EditMode::isDocumentRequest) followed a link in the page. The frame shows that page instead.
    try {
        if (window.parent !== window && window.parent.NeoFrontendEditor?.follow) {
            window.parent.NeoFrontendEditor.follow(location.href);

            return;
        }
    } catch (e) {
        // A frame of another website: the view works on its own.
    }

    const config = JSON.parse(configNode.textContent);
    const fill = (text, vars) => text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? vars[name] : match));
    const t = (key, vars = {}) => fill(config.texts[key] || key, vars);
    // A text with a number: the translator's form for a sample number of the same plural category (EditorViewListener).
    const tn = (key, n, vars = {}) => {
        const forms = config.plurals?.[key];

        if (!forms) {
            return t(key, vars);
        }

        const rules = new Intl.PluralRules(config.lang || undefined);
        const samples = Object.keys(forms);
        const sample = forms[n] !== undefined ? String(n) : (samples.find((entry) => rules.select(Number(entry)) === rules.select(n)) || samples[samples.length - 1]);

        return fill(forms[sample], vars);
    };
    // Name of the translation service, shown at every proposal: it is a machine translation.
    const serviceName = () => t('service_' + (editions().translateService || 'azure'));
    const storage = {
        get: (key) => { try { return localStorage.getItem('nfe:' + key); } catch (e) { return null; } },
        set: (key, value) => { try { localStorage.setItem('nfe:' + key, value); } catch (e) { /* private mode */ } },
    };
    const narrow = () => window.matchMedia('(max-width: 768px)').matches;

    // ---- one row of buttons

    // A row of buttons always stays one line (the editor's rule): where its buttons do not fit, the last ones (not those
    // marked data-keep, which stay in the row) go into a menu behind "…" at its end, in their order; where the row gets
    // wider they come back. The buttons keep their own actions; in the menu they look like the entries of the
    // element's menu, a button showing only a symbol with its name. Separators move along.
    const fitRows = new WeakMap();

    function fitRow(row) {
        let fit = fitRows.get(row);

        if (!fit) {
            const more = h('button', { type: 'button', class: 'tl_submit nfe-button nfe-button--icon nfe-more', title: t('more'), 'aria-label': t('more'), 'aria-haspopup': 'true', 'aria-expanded': 'false', 'data-keep': '', hidden: true, onmousedown: (event) => event.preventDefault() }, [icon('more') || '…']);
            const menu = h('div', { class: 'nfe-menu nfe-more__menu', hidden: true });
            const outside = (event) => {
                if (!event.composedPath().includes(more) && !event.composedPath().includes(menu)) {
                    show(false);
                }
            };
            const show = (open) => {
                menu.hidden = !open;
                more.setAttribute('aria-expanded', open ? 'true' : 'false');

                for (const doc of [document, pageDoc].filter(Boolean)) {
                    doc.removeEventListener('pointerdown', outside, true);

                    if (open) {
                        doc.addEventListener('pointerdown', outside, true);
                    }
                }

                if (open) {
                    // Below the button, or above it where the window ends; inside the window.
                    const box = more.getBoundingClientRect();
                    const view = more.ownerDocument.defaultView;
                    const top = box.bottom + 2 + menu.offsetHeight <= view.innerHeight - 4 ? box.bottom + 2 : Math.max(4, box.top - 2 - menu.offsetHeight);

                    Object.assign(menu.style, { left: Math.max(4, Math.min(box.right - menu.offsetWidth, view.innerWidth - menu.offsetWidth - 4)) + 'px', top: top + 'px' });
                }
            };

            more.addEventListener('click', () => show(menu.hidden));
            // An entry closes the menu once it did its work.
            menu.addEventListener('click', (event) => {
                if (event.target.closest('button')) {
                    show(false);
                }
            });

            fit = { more, menu, show, moved: [] };
            fitRows.set(row, fit);
            new ResizeObserver(() => fitRow(row)).observe(row);
        }

        const { more, menu } = fit;

        // Everything back in its place first.
        for (const { node, mark, className, label } of fit.moved.splice(0)) {
            // Drawn anew in the meantime (the buttons of the status, the tools of another text): the old one goes.
            if (!mark.isConnected) {
                node.remove();
                continue;
            }

            node.className = className;
            label?.remove();
            mark.replaceWith(node);
        }

        fit.show(false);
        more.hidden = true;

        if (!row.isConnected || row.hidden) {
            return;
        }

        if (more.parentNode !== row) {
            row.append(more, menu);
        }

        const overflows = () => row.scrollWidth > row.clientWidth + 1;

        if (!overflows()) {
            return;
        }

        more.hidden = false;

        const candidates = [...row.querySelectorAll('button, .nfe-sep')].filter((node) => node !== more && !menu.contains(node) && !node.closest('[data-keep]') && !node.hidden);

        while (overflows() && candidates.length) {
            const node = candidates.pop();
            const mark = document.createComment('');
            const entry = { node, mark, className: node.className, label: null };

            node.replaceWith(mark);

            if (node.classList.contains('nfe-sep')) {
                node.className = 'nfe-menu__sep';
            } else {
                node.className = 'nfe-menu__item';

                // A button with a symbol only gets its name in the menu.
                if (!node.textContent.trim() || node.classList.contains('nfe-button--icon')) {
                    entry.label = h('span', { text: node.getAttribute('aria-label') || node.title || '' });
                    node.append(entry.label);
                }
            }

            menu.prepend(node);
            fit.moved.push(entry);
        }

        // A separator does not end the row or start or end the menu.
        const last = [...row.children].filter((node) => node !== more && node !== menu && !node.hidden && node.nodeType === 1).pop();

        if (last?.classList.contains('nfe-sep')) {
            const mark = document.createComment('');

            last.replaceWith(mark);
            last.className = 'nfe-menu__sep';
            menu.prepend(last);
            fit.moved.push({ node: last, mark, className: 'nfe-sep', label: null });
        }
    }

    const state = {
        token: '',
        structure: null,
        ranges: new Map(),
        selected: null,
        editing: null,
        // Changed texts of parts that are no longer edited, waiting for Save (or Cancel): { part, values, shown, proposal }.
        pending: [],
        dirty: false,
        error: false,
        // The sidebar beside the page (it makes the page narrower), or over it: then it opens when a view needs it
        // and closes again by itself (autoOpened), and a click into the page closes it.
        overlay: storage.get('overlay') === '1',
        collapsed: storage.get('overlay') === '1' || storage.get('collapsed') === '1',
        autoOpened: false,
        view: 'structure',
        busy: false,
        // The next placement of the selected part may scroll the window to make room for its tabs (placeSelection).
        reveal: false,
        form: null,
        drag: null,
        menu: null,
        lang: null,
        // The part under the pointer (only outlined), and what to restore after the page in the frame was loaded again.
        hover: null,
        restore: null,
    };

    // The page in the frame: its document and window, and the editor's closed area in it (attachPage).
    let pageDoc = null;
    let pageWin = null;
    let pageHost = null;
    let pageRoot = null;

    // ------------------------------------------------------------------ helpers

    const h = (tag, attributes = {}, children = []) => {
        const node = document.createElement(tag);

        for (const [name, value] of Object.entries(attributes)) {
            if (value === false || value === null || value === undefined) {
                continue;
            }

            if (name.startsWith('on')) {
                node.addEventListener(name.slice(2), value);
            } else if (name === 'text') {
                node.textContent = value;
            } else {
                node.setAttribute(name, value === true ? '' : value);
            }
        }

        for (const child of [].concat(children)) {
            if (child) {
                node.append(child);
            }
        }

        return node;
    };

    // Contao's own icons of the back-end theme (light and dark variant where the theme has one).
    const icon = (name) => {
        const entry = config.icons[name];

        if (!entry) {
            return null;
        }

        const images = [h('img', { src: entry.light, alt: '', class: entry.dark ? 'color-scheme--light' : null, width: 16, height: 16, draggable: 'false' })];

        if (entry.dark) {
            images.push(h('img', { src: entry.dark, alt: '', class: 'color-scheme--dark', width: 16, height: 16, draggable: 'false' }));
        }

        return h('span', { class: 'nfe-icon', 'aria-hidden': 'true' }, images);
    };

    // An icon in the colour of the text: Contao's outline icon as a mask, so icons that Contao colours differently
    // look alike (views bar) and follow the back-end theme.
    const maskIcon = (name) => {
        const entry = config.icons[name];

        if (!entry) {
            return null;
        }

        const node = h('span', { class: 'nfe-mask-icon', 'aria-hidden': 'true' });
        node.style.setProperty('--nfe-icon', 'url("' + entry.light + '")');

        return node;
    };

    // A row of actions on one line: labels shorten instead of wrapping (the full label as tooltip), the "…" button
    // keeps its place next to them.
    const actionRow = (nodes, className = '') => {
        const row = h('div', { class: ('nfe-actions ' + className).trim() }, nodes.filter(Boolean));
        titleActions(row);

        return row;
    };

    const titleActions = (row) => {
        row.querySelectorAll(':scope > .nfe-button:not(.nfe-button--icon)').forEach((node) => {
            node.title = node.textContent.trim();
        });
    };

    const button = (label, options = {}) => h('button', {
        type: 'button',
        class: 'tl_submit nfe-button' + (options.iconOnly ? ' nfe-button--icon' : '') + (options.className ? ' ' + options.className : ''),
        title: options.iconOnly ? label : null,
        'aria-label': options.iconOnly ? label : null,
        'aria-pressed': options.pressed,
        'aria-keyshortcuts': options.shortcuts,
        'data-action': options.action,
        'aria-busy': options.action && running.has(options.action) ? 'true' : null,
        disabled: options.disabled,
        onclick: options.onclick,
    }, [options.glyph ? glyph(options.glyph) : (options.icon ? icon(options.icon) : null), options.iconOnly ? null : h('span', { text: label })]);

    const glyph = (text, className) => h('span', { class: 'nfe-glyph ' + (className || ''), 'aria-hidden': 'true', text });

    // "Loading …" with a spinner, where a view or a window waits for its content.
    const loadingNote = (className = '') => h('p', { class: (className ? className + ' ' : '') + 'nfe-loading', role: 'status' }, [h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }), h('span', { text: t('loading') })]);

    // A veil with a spinner and what runs, over the area that waits for it (the fields of the translation window, one
    // field, the comparison of the languages, the writing aid). Visual only: a status line says the same to screen
    // readers.
    const busyCover = (text, small = false) => h('div', { class: 'nfe-busy-cover' + (small ? ' nfe-busy-cover--small' : ''), 'aria-hidden': 'true' }, [
        h('div', { class: 'nfe-busy-cover__note' }, [h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }), h('span', { text })]),
    ]);

    // The scrolling area gets the veil over the part in view; it does not scroll while the work runs. $focus: a part
    // of it that is brought into view first (the card being translated).
    function coverArea(area, text, focus = null) {
        area.classList.add('nfe-busy-area');
        area.append(busyCover(text));

        // After drawing: the veil where the area is scrolled to, as high as it is shown.
        window.requestAnimationFrame(() => {
            if (!area.isConnected) {
                return;
            }

            focus?.scrollIntoView({ block: 'center' });

            const cover = area.querySelector(':scope > .nfe-busy-cover');

            if (cover) {
                cover.style.top = area.scrollTop + 'px';
                cover.style.height = area.clientHeight + 'px';
            }
        });
    }

    // A back-end page in the sidebar (Contao's form, a module): "Loading …" over it until it is there, and again
    // when it is loaded anew (waitForm).
    const framed = (iframe) => {
        const wait = loadingNote('nfe-panel__wait');

        iframe.addEventListener('load', () => {
            wait.hidden = true;
        });

        return h('div', { class: 'nfe-panel__frame' }, [iframe, wait]);
    };

    const waitForm = (iframe) => {
        const wait = iframe.parentElement?.querySelector('.nfe-panel__wait');

        if (wait) {
            wait.hidden = false;
        }
    };

    // A button drawn while its work still runs (the panel was drawn again): busy from the start.
    const busyWhile = (node, busy) => {
        if (busy) {
            node.setAttribute('aria-busy', 'true');
        }

        return node;
    };

    const decode = (html) => {
        const area = document.createElement('textarea');
        area.innerHTML = html;

        return area.value;
    };

    // The button whose click started a request shows it at once: spinner and aria-busy until the answer is there
    // (requests that start up to 1.5 s after the click, e.g. after a question, count as started by it).
    let lastClick = { node: null, action: null, at: 0 };

    const recordClick = (event) => {
        // Elements of the page's frame belong to its window: no instanceof check.
        const node = event.composedPath().find((entry) => entry.nodeType === 1 && entry.matches('button, .nfe-button'));

        if (node) {
            lastClick = { node, action: node.dataset.action || null, at: performance.now() };
        }
    };

    // Actions (data-action) whose work still runs: a button drawn again meanwhile (the window or the sidebar shows the
    // new state) is busy from the start, and the one drawn before the request started is found by its action.
    const running = new Map();

    const actionNodes = (action) => [root, pageRoot].filter(Boolean).flatMap((area) => [...area.querySelectorAll('[data-action="' + CSS.escape(action) + '"]')]);

    function holdAction(action, step) {
        const count = (running.get(action) || 0) + step;

        if (count > 0) {
            running.set(action, count);
        } else {
            running.delete(action);
        }

        // Buttons marked only because their action runs (not counted by setBusy) follow the registry.
        for (const node of actionNodes(action)) {
            if (node.dataset.nfeBusy) {
                continue;
            }

            if (count > 0) {
                node.setAttribute('aria-busy', 'true');
            } else {
                node.removeAttribute('aria-busy');
            }
        }
    }

    // Work of several requests (a whole page, all proposals): its button stays busy from the first to the last one.
    async function whileAction(action, work) {
        holdAction(action, 1);

        try {
            return await work();
        } finally {
            holdAction(action, -1);
        }
    }

    function setBusy(node, step) {
        if (!node) {
            return;
        }

        const count = (Number(node.dataset.nfeBusy) || 0) + step;

        if (count > 0) {
            node.dataset.nfeBusy = String(count);
            node.setAttribute('aria-busy', 'true');
        } else {
            delete node.dataset.nfeBusy;

            if (!running.has(node.dataset.action)) {
                node.removeAttribute('aria-busy');
            }
        }
    }

    // Requests started by a click whose button is gone (an entry of a menu that closed) show a note instead.
    let unmarked = 0;
    let unmarkedTimer = 0;

    function showWorking(step) {
        unmarked += step;
        window.clearTimeout(unmarkedTimer);

        // Short requests need no note: it appears after a moment and goes with the last of them.
        if (unmarked > 0) {
            unmarkedTimer = window.setTimeout(() => {
                working.replaceChildren(h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }), h('span', { text: t('busy_working') }));
                working.hidden = false;
            }, 300);
        } else {
            working.hidden = true;
            working.replaceChildren();
        }
    }

    // The veil over the page while it loads again; it goes once the editor is attached to the new page (at most 20 s).
    let veilTimer = 0;

    function showVeil(shown) {
        window.clearTimeout(veilTimer);
        veil.hidden = !shown;
        veil.firstElementChild.replaceChildren(...(shown ? [h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }), h('span', { text: t('busy_page') })] : []));
        pageFrame.toggleAttribute('aria-busy', shown);

        if (shown) {
            pageFrame.setAttribute('aria-busy', 'true');
            veilTimer = window.setTimeout(() => showVeil(false), 20000);
        }
    }

    async function api(path, options = {}) {
        const recent = performance.now() - lastClick.at < 1500;
        const action = recent ? lastClick.action : null;
        const trigger = recent ? (lastClick.node?.isConnected ? lastClick.node : (action ? actionNodes(action)[0] || null : null)) : null;
        const note = recent && !trigger;

        setBusy(trigger, 1);

        if (action) {
            holdAction(action, 1);
        }

        if (note) {
            showWorking(1);
        }

        try {
            return await request(path, options);
        } finally {
            setBusy(trigger, -1);

            if (action) {
                holdAction(action, -1);
            }

            if (note) {
                showWorking(-1);
            }
        }
    }

    async function request(path, options = {}) {
        const headers = { Accept: 'application/json' };

        if (options.body) {
            headers['Content-Type'] = 'application/json';
            headers['X-Request-Token'] = state.token;
        }

        const response = await fetch(config.api + path, { credentials: 'include', ...options, headers });
        let data = null;

        try {
            data = await response.json();
        } catch (e) {
            data = null;
        }

        // The request token is no longer valid (a new login, an expired cookie): fetch it again once and
        // repeat the request; if that fails too, the session has ended and only reloading the page helps.
        if (data?.errorCode === 'NFE419' && options.body) {
            if (!options.retried) {
                try {
                    state.token = (await api('/session')).token;

                    return await api(path, { ...options, retried: true });
                } catch (e) {
                    if (e.data?.errorCode !== 'NFE419') {
                        sessionExpired();
                    }

                    throw e;
                }
            }

            sessionExpired();
        }

        if (!response.ok || !data || data.success !== true) {
            const error = new Error(state.sessionExpired ? t('session_expired') : (data?.message || response.statusText));
            error.data = data;
            error.status = response.status;
            throw error;
        }

        return data;
    }

    // From now on every message says that the session ended, with "Reload" instead of "Save again".
    function sessionExpired() {
        state.sessionExpired = true;
        showToast(t('session_expired'));
    }

    // ------------------------------------------------------------------ elements on the page

    // Contao encloses every element in <!--nfe:ce:ID--> … <!--/nfe:ce:ID--> (ContentMarkerListener).
    function findRanges(rootNode = pageDoc?.body) {
        const ranges = new Map();
        const open = [];

        if (!rootNode) {
            return ranges;
        }

        const walker = rootNode.ownerDocument.createTreeWalker(rootNode, NodeFilter.SHOW_COMMENT);
        let comment;

        while ((comment = walker.nextNode())) {
            const match = /^(\/?)nfe:ce:(\d+)$/.exec(comment.data);

            if (!match) {
                continue;
            }

            const id = Number(match[2]);

            if (!match[1]) {
                open.push({ id, start: comment });
                continue;
            }

            const index = open.map((entry) => entry.id).lastIndexOf(id);

            if (index !== -1) {
                ranges.set(id, { start: open.splice(index, 1)[0].start, end: comment });
            }
        }

        return ranges;
    }

    // Other marked parts in edit mode (DesignMarkerListener): fm:ID module, ff:ID form field, cm:ID comment, lp:TABLE:ID post in a list.
    function findItems(rootNode = pageDoc?.body) {
        const items = new Map();
        const open = new Map();

        if (!rootNode) {
            return items;
        }

        const walker = rootNode.ownerDocument.createTreeWalker(rootNode, NodeFilter.SHOW_COMMENT);
        let comment;

        while ((comment = walker.nextNode())) {
            const match = /^(\/?)nfe:((?:fm|ff|cm):\d+|lp:[a-z0-9_]+:\d+)$/.exec(comment.data);

            if (!match) {
                continue;
            }

            if (!match[1]) {
                open.set(match[2], comment);
            } else if (open.has(match[2])) {
                const parts = match[2].split(':');

                items.set(match[2], { key: match[2], kind: parts[0], table: parts.length === 3 ? parts[1] : null, id: Number(parts[parts.length - 1]), start: open.get(match[2]), end: comment });
                open.delete(match[2]);
            }
        }

        return items;
    }

    const nodesOf = (range) => {
        const nodes = [];

        if (!range?.start) {
            return nodes;
        }

        for (let node = range.start.nextSibling; node && node !== range.end; node = node.nextSibling) {
            if (node.nodeType === Node.ELEMENT_NODE) {
                nodes.push(node);
            }
        }

        return nodes;
    };

    // The area of the nodes of a part, in the window of the page's frame (the overlays there are fixed).
    const boxOfRange = (range) => {
        const rects = range ? nodesOf(range).map((node) => node.getBoundingClientRect()).filter((rect) => rect.width || rect.height) : [];

        if (!rects.length) {
            return null;
        }

        const left = Math.min(...rects.map((rect) => rect.left));
        const top = Math.min(...rects.map((rect) => rect.top));

        return { x: left, y: top, w: Math.max(...rects.map((rect) => rect.right)) - left, h: Math.max(...rects.map((rect) => rect.bottom)) - top };
    };

    const boxOf = (id) => boxOfRange(state.ranges.get(id));

    // The innermost marked part (module, form field, comment, post in a list) with data, and its area.
    function itemAt(node) {
        let best = null;
        let bestArea = Infinity;

        for (const [key, item] of state.items || []) {
            if (!state.itemData?.[key] || !nodesOf(item).some((child) => child === node || child.contains(node))) {
                continue;
            }

            const box = boxOfRange(item);
            const area = box ? box.w * box.h : Infinity;

            if (area < bestArea) {
                best = key;
                bestArea = area;
            }
        }

        return best ? { key: best, area: bestArea } : null;
    }

    // The innermost element that contains a node (elements can be nested).
    function elementAt(node) {
        let best = null;
        let bestArea = Infinity;

        for (const [id, range] of state.ranges) {
            if (!nodesOf(range).some((child) => child === node || child.contains(node))) {
                continue;
            }

            const box = boxOf(id);
            const area = box ? box.w * box.h : Infinity;

            if (area < bestArea) {
                best = id;
                bestArea = area;
            }
        }

        return best;
    }

    const elementData = (id) => {
        for (const article of state.structure?.articles || []) {
            const found = article.elements.find((element) => element.id === id);

            if (found) {
                return found;
            }
        }

        return null;
    };

    // Every element of the page (nested groups are groups of their own in state.structure.articles).
    const allElements = () => (state.structure?.articles || []).flatMap((article) => article.elements);

    const articleOf = (id) => (state.structure?.articles || []).find((article) => article.elements.some((element) => element.id === id)) || null;

    // Groups of elements in the sidebar: the page's articles and, on the page of a post (Plus), the post itself.
    const isPost = (group) => group?.kind === 'post';
    // Elements in an element (accordion, slider, element group …) form a group of their own.
    const isNested = (group) => group?.kind === 'nested';
    const groupById = (id) => (state.structure?.articles || []).find((group) => String(group.id) === String(id)) || null;
    const topOf = (group) => (isPost(group) ? { parent: group.postId } : isNested(group) ? { parent: group.parentId } : { article: group.id });
    // Elements move within the articles of the page, within one post, or within the element that contains them.
    const sameContainer = (a, b) => Boolean(a && b) && (isPost(a) || isPost(b) || isNested(a) || isNested(b) ? a === b : true);

    // ------------------------------------------------------------------ the closed areas: view and page

    const prefersDark = (() => {
        let stored = null;

        try {
            stored = localStorage.getItem('contao--prefers-dark');
        } catch (e) {
            stored = null;
        }

        return stored === null ? window.matchMedia('(prefers-color-scheme: dark)').matches : stored === 'true';
    })();

    // A closed area with the back end's stylesheets; "view" in the editor view, "page" in the page of the frame.
    function closedArea(node, kind) {
        const area = node.attachShadow({ mode: 'open' });

        node.dataset.nfe = kind;
        node.dataset.colorScheme = prefersDark ? 'dark' : 'light';
        area.addEventListener('click', recordClick, true);

        for (const href of config.styles) {
            area.append(h('link', { rel: 'stylesheet', href }));
        }

        adoptMainAction(node, area);

        return area;
    }

    // The main action of a row (Save, Apply …) looks like the back end's own main action, the first button of a form.
    // Contao draws it like every other button; a skin may colour it. Its colours are read in the closed area once the
    // back end's stylesheets are there, and kept only where they differ from a plain button (editor.css, --nfe-main-*).
    async function adoptMainAction(node, area) {
        await Promise.all([...area.querySelectorAll('link[rel="stylesheet"]')].map((link) => (link.sheet ? null : new Promise((resolve) => {
            link.addEventListener('load', resolve, { once: true });
            link.addEventListener('error', resolve, { once: true });
        }))));

        const plain = h('button', { type: 'button', class: 'tl_submit', tabindex: '-1', text: 'x' });
        const main = h('button', { type: 'button', class: 'tl_submit', tabindex: '-1', text: 'x' });
        const probe = h('div', { class: 'nfe-probe', 'aria-hidden': 'true' }, [h('div', {}, [plain]), h('div', { class: 'tl_submit_container' }, [main])]);

        area.append(probe);

        const [a, b] = [getComputedStyle(plain), getComputedStyle(main)];

        if (b.backgroundColor !== a.backgroundColor || b.color !== a.color) {
            node.dataset.nfeMain = '';
            node.style.setProperty('--nfe-main-bg', b.backgroundColor);
            node.style.setProperty('--nfe-main-text', b.color);
            node.style.setProperty('--nfe-main-border', b.borderTopColor);
        }

        probe.remove();
    }

    const host = h('nfe-editor');
    const root = closedArea(host, 'view');

    // In the page: frame, the tab with the type (the menu of the part) and "insert element" at the selected part, the
    // outline under the pointer, the tools of the text or image being edited (tabs next to the type), the line where a
    // dragged element lands and the marks of the check.
    const layer = h('div', { class: 'nfe-layer' });
    const frame = h('div', { class: 'nfe-frame', hidden: true });
    const hoverFrame = h('div', { class: 'nfe-frame nfe-frame--hover', hidden: true });
    const label = h('div', { class: 'nfe-type', hidden: true });
    const format = h('div', { class: 'nfe-format', role: 'toolbar', 'aria-label': t('edit_text'), hidden: true });
    const imageTools = h('div', { class: 'nfe-image', hidden: true });
    const insertAbove = h('div', { class: 'nfe-insert', hidden: true });
    const insertBelow = h('div', { class: 'nfe-insert', hidden: true });
    const dropLine = h('div', { class: 'nfe-drop', hidden: true });
    const markers = h('div', { class: 'nfe-markers' });
    const note = h('div', { class: 'nfe-note', role: 'status', hidden: true });

    layer.append(hoverFrame, frame, label, insertAbove, insertBelow, format, imageTools, dropLine, note);

    // In the view: the page in its frame (with the bar of a screen size above it), the sidebar, the bar of the
    // collapsed sidebar, the handle for the sidebar's width, messages.
    const pageFrame = h('iframe', { class: 'nfe-page', title: document.title });
    const screenBar = h('div', { class: 'nfe-screen__bar', role: 'toolbar', 'aria-label': t('screen_size'), hidden: true });
    // While the page loads again after a change, a veil over the old one says so (reloadPage until attach).
    const veil = h('div', { class: 'nfe-veil', hidden: true }, [h('div', { class: 'nfe-veil__note', role: 'status' })]);
    const stageArea = h('div', { class: 'nfe-stage__area' }, [pageFrame, veil]);
    const stage = h('div', { class: 'nfe-stage' }, [screenBar, stageArea]);
    const live = h('div', { class: 'nfe-live', role: 'status', 'aria-live': 'polite' });
    const panel = h('aside', { class: 'nfe-panel' });
    const floating = h('div', { class: 'nfe-float', role: 'toolbar' });
    // The strip at the right edge (wide screens): open and close the sidebar, beside or over the page.
    const rail = h('div', { class: 'nfe-rail', role: 'toolbar', 'aria-orientation': 'vertical' });
    const toast = h('div', { class: 'nfe-toast', role: 'alert', hidden: true });
    // Work started from an entry of a menu that closed: a note at the bottom edge while it runs (showWorking).
    const working = h('div', { class: 'nfe-working', role: 'status', hidden: true });
    const view = h('div', { class: 'nfe-view' }, [stage, panel, floating, rail]);
    // The width of the sidebar can be changed (drag or arrow keys); the browser keeps it per kind of view.
    const resizer = h('div', { class: 'nfe-resize', role: 'separator', 'aria-orientation': 'vertical', tabindex: '0', hidden: true }, [h('span', { class: 'nfe-resize__grip', 'aria-hidden': 'true' })]);

    // The grip on the edge: six dots, like the grip of the TinyMCE bar.
    resizer.firstElementChild.innerHTML = '<svg width="8" height="14" viewBox="0 0 8 14" fill="currentColor"><circle cx="2" cy="2" r="1.3"/><circle cx="6" cy="2" r="1.3"/><circle cx="2" cy="7" r="1.3"/><circle cx="6" cy="7" r="1.3"/><circle cx="2" cy="12" r="1.3"/><circle cx="6" cy="12" r="1.3"/></svg>';

    root.append(view, resizer, toast, working, live);
    document.documentElement.style.overflow = 'hidden';
    document.body.style.margin = '0';
    document.body.append(host);

    // Address of the page in the frame, and the page's own address (the view's, without the marker).
    const frameUrl = (href) => {
        const url = new URL(href, location.href);

        url.searchParams.set(config.frame, '1');

        return url.href;
    };
    const plainUrl = (href) => {
        const url = new URL(href, location.href);

        url.searchParams.delete(config.frame);

        return url.href;
    };

    // The size of the page's window (without its scroll bar), and the area of a node in the view's window.
    const pageView = () => ({ width: pageDoc?.documentElement.clientWidth || 0, height: pageDoc?.documentElement.clientHeight || 0 });
    const styleOf = (node) => node.ownerDocument.defaultView.getComputedStyle(node);

    function viewRect(node) {
        const rect = node.getBoundingClientRect();

        if (node.ownerDocument === document) {
            return rect;
        }

        const outer = pageFrame.getBoundingClientRect();
        const left = rect.left + outer.left + pageFrame.clientLeft;
        const top = rect.top + outer.top + pageFrame.clientTop;

        return { left, top, right: left + rect.width, bottom: top + rect.height, width: rect.width, height: rect.height };
    }

    // Contao's preview toolbar: a custom element with an open shadow root (cto-toolbar); before
    // Contao 5.3.40, 5.4 to 5.6.9 a plain element in the page (.cto-toolbar), which fires
    // "cto-toolbar-loaded" on the window instead of on itself.
    const toolbarArea = () => document.querySelector('cto-toolbar')?.shadowRoot || (document.querySelector('body > .cto-toolbar') ? document : null);

    // What Contao's preview toolbar takes at the top of the window: the bar, or its button to open it again.
    function toolbarHeight() {
        const area = toolbarArea();

        for (const selector of ['.cto-toolbar__inside', '.cto-toolbar__open']) {
            const node = area?.querySelector(selector);

            if (node && window.getComputedStyle(node).display !== 'none') {
                return Math.max(0, node.getBoundingClientRect().bottom);
            }
        }

        return 0;
    }

    // Contao's toolbar loads after the view and can be closed: the view starts below it.
    function placeView() {
        view.style.top = toolbarHeight() + 'px';
        applyPanelWidth();
    }

    function watchToolbar() {
        const toolbarRoot = toolbarArea();

        new ResizeObserver(placeView).observe(document.documentElement);
        placeView();

        if (!toolbarRoot) {
            return;
        }

        // In the page the toolbar element itself is watched, not the whole document.
        const watched = toolbarRoot === document ? document.querySelector('body > .cto-toolbar') : toolbarRoot;

        new MutationObserver(placeView).observe(watched, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

        // On narrow screens Contao's toolbar takes several lines: it is closed once per
        // browser session when the editor starts; Contao's own button opens it again.
        if (narrow() && sessionStorage.getItem('nfe:toolbar-closed') !== '1') {
            const closeOnce = () => {
                const bar = toolbarRoot.querySelector('.cto-toolbar');
                const close = toolbarRoot.querySelector('.cto-toolbar__close');

                if (!bar || !close) {
                    return false;
                }

                if (bar.classList.contains('cto-toolbar--visible')) {
                    close.click();
                }

                sessionStorage.setItem('nfe:toolbar-closed', '1');

                return true;
            };

            if (!closeOnce()) {
                (toolbarRoot === document ? window : toolbarRoot).addEventListener('cto-toolbar-loaded', () => window.setTimeout(closeOnce, 0), { capture: true, once: true });
            }
        }
    }

    // ------------------------------------------------------------------ frame and tab at an element

    // Frame and tab of the selected element; their places follow the page (placeSelection).
    function showAt(id) {
        const data = id ? elementData(id) : null;

        if (!data || !boxOf(id)) {
            frame.hidden = label.hidden = insertAbove.hidden = insertBelow.hidden = true;

            return;
        }

        frame.hidden = label.hidden = false;

        // Plus: state of the translation; while a proposal is edited, where it comes from.
        const badge = state.editing?.id === id && state.editing.proposal
            ? { class: 'nfe-type__badge nfe-type__badge--note', text: t('proposal_note', { service: serviceName() }) }
            : languageBadge(data.translation);

        // While Contao's form of a new element or article is open, its own buttons apply: the tab only names the type.
        // While a text is edited the menu stays: an action ends the edit first (its changes wait for Save).
        fillTab(data.typeLabel, badge, state.form?.isNew ? null : () => elementItems(id, data, { where: 'tab' }), data.canMove && state.view !== 'form' ? id : null);

        // "Insert element" between the elements: below the element, and above it when it is the first of its article.
        const article = articleOf(id);
        const insertable = Boolean(article?.canCreate) && !state.drag && state.view !== 'form';

        fillInsert(insertBelow, insertable, () => createElement(article.id, id));
        fillInsert(insertAbove, insertable && article.elements[0]?.id === id, () => createElement(article.id, null));
        placeSelection();
    }

    function fillInsert(node, visible, action) {
        node.hidden = !visible;
        node.replaceChildren(visible ? button(t('insert_element'), { icon: 'new', className: 'nfe-insert__button', onclick: action }) : '');
    }

    // Where the selected part is now: its frame around it; type, buttons and "insert above" over its top edge,
    // or inside it when there is no room above (the top of the frame, or a fixed header over it);
    // "insert below" under it. Everything stays within the width and height of the page's window.
    function placeSelection() {
        const range = state.editing ? editingRange() : state.item ? state.items?.get(state.item) : state.ranges.get(state.selected);
        const box = boxOfRange(range);

        if (!box || frame.hidden) {
            return;
        }

        const { width, height } = pageView();
        const nodes = nodesOf(range);
        const clamp = (value, min, max) => Math.max(min, Math.min(value, max));
        // The tabs on the line of the frame (6 px above the element): the type, then the tools of the text and of the
        // image being edited, each right of the one before; a tab that does not fit in the window's width wraps.
        const tabs = [label, format, imageTools].filter((node) => !node.hidden);
        let next = clamp(box.x - 6, 0, width - (label.hidden ? 0 : label.offsetWidth));
        const lefts = tabs.map((node) => {
            const left = next;

            node.style.maxWidth = node === label ? '' : Math.max(120, width - left - 4) + 'px';
            next = Math.min(left + node.offsetWidth + 4, width - 124);

            return left;
        });
        const tabsTop = -6 - Math.max(0, ...tabs.map((node) => node.offsetHeight));
        // The places above the element, relative to its top edge: the tabs, "insert element" above them.
        const insertTop = tabsTop - 16;
        const headTop = insertAbove.hidden ? tabsTop : insertTop - 16;
        const cover = coverBottom(box, nodes, box.y + headTop);
        const room = box.y + headTop >= cover;
        // Not for an element under what covers it with less than 40 px showing below: its tabs stay above it.
        const underCover = box.y < cover && box.y + box.h < cover + 40;
        const reveal = state.reveal;

        state.reveal = false;

        // Without room, when the part was just selected or writing begins: the window scrolls by just enough for the
        // tabs above it, as long as what is written in (or the top of the part) stays in the window.
        if (!room && !underCover && reveal && pageWin) {
            const missing = Math.ceil(cover - (box.y + headTop));
            const field = state.editing?.focused?.node?.isConnected ? state.editing.focused.node.getBoundingClientRect() : null;
            const keep = field ? field.top : box.y;

            if (missing > 0 && pageWin.scrollY >= missing && keep + missing + 24 <= height) {
                pageWin.scrollBy({ top: -missing, left: 0, behavior: 'instant' });
                placeSelection();

                return;
            }
        }

        // Still without room: the tabs never lie over what is written in. While writing they hang from the bottom line
        // of the frame (where it is in the window; else they stay above it, out of view until it scrolls back); a part
        // that is only selected gets them inside its top edge, below what covers it (and inside the window).
        const writing = Boolean(state.editing);
        const beneath = !room && !underCover && writing && box.y + box.h + 6 + Math.max(0, ...tabs.map((node) => node.offsetHeight)) <= height;
        const shift = room || underCover || writing ? 0 : Math.max(cover, box.y - 6) + 4 - (box.y + headTop);

        // The frame's sides stay in the window; its top below what covers it, where the tabs moved inside.
        placeOutline(frame, box, shift > 0 ? cover + 2 : -Infinity);
        frame.classList.toggle('nfe-frame--tab-top', !label.hidden && shift <= 0 && !beneath);
        frame.classList.toggle('nfe-frame--tab-below', !label.hidden && beneath);

        // On the line of the frame the tabs stand on it; inside the part, or below it, they hang from the line.
        tabs.forEach((node, index) => {
            node.classList.toggle('nfe-tab--inside', shift > 0);
            node.classList.toggle('nfe-tab--below', beneath);

            const top = beneath ? box.y + box.h + 6 : shift > 0 ? box.y + tabsTop + shift : box.y - 6 - node.offsetHeight;

            Object.assign(node.style, { left: lefts[index] + 'px', top: top + 'px' });
        });

        placeFormatMenu();

        const lineLeft = Math.max(0, box.x - 6);
        const lineWidth = Math.min(width, box.x + box.w + 6) - lineLeft + 'px';
        const tabsHeight = Math.max(0, ...tabs.map((node) => node.offsetHeight));

        // Contao's TinyMCE: its bar above the tabs (or the frame), flush with the frame.
        placeTinyBar({
            left: lineLeft,
            width: parseFloat(lineWidth),
            top: tabs.length && !beneath && shift <= 0 ? Math.min(...tabs.map((node) => node.getBoundingClientRect().top)) : box.y - 6,
            bottom: box.y + box.h + 6 + (beneath ? tabsHeight : 0),
        });

        Object.assign(insertAbove.style, { left: lineLeft + 'px', width: lineWidth, top: box.y + insertTop + shift + 'px' });

        // Below the element; at the end of the page inside it, so the button stays in the window.
        const below = box.y + box.h + 18;

        Object.assign(insertBelow.style, { left: lineLeft + 'px', width: lineWidth, top: (box.y + box.h <= height ? Math.min(below, height - 18) : below) + 'px' });

        // While a text is edited, "Insert element" does not lie over it (where the element reaches beyond the window
        // the button sits inside it); the structure and the context menu offer it as well.
        for (const node of [insertAbove, insertBelow]) {
            const own = node.hidden ? null : node.firstElementChild?.getBoundingClientRect();
            const covers = Boolean(own && state.editing?.targets.some((target) => {
                const other = target.node?.isConnected ? target.node.getBoundingClientRect() : null;

                return other && other.left < own.right && other.right > own.left && other.top < own.bottom && other.bottom > own.top;
            }));

            // Tabs below the part take the place of "insert element" there.
            node.style.visibility = covers || (beneath && node === insertBelow) ? 'hidden' : '';
        }
    }

    // The lowest bottom edge of what is fixed or sticky on the page and lies over a part's top (a header over
    // the first section), looked up from the given height on, at three points across the part.
    function coverBottom(box, nodes, from) {
        const { width } = pageView();
        const xs = [box.x + 12, box.x + box.w / 2, box.x + box.w - 12].map((x) => Math.max(2, Math.min(width - 2, x)));
        let bottom = 0;

        for (let round = 0; round < 3; round += 1) {
            const y = Math.max(from, bottom, 0) + 1;
            let found = 0;

            for (const x of xs) {
                const hit = pageDoc.elementsFromPoint(x, y).find((node) => node !== pageHost);
                const own = !hit || nodes.some((node) => node === hit || node.contains(hit) || hit.contains(node));
                const fixed = own ? null : fixedAncestor(hit);

                if (fixed && !nodes.some((node) => fixed.contains(node))) {
                    found = Math.max(found, fixed.getBoundingClientRect().bottom);
                }
            }

            if (found <= bottom) {
                break;
            }

            bottom = found;
        }

        return bottom;
    }

    function fixedAncestor(node) {
        for (let current = node; current && current.nodeType === Node.ELEMENT_NODE; current = current.parentElement) {
            if (['fixed', 'sticky'].includes(styleOf(current).position)) {
                return current;
            }
        }

        return null;
    }

    // An outline 6 px around a part, its sides within the width of the page's window (an element over the full
    // width keeps visible sides), its top not above the given line.
    function placeOutline(node, box, minTop) {
        const { width } = pageView();
        const left = Math.max(2, box.x - 6);
        const top = Math.max(minTop, box.y - 6);

        Object.assign(node.style, { left: left + 'px', top: top + 'px', width: Math.min(width - 2, box.x + box.w + 6) - left + 'px', height: Math.max(0, box.y + box.h + 6 - top) + 'px' });
    }

    // The outline of the part under the pointer; the selection changes only with a click.
    function showHover() {
        const target = state.hover;
        const selected = target && (target.item ? target.item === state.item : !state.item && target.id === state.selected);
        const box = target && !selected ? (target.item ? boxOfRange(state.items?.get(target.item)) : boxOf(target.id)) : null;

        hoverFrame.hidden = !box;

        if (box) {
            placeOutline(hoverFrame, box, 2);
        }
    }

    // After scrolling, resizing or a change of the page: the overlays follow, once per frame of the screen.
    let placing = 0;

    function schedulePlace() {
        if (placing || !pageWin) {
            return;
        }

        placing = pageWin.requestAnimationFrame(() => {
            placing = 0;
            placeSelection();
            showHover();
            placeInfo();

            if (state.view === 'check' && state.check?.tab === 'a11y') {
                showMarkers(state.check.a11y?.findings || []);
            }
        });
    }

    // The tab of the selected part on the line of its frame: its type and, where it has a menu, the button of that menu
    // (WAI-ARIA menu button: Enter, Space or an arrow key opens it) with the grip that drags an element. Drawn again
    // only when something in it changes, so a grip that is being pressed and the focus stay. $items gives the entries
    // when the menu opens (the same as the part's part of the context menu); null: no menu.
    let tabItems = null;
    let tabDragId = null;
    let draggedAt = 0;

    // The language versions of an element at its tab (Plus, pages with language versions): a symbol where every
    // version is there and current, a word where something is missing or out of date.
    function languageBadge(translation) {
        const status = translation?.status;

        if (status === 'outdated') {
            return { class: 'nfe-type__badge', text: t('status_outdated') };
        }

        if (status === 'unlinked' || status === 'missing') {
            return { class: 'nfe-type__badge', text: t(status === 'missing' ? 'badge_missing' : 'status_unlinked'), title: t(status === 'missing' ? 'badge_missing_title' : 'status_unlinked') };
        }

        return status === 'current' || status === 'all'
            ? { class: 'nfe-type__badge nfe-type__badge--lang', title: t(status === 'all' ? 'badge_all' : 'status_current'), 'aria-label': t(status === 'all' ? 'badge_all' : 'status_current'), icon: 'translate' }
            : null;
    }

    function fillTab(text, badge, items, dragId) {
        const key = JSON.stringify([text, badge, Boolean(items), dragId]);

        tabItems = items;
        tabDragId = dragId;

        if (label.dataset.key !== key) {
            label.dataset.key = key;
            label.replaceChildren(...[
                dragId ? h('span', { class: 'nfe-type__grip', title: t('move'), 'aria-hidden': 'true' }, [maskIcon('drag')]) : null,
                h('span', { class: 'nfe-type__name', text }),
                badge ? h('span', { ...badge, icon: null }, badge.icon ? [maskIcon(badge.icon)] : []) : null,
                items ? h('span', { class: 'nfe-type__chevron', 'aria-hidden': 'true' }) : null,
            ].filter(Boolean));
        }

        if (items) {
            label.setAttribute('role', 'button');
            label.setAttribute('tabindex', '0');
            label.setAttribute('aria-haspopup', 'menu');
            label.setAttribute('aria-expanded', state.menu?.anchor === label ? 'true' : 'false');
        } else {
            for (const name of ['role', 'tabindex', 'aria-haspopup', 'aria-expanded']) {
                label.removeAttribute(name);
            }
        }
    }

    function openTabMenu(last = false) {
        if (tabItems) {
            openMenu(label, tabItems(), label.querySelector('.nfe-type__name')?.textContent || '', { last });
        }
    }

    // The tab's events, on the closed area of each page: the nodes of a page that is left lose their listeners, and the
    // tab moves on to the next page. The grip drags the element; the click that ends dragging does not open the menu.
    function listenToTab(area) {
        area.addEventListener('pointerdown', (event) => {
            const grip = event.composedPath().find((node) => node.classList?.contains('nfe-type__grip'));

            if (grip && tabDragId !== null) {
                startDrag(event, tabDragId, 'page', grip);
            }
        });
        area.addEventListener('click', (event) => {
            if (event.composedPath().includes(label) && performance.now() - draggedAt > 400) {
                openTabMenu();
            }
        });
        area.addEventListener('keydown', (event) => {
            if (event.composedPath().includes(label)) {
                tabKey(event);
            }
        });
    }

    function tabKey(event) {
        if (!tabItems || event.ctrlKey || event.metaKey) {
            return;
        }

        if (!event.altKey && ['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            openTabMenu(event.key === 'ArrowUp');
        } else if (event.key === 'Tab') {
            // Back to the part on the page: Shift+Tab stays there, Tab goes on from there to what follows it.
            const node = focusNodeOf(state.item ?? state.selected);

            if (node) {
                node.focus();

                if (event.shiftKey) {
                    event.preventDefault();
                }
            }
        } else {
            elementKey(event, true);
        }
    }

    // The place on the page that has the focus for a part (Tab selects it): its first node.
    function focusNodeOf(part) {
        const node = part === null || part === undefined ? null : nodesOf(partRange(part)).find((child) => child.nodeType === Node.ELEMENT_NODE);

        return node && (node.tabIndex >= 0) ? node : null;
    }

    // The keys the menu of an element names, on the element (its place on the page with the focus) and on its tab:
    // Enter edits it, or opens all fields when it has no text on the page (on the tab Enter opens the menu), Alt+arrow
    // up or down moves it, Delete deletes it after a question. Tab goes from the element to its tab. True: handled.
    function elementKey(event, onTab = false) {
        const id = state.selected;
        const data = id !== null && !state.item && !state.editing ? elementData(id) : null;

        if (!data || state.menu || event.ctrlKey || event.metaKey || (!onTab && (!pageDoc || event.target !== focusNodeOf(id)))) {
            return false;
        }

        const structure = state.view !== 'form' && !state.form?.isNew;
        const back = onTab ? { where: 'tab' } : { where: 'part' };

        if (!onTab && event.key === 'Tab' && !event.shiftKey && !event.altKey && tabItems && !label.hidden) {
            event.preventDefault();
            label.focus();
        } else if (!onTab && event.key === 'Enter' && !event.altKey && !event.shiftKey && data.canUpdate) {
            event.preventDefault();

            if (inlineTargets(id, data).length) {
                startEditing(id);
            } else {
                openForm(id);
            }
        } else if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown') && structure && data.canMove) {
            event.preventDefault();
            moveStep(id, event.key === 'ArrowUp' ? -1 : 1, back);
        } else if (event.key === 'Delete' && !event.altKey && !event.shiftKey && structure && data.canDelete) {
            event.preventDefault();
            remove(id);
        } else {
            return false;
        }

        return true;
    }

    // Entries of the menu of a row in the structure.
    function moreItems(id, data) {
        const items = [];
        const article = articleOf(id);
        const index = article ? article.elements.findIndex((element) => element.id === id) : -1;
        const back = { where: 'tree' };

        if (data.canUpdate) {
            if (inlineTargets(id, data).length) {
                items.push({ label: t('edit'), icon: 'edit', action: () => startEditing(id) });
            }

            items.push({ label: t('all_fields'), icon: 'fields', action: () => openForm(id) });
        }

        if (data.canMove) {
            items.push(
                { label: t('move_up'), glyph: '↑', disabled: index < 1, action: () => moveStep(id, -1, back) },
                { label: t('move_down'), glyph: '↓', disabled: index === article.elements.length - 1, action: () => moveStep(id, 1, back) },
            );
        }

        items.push(...translationItems(id, data));

        if (data.canCopy) {
            items.push({ label: t('duplicate'), icon: 'copy', action: () => duplicate(id, back) });
        }

        if (data.canToggle) {
            items.push({ label: t('hide'), icon: 'hide', checked: data.invisible, action: () => toggleHidden(id, back) });
        }

        if (data.canDelete) {
            items.push({ label: t('delete'), icon: 'delete', action: () => remove(id) });
        }

        if (article?.canCreate) {
            items.push({ label: t('insert_element'), icon: 'new', action: () => createElement(article.id, id) });
        }

        return items;
    }

    function select(id, scroll = false) {
        // Another part: the text being edited is left, its changes wait for Save.
        if (state.editing && state.editing.id !== id) {
            parkEditing();
        }

        // A part that is newly selected on the page gets room for its tab above it (placeSelection); from the
        // structure it is scrolled into the middle of the window anyway.
        state.reveal = !scroll && state.selected !== id;
        state.selected = id;
        state.item = null;
        showAt(id);
        showHover();
        renderStructure();

        if (scroll && id) {
            const node = nodesOf(state.ranges.get(id))[0];

            node?.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
    }

    // The innermost of the content elements and the other marked parts at a node of the page.
    function partAt(target) {
        const id = elementAt(target);
        const item = itemAt(target);
        const box = id ? boxOf(id) : null;

        // A post fills the module element of a reader as large as it: the post is what is edited there.
        const area = box ? box.w * box.h : Infinity;

        if (item && (!id || item.area < area || (item.key.startsWith('lp:') && item.area <= area))) {
            return { item: item.key };
        }

        return id ? { id } : null;
    }

    // Selects the part at a node (click, keyboard focus); true when the selection changed.
    function pointAt(target) {
        const part = partAt(target);

        if (part?.item && state.item !== part.item) {
            selectItem(part.item);

            return true;
        }

        if (part?.id && (part.id !== state.selected || state.item)) {
            state.item = null;
            select(part.id);

            return true;
        }

        return false;
    }

    // A press of a finger that opens the context menu after a while (listenToPage), and when a key opened it.
    const longPress = { timer: 0, x: 0, y: 0, opened: 0 };
    let keyMenuAt = 0;
    // A second right click on the text being written: the browser's menu with its spelling suggestions.
    let spellingAt = 0;

    // What a first click on a part does not trigger (it selects the part); the next click does.
    const INTERACTIVE = 'a[href], area[href], button, summary, label, input, select, textarea, [role="button"], [role="link"], [onclick]';
    let lastPointer = 0;

    // The editor's listeners in the page of the frame (attachPage); they end with the page.
    function listenToPage(doc, win) {
        doc.addEventListener('pointerdown', (event) => {
            lastPointer = Date.now();
            hideNote();

            // A second right click while the menu of the text being written is open: the browser's menu follows.
            if (event.button === 2 && state.menu?.spelling) {
                spellingAt = Date.now();
            }

            closeOnOutside(event);
            closeOnPageClick(event);
        }, true);

        // A tap focuses only when the finger is lifted.
        doc.addEventListener('pointerup', () => {
            lastPointer = Date.now();
        }, true);

        // The pointer only outlines; on the editor's own buttons nothing changes.
        doc.addEventListener('mouseover', (event) => {
            if (state.drag || event.composedPath().includes(pageHost) || inTinyMce(event)) {
                return;
            }

            state.hover = partAt(event.target);
            showHover();
        });

        doc.addEventListener('mouseout', (event) => {
            if (!event.relatedTarget) {
                state.hover = null;
                showHover();
            }
        });

        doc.addEventListener('click', (event) => {
            if (state.drag || event.button !== 0 || event.composedPath().includes(pageHost) || inTinyMce(event)) {
                return;
            }

            // The click that ends a long press does not select or edit.
            if (Date.now() - longPress.opened < 1000) {
                event.preventDefault();
                event.stopPropagation();

                return;
            }

            hideNote();

            // Ctrl or ⌘ + click: a link of the page leads there (in the editor), a button or field does what it does.
            // A plain click never leaves the page: it selects or edits (see the quick guide).
            if ((event.ctrlKey || event.metaKey) && event.target.closest?.(INTERACTIVE)) {
                const link = event.target.closest('a[href], area[href]');

                if (link) {
                    event.preventDefault();
                    event.stopPropagation();
                    openLink(link);
                }

                return;
            }

            // While texts are edited: a link in an edited text does not lead away; another text of the part says why it is
            // not edited; a click anywhere else leaves the text (its changes wait for Save) and selects what was clicked.
            if (state.editing) {
                if (state.editing.targets.some((target) => target.node && inTarget(target, event.target))) {
                    if (event.target.closest?.('a[href]')) {
                        event.preventDefault();
                    }

                    return;
                }

                if (editingRange() && nodesOf(editingRange()).some((node) => node === event.target || node.contains(event.target))) {
                    explain(event, state.editing.id ?? state.editing.item);

                    return;
                }

                parkEditing();
            }

            if (pointAt(event.target)) {
                if (event.target.closest?.(INTERACTIVE)) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                return;
            }

            // A further click on a text of the selected part edits it, or says why it cannot be edited there. Links and
            // buttons of the page do not lead away: their text is edited like any other (Ctrl/⌘ + click follows them).
            const control = event.target.closest?.(INTERACTIVE);

            if (control) {
                event.preventDefault();
            }

            // The innermost part at the text (a module inside the selected element of type "module" is its own part).
            const node = textNodeAt(event);
            const part = node ? partOfNode(node) : null;
            const target = node && part !== null ? targetAtNode(part, node) : null;

            if (target?.source.editable && inlineTargetsOf(part, partData(part)).some((entry) => entry.key === target.key)) {
                event.preventDefault();
                startEditing(part, target);
            } else if (control?.matches('a[href], area[href]')) {
                showNote(t('follow_hint', { key: modifierKey() }), event);
            } else if (node) {
                explain(event, part);
            }
        }, true);

        // Right click (with or without Shift): the editor's menu for what is under the pointer; on the editor's own tabs
        // the menu of the part. The browser's own menu opens only for its spelling suggestions: with a second right
        // click on the text being written while the editor's menu (with that hint at its top) is open. Where the
        // editor has no menu for the place (a text of the template), a note says why it is not edited.
        doc.addEventListener('contextmenu', (event) => {
            if (inTinyMce(event)) {
                return;
            }

            const written = Boolean(state.editing?.targets.some((target) => target.node && inTarget(target, event.target)));

            if (written && Date.now() - spellingAt < 1000) {
                spellingAt = 0;

                return;
            }

            event.preventDefault();

            // The long press or the key that opened the menu already; nothing while dragging.
            if (state.drag || Date.now() - longPress.opened < 1000 || Date.now() - keyMenuAt < 500) {
                return;
            }

            hideNote();

            if (event.composedPath().includes(pageHost)) {
                closeMenu(false);
                openTabMenu();

                return;
            }

            if (!openContextMenu(textNodeAt(event) || event.target, event.clientX, event.clientY, null, written)) {
                explain(event, null);
            }
        });

        // A long press (touch): the same menu, where the finger is.
        doc.addEventListener('pointerdown', (event) => {
            window.clearTimeout(longPress.timer);

            if (event.pointerType !== 'touch' || state.drag || event.composedPath().includes(pageHost) || inTinyMce(event)) {
                return;
            }

            longPress.x = event.clientX;
            longPress.y = event.clientY;
            longPress.timer = window.setTimeout(() => {
                const fake = { clientX: longPress.x, clientY: longPress.y };

                if (openContextMenu(textNodeAt(fake) || doc.elementFromPoint(longPress.x, longPress.y), longPress.x, longPress.y)) {
                    longPress.opened = Date.now();
                }
            }, 550);
        }, { passive: true });

        for (const type of ['pointerup', 'pointercancel']) {
            doc.addEventListener(type, () => window.clearTimeout(longPress.timer), { passive: true });
        }

        doc.addEventListener('pointermove', (event) => {
            if (Math.hypot(event.clientX - longPress.x, event.clientY - longPress.y) > 8) {
                window.clearTimeout(longPress.timer);
            }
        }, { passive: true });

        // A link to another website opens in place of the view, not in the frame (after the page's own handlers).
        win.addEventListener('click', (event) => {
            const link = event.defaultPrevented || event.button !== 0 ? null : event.target.closest?.('a[href]');
            const url = link && (!link.target || link.target === '_self') ? new URL(link.href, win.location.href) : null;

            if (url && /^https?:$/.test(url.protocol) && url.origin !== win.location.origin) {
                event.preventDefault();
                window.location.href = url.href;
            }
        });

        // The keyboard selects with the focus (Tab); the focus a click gives is left to the click.
        doc.addEventListener('focusin', (event) => {
            if (state.drag || event.target === pageHost || Date.now() - lastPointer < 500 || inTinyMce(event)) {
                return;
            }

            // The keyboard leaves an edited text (Tab): its changes wait, the part with the focus is selected.
            if (state.editing) {
                const range = editingRange();

                if (state.editing.targets.some((target) => target.node && inTarget(target, event.target)) || (range && nodesOf(range).some((node) => node === event.target || node.contains(event.target)))) {
                    return;
                }

                parkEditing();
            }

            pointAt(event.target);
        });

        doc.addEventListener('keydown', onKeydown, true);
        doc.addEventListener('selectionchange', onSelectionChange);
        doc.addEventListener('scroll', schedulePlace, { capture: true, passive: true });
        win.addEventListener('resize', schedulePlace);
        win.addEventListener('beforeunload', warnUnsaved);
        win.addEventListener('pagehide', () => detachPage(doc));
        new win.ResizeObserver(schedulePlace).observe(doc.documentElement);
    }

    // ------------------------------------------------------------------ editing in the text

    // Where the texts of a part are on the page and which field each comes from. First the source map
    // (SourceMapRenderer): the comments <!--nfe:f:KEY--> … <!--/nfe:f:KEY--> around a value, also when the
    // template changes it (escaping, upper case, line breaks, insert tags). For values without them, their
    // text: the headline with the stored text, the rich text area of Contao's templates (.rte), and the other
    // sources where exactly as many nodes show their text as there are sources with it (in their order).
    // Sources the user may not change are found as well (locked), so a click on them can say why.
    // Kept per part until the page changes.
    const targetCache = new Map();

    function inlineTargets(id, data) {
        return partTargets(id, data).targets;
    }

    function partTargets(id, data, range = state.ranges.get(id)) {
        const cacheKey = typeof id === 'string' ? id : 'ce:' + id;

        if (!targetCache.has(cacheKey)) {
            targetCache.set(cacheKey, findTargets(range, data));
        }

        return targetCache.get(cacheKey);
    }

    function findTargets(range, data) {
        // Parts inside this one (elements in an element, a module in an element) have texts of their own.
        const inside = (node) => Boolean(range?.start.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING) && Boolean(range.end.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING);
        const nested = [...state.ranges.values(), ...(state.items?.values() || [])].filter((other) => other !== range && other?.start && inside(other.start)).flatMap(nodesOf);
        const nodes = nodesOf(range);
        const sources = data?.sources || [];
        const pairs = sourcePairs(range);
        const found = [];
        const taken = [...nested];
        const missing = [];

        for (const source of sources) {
            const places = (pairs.repeats || []).filter((entry) => entry.key === source.key).map((entry) => entry.pair);
            let mapped = false;

            for (const pair of places) {
                const holder = soleHolder(pair);

                if (!holder || !holder.closest('button, select, option, textarea, svg')) {
                    found.push(targetOf(source, holder, pair));
                    taken.push(...(holder ? [holder] : pairNodes(pair).filter((node) => node.nodeType === Node.ELEMENT_NODE)));
                    mapped = true;
                }
            }

            if (!mapped) {
                missing.push(source);
            }
        }

        // A value inside the place of another one, in an element of its own (the accent word of a headline in its
        // span): part of that place, edited there with it and saved into its own field.
        for (const parent of [...found]) {
            const scope = parent.node ? [parent.node] : pairNodes(parent.pair).filter((node) => node.nodeType === Node.ELEMENT_NODE);

            if (!['headline', 'line', 'unit'].includes(parent.source.kind) || !scope.length) {
                continue;
            }

            for (const source of [...missing]) {
                const wanted = source.kind === 'rich' ? '' : sameText(shownText(fragment(source.shown)));
                const inner = wanted ? scope.flatMap((node) => [...node.querySelectorAll('*')]).find((node) => sameText(shownText(node)) === wanted) : null;

                if (inner) {
                    const target = targetOf(source, inner, null);

                    target.within = parent;
                    found.push(target);
                    missing.splice(missing.indexOf(source), 1);
                }
            }
        }

        const headline = missing.find((source) => source.kind === 'headline');

        if (headline && data.fields?.includes('headline')) {
            const value = shownText(fragment(headline.shown));
            const heading = nodes.flatMap((node) => [node, ...node.querySelectorAll('h1,h2,h3,h4,h5,h6')])
                .find((node) => /^H[1-6]$/.test(node.tagName) && shownText(node) === value && !taken.some((other) => other.contains(node) || node.contains(other)));

            if (heading) {
                found.push(targetOf(headline, heading, null));
                taken.push(heading);
                missing.splice(missing.indexOf(headline), 1);
            }
        }

        const text = missing.find((source) => source.field === 'text' && source.scope === 'own' && source.kind === 'rich');

        if (text && data.fields?.includes('text')) {
            const node = nodes.flatMap((child) => [child, ...child.querySelectorAll('.rte')]).find((child) => child.classList?.contains('rte') && !taken.some((other) => other.contains(child) || child.contains(other)));

            if (node) {
                found.push(targetOf(text, node, null));
                taken.push(node);
                missing.splice(missing.indexOf(text), 1);
            }
        }

        found.push(...textMatches(nodes, missing, taken, pairs));

        // A content element may show values of other records: of a module (the same opening hours as the footer)
        // or of other elements of the page (a slider of the references that follow it). They are those records'
        // texts, found by their text (not short ones, which a template may show as well).
        if (!data?.module) {
            const own = new Set(sources);
            const long = (source) => sameText(shownText(fragment(source.shown))).length >= 6;
            const modules = Object.values(state.moduleTexts || {}).flatMap((texts) => texts.sources);
            const elements = allElements().filter((element) => element.id !== data?.id).flatMap((element) => (element.sources || [])
                .map((source) => ({ ...source, scope: source.scope === 'own' ? 'element' : source.scope, owner: element.id })));

            found.push(...textMatches(nodes, [...modules, ...elements].filter((source) => !own.has(source) && long(source)), taken, pairs));
        }

        const targets = found.filter((target) => target.source.editable);

        if (data?.fields?.includes('singleSRC')) {
            const figure = nodes.flatMap((node) => [node, ...node.querySelectorAll('img')]).find((node) => node.tagName === 'IMG');

            if (figure) {
                targets.push({ field: 'image', kind: 'image', node: figure, source: { editable: true, scope: 'own' } });
            }
        }

        return { targets, locked: found.filter((target) => !target.source.editable), unmapped: missing.filter((source) => !found.some((target) => target.source === source)) };
    }

    function targetOf(source, node, pair, truncated = false) {
        const kind = source.kind === 'headline' && !(node && /^H[1-6]$/.test(node.tagName)) ? 'line' : ({ unit: 'line' }[source.kind] || source.kind);

        return { field: source.field, key: source.key, kind, node, pair, source, value: source.value, label: source.label, truncated };
    }

    // Comments of the source map in a part: the first complete pair of each key, both comments in the same parent
    // (pairs.repeats: every complete pair, also further places of the same value).
    function sourcePairs(range) {
        const pairs = new Map();
        const repeats = [];

        pairs.repeats = repeats;

        if (!range?.start) {
            return pairs;
        }

        const open = new Map();
        const visit = (comment) => {
            const match = /^(\/?)nfe:f:([a-z0-9_]+\.\d+\.[A-Za-z0-9_]+(?:\.\d+){0,2})$/.exec(comment.data);

            if (!match) {
                return;
            }

            if (!match[1]) {
                if (!open.has(match[2])) {
                    open.set(match[2], comment);
                }
            } else if (open.has(match[2])) {
                const start = open.get(match[2]);

                // Every place of a value the template shows several times; the first is the value's place.
                if (start.parentNode === comment.parentNode) {
                    if (!pairs.has(match[2])) {
                        pairs.set(match[2], { start, end: comment });
                    }

                    repeats.push({ key: match[2], pair: { start, end: comment } });
                }

                open.delete(match[2]);
            }
        };

        for (let node = range.start.nextSibling; node && node !== range.end; node = node.nextSibling) {
            if (node.nodeType === Node.COMMENT_NODE) {
                visit(node);
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                const walker = node.ownerDocument.createTreeWalker(node, NodeFilter.SHOW_COMMENT);
                let comment;

                while ((comment = walker.nextNode())) {
                    visit(comment);
                }
            }
        }

        return pairs;
    }

    const pairNodes = (pair) => {
        const list = [];

        for (let node = pair.start.nextSibling; node && node !== pair.end; node = node.nextSibling) {
            list.push(node);
        }

        return list;
    };

    // The element that holds nothing but the value (besides spaces and comments), or null.
    function soleHolder(pair) {
        const parent = pair.start.parentNode;
        let inside = false;

        if (!parent || parent.nodeType !== Node.ELEMENT_NODE || /^(BODY|HTML|HEAD)$/.test(parent.tagName)) {
            return null;
        }

        for (const child of parent.childNodes) {
            if (child === pair.start || child === pair.end) {
                inside = child === pair.start;
            } else if (!inside && !(child.nodeType === Node.COMMENT_NODE || (child.nodeType === Node.TEXT_NODE && !child.data.trim()))) {
                return null;
            }
        }

        return parent;
    }

    // Whether a node of the page lies in a target: in its node, or between the comments of its pair.
    function inTarget(target, node) {
        if (target.node && (target.node === node || target.node.contains(node))) {
            return true;
        }

        if (target.textNode) {
            return target.textNode === node;
        }

        return Boolean(target.pair)
            && Boolean(target.pair.start.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING)
            && Boolean(target.pair.end.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING);
    }

    // The node to edit: the holder of the value, or a wrapper around the nodes between the comments while editing.
    function holderOf(target) {
        if (target.node) {
            return target.node;
        }

        // A text next to an icon: a wrapper around the text only.
        if (target.textNode) {
            const wrapper = target.textNode.ownerDocument.createElement('span');

            wrapper.dataset.nfeWrap = '';
            target.textNode.replaceWith(wrapper);
            wrapper.append(target.textNode);
            target.node = wrapper;
            target.wrapped = true;

            return wrapper;
        }

        const inner = pairNodes(target.pair);
        const block = inner.some((node) => node.nodeType === Node.ELEMENT_NODE && /^(P|DIV|UL|OL|LI|H[1-6]|TABLE|BLOCKQUOTE|FIGURE|SECTION|ARTICLE|PRE|DL|HR)$/.test(node.tagName));
        const wrapper = target.pair.start.ownerDocument.createElement(block ? 'div' : 'span');

        wrapper.dataset.nfeWrap = '';
        target.pair.start.after(wrapper);
        wrapper.append(...inner);
        target.node = wrapper;
        target.wrapped = true;

        return wrapper;
    }

    function unwrap(target) {
        if (target.wrapped && target.node?.parentNode) {
            const children = [...target.node.childNodes];

            target.node.replaceWith(...children);

            if (target.textNode && !children.includes(target.textNode)) {
                target.textNode = children.find((child) => child.nodeType === Node.TEXT_NODE) || null;
            }
        }

        if (target.wrapped) {
            target.node = null;
            target.wrapped = false;
        }
    }

    // The text a reader sees in a node, to find a field's value on the page: without spaces, line breaks,
    // soft hyphens and invisible characters, so the markup and line breaks of a template do not count, and
    // without what is hidden from readers (the names of icons in an icon font, aria-hidden).
    function shownText(node) {
        let text = '';
        const walk = (current) => {
            for (const child of current.childNodes) {
                if (child.nodeType === Node.TEXT_NODE) {
                    text += child.data;
                } else if (child.nodeType === Node.ELEMENT_NODE && !['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT'].includes(child.tagName) && child.getAttribute('aria-hidden') !== 'true') {
                    walk(child);
                }
            }
        };

        walk(node);

        return text.replace(/[\s­​-‍⁠﻿]+/g, '');
    }

    // The same text, whatever case, quotation marks or dashes the template uses.
    const sameText = (text) => text.toLocaleLowerCase().replace(/[“”„‟″«»]/g, '"').replace(/[‘’‚‛′‹›]/g, "'").replace(/[‐-―−]/g, '-');

    const fragment = (html) => {
        const template = document.createElement('template');

        template.innerHTML = html;

        return template.content;
    };

    // The tags of a node's own elements: rich text is edited in the node that holds its paragraphs, not in one of them.
    const childTags = (node) => [...node.children].map((child) => child.tagName).join(',');

    // Sources without comments, found by their text: the innermost nodes that show the value (insert tags
    // replaced, as the page shows it) and nothing else, outside the other targets. Sources with the same text
    // get the nodes with it in the order of the page, when there are as many of both. A shortened teaser
    // (the start of the value and an ellipsis) counts as the value; it is edited whole. Form controls and
    // graphics are left out.
    function textMatches(nodes, sources, taken, pairs) {
        const targets = [];
        const candidates = nodes.flatMap((node) => [node, ...node.querySelectorAll('*')])
            .filter((node) => !node.closest('script, style, template, noscript, svg, button, select, option, textarea'));
        const texts = new Map(candidates.map((node) => [node, sameText(shownText(node))]));
        const textNodes = candidates.flatMap((node) => [...node.childNodes].filter((child) => child.nodeType === Node.TEXT_NODE && child.data.trim()));
        const free = (node) => !taken.some((other) => other === node || other.contains(node) || node.contains(other))
            && ![...pairs.values()].some((pair) => inTarget({ pair }, node));
        const groups = new Map();

        for (const source of sources) {
            const shown = fragment(source.shown);
            const wanted = sameText(shownText(shown));

            if (wanted) {
                groups.set(wanted, [...(groups.get(wanted) || []), { source, shown }]);
            }
        }

        // Rich text of a single paragraph that the template shows without a wrapper of its own: the paragraph itself.
        const single = (shown) => (shown.childElementCount === 1 && ![...shown.childNodes].some((child) => child.nodeType === Node.TEXT_NODE && child.data.trim()) ? shown.firstElementChild.tagName : null);

        for (const [wanted, group] of groups) {
            const fits = (node) => texts.get(node) === wanted && group.every(({ source, shown }) => source.kind !== 'rich' || childTags(node) === childTags(shown) || node.tagName === single(shown)) && free(node);
            let found = candidates.filter(fits);
            let truncated = false;

            if (!found.length && wanted.length >= 24) {
                found = candidates.filter((node) => {
                    const text = texts.get(node);
                    const start = text.replace(/(…|\.\.\.)$/, '');

                    return start !== text && start.length >= 12 && wanted.startsWith(start) && free(node);
                });
                truncated = true;
            }

            let innermost = found.filter((node) => !found.some((other) => other !== node && node.contains(other)));

            // A plain value as one text among others in its element (a number before it in its own tag): the text alone.
            if (!innermost.length && group.every(({ source }) => source.kind !== 'rich')) {
                innermost = textNodes.filter((text) => sameText(text.data.replace(/[\s­​-‍⁠﻿]+/g, '')) === wanted && free(text.parentElement) && !taken.includes(text));
                truncated = false;

                if (innermost.length === group.length || (group.length === 1 && innermost.length)) {
                    const nodes = innermost;

                    (group.length === 1 ? nodes : nodes.slice(0, group.length)).forEach((text, index) => {
                        const target = targetOf((group[index] || group[0]).source, null, null);

                        target.textNode = text;
                        targets.push(target);
                        taken.push(text);
                    });
                }

                continue;
            }

            // One value shown several times (the same label in each tab): every place is that value.
            if (group.length === 1 && innermost.length > 1 && !truncated) {
                group.push(...innermost.slice(1).map(() => group[0]));
            }

            if (innermost.length === group.length) {
                group.forEach(({ source }, index) => {
                    const node = innermost[index];
                    const text = source.kind === 'rich' ? null : soleText(node);
                    const target = targetOf(source, text ? null : node, null, truncated);

                    // The node is the value's only element: its content is edited, the element is kept around it.
                    if (source.kind === 'rich' && childTags(node) !== childTags(group[index].shown) && node.tagName === single(group[index].shown)) {
                        target.outer = true;
                    }

                    // The value is one text next to other things (an icon): the text alone is edited.
                    if (text) {
                        target.textNode = text;
                    }

                    targets.push(target);
                    taken.push(node);
                });
            }
        }

        return targets;
    }

    // The only text of a node that also holds other things (an icon, hidden from readers), or null.
    function soleText(node) {
        const texts = [];
        let other = false;

        for (const child of node.childNodes) {
            if (child.nodeType === Node.TEXT_NODE) {
                if (child.data.trim()) {
                    texts.push(child);
                }
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                other = true;

                if (child.getAttribute('aria-hidden') !== 'true') {
                    return null;
                }
            }
        }

        return other && texts.length === 1 ? texts[0] : null;
    }

    // ------------------------------------------------------------------ context menu of the page

    // A right click (the context-menu key, Shift+F10, a long press) anywhere on the page: what applies to what is
    // there, nearest first: its text, its image (also one the template shows as a background), its link, then the
    // element (or module) that contains it, as the lower part with its type as heading; outside the elements the
    // article. Entries the rights or the edition do not allow are shown disabled with the reason; entries that do
    // not apply are left out. Shift with the right click, or a second right click, gives the browser's own menu.
    function openContextMenu(node, x, y, returnTo = null, spelling = false) {
        const textNode = node?.nodeType === Node.TEXT_NODE ? node : null;
        const element = textNode ? textNode.parentElement : node;
        const part = element ? partOfNode(textNode || element) : null;
        const items = [];
        let heading = '';

        if (part !== null && part !== undefined) {
            if (typeof part === 'number') {
                if (state.selected !== part || state.item) {
                    select(part);
                }
            } else if (state.item !== part && state.itemData?.[part]) {
                selectItem(part);
            }

            items.push(...textItems(part, textNode), ...imageItems(part, element), ...linkItems(part, element));

            if (typeof part === 'number' && elementData(part)) {
                heading = elementData(part).typeLabel;
                items.push({ group: heading }, ...elementItems(part, elementData(part)));
            } else if (typeof part === 'string' && state.itemData?.[part]) {
                heading = itemLabel(state.itemData[part]);
                items.push({ group: heading }, ...itemMenuItems(part, state.itemData[part]));
            }
        } else if (element) {
            const article = articleAtNode(element);

            if (article) {
                heading = t('article_named', { title: article.title });
                items.push({ group: heading }, ...articleItems(article));
            }
        }

        // An entry the nearer part of the menu has already (the image under the pointer) is not repeated below.
        const seen = new Set();
        const entries = items.filter((item) => {
            const key = item.group === undefined ? item.label + '|' + (item.note || '') : null;

            if (key && seen.has(key)) {
                return false;
            }

            seen.add(key);

            return true;
        });

        if (!entries.some((item) => item.group === undefined)) {
            return false;
        }

        for (const old of layer.querySelectorAll('.nfe-menu-anchor')) {
            old.remove();
        }

        const anchor = h('div', { class: 'nfe-menu-anchor', tabindex: '-1' });

        // A point only: a further right click at the same place reaches the page under it.
        Object.assign(anchor.style, { position: 'fixed', left: x + 'px', top: y + 'px', width: '1px', height: '1px', pointerEvents: 'none' });
        layer.append(anchor);
        closeMenu(false);
        // In the text being written: at the top, how to reach the browser's spelling suggestions.
        openMenu(anchor, spelling ? [{ hint: t('spelling_hint') }, ...entries] : entries, heading || t('more'));

        if (state.menu) {
            state.menu.returnTo = returnTo;
            state.menu.spelling = spelling;
        }

        return true;
    }

    // The text at the click: edit it (the field it is saved into as note), the writing aid (Pro); or why not.
    function textItems(part, textNode) {
        if (!textNode) {
            return [];
        }

        const target = targetAtNode(part, textNode);
        const items = [{ group: t('menu_text') }];

        if (target?.source.editable && inlineTargetsOf(part, partData(part)).some((entry) => entry.key === target.key)) {
            items.push({ label: t('edit_text'), icon: 'edit', note: t('saves_into', { name: target.label || target.field }), action: () => startEditing(part, target) });

            if (typeof part === 'number' && target.source.scope === 'own' && (target.field === 'headline' || target.field === 'text')) {
                const ed = editions();

                items.push(ed.writingAid
                    ? { label: t('writing_aid'), glyph: '✦', action: () => { startEditing(part, target); openWritingAid(); } }
                    : { label: t('writing_aid'), glyph: '✦', disabled: true, reason: ed.pro ? t('no_permission') : t('needs_pro') });
            }
        } else {
            const reason = reasonAt(part, textNode);

            items.push({ label: t('edit_text'), icon: 'edit', disabled: true, reason: reason ? reasonText(reason, part) : t('reason_template') });
        }

        return items;
    }

    // The image at a node: an <img> (or its <picture>), or the nearest background (or mask) image of the node or one
    // of its parents inside the part; matched to an image field of the element by its file name (Contao names the
    // resized copies after the original).
    function imageAtNode(part, node) {
        const range = partRange(part);
        const inside = (current) => nodesOf(range).some((child) => child === current || child.contains(current));

        for (let current = node; current && current.nodeType === Node.ELEMENT_NODE && inside(current); current = current.parentElement) {
            if (current.tagName === 'IMG' || current.tagName === 'PICTURE') {
                const img = current.tagName === 'IMG' ? current : current.querySelector('img');

                return img ? { node: img, url: img.currentSrc || img.src } : null;
            }

            // A background image, or an image drawn as a mask (a logo in the colour of the text).
            for (const pseudo of [null, '::before', '::after']) {
                const style = current.ownerDocument.defaultView.getComputedStyle(current, pseudo);
                const match = /url\(\s*["']?([^"')]+)["']?\s*\)/.exec([style.backgroundImage, style.maskImage, style.webkitMaskImage].filter((value) => value && value !== 'none').join(' '));

                if (match) {
                    return { node: current, url: match[1], background: true };
                }
            }
        }

        return null;
    }

    const fileStem = (path) => decodeURIComponent(String(path).split(/[?#]/)[0].split('/').pop() || '').replace(/\.[a-z0-9]+$/i, '').toLowerCase();

    function imageItems(part, node) {
        const found = typeof part === 'number' && node ? imageAtNode(part, node) : null;

        if (!found) {
            return [];
        }

        const data = elementData(part);
        const stem = fileStem(found.url);
        const images = data?.images || [];
        const image = images.find((entry) => entry.path && (stem === fileStem(entry.path) || stem.startsWith(fileStem(entry.path) + '-')))
            || (images.length === 1 && images[0].path && found.node.tagName === 'IMG' ? images[0] : null);
        const items = [{ group: t('menu_image') }];

        if (!image) {
            items.push({ label: t('change_image'), icon: 'image', disabled: true, reason: t('image_template') });

            return items;
        }

        const locked = !image.editable ? t('no_permission') : null;

        items.push({ label: t('change_image'), icon: 'image', note: image.label, disabled: Boolean(locked), reason: locked, action: () => changeImage(part, image) });

        if (image.field === 'singleSRC') {
            for (const [field, label] of [['alt', t('alt')], ['caption', t('caption_text')]]) {
                if (data.fields.includes(field)) {
                    items.push({ label, icon: 'edit', action: () => editImageText(part, field, label) });
                }
            }
        }

        if (image.uuid && !image.mandatory) {
            items.push({ label: t('remove_image'), icon: 'delete', disabled: Boolean(locked), reason: locked, action: () => setImage(part, image, '') });
        }

        return items;
    }

    // The link at a node: its address from a link field of the element (Contao's link picker), its text, open it.
    function linkItems(part, node) {
        const link = node?.closest?.('a[href]');

        if (!link || partOfNode(link) !== part) {
            return [];
        }

        const data = typeof part === 'number' ? elementData(part) : null;
        const href = link.href;
        // The same address, also where the preview puts its entry point (/preview.php) before the path.
        const plain = (value) => {
            const url = new URL(value, pageWin.location.href);

            return url.origin + url.pathname.replace(/^\/preview\.php(?=\/|$)/, '') + url.search + url.hash;
        };
        const same = (value) => {
            try {
                return plain(value) === plain(href);
            } catch (e) {
                return false;
            }
        };
        const field = (data?.links || []).find((entry) => same(entry.href));
        const rich = data ? inlineTargets(part, data).find((target) => target.kind === 'rich' && inTarget(target, link)) : null;
        const items = [{ group: t('menu_link') }];

        if (field) {
            const locked = !field.editable ? t('no_permission') : null;

            items.push({ label: t('edit_link'), icon: 'link', note: field.label, disabled: Boolean(locked), reason: locked, action: () => changeLink(part, field) });
        } else if (rich) {
            items.push({ label: t('edit_link'), icon: 'link', note: rich.label, action: () => editLinkInText(part, rich, link) });
        } else {
            items.push({ label: t('edit_link'), icon: 'link', disabled: true, reason: t('link_template') });
        }

        const textNode = [...link.childNodes].find((child) => child.nodeType === Node.TEXT_NODE && child.data.trim()) || link.querySelector('*')?.firstChild;
        const text = textNode ? targetAtNode(part, textNode) : null;

        if (text?.source.editable && !rich) {
            items.push({ label: t('edit_link_text'), icon: 'edit', note: text.label, action: () => startEditing(part, text) });
        }

        items.push({ label: t('open_link'), icon: 'visible', note: link.getAttribute('href'), action: () => openLink(link) });

        return items;
    }

    // The menu of an element: in its tab on the page and as the lower part of the context menu (one menu, two ways in).
    // Edit, all fields, its images, translation; duplicate, hide, move, insert; info; delete last. Entries its rights do
    // not allow are disabled with the reason. The keys of an entry work on the selected element (elementKey). $back:
    // where the focus goes after a change of the structure.
    function elementItems(id, data, back = { where: 'tree' }) {
        const article = articleOf(id);
        const list = article ? article.elements : [];
        const index = list.findIndex((element) => element.id === id);
        const denied = (allowed) => (allowed ? {} : { disabled: true, reason: t('no_permission') });
        const items = [];

        if (inlineTargets(id, data).length && data.canUpdate) {
            items.push({ key: 'edit', label: t('edit'), icon: 'edit', keys: 'Enter', action: () => startEditing(id) });
        }

        items.push({ key: 'form', label: t('all_fields'), icon: 'fields', ...denied(data.canUpdate), action: () => openForm(id) });

        // Its images, also those its template shows as a background where no pointer reaches them.
        for (const image of (data.images || []).filter((entry) => entry.editable)) {
            items.push({ label: t('change_image'), icon: 'image', note: image.label, action: () => changeImage(id, image) });
        }

        items.push(...translationItems(id, data));

        if (state.view !== 'form') {
            items.push({ separator: true });
            items.push({ key: 'copy', label: t('duplicate'), icon: 'copy', ...denied(data.canCopy), action: () => duplicate(id, back) });
            items.push({ key: 'hide', label: t('hide'), icon: 'hide', checked: Boolean(data.invisible), ...denied(data.canToggle), action: () => toggleHidden(id, back) });

            if (index > 0) {
                items.push({ key: 'up', label: t('move_up'), glyph: '↑', keys: 'Alt+ArrowUp', ...denied(data.canMove), action: () => moveStep(id, -1, back) });
            }

            if (index !== -1 && index < list.length - 1) {
                items.push({ key: 'down', label: t('move_down'), glyph: '↓', keys: 'Alt+ArrowDown', ...denied(data.canMove), action: () => moveStep(id, 1, back) });
            }

            if (article) {
                items.push(
                    { label: t('insert_before'), icon: 'new', ...denied(article.canCreate), action: () => createElement(article.id, index > 0 ? list[index - 1].id : null) },
                    { label: t('insert_after'), icon: 'new', ...denied(article.canCreate), action: () => createElement(article.id, id) },
                );
            }
        }

        // Web designers (Basic): templates and classes of the element.
        if (editions().design) {
            items.push({ separator: true }, { key: 'info', label: t('info'), icon: 'info', action: () => openInfo({ kind: 'content', id, title: t('id_label', { type: data.typeLabel, id }) }, label) });
        }

        if (state.view !== 'form') {
            items.push({ separator: true }, { key: 'delete', label: t('delete'), icon: 'delete', danger: true, keys: 'Delete', ...denied(data.canDelete), action: () => remove(id) });
        }

        return items;
    }

    // A module (header, footer …), a form field, a post in a list: its texts and its form; a module changes on every
    // page that shows it. A form field: a new field after it and delete (Contao's operations and permissions). Info
    // (web designers) also when Contao does not allow the change: the card names the missing permission. In the tab
    // of the part and in the context menu.
    function itemMenuItems(key, data) {
        const items = [];

        if (key.startsWith('fm:') && inlineTargetsOf(key, moduleData(key) || {}).length) {
            items.push({ key: 'edit', label: t('edit_texts'), icon: 'edit', note: t('module_note'), action: () => startEditing(key) });
        }

        if (!data.textsOnly) {
            items.push({
                key: 'form',
                label: t(data.kind === 'module' ? 'edit_module' : 'edit_' + data.kind),
                icon: 'fields',
                note: data.kind === 'module' ? t('module_note') : null,
                disabled: !data.canEdit,
                reason: data.canEdit ? null : t('no_permission'),
                action: () => openItemForm(data),
            });
        }

        // A module's texts in the other languages: its version there is linked or made as a copy (Plus or Pro).
        if (key.startsWith('fm:') && hasVersions()) {
            items.push({ key: 'translate', label: t('rec_translate'), icon: 'translate', action: () => openRecordTranslation('tl_module', Number(key.slice(3))) });
        }

        if (data.kind === 'field' && state.view !== 'form' && data.canCreate) {
            items.push({ separator: true }, { label: t('field_insert_after'), icon: 'new', action: () => createField(data) });
        }

        if (editions().design && ((data.templates || []).length || Array.isArray(data.missing) && !data.canEdit)) {
            items.push({ separator: true }, { key: 'info', label: t('info'), icon: 'info', action: () => openInfo({ kind: data.kind, key, data, title: itemLabel(data) }, label) });
        }

        if (data.kind === 'field' && state.view !== 'form' && data.canDelete) {
            items.push({ separator: true }, { key: 'delete', label: t('field_delete'), icon: 'delete', danger: true, action: () => deleteField(data) });
        }

        return items;
    }

    // Outside the elements, in an article: its settings and a new element at its end.
    function articleItems(article) {
        const last = article.elements[article.elements.length - 1];

        return [
            { label: t('article_settings'), icon: 'settings', disabled: !article.canEdit, reason: article.canEdit ? null : t('no_permission'), action: () => openArticleSettings(article) },
            { label: t('insert_element'), icon: 'new', disabled: !article.canCreate, reason: article.canCreate ? null : t('no_permission'), action: () => createElement(article.id, last ? last.id : null) },
        ];
    }

    // The article of the page around a node: the innermost parent that holds elements of one article only.
    function articleAtNode(node) {
        const articles = (state.structure?.articles || []).filter((article) => typeof article.id === 'number' && !isPost(article));

        for (let current = node; current && current.nodeType === Node.ELEMENT_NODE && current !== pageDoc.body; current = current.parentElement) {
            const inside = articles.filter((article) => article.elements.some((element) => nodesOf(state.ranges.get(element.id)).some((child) => current.contains(child))));

            if (inside.length === 1) {
                return inside[0];
            }

            if (inside.length > 1) {
                return null;
            }
        }

        return null;
    }

    async function openArticleSettings(article) {
        if (!(await settle())) {
            return;
        }

        openRecordForm({ table: 'tl_article', id: article.id, title: t('article_settings'), isNew: false });
    }

    // An image of the element, chosen in Contao's file picker (with upload) and saved at once through Contao's form
    // rules; the element is rendered again.
    async function changeImage(id, image) {
        if (!(await settle())) {
            return;
        }

        const value = await openPicker({ context: 'image', element: id, field: image.field, value: image.uuid || '', title: t('choose_image') });

        if (value) {
            await setImage(id, image, value.split(',')[0]);
        }
    }

    async function setImage(id, image, value) {
        await saveFields(id, { [image.field]: value });
    }

    // Alternative text or caption of the element's image, in a dialog; saved at once.
    async function editImageText(id, field, label) {
        if (!(await settle())) {
            return;
        }

        const data = elementData(id);
        const current = field === 'alt' ? data.values?.image?.alt : data.values?.image?.caption;
        const value = await askText(label, label, current || '');

        if (value !== null && value !== current) {
            await saveFields(id, { [field]: value });
        }
    }

    // A link field of the element, chosen in Contao's link picker; saved at once.
    async function changeLink(id, field) {
        if (!(await settle())) {
            return;
        }

        const value = await openPicker({ context: 'link', value: field.value, title: t('edit_link') });

        if (value !== null && value !== field.value) {
            await saveFields(id, { [field.field]: value });
        }
    }

    // A link inside a rich text: the text is edited, the link selected, Contao's link picker sets its address.
    async function editLinkInText(part, target, link) {
        startEditing(part, target);

        if (!state.editing || !link.isConnected) {
            return;
        }

        const selection = pageDoc.getSelection();
        const range = pageDoc.createRange();

        range.selectNodeContents(link);
        selection.removeAllRanges();
        selection.addRange(range);
        await pickLink();
    }

    // Opens a link: on this website in the page's frame, elsewhere in a new window.
    async function openLink(link) {
        const url = new URL(link.href, pageWin.location.href);

        if (url.origin !== pageWin.location.origin) {
            window.open(url.href, '_blank', 'noopener');
        } else if (await settle()) {
            state.reloading = true;
            pageWin.location.href = frameUrl(url.href);
        }
    }

    async function saveFields(id, fields) {
        try {
            const result = await api('/element/' + id, { method: 'POST', body: JSON.stringify({ fields }) });

            replaceElementData(result.element);
            await refreshElement(id);
            announce(t('saved'));
            afterChange();
        } catch (error) {
            showToast(error.status ? error.message : t('save_failed'));
        }
    }

    // The key for the context menu, or Shift+F10, on the page: the menu of what has the focus (or the text cursor),
    // or of the selected part, at its top left corner. (In the structure the row opens its own menu.)
    function partMenuByKey(event) {
        if (!(event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) || !pageDoc || event.target?.ownerDocument !== pageDoc) {
            return false;
        }

        // On the tab of the part: its menu. The browser's own menu does not follow (the contextmenu event of the key).
        if (pageRoot?.activeElement === label && tabItems) {
            event.preventDefault();
            keyMenuAt = Date.now();
            openTabMenu();

            return true;
        }

        const selection = pageDoc.getSelection();
        const caret = state.editing && selection?.anchorNode && pageDoc.body.contains(selection.anchorNode) ? selection.anchorNode : null;
        const focused = pageDoc.activeElement && pageDoc.activeElement !== pageDoc.body && pageDoc.activeElement !== pageHost ? pageDoc.activeElement : null;
        const part = state.item ?? state.selected;
        const fallback = part === null || part === undefined ? null : nodesOf(partRange(part)).find((child) => child.nodeType === Node.ELEMENT_NODE);
        const node = caret || focused || fallback;

        if (!node) {
            return false;
        }

        const box = (node.nodeType === Node.TEXT_NODE ? node.parentElement : node).getBoundingClientRect();
        const textNode = node.nodeType === Node.TEXT_NODE ? node : [...(node.childNodes || [])].find((child) => child.nodeType === Node.TEXT_NODE && child.data.trim()) || null;

        event.preventDefault();
        keyMenuAt = Date.now();

        return openContextMenu(textNode || node, Math.max(4, box.left + 8), Math.max(4, box.top + 8), focused || null);
    }

    // ------------------------------------------------------------------ why a text is not edited on the page

    // Modules whose texts are the titles of pages: the navigation is not edited in the editor.
    const NAVIGATION = ['navigation', 'customnav', 'breadcrumb', 'sitemap', 'quicknav', 'quicklink', 'booknav', 'articlenav', 'changelanguage'];

    // The innermost marked part (element or other) that contains a node, whether or not it has data.
    function partOfNode(node) {
        let best = null;
        let bestArea = Infinity;
        let bestStart = null;
        // Of two parts as large as each other (a post filling a module filling an element), the inner one: it starts later.
        const inner = (start) => !bestStart || !start || Boolean(bestStart.compareDocumentPosition(start) & Node.DOCUMENT_POSITION_FOLLOWING);
        const consider = (part, range) => {
            if (!nodesOf(range).some((child) => child === node || child.contains(node))) {
                return;
            }

            const box = boxOfRange(range);
            const area = box ? box.w * box.h : Infinity;

            if (area < bestArea || (area === bestArea && inner(range.start))) {
                best = part;
                bestArea = area;
                bestStart = range.start || null;
            }
        };

        for (const [id, range] of state.ranges) {
            consider(id, range);
        }

        for (const [key, range] of state.items || []) {
            consider(key, range);
        }

        return best;
    }

    // A source of a part at a node, the user may change it or not.
    function targetAtNode(part, node) {
        const data = part === null ? null : partData(part);
        const found = data ? partTargets(part, data, partRange(part)) : null;

        return found ? [...found.targets, ...found.locked].find((target) => target.kind !== 'image' && inTarget(target, node)) || null : null;
    }

    // Why the text at a node is not edited on the page, as a code: field (field permission), record (the part
    // may not be changed), module (the module may not be changed), unmapped (the template changes the value of
    // a field so it cannot be found), navigation (page titles), post, other (form fields, comments), template
    // (no field: the template, a language file or the layout).
    function reasonAt(part, node) {
        const target = targetAtNode(part, node);

        if (target) {
            return target.source.editable ? null : { code: target.source.reason || 'record', source: target.source };
        }

        if (typeof part === 'string') {
            if (part.startsWith('lp:')) {
                return { code: 'post' };
            }

            if (!part.startsWith('fm:')) {
                return { code: 'other' };
            }

            if (NAVIGATION.includes(state.moduleTexts?.[part]?.type)) {
                return { code: 'navigation' };
            }
        }

        const data = part === null ? null : partData(part);
        const found = data ? partTargets(part, data, partRange(part)) : null;
        const text = sameText((node.nodeType === Node.TEXT_NODE ? node.data : node.textContent || '').replace(/[\s­​-‍⁠﻿]+/g, ''));
        const source = text.length > 1 ? found?.unmapped.find((entry) => sameText(shownText(fragment(entry.shown))) === text) : null;

        return source ? { code: 'unmapped', source } : { code: 'template' };
    }

    function reasonText(reason, part) {
        const name = reason.source?.label || '';

        return {
            field: () => t('reason_field', { name }),
            record: () => t('reason_record'),
            module: () => t('reason_module', { name: state.moduleTexts?.[part]?.name || '' }),
            unmapped: () => t('reason_unmapped', { name }),
            navigation: () => t('reason_navigation'),
            post: () => t('reason_post'),
            other: () => t('reason_other'),
            template: () => t('reason_template'),
        }[reason.code]();
    }

    // The text node under the pointer, or null where there is no text (images, empty space).
    function textNodeAt(event) {
        const doc = pageDoc;
        let node = null;

        if (doc.caretPositionFromPoint) {
            node = doc.caretPositionFromPoint(event.clientX, event.clientY)?.offsetNode || null;
        } else if (doc.caretRangeFromPoint) {
            node = doc.caretRangeFromPoint(event.clientX, event.clientY)?.startContainer || null;
        }

        if (!node || node.nodeType !== Node.TEXT_NODE || !node.data.trim() || pageHost?.contains(node)) {
            return null;
        }

        const range = doc.createRange();

        range.selectNodeContents(node);

        return [...range.getClientRects()].some((rect) => event.clientX >= rect.left - 2 && event.clientX <= rect.right + 2 && event.clientY >= rect.top - 2 && event.clientY <= rect.bottom + 2) ? node : null;
    }

    // Says next to the click why the text cannot be changed there; the next click or key closes it.
    function showNote(message, event) {
        note.textContent = message;
        note.hidden = false;

        const view = pageView();
        const width = note.offsetWidth;
        const height = note.offsetHeight;
        const top = event.clientY + 16 + height > view.height ? event.clientY - height - 12 : event.clientY + 16;

        Object.assign(note.style, { left: Math.max(4, Math.min(event.clientX - width / 2, view.width - width - 4)) + 'px', top: Math.max(4, top) + 'px' });
        announce(message);
    }

    function hideNote() {
        note.hidden = true;
    }

    // A click on a text of the selected part (or while it is edited) that is not edited there: the reason.
    function explain(event, part) {
        const node = textNodeAt(event);
        const reason = node ? reasonAt(part, node) : null;

        if (reason) {
            showNote(reasonText(reason, part), event);
        }

        return Boolean(reason);
    }

    // Every visible text of the page with its source (TABLE.ID.FIELD…), whether the user may change it there
    // and how it was found (source map or text), or why it is not edited there. For checking how much of a
    // page can be edited; window.NeoFrontendEditor.textMap() in the editor view.
    function textMap() {
        const entries = [];
        const walker = pageDoc.createTreeWalker(pageDoc.body, NodeFilter.SHOW_TEXT);
        let node;

        while ((node = walker.nextNode())) {
            const text = node.data.replace(/\s+/g, ' ').trim();
            const parent = node.parentElement;

            // Names of icons in an icon font and other text hidden from readers are not texts of the page.
            if (!text || !parent || parent.closest('script, style, noscript, template, [hidden], [aria-hidden="true"]') || pageHost?.contains(node)) {
                continue;
            }

            const style = pageWin.getComputedStyle(parent);

            if (!parent.getClientRects().length || style.visibility === 'hidden' || style.display === 'none') {
                continue;
            }

            const part = partOfNode(node);
            const target = part === null ? null : targetAtNode(part, node);
            const entry = { text: text.slice(0, 100), part: part === null ? null : typeof part === 'string' ? part : 'ce:' + part };

            if (target) {
                Object.assign(entry, { source: target.source.key, scope: target.source.scope, editable: target.source.editable, via: target.pair ? 'map' : 'text', reason: target.source.editable ? null : target.source.reason });
            } else {
                Object.assign(entry, { source: null, editable: false, reason: reasonAt(part, node)?.code || 'template' });
            }

            entries.push(entry);
        }

        return entries;
    }

    // The text of an edited node: <br> and the blocks the browser adds for a new line count as line breaks.
    // A line break in the markup (the template's "<br>" followed by a new line in the source) is a space unless the
    // element keeps line breaks (white-space pre, pre-wrap, pre-line, break-spaces).
    function editedText(node) {
        let text = '';
        const keeps = new Map();
        const keepsBreaks = (element) => {
            if (!keeps.has(element)) {
                keeps.set(element, /^(pre|pre-wrap|pre-line|break-spaces)$/.test(element.ownerDocument.defaultView?.getComputedStyle(element).whiteSpace || ''));
            }

            return keeps.get(element);
        };
        const walk = (current) => {
            for (const child of current.childNodes) {
                if (child.nodeType === Node.TEXT_NODE) {
                    text += child.parentElement && child.parentElement.isConnected && keepsBreaks(child.parentElement) ? child.data : child.data.replace(/[\t\n\r\f]+/g, ' ');
                } else if (child.tagName === 'BR') {
                    text += '\n';
                } else if (child.nodeType === Node.ELEMENT_NODE) {
                    if (/^(DIV|P)$/.test(child.tagName) && text && !text.endsWith('\n')) {
                        text += '\n';
                    }

                    walk(child);
                }
            }
        };

        walk(node);

        return text.replace(/ /g, ' ').trim();
    }

    // A single line: Enter starts no new line.
    function keepOneLine(event) {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
        }
    }

    // Plain text only, where the browser can (contenteditable="plaintext-only").
    function editPlain(node) {
        try {
            node.contentEditable = 'plaintext-only';
        } catch (e) {
            node.contentEditable = 'true';
        }
    }

    // ---- Contao's rich text editor (TinyMCE) on the page

    // A rich text (a field with Contao's TinyMCE in the back end; an HTML field is not one) is written on the page with
    // the text toolbar; its button "HTML editor" switches to Contao's own TinyMCE, inline where the page shows the
    // text: the script Contao ships, loaded into the page, with the configuration Contao's template writes for the
    // field in the back-end form (the website's own version of the template included). Only what the page needs is
    // changed: inline, the page's own styles, Contao's picker opened by the editor, the toolbar in a bar of its own
    // that stands above the tabs of the element, flush with the frame, and can be dragged anywhere in the window.
    // The choice is remembered. Without Contao's rich text editor (System › Settings), in a place that cannot hold
    // paragraphs or when it does not load, the text toolbar stays.
    const TINY_PLACES = new Set(['DIV', 'SECTION', 'ARTICLE', 'ASIDE', 'MAIN', 'BLOCKQUOTE', 'TD', 'TH', 'LI', 'DD', 'FIGCAPTION']);
    // Space between the bar and the tabs (or the frame).
    const TINY_GAP = 8;
    const tinyScripts = new WeakMap();
    const tinyConfigs = new WeakMap();
    let tinyCount = 0;

    const usesTinyMce = (target) => Boolean(config.tinymce && target?.kind === 'rich' && target.source?.table && !target.tinyFailed && TINY_PLACES.has(target.node?.tagName) && !narrow());
    const tinyWanted = () => readStored('nfe:tinymce') === '1';

    // TinyMCE draws its toolbar, menus and dialogs into the page, outside the text: the editor leaves them alone.
    const inTinyMce = (event) => event.composedPath().some((node) => node.classList?.contains('tox') || node.classList?.contains('nfe-tiny-bar'));

    function readStored(key) {
        try {
            return localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function writeStored(key, value) {
        try {
            if (value === null) {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(key, value);
            }
        } catch (e) {
            // Without storage the choice lasts until the page is left.
        }
    }

    function loadPageScript(src) {
        return new Promise((resolve, reject) => {
            const script = pageDoc.createElement('script');

            script.src = src;

            if (config.nonce) {
                script.nonce = config.nonce;
            }

            script.onload = resolve;
            script.onerror = () => reject(new Error(src));
            pageDoc.head.append(script);
        });
    }

    // What the editor needs in the page itself (light DOM, beside TinyMCE's own skin): the text being written has the
    // frame as its focus mark (not the browser's outline in addition), the bar of TinyMCE's toolbar with its grip, and
    // TinyMCE's menus and dialogs above the editor's frame and tabs.
    const PAGE_STYLE = [
        '[data-nfe-writing]:focus{outline:0}',
        '.nfe-tiny-bar{position:fixed;z-index:2147483647;display:flex;align-items:stretch;box-sizing:border-box;background:#fff;border:1px solid #ccc;border-radius:4px;box-shadow:0 2px 6px rgb(0 0 0/12%)}',
        '.nfe-tiny-bar__grip{flex:none;width:20px;margin:0;padding:0;background:#f4f4f4;border:0;border-right:1px solid #ccc;border-radius:3px 0 0 3px;cursor:grab;color:#555;display:flex;align-items:center;justify-content:center}',
        '.nfe-tiny-bar__grip:hover{background:#e8e8e8}',
        '.nfe-tiny-bar__grip:focus-visible{outline:2px solid #006ce7;outline-offset:-2px}',
        '.nfe-tiny-bar--moving .nfe-tiny-bar__grip{cursor:grabbing}',
        '.nfe-tiny-bar__slot{flex:1;min-width:0}',
        '.nfe-tiny-bar .tox-tinymce,.nfe-tiny-bar .tox-editor-header{border:0!important;box-shadow:none!important;border-radius:0 3px 3px 0}',
        '.tox.tox-silver-sink{z-index:2147483647}',
    ].join('');

    function pageStyle() {
        if (pageDoc.getElementById('nfe-page-style')) {
            return;
        }

        const style = pageDoc.createElement('style');

        style.id = 'nfe-page-style';
        style.textContent = PAGE_STYLE;

        if (config.styleNonce) {
            style.setAttribute('nonce', config.styleNonce);
        }

        pageDoc.head.append(style);
    }

    // Contao's TinyMCE in the page of the frame, loaded once per page (a TinyMCE of the website itself is used).
    function loadTinyMce() {
        if (!tinyScripts.has(pageWin)) {
            tinyScripts.set(pageWin, pageWin.tinymce ? Promise.resolve(pageWin.tinymce) : loadPageScript(config.tinymce).then(() => pageWin.tinymce));
        }

        return tinyScripts.get(pageWin);
    }

    // Contao's configuration for a field, once per field and page. The template finds the field it is written for in
    // the document: a hidden stand-in takes its place. Newer versions of Contao hand the configuration to the field,
    // older ones call tinymce.init() themselves; both are caught.
    function tinyConfig(tiny, { table, field, id }) {
        const configs = tinyConfigs.get(pageWin) || new Map();
        const key = table + '.' + field;

        tinyConfigs.set(pageWin, configs);

        if (!configs.has(key)) {
            configs.set(key, (async () => {
                const selector = 'nfe-tinymce-' + (++tinyCount);
                const stand = pageDoc.createElement('textarea');
                const init = tiny.init;
                let called = null;

                stand.id = selector;
                stand.hidden = true;
                pageDoc.body.append(stand);
                tiny.init = (options) => {
                    called = options;

                    return Promise.resolve([]);
                };

                try {
                    await loadPageScript(config.api + '/tinymce?' + new URLSearchParams({ table, field, id: String(id || 0), selector }));
                } finally {
                    tiny.init = init;
                    stand.remove();
                }

                return called || stand.tinymceConfig || null;
            })());
        }

        return configs.get(key);
    }

    // The button "HTML editor" of the text toolbar: Contao's TinyMCE on or off for the text being written (its text
    // stays as it is), and from now on for the texts written next.
    function toggleTinyMce(target) {
        const on = !(target.editor || target.tinyWanted);

        writeStored('nfe:tinymce', on ? '1' : '0');

        if (on) {
            attachTinyMce(target);
        } else {
            detachTinyMce(target);
            target.node.focus();
        }

        buildFormat(target);
    }

    // Starts Contao's TinyMCE on a rich text being edited. The text can be written while it loads.
    async function attachTinyMce(target) {
        target.tinyWanted = true;

        let options = null;
        let tiny = null;

        try {
            tiny = await loadTinyMce();
            options = tiny ? await tinyConfig(tiny, target.source) : null;
        } catch (e) {
            options = null;
        }

        // Switched off again, or editing ended or moved on, while TinyMCE loaded.
        if (!target.tinyWanted || !state.editing?.targets.includes(target) || target.editor) {
            return;
        }

        if (!options) {
            target.tinyWanted = false;
            target.tinyFailed = true;

            if (state.editing.focused === target) {
                buildFormat(target);
            }

            return;
        }

        pageStyle();

        const { setup, init_instance_callback: ready } = options;
        const bar = tinyBar();
        const settings = {
            ...options,
            target: target.node,
            inline: true,
            // The toolbar in the editor's bar, always shown while TinyMCE is on.
            fixed_toolbar_container_target: bar.querySelector('.nfe-tiny-bar__slot'),
            toolbar_persist: true,
            // The page's own styles show the text (the back end's style sheet stands in for them there).
            content_css: false,
            // The editor asks before unsaved changes are left (Save, Cancel), not TinyMCE.
            autosave_ask_before_unload: false,
            // Contao's picker, opened by the editor: the back end's modal window is not on the page.
            file_picker_callback: (callback, value, meta) => {
                openPicker({
                    context: meta.filetype === 'image' ? 'file' : 'link',
                    value: value || '',
                    title: pageDoc.querySelector('.tox-dialog__title')?.textContent || '',
                    source: target.source.table + '.' + target.source.id,
                }).then((picked) => {
                    if (picked !== null) {
                        callback(picked);
                    }
                });
            },
            setup: (editor) => {
                setup?.(editor);
                // Changes made with the toolbar (no input event on the text).
                editor.on('input change undo redo ExecCommand', () => {
                    if (editor.isDirty()) {
                        markDirty();
                    }
                });
            },
            init_instance_callback: (editor) => ready?.(editor),
        };

        delete settings.selector;
        delete settings.min_height;
        delete settings.height;

        // The page's markup of the place as it was: TinyMCE adds an ID, classes and styles to it.
        const attributes = [...target.node.attributes].map((attribute) => [attribute.name, attribute.value]);
        const typed = target.node.innerHTML !== target.original?.html;
        let editor = null;

        target.tinyBar = bar;

        try {
            [editor] = await tiny.init(settings);
        } catch (e) {
            editor = null;
        }

        if (!editor) {
            bar.remove();
            target.tinyBar = null;
            target.tinyWanted = false;
            target.tinyFailed = true;
            buildFormat(state.editing?.focused === target ? target : null);

            return;
        }

        target.editor = editor;
        target.tinyAttributes = attributes;
        // Typed before TinyMCE was ready: the text has changed whatever TinyMCE says.
        target.tinyTyped = typed;
        target.tinyStart = editor.getContent();

        // Switched off or editing ended while TinyMCE started.
        if (!target.tinyWanted || !state.editing?.targets.includes(target)) {
            detachTinyMce(target);

            return;
        }

        if (state.editing.focused === target) {
            buildFormat(target);
            editor.focus(false);
        }

        placeSelection();
    }

    // The bar of TinyMCE's toolbar: a grip at its left drags it (also with the arrow keys); a double click on the
    // grip puts it back above the tabs. Where it was dragged to is remembered.
    function tinyBar() {
        const bar = pageDoc.createElement('div');
        const grip = pageDoc.createElement('button');
        const slot = pageDoc.createElement('div');

        bar.className = 'nfe-tiny-bar';
        grip.type = 'button';
        grip.className = 'nfe-tiny-bar__grip';
        grip.title = t('move_toolbar');
        grip.setAttribute('aria-label', t('move_toolbar'));
        grip.innerHTML = '<svg width="8" height="14" viewBox="0 0 8 14" aria-hidden="true" fill="currentColor"><circle cx="2" cy="2" r="1.3"/><circle cx="6" cy="2" r="1.3"/><circle cx="2" cy="7" r="1.3"/><circle cx="6" cy="7" r="1.3"/><circle cx="2" cy="12" r="1.3"/><circle cx="6" cy="12" r="1.3"/></svg>';
        slot.className = 'nfe-tiny-bar__slot';
        bar.append(grip, slot);
        bar.hidden = true;
        pageDoc.body.append(bar);
        watchTop(bar);

        // The whole bar stays in the window.
        const moveTo = (x, y) => {
            const { width, height } = pageView();
            const at = { x: Math.round(Math.max(0, Math.min(x, width - bar.offsetWidth))), y: Math.round(Math.max(0, Math.min(y, height - bar.offsetHeight))) };

            Object.assign(bar.style, { left: at.x + 'px', top: at.y + 'px' });
            writeStored('nfe:tinybar', JSON.stringify(at));
        };

        // The grip keeps the focus in the text.
        grip.addEventListener('mousedown', (event) => event.preventDefault());
        grip.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            const box = bar.getBoundingClientRect();
            const dx = event.clientX - box.left;
            const dy = event.clientY - box.top;
            const move = (moving) => moveTo(moving.clientX - dx, moving.clientY - dy);
            const end = () => {
                bar.classList.remove('nfe-tiny-bar--moving');
                grip.removeEventListener('pointermove', move);
                grip.removeEventListener('pointerup', end);
                grip.removeEventListener('pointercancel', end);
            };

            event.preventDefault();
            grip.setPointerCapture(event.pointerId);
            bar.classList.add('nfe-tiny-bar--moving');
            grip.addEventListener('pointermove', move);
            grip.addEventListener('pointerup', end);
            grip.addEventListener('pointercancel', end);
        });
        grip.addEventListener('dblclick', () => {
            writeStored('nfe:tinybar', null);
            placeSelection();
        });
        grip.addEventListener('keydown', (event) => {
            const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

            if (event.key === 'Home') {
                event.preventDefault();
                writeStored('nfe:tinybar', null);
                placeSelection();
            } else if (steps[event.key]) {
                const box = bar.getBoundingClientRect();
                const step = event.shiftKey ? 50 : 10;

                event.preventDefault();
                moveTo(box.left + steps[event.key][0] * step, box.top + steps[event.key][1] * step);
            }
        });

        return bar;
    }

    // The bar stays on top of everything: besides the highest z-index (which the editor's frame and tabs, a chat
    // button or a consent banner of the website may have as well, where the order in the page decides) it comes
    // last in the page, followed only by TinyMCE's menus and dialogs. What the website adds later moves it back to
    // the end; never while the focus is in the bar or in a menu of TinyMCE.
    function keepOnTop(bar) {
        const sinks = [...pageDoc.querySelectorAll('.tox-silver-sink')];
        const order = [bar, ...sinks];
        const tail = [...pageDoc.body.children].slice(-order.length);
        const active = pageDoc.activeElement;

        if (order.every((node, index) => tail[index] === node) || order.some((node) => node.contains(active))) {
            return;
        }

        pageDoc.body.append(...order);
    }

    function watchTop(bar) {
        const observer = new pageWin.MutationObserver(() => {
            if (bar.isConnected) {
                keepOnTop(bar);
            } else {
                observer.disconnect();
            }
        });

        observer.observe(pageDoc.body, { childList: true });
    }

    // The bar's place: where it was dragged to, else above the tabs (or the frame), flush with the frame's left edge
    // and as wide as the frame, TINY_GAP away; without room there below the frame.
    function placeTinyBar(place) {
        for (const target of state.editing?.targets || []) {
            const bar = target.tinyBar;

            if (!bar || !target.editor) {
                continue;
            }

            const { width, height } = pageView();
            let dragged = null;

            try {
                dragged = JSON.parse(readStored('nfe:tinybar') || 'null');
            } catch (e) {
                dragged = null;
            }

            bar.hidden = false;
            bar.style.width = Math.max(240, Math.min(place.width, width - 8)) + 'px';
            keepOnTop(bar);

            if (dragged && Number.isFinite(dragged.x) && Number.isFinite(dragged.y)) {
                Object.assign(bar.style, {
                    left: Math.max(0, Math.min(dragged.x, width - bar.offsetWidth)) + 'px',
                    top: Math.max(0, Math.min(dragged.y, height - bar.offsetHeight)) + 'px',
                });

                continue;
            }

            const above = place.top - TINY_GAP - bar.offsetHeight;
            const top = above >= 4 ? above : Math.min(place.bottom + TINY_GAP, height - bar.offsetHeight - 4);

            Object.assign(bar.style, { left: Math.min(place.left, Math.max(0, width - bar.offsetWidth)) + 'px', top: Math.max(0, top) + 'px' });
        }
    }

    // The value TinyMCE has for a text: null while it is unchanged.
    function tinyContent(target) {
        const html = target.editor.getContent();

        return target.tinyTyped || target.replaced || html !== target.tinyStart ? html : null;
    }

    // TinyMCE leaves the text: what was written stays on the page (TinyMCE writes it in its own clean form), an
    // unchanged text gets the page's markup back, the place its attributes.
    function detachTinyMce(target) {
        const editor = target.editor;

        target.tinyWanted = false;
        target.tinyBar?.remove();
        target.tinyBar = null;

        if (!editor) {
            return;
        }

        const html = tinyContent(target);

        target.editor = null;
        editor.remove();

        for (const attribute of [...target.node.attributes]) {
            target.node.removeAttribute(attribute.name);
        }

        for (const [name, value] of target.tinyAttributes || []) {
            target.node.setAttribute(name, value);
        }

        if (html === null && target.original) {
            target.node.innerHTML = target.original.html;
        }
    }

    // The parts whose texts are edited: a content element (its ID) or a front-end module (its key "fm:ID").
    const partRange = (part) => (typeof part === 'string' ? state.items?.get(part) : state.ranges.get(part));
    const partData = (part) => (typeof part === 'string' ? moduleData(part) : elementData(part));

    function moduleData(key) {
        const texts = state.moduleTexts?.[key];

        return texts ? { sources: texts.sources, fields: [], module: true, name: texts.name, type: texts.type } : null;
    }

    const editingRange = () => (state.editing ? partRange(state.editing.id ?? state.editing.item) : null);

    // Starts editing the texts of a part; "at" is the target to begin with (a click on it). The texts are edited
    // where the page shows them: nothing of the template's markup, classes or effects is replaced, only the text
    // nodes change (contenteditable on the place of each value). Leaving without a change leaves the page as it was.
    function startEditing(part, at = null) {
        if (state.editing) {
            // Another text of the part being edited: it gets the focus.
            if ((state.editing.id ?? state.editing.item) === part) {
                const target = at && state.editing.targets.find((entry) => entry.key === at.key);

                (target?.within || target)?.node?.focus();

                return;
            }

            parkEditing();
        }

        const data = partData(part);
        // Changes that waited for Save: editing goes on where they are, with the places found then.
        const waiting = state.pending.find((entry) => entry.part === part);
        const reachable = (target) => (target.node || target.textNode || target.pair?.start)?.isConnected;
        const resume = waiting && waiting.targets.every(reachable) ? waiting : null;
        // A value shown in several places is edited in one of them: where it was clicked, or the first.
        const targets = resume ? resume.targets : (data ? inlineTargetsOf(part, data) : []).filter((target, index, all) => !target.key
            || (at?.key === target.key ? target === at || !all.includes(at) && all.findIndex((other) => other.key === target.key) === index : all.findIndex((other) => other.key === target.key) === index));

        if (!targets.length) {
            return;
        }

        targetCache.clear();
        state.hover = null;
        state.pending = state.pending.filter((entry) => entry !== waiting);

        for (const target of targets) {
            if (target.kind !== 'image') {
                holderOf(target);
                // The page as it was before the first change: what the edited text is compared with when it is saved,
                // and what Cancel puts back.
                target.original ??= { html: target.node.innerHTML, text: editedText(target.node), clone: target.node.cloneNode(true), tag: target.node.tagName };
            }
        }

        state.editing = {
            id: typeof part === 'number' ? part : null,
            item: typeof part === 'string' ? part : null,
            data,
            targets,
            values: { ...(waiting?.editValues || {}) },
            // Values of an earlier edit whose places the page no longer shows (saved with these).
            earlier: waiting && !resume ? waiting.values : null,
            proposal: Boolean(waiting?.proposal),
        };

        for (const target of targets) {
            // A value inside another value's place (the accent word in its span inside the headline) is edited there.
            if (target.within) {
                continue;
            }

            if (target.kind === 'headline') {
                editPlain(target.node);
                target.node.addEventListener('keydown', keepOneLine);
                state.editing.values.unit ??= data.values?.headline?.unit || target.source.unit || 'h2';
            } else if (target.kind === 'rich') {
                target.node.contentEditable = 'true';

                if (usesTinyMce(target) && tinyWanted()) {
                    attachTinyMce(target);
                }
            } else if (target.kind === 'line' || target.kind === 'lines') {
                editPlain(target.node);

                if (target.kind === 'line') {
                    target.node.addEventListener('keydown', keepOneLine);
                }
            } else if (target.kind === 'image') {
                startImage(target.node, data);
            }

            if (target.kind !== 'image') {
                target.node.addEventListener('input', markDirty);
                target.node.addEventListener('paste', pastePlain);
                target.node.addEventListener('focus', focusTarget);
                // The frame marks the text being written (see PAGE_STYLE).
                target.node.setAttribute('data-nfe-writing', '');
                pageStyle();
            }
        }

        // Writing begins: room for the tabs above the part (placeSelection).
        state.reveal = true;

        const first = (at && targets.find((target) => target.key === at.key && !target.within)) || (at?.within && targets.includes(at.within) ? at.within : null)
            || targets.find((target) => target.kind === 'rich') || targets.find((target) => target.kind === 'headline') || targets.find((target) => target.kind === 'line' || target.kind === 'lines');

        if (first) {
            first.node.focus();
            state.editing.focused = first;
            buildFormat(first);
        }

        if (waiting) {
            state.dirty = true;
        }

        // A module is shown on every page whose layout or elements include it: the change applies there too.
        const module = state.editing.item ? data.name : targets.some((target) => target.source?.scope === 'module') ? moduleOfSource(targets.find((target) => target.source?.scope === 'module').source) : null;

        if (module !== null) {
            showToast(t('module_everywhere', { name: module }), null, 'note');
        } else if (targets.some((target) => target.source?.included)) {
            // "Include element": the original is changed, on every page that shows it.
            showToast(t('included_everywhere'), null, 'note');
        }

        showEditedPart();
        showHover();
        renderStructure();
        renderStatus();
    }

    function moduleOfSource(source) {
        return state.moduleTexts?.['fm:' + source.id]?.name || '';
    }

    // The targets of a part the user may change, as startEditing() edits them.
    function inlineTargetsOf(part, data) {
        return partTargets(part, data, partRange(part)).targets;
    }

    function showEditedPart() {
        if (state.editing?.item) {
            showItem(state.editing.item);
        } else {
            showAt(state.editing ? state.editing.id : state.selected);
        }
    }

    function markDirty() {
        state.dirty = true;
        state.error = false;
        renderStatus();
    }

    // Pasted text arrives as plain text; formatting is done with the toolbar. With Contao's TinyMCE on, TinyMCE takes
    // it as Contao's form does.
    function pastePlain(event) {
        if (state.editing?.targets.some((target) => target.editor && target.node === event.currentTarget)) {
            return;
        }

        event.preventDefault();
        pageDoc.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    }

    function command(name, value = null) {
        pageDoc.execCommand(name, false, value);
        markDirty();
        updateFormatState();
    }

    // The toolbar of the field being written in, named after it: the level of the headline, formatting only for
    // rich text, with the switch to Contao's TinyMCE ("HTML editor", which brings its own toolbar), the writing aid
    // (Pro) for the headline and the text. Plain text fields get none: they stay plain text.
    function buildFormat(target) {
        format.replaceChildren();
        format.hidden = true;
        state.formatMenu = null;
        pageDoc?.removeEventListener('pointerdown', closeFormatMenu, true);

        // Contao's own form at this field of the element: its TinyMCE for a text where it cannot be on the page, the
        // stored HTML for a value with tags.
        const tiny = usesTinyMce(target);
        const tinyOn = Boolean(tiny && (target.editor || target.tinyWanted));
        const ownField = target && typeof state.editing?.id === 'number' && target.source?.scope === 'own' ? target.field : null;
        const tagged = ownField && target.kind !== 'rich' && /<[a-z][^>]*>/i.test(String(target.source?.value || ''));
        const formButton = ownField && ((target.kind === 'rich' && !tiny && config.tinymce && !narrow()) || tagged)
            ? h('button', { type: 'button', class: 'tl_submit nfe-button', title: t(target.kind === 'rich' ? 'open_tinymce_title' : 'open_source'), onmousedown: (event) => event.preventDefault(), onclick: () => openForm(state.editing.id, ownField) }, [icon(target.kind === 'rich' ? 'edit' : 'code') || '', h('span', { text: t(target.kind === 'rich' ? 'open_tinymce' : 'open_source') })])
            : null;

        if (!target || (!['headline', 'rich'].includes(target.kind) && !tagged)) {
            return;
        }

        const name = target.label || t(target.field === 'headline' ? 'headline_field' : 'text_field');
        // On a phone the tools are behind one button (the name of the field) that opens them as a list; TinyMCE is
        // not offered there (its toolbar does not fit).
        const compact = narrow();
        const tools = [];
        const separator = () => (compact ? null : h('span', { class: 'nfe-sep', 'aria-hidden': 'true' }));

        format.setAttribute('aria-label', name);
        format.classList.toggle('nfe-format--compact', compact);

        if (target.kind === 'headline') {
            const select = h('select', {
                class: 'tl_select nfe-level',
                'aria-label': t('headline_level'),
                onchange: (event) => setHeadlineLevel(event.target.value),
            }, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].map((unit) => h('option', { value: unit, text: unit.toUpperCase(), selected: unit === state.editing.values.unit })));

            tools.push(compact ? h('label', { class: 'nfe-format__level' }, [h('span', { text: t('headline_level') }), select]) : select);
        } else if (target.kind === 'rich' && !tinyOn) {
            // On the phone each button names what it does; on wider screens the symbol says it (and its tooltip).
            const formatButton = (labelText, content, action, name) => h('button', {
                type: 'button',
                class: compact ? 'tl_submit nfe-button' : 'tl_submit nfe-button nfe-button--icon',
                title: labelText,
                'aria-label': compact ? null : labelText,
                'data-command': name,
                'aria-pressed': name ? 'false' : null,
                onmousedown: (event) => event.preventDefault(),
                onclick: action,
            }, [content, compact ? h('span', { text: labelText }) : null]);

            tools.push(
                formatButton(t('bold'), glyph('B', 'nfe-glyph--bold'), () => command('bold'), 'bold'),
                formatButton(t('italic'), glyph('I', 'nfe-glyph--italic'), () => command('italic'), 'italic'),
                formatButton(t('link'), icon('pickpage') || glyph('↗'), () => pickLink(), null),
                formatButton(t('list'), glyph('•≡'), () => command('insertUnorderedList'), 'insertUnorderedList'),
                formatButton(t('paragraph'), glyph('¶'), () => command('formatBlock', 'p'), null),
            );
        }

        if (tiny && !compact) {
            tools.push(
                tinyOn ? null : separator(),
                h('button', {
                    type: 'button',
                    class: 'tl_submit nfe-button',
                    title: t('html_editor_title'),
                    'aria-pressed': tinyOn ? 'true' : 'false',
                    'data-tinymce': '',
                    onmousedown: (event) => event.preventDefault(),
                    onclick: () => toggleTinyMce(target),
                }, [icon('code') || '', h('span', { text: t('html_editor') })]),
            );
        }

        if (formButton) {
            tools.push(separator(), formButton);
        }

        // Pro: writing aid for the headline or the text.
        if (editions().writingAid && target.source?.scope === 'own' && (target.field === 'headline' || target.field === 'text')) {
            tools.push(
                separator(),
                h('button', { type: 'button', class: 'tl_submit nfe-button', 'data-aid': '', 'aria-pressed': state.view === 'aid' ? 'true' : 'false', onmousedown: (event) => event.preventDefault(), onclick: openWritingAid }, [glyph('✦'), h('span', { text: t('writing_aid') })]),
            );
        }

        if (compact) {
            // The tools as entries of a dropdown below the button, like the element's menu.
            for (const tool of tools) {
                if (tool?.tagName === 'BUTTON') {
                    tool.className = 'nfe-menu__item';
                }
            }

            const list = h('div', { class: 'nfe-menu nfe-format__menu', id: 'nfe-format-menu', hidden: true }, tools.filter(Boolean));
            const toggle = h('button', {
                type: 'button',
                class: 'tl_submit nfe-button nfe-format__toggle',
                title: t('format_menu'),
                'aria-expanded': 'false',
                'aria-controls': 'nfe-format-menu',
                onmousedown: (event) => event.preventDefault(),
                onclick: () => openFormatMenu(list.hidden),
            }, [h('span', { class: 'nfe-format__field', text: name }), h('span', { class: 'nfe-type__chevron', 'aria-hidden': 'true' })]);

            // A tool closes the dropdown again once it did its work.
            list.addEventListener('click', (event) => {
                if (event.target.closest('button')) {
                    openFormatMenu(false);
                }
            });
            list.addEventListener('change', () => openFormatMenu(false));
            state.formatMenu = { list, toggle };
            format.append(toggle, list);
        } else {
            format.append(h('span', { class: 'nfe-format__field', text: name }), ...tools.filter(Boolean));
        }

        format.hidden = false;
        format.toggleAttribute('data-fit', !compact);
        placeSelection();

        if (!compact) {
            fitRow(format);
        }

        updateFormatState();
    }

    // The dropdown of the text tools on a phone (buildFormat): below the tab. Opening it scrolls the page by just
    // enough for the whole list below the tab (as long as the tab stays in the window); where the page cannot scroll
    // further the list scrolls in itself, and only without room for a few entries it opens above the tab. A tap
    // outside closes it.
    function openFormatMenu(open) {
        const menu = state.formatMenu;

        if (!menu?.list.isConnected) {
            return;
        }

        menu.list.hidden = !open;
        menu.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        pageDoc?.removeEventListener('pointerdown', closeFormatMenu, true);

        if (open) {
            pageDoc?.addEventListener('pointerdown', closeFormatMenu, true);
            placeFormatMenu(true);
        }
    }

    function closeFormatMenu(event) {
        if (!event.composedPath().includes(format)) {
            openFormatMenu(false);
        }
    }

    function placeFormatMenu(reveal = false) {
        const menu = state.formatMenu;

        if (!menu || menu.list.hidden || !menu.list.isConnected) {
            return;
        }

        const { width, height } = pageView();

        menu.list.style.maxHeight = '';

        const listHeight = menu.list.offsetHeight;
        let tab = format.getBoundingClientRect();

        if (reveal && pageWin) {
            const missing = Math.ceil(tab.bottom + 2 + listHeight - (height - 4));
            const shift = Math.min(missing, Math.floor(tab.top - 4));

            if (shift > 0) {
                pageWin.scrollBy({ top: shift, left: 0, behavior: 'instant' });
                placeSelection();
                tab = format.getBoundingClientRect();
            }
        }

        const below = tab.bottom + 2;
        const room = height - 4 - below;
        const down = room >= Math.min(listHeight, 120);

        Object.assign(menu.list.style, {
            left: Math.max(4, Math.min(tab.left, width - menu.list.offsetWidth - 4)) + 'px',
            top: (down ? below : Math.max(4, tab.top - 2 - listHeight)) + 'px',
            maxHeight: down && room < listHeight ? room + 'px' : '',
        });
    }

    // The field that gets the focus gets the toolbar.
    function focusTarget(event) {
        const target = state.editing?.targets.find((entry) => entry.node === event.currentTarget);

        if (target && target !== state.editing.focused) {
            state.editing.focused = target;
            buildFormat(target);
        }
    }

    function setHeadlineLevel(unit) {
        const target = state.editing.targets.find((entry) => entry.kind === 'headline' && entry.source?.scope === 'own');

        if (!target || target.node.tagName.toLowerCase() === unit) {
            return;
        }

        // Swap the tag on the page so the change is visible before saving.
        const replacement = pageDoc.createElement(unit);

        for (const attribute of target.node.attributes) {
            replacement.setAttribute(attribute.name, attribute.value);
        }

        replacement.append(...target.node.childNodes);
        replacement.addEventListener('input', markDirty);
        replacement.addEventListener('paste', pastePlain);
        replacement.addEventListener('focus', focusTarget);
        target.node.replaceWith(replacement);
        target.node = replacement;
        state.editing.values.unit = unit;
        markDirty();
        placeSelection();
    }

    function updateFormatState() {
        for (const node of format.querySelectorAll('[data-command]')) {
            let active = false;

            try {
                active = pageDoc.queryCommandState(node.dataset.command);
            } catch (e) {
                active = false;
            }

            node.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
    }

    // The buttons of the text tools show whether the text at the cursor is bold, italic, a list.
    function onSelectionChange() {
        if (state.editing) {
            updateFormatState();
        }
    }

    // ------------------------------------------------------------------ image

    function startImage(img, data) {
        const image = data.values.image || { alt: '', caption: '', uuid: '' };
        const altField = h('input', { type: 'text', class: 'tl_text', value: image.alt, id: 'nfe-alt', oninput: (event) => { state.editing.values.alt = event.target.value; markDirty(); } });
        const chosen = h('span', { class: 'nfe-image__file', text: image.path ? image.path.split('/').pop() : '' });

        imageTools.replaceChildren(
            button(t('choose_image'), { icon: 'image', onclick: () => pickImage(data, chosen) }),
            chosen,
            h('label', { for: 'nfe-alt', text: t('alt') }),
            altField,
        );

        if (!data.fields.includes('alt')) {
            altField.disabled = true;
        }

        imageTools.hidden = false;
        imageTools.setAttribute('data-fit', '');
        placeSelection();
        fitRow(imageTools);

        // The caption is edited where it is shown.
        const caption = img.closest('figure')?.querySelector('figcaption');

        if (caption && data.fields.includes('caption') && !state.editing.targets.some((target) => target.field === 'caption' || target.node === caption)) {
            const target = { field: 'caption', kind: 'caption', node: caption, source: { editable: true, scope: 'own' } };

            target.original = { html: caption.innerHTML, text: editedText(caption), clone: caption.cloneNode(true), tag: caption.tagName };
            state.editing.targets.push(target);
            caption.contentEditable = 'true';
            caption.addEventListener('input', markDirty);
            caption.addEventListener('paste', pastePlain);
        }

        window.setTimeout(() => altField.focus(), 0);
    }

    // ------------------------------------------------------------------ Contao's pickers

    function openPicker(params) {
        return new Promise((resolve) => {
            const query = new URLSearchParams({ ...params, origin: location.origin });
            const overlay = h('iframe', { class: 'nfe-picker', title: params.title || '', src: config.api + '/picker?' + query.toString() });
            const backendOrigin = new URL(config.api, location.href).origin;
            const listener = (event) => {
                if (event.origin !== backendOrigin || event.source !== overlay.contentWindow || !event.data || typeof event.data !== 'object') {
                    return;
                }

                if (event.data.type === 'nfe:picked' || event.data.type === 'nfe:closed') {
                    window.removeEventListener('message', listener);
                    overlay.remove();
                    resolve(event.data.type === 'nfe:picked' ? String(event.data.value || '') : null);
                }
            };

            window.addEventListener('message', listener);
            root.append(overlay);
        });
    }

    async function pickLink() {
        const selection = pageDoc.getSelection();
        const range = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
        const existing = selection.anchorNode?.parentElement?.closest('a');
        const value = await openPicker({ context: 'link', value: existing ? existing.getAttribute('href') : '', title: t('link') });

        if (value === null) {
            return;
        }

        const targets = state.editing?.targets.filter((entry) => entry.kind === 'rich') || [];
        const target = targets.find((entry) => range && entry.node.contains(range.commonAncestorContainer)) || targets[0];

        if (!target) {
            return;
        }

        target.node.focus();

        if (range) {
            selection.removeAllRanges();
            selection.addRange(range);
        }

        if (existing) {
            existing.setAttribute('href', value);
            markDirty();
        } else if (range && !range.collapsed) {
            command('createLink', value);
        }
    }

    async function pickImage(data, chosen) {
        const value = await openPicker({ context: 'image', element: data.id, value: state.editing.values.singleSRC || data.values.image?.uuid || '', title: t('choose_image') });

        if (value) {
            state.editing.values.singleSRC = value.split(',')[0];
            chosen.textContent = state.editing.values.singleSRC;
            markDirty();
        }
    }

    // ------------------------------------------------------------------ dialogs

    // A dialog over the view: the focus goes into it and stays there (Tab), Esc closes it like its last button, the
    // focus goes back where it was. Resolves with the value of the button pressed (a function: its result).
    function openDialog({ title, text = '', body = null, buttons }) {
        return new Promise((resolve) => {
            const before = root.activeElement || document.activeElement;
            const id = 'nfe-dialog-' + Date.now();
            let overlay = null;
            const done = (entry) => {
                overlay.remove();
                before?.focus?.();
                resolve(typeof entry.value === 'function' ? entry.value() : entry.value);
            };
            const nodes = buttons.map((entry) => button(entry.label, { className: entry.primary ? 'nfe-button--primary' : '', onclick: () => done(entry) }));
            const box = h('div', { class: 'nfe-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id + '-title', 'aria-describedby': text ? id + '-text' : null }, [
                h('h2', { class: 'nfe-dialog__title', id: id + '-title', text: title }),
                text ? h('p', { class: 'nfe-dialog__text', id: id + '-text', text }) : null,
                body,
                h('div', { class: 'nfe-dialog__buttons' }, nodes),
            ]);

            overlay = h('div', { class: 'nfe-dialog-backdrop' }, [box]);
            overlay.style.top = toolbarHeight() + 'px';
            box.addEventListener('keydown', (event) => {
                const focusable = [...box.querySelectorAll('button, input, textarea, select')];

                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    done(buttons[buttons.length - 1]);
                } else if (event.key === 'Tab' && focusable.length) {
                    const index = focusable.indexOf(root.activeElement);
                    const next = event.shiftKey ? (index <= 0 ? focusable.length - 1 : index - 1) : (index + 1) % focusable.length;

                    event.preventDefault();
                    focusable[next].focus();
                } else if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
                    event.preventDefault();
                    done(buttons.find((entry) => entry.primary) || buttons[0]);
                }
            });
            root.append(overlay);
            (body?.querySelector('input, textarea') || nodes[0]).focus();
        });
    }

    // Before an action that loads the page again or opens Contao's form of a record: a text being edited is left,
    // and changes that wait for Save are saved or dropped in one dialog (Save, Discard, Cancel). True: go on.
    async function settle() {
        if (state.editing) {
            parkEditing();
        }

        if (!state.pending.length) {
            return true;
        }

        const choice = await openDialog({
            title: t('unsaved_title'),
            text: t('unsaved_text'),
            buttons: [
                { label: t('save'), value: 'save', primary: true },
                { label: t('discard'), value: 'discard' },
                { label: t('unsaved_cancel'), value: null },
            ],
        });

        if (choice === 'save') {
            await save();

            return !state.pending.length && !state.error;
        }

        if (choice === 'discard') {
            await cancel();

            return true;
        }

        return false;
    }

    // A short text in a dialog (alternative text, caption); null when it is closed without applying.
    function askText(title, label, value) {
        const input = h('input', { type: 'text', class: 'tl_text', id: 'nfe-ask', value: value || '' });

        return openDialog({
            title,
            body: h('div', { class: 'nfe-dialog__field' }, [h('label', { for: 'nfe-ask', text: label }), input]),
            buttons: [
                { label: t('apply'), value: () => input.value, primary: true },
                { label: t('unsaved_cancel'), value: null },
            ],
        });
    }

    // ------------------------------------------------------------------ save and cancel

    // The edited values by record: the element's own fields and rows (POST /element), the fields of its child
    // records and of a module (POST /record). Values that did not change are not sent.
    function collect() {
        const editing = state.editing;
        const earlier = editing.earlier;
        const own = { fields: { ...(earlier?.own.fields || {}) }, rows: { ...(earlier?.own.rows || {}) } };
        const records = new Map((earlier?.records || []).map((record) => [record.table + '.' + record.id, record]));
        const unmapped = [];

        for (const target of editing.targets) {
            const source = target.source || {};

            if (target.kind === 'image') {
                continue;
            }

            if (target.kind === 'caption') {
                if (target.node.innerHTML !== target.original?.html) {
                    own.fields.caption = target.node.textContent.trim();
                }

                continue;
            }

            if (!target.node?.isConnected) {
                continue;
            }

            const stored = source.value ?? target.value;
            const value = targetValue(target, stored);
            const unitChanged = target.kind === 'headline' && source.field === 'headline' && source.scope === 'own' && editing.values.unit !== (editing.data.values?.headline?.unit || source.unit);

            // A change the stored value cannot take over (inside what an insert tag or the template shows there).
            if (value === null) {
                unmapped.push(target);
                continue;
            }

            // Unchanged values (against the stored one) are not sent: the widget would store them again (an array in
            // another order, for example).
            if (value === stored && !unitChanged) {
                continue;
            }

            const key = source.scope === 'own' ? null : source.table + '.' + source.id;
            const into = key ? records.get(key) || { table: source.table, id: source.id, element: source.scope === 'child' ? source.owner ?? editing.id : 0, fields: {}, rows: {} } : own;

            if (key) {
                records.set(key, into);
            }

            if (source.row !== null && source.row !== undefined) {
                into.rows[source.field] ??= {};

                if (source.col !== null && source.col !== undefined) {
                    into.rows[source.field][source.row] ??= {};
                    into.rows[source.field][source.row][source.col] = value;
                } else {
                    into.rows[source.field][source.row] = value;
                }
            } else if (source.kind === 'headline' || source.kind === 'unit') {
                const unit = target.kind === 'headline' && source.field === 'headline' && source.scope === 'own' ? editing.values.unit : source.unit;

                into.fields[source.field] = { value, unit: unit || '' };
            } else {
                into.fields[source.field] = value;
            }
        }

        if (editing.values.alt !== undefined) {
            own.fields.alt = editing.values.alt;
        }

        if (editing.values.singleSRC) {
            own.fields.singleSRC = editing.values.singleSRC;
        }

        return { own, records: [...records.values()], unmapped };
    }

    // The value of an edited target as it is saved: the stored value with the change the page shows, or null when
    // the change cannot be put into it. Unchanged: the stored value itself.
    function targetValue(target, stored) {
        if (target.kind === 'rich') {
            return richValue(target, stored);
        }

        const lines = target.kind === 'lines';
        const after = editedText(target.node);

        // A whole value put in by a proposal (translation, writing aid) is the value.
        if (target.replaced) {
            return squashText(after, lines);
        }

        return mapPlain(String(stored ?? ''), target.original?.text ?? after, after, lines);
    }

    // Spaces of a plain text as they are saved: one space, lines kept where the field has lines.
    function squashText(text, lines) {
        return lines
            ? String(text).split('\n').map((line) => line.replace(/\s+/g, ' ').trim()).join('\n').trim()
            : String(text).replace(/\s+/g, ' ').trim();
    }

    // The change between the text the page showed and the edited one, put into the stored value. Where the page
    // shows the stored value (spaces aside) the edited text is the value; otherwise (an insert tag, a shortened
    // teaser, quotation marks of the template) the changed stretch is put at the same place of the stored value
    // when it lies where both agree (at their start or their end), else null.
    function mapPlain(stored, before, after, lines) {
        const value = squashText(stored, lines);
        const shown = squashText(before, lines);
        const edited = squashText(after, lines);

        if (edited === shown) {
            return stored;
        }

        if (shown === value) {
            return edited;
        }

        const common = (a, b, limit = Infinity) => {
            let n = 0;

            while (n < a.length && n < b.length && n < limit && a[n] === b[n]) {
                n += 1;
            }

            return n;
        };
        const reverse = (text) => [...text].reverse().join('');
        const start = common(shown, edited);
        const end = common(reverse(shown), reverse(edited), Math.min(shown.length, edited.length) - start);
        const insert = edited.slice(start, edited.length - end);
        const head = common(shown, value);
        const tail = common(reverse(shown), reverse(value), Math.min(shown.length, value.length) - head);

        if (shown.length - end <= head) {
            return value.slice(0, start) + insert + value.slice(shown.length - end);
        }

        if (start >= shown.length - tail) {
            const shift = value.length - shown.length;

            return value.slice(0, start + shift) + insert + value.slice(shown.length - end + shift);
        }

        return null;
    }

    // Leaves the texts being edited without losing them: changed values wait for Save (Cancel drops them) and the
    // page keeps showing them; editing the part again starts from them.
    function parkEditing() {
        const editing = state.editing;

        if (!editing) {
            return;
        }

        const part = editing.id ?? editing.item;

        if (state.dirty) {
            const values = collect();

            state.pending = state.pending.filter((entry) => entry.part !== part);

            // Typed and taken back: nothing waits.
            if (hasChanges(values) || editing.proposal) {
                state.pending.push({ part, values, targets: editing.targets, editValues: { ...editing.values }, proposal: Boolean(editing.proposal) });
            }
        }

        stopEditing(true);
        state.dirty = state.pending.length > 0;
        renderStatus();
    }

    const hasChanges = (values) => Boolean(Object.keys(values.own.fields).length || Object.keys(values.own.rows).length || values.records.length || values.unmapped?.length);

    // The edited rich text. Where the page shows the stored HTML, the edited HTML is the value; otherwise (insert
    // tags, markup a listener adds) the changed texts are put into the stored HTML, which needs the same markup as
    // before (else null). A single paragraph edited in place gets its element (with its attributes) back.
    function richValue(target, stored) {
        // With TinyMCE: its content (without the markup it adds while it edits).
        const edited = target.editor ? tinyContent(target) : target.node.innerHTML;

        if (edited === null || edited === target.original?.html) {
            return stored;
        }

        const shell = target.outer ? fragment(String(stored)).firstElementChild : null;
        const inner = shell ? shell.innerHTML : String(stored ?? '');
        const html = target.replaced || htmlKey(target.original?.html ?? '') === htmlKey(inner)
            ? cleanHtml(edited)
            : alignTexts(inner, target.original.clone, target.editor ? Object.assign(pageDoc.createElement('div'), { innerHTML: edited }) : target.node);

        if (html === null || !shell) {
            return html;
        }

        const element = shell.cloneNode(false);

        element.innerHTML = html;

        return element.outerHTML;
    }

    // HTML compared without the editor's comments, spaces between tags and the tags browsers write differently.
    function htmlKey(html) {
        return cleanHtml(html).replace(/\s+/g, ' ').replace(/>\s+</g, '><').trim();
    }

    // The texts changed in an edited node, put into the stored HTML: each changed text node of the page found once in
    // a text of the stored HTML (spaces aside). Null when the markup changed or a text is not found once.
    function alignTexts(storedHtml, beforeNode, afterNode) {
        const before = beforeNode.cloneNode(true);
        const after = afterNode.cloneNode(true);
        const textsOf = (node) => {
            const list = [];
            const walker = node.ownerDocument.createTreeWalker(node, NodeFilter.SHOW_TEXT);
            let text;

            while ((text = walker.nextNode())) {
                list.push(text);
            }

            return list;
        };
        const skeleton = (node) => [...node.querySelectorAll('*')].map((element) => element.tagName).join(',');

        before.normalize();
        after.normalize();

        const old = textsOf(before);
        const now = textsOf(after);

        if (skeleton(before) !== skeleton(after) || old.length !== now.length) {
            return null;
        }

        const box = document.createElement('div');

        box.innerHTML = storedHtml;

        const pool = textsOf(box);
        const squash = (text) => text.replace(/\s+/g, ' ');

        for (let index = 0; index < old.length; index += 1) {
            if (old[index].data === now[index].data) {
                continue;
            }

            const core = squash(old[index].data).trim();
            const exact = core ? pool.filter((node) => squash(node.data).includes(core)) : [];
            let target = null;
            let value = null;

            if (exact.length === 1 && squash(exact[0].data).split(core).length === 2) {
                // The text of the page is a text of the stored HTML (or a part of one): replaced there.
                let text = squash(now[index].data);

                // The spaces around the old text belong to the template's markup, not to the stored text.
                if (/^\s/.test(old[index].data) && text.startsWith(' ')) {
                    text = text.slice(1);
                }

                if (/\s$/.test(old[index].data) && text.endsWith(' ')) {
                    text = text.slice(0, -1);
                }

                target = exact[0];
                value = squash(target.data).replace(core, () => text);
            } else {
                // The page shows it otherwise (an insert tag replaced, quotation marks of the template): the stored
                // text that agrees with it most at its start and end, the change put where both agree.
                const agree = (text) => {
                    const a = squash(text).trim();
                    let head = 0;
                    let tail = 0;

                    while (head < a.length && head < core.length && a[head] === core[head]) {
                        head += 1;
                    }

                    while (tail < a.length - head && tail < core.length - head && a[a.length - 1 - tail] === core[core.length - 1 - tail]) {
                        tail += 1;
                    }

                    return head + tail;
                };
                const ranked = pool.map((node) => ({ node, score: agree(node.data) })).filter((entry) => entry.score > 0).sort((a, b) => b.score - a.score);

                if (core && ranked.length && !(ranked[1] && ranked[1].score === ranked[0].score)) {
                    const mapped = mapPlain(ranked[0].node.data, old[index].data, now[index].data, false);

                    target = ranked[0].node;
                    value = mapped === null || mapped === target.data ? mapped : target.data.match(/^\s*/)[0] + mapped + target.data.match(/\s*$/)[0];
                }
            }

            if (!target || value === null) {
                return null;
            }

            target.data = value;
        }

        return cleanHtml(box.innerHTML);
    }

    // Browsers write <b> and <i>; Contao's editor writes <strong> and <em>.
    function cleanHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html;

        for (const [from, to] of [['b', 'strong'], ['i', 'em']]) {
            for (const node of template.content.querySelectorAll(from)) {
                const replacement = document.createElement(to);
                replacement.append(...node.childNodes);
                node.replaceWith(replacement);
            }
        }

        // The editor's comments (source map, parts) are not part of the value.
        const walker = document.createTreeWalker(template.content, NodeFilter.SHOW_COMMENT);
        const comments = [];
        let comment;

        while ((comment = walker.nextNode())) {
            if (/^\/?nfe:/.test(comment.data)) {
                comments.push(comment);
            }
        }

        comments.forEach((node) => node.remove());

        return template.innerHTML;
    }

    async function save() {
        const editing = state.editing;
        const batches = [...state.pending];

        if (editing && state.dirty) {
            batches.push({ part: editing.id ?? editing.item, values: collect(), proposal: Boolean(editing.proposal) });
        }

        // Nothing changed (typed and taken back): the edit ends, the page stays as it is.
        if (!batches.some((batch) => hasChanges(batch.values) || batch.proposal)) {
            state.pending = [];
            stopEditing(true);
            state.dirty = false;
            renderStatus();

            return;
        }

        // A change that cannot be put into its field: nothing is saved, the message names the field.
        const unmapped = batches.flatMap((batch) => batch.values.unmapped || []);

        if (unmapped.length) {
            state.error = true;
            renderStatus();
            showToast(t('change_unmapped', { name: unmapped[0].label || unmapped[0].field }));
            (unmapped[0].within || unmapped[0]).node?.focus();

            return;
        }

        // The Save buttons show that it runs, also after Ctrl+S (no click) and while the page loads again.
        holdAction('save', 1);

        try {
            const refresh = { elements: new Set(), items: new Set() };

            for (const batch of batches.filter((entry) => hasChanges(entry.values) || entry.proposal)) {
                await sendBatch(batch, refresh);
                state.pending = state.pending.filter((entry) => entry !== batch);
            }

            if (editing) {
                stopEditing(true);
            }

            state.pending = [];
            state.dirty = false;

            // The page already shows what was saved where it was edited in place and Contao renders the saved state
            // the same way: the page stays as it is (with the state its scripts gave it). Otherwise it loads again.
            if (await showsSaved(refresh)) {
                await loadModuleTexts([...refresh.items].filter(hasTexts));
                targetCache.clear();
            } else {
                await reloadFrame();
            }

            if (batches.some((batch) => batch.proposal)) {
                await loadStructure();
                renderStructure();
            }

            if (state.item) {
                showItem(state.item);
            } else {
                showAt(state.selected);
            }

            renderStatus();
            announce(t('saved'));
            afterChange();
        } catch (error) {
            state.error = true;
            renderStatus();
            showToast(error.status ? error.message : t('save_failed'), error.status ? null : save);
        } finally {
            holdAction('save', -1);
        }
    }

    // One part's changed values: the element's own fields (POST /element), the other records (child records,
    // modules, other elements). The parts whose places on the page show them are noted for refreshing.
    async function sendBatch(batch, refresh) {
        const { own, records } = batch.values;
        const changed = [];

        if (typeof batch.part === 'number') {
            refresh.elements.add(batch.part);

            if (Object.keys(own.fields).length || Object.keys(own.rows).length) {
                changed.push((await api('/element/' + batch.part, { method: 'POST', body: JSON.stringify({ fields: own.fields, rows: own.rows, synced: batch.proposal }) })).element);
            }
        } else {
            refresh.items.add(batch.part);
        }

        for (const record of records) {
            // Another element of the page: through its own endpoint.
            const result = record.table === 'tl_content'
                ? await api('/element/' + record.id, { method: 'POST', body: JSON.stringify({ fields: record.fields, rows: record.rows }) })
                : await api('/record/' + record.table + '/' + record.id, { method: 'POST', body: JSON.stringify({ element: record.element, fields: record.fields, rows: record.rows, page: config.page }) });

            if (result.element) {
                changed.push(result.element);
            }

            if (record.table === 'tl_module') {
                refresh.items.add('fm:' + record.id);
            } else if (record.table === 'tl_content' || record.element) {
                refresh.elements.add(record.table === 'tl_content' ? record.id : record.element);
            }
        }

        for (const data of changed.filter(Boolean)) {
            replaceElementData(data);
        }
    }

    function stopEditing(saved) {
        const editing = state.editing;

        if (!editing) {
            return;
        }

        for (const target of editing.targets) {
            detachTinyMce(target);
            target.node.removeAttribute('contenteditable');
            target.node.removeAttribute('data-nfe-writing');
            target.node.removeEventListener('input', markDirty);
            target.node.removeEventListener('paste', pastePlain);
            target.node.removeEventListener('keydown', keepOneLine);
            target.node.removeEventListener('focus', focusTarget);
        }

        if (!saved) {
            for (const target of editing.targets) {
                const original = target.original;

                if (!original || target.within || !target.node) {
                    continue;
                }

                // A headline whose level was changed gets its old tag back.
                if (target.node.tagName !== original.tag) {
                    const restored = target.node.ownerDocument.createElement(original.tag.toLowerCase());

                    for (const attribute of target.node.attributes) {
                        restored.setAttribute(attribute.name, attribute.value);
                    }

                    target.node.replaceWith(restored);
                    target.node = restored;
                }

                // Only what was changed is put back: the nodes of the page (and what its scripts hold) stay.
                if (target.node.innerHTML !== original.html) {
                    target.node.innerHTML = original.html;
                }

                delete target.original;
            }
        }

        // Wrappers around values that share their parent with other content go again.
        editing.targets.forEach(unwrap);

        state.editing = null;
        // Changes of other parts may still wait for Save.
        state.dirty = state.pending.length > 0;
        state.error = false;
        format.hidden = true;
        imageTools.hidden = true;
        targetCache.clear();

        if (state.view === 'aid') {
            state.view = 'structure';
            closeAutoOpened();
            renderPanel();
        }
        hideToast();

        if (editing.item) {
            showItem(editing.item);
        } else {
            showAt(editing.id);
        }

        renderStructure();
        renderStatus();
    }

    // Cancel drops every waiting change: the parts show what is stored again.
    async function cancel() {
        const waiting = state.pending;

        state.pending = [];
        stopEditing(false);
        state.dirty = false;
        renderStatus();

        // The texts of the parts left before are put back where they are; a part whose places the page no longer
        // shows is rendered again.
        for (const entry of waiting) {
            if (!restoreTargets(entry.targets)) {
                await (typeof entry.part === 'string' ? refreshItem(entry.part) : refreshElement(entry.part));
            }
        }

        if (state.item) {
            showItem(state.item);
        } else {
            showAt(state.selected);
        }
    }

    // Puts the page's text back into edited places (as it was before the first change); false when one of them is gone.
    function restoreTargets(targets) {
        let complete = true;

        for (const target of targets) {
            const original = target.original;

            if (!original || target.within) {
                continue;
            }

            if (!(target.node || target.textNode || target.pair?.start)?.isConnected) {
                complete = false;
                continue;
            }

            holderOf(target);

            if (target.node.tagName !== original.tag) {
                const restored = target.node.ownerDocument.createElement(original.tag.toLowerCase());

                for (const attribute of target.node.attributes) {
                    restored.setAttribute(attribute.name, attribute.value);
                }

                target.node.replaceWith(restored);
                target.node = restored;
            }

            if (target.node.innerHTML !== original.html) {
                target.node.innerHTML = original.html;
            }

            unwrap(target);
            delete target.original;
        }

        targetCache.clear();

        return complete;
    }

    // After a change Contao has to render (all fields, an image, a link, a translation, an insert tag): the page in
    // the frame loads again, so the website's own scripts (elements that appear when scrolled into view, counters,
    // sliders, lazy images) start on it as on any load. Swapping the element's HTML left them without their scripts
    // (a section that appears on scrolling stayed invisible). Scroll position, selection and the sidebar stay.
    function refreshElement(id) {
        return reloadFrame(state.item ? {} : { select: state.selected ?? id });
    }

    function refreshItem(key) {
        return reloadFrame(state.item === key ? { item: key } : {});
    }

    // Loads the page in the frame again and resolves once the editor is attached to it; calls before that share one
    // reload. A page that does not come back within 20 seconds lets the callers go on.
    function reloadFrame(restore = {}) {
        if (!state.reloadWait) {
            state.reloadWait = new Promise((resolve) => {
                const done = () => {
                    window.clearTimeout(timer);
                    state.reloadWait = null;
                    state.reloadDone = null;
                    resolve();
                };
                const timer = window.setTimeout(done, 20000);

                state.reloadDone = done;
            });
            reloadPage({ select: state.item ? null : state.selected, item: state.item, keepScroll: true, ...restore });
        }

        return state.reloadWait;
    }

    // Whether the parts that were saved show what Contao renders for the saved state: the same elements and the same
    // text (spaces aside) in the page as in a fresh rendering of it. Anything else (a value the template or an insert
    // tag changes, formatting Contao writes otherwise, a value shown in a further place, a script that changed the
    // part) needs the page to load again.
    async function showsSaved(refresh) {
        const doc = pageDoc;

        try {
            const response = await fetch(frameUrl(pageWin.location.href), { credentials: 'include', headers: { Accept: 'text/html' } });
            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').body;

            if (doc !== pageDoc) {
                return false;
            }

            const elements = findRanges(fresh);
            const items = findItems(fresh);
            const pairs = [...[...refresh.elements].map((id) => [state.ranges.get(id), elements.get(id)]), ...[...refresh.items].map((key) => [state.items?.get(key), items.get(key)])];

            return pairs.length > 0 && pairs.every(([now, saved]) => now && saved && partSignature(nodesOf(now)) === partSignature(nodesOf(saved)));
        } catch (e) {
            return false;
        }
    }

    // The elements (by tag) and the text of the nodes of a part, without the editor's own nodes.
    function partSignature(nodes) {
        const tags = [];
        let text = '';
        const walk = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                text += node.data;
            } else if (node.nodeType === Node.ELEMENT_NODE && node !== pageHost && !/^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT|NFE-EDITOR)$/.test(node.tagName)) {
                tags.push(node.tagName);
                node.childNodes.forEach(walk);
            }
        };

        nodes.forEach(walk);

        return tags.join(',') + '|' + text.replace(/[\s\u00a0]+/g, ' ').trim();
    }

    function replaceElementData(element) {
        for (const article of state.structure.articles) {
            const index = article.elements.findIndex((entry) => entry.id === element.id);

            if (index !== -1) {
                article.elements[index] = element;
            }
        }
    }

    // In the view and in the page of the frame.
    function onKeydown(event) {
        // Keys in TinyMCE's menus and dialogs (Esc closes them) are TinyMCE's.
        if (inTinyMce(event) || partMenuByKey(event) || elementKey(event)) {
            return;
        }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && (state.editing || state.dirty)) {
            event.preventDefault();
            save();
        } else if (event.key === 'Escape' && state.editing && !root.querySelector('.nfe-picker')) {
            // Esc leaves the text (its changes wait for Save); the part stays selected.
            event.preventDefault();
            const range = editingRange();
            parkEditing();
            nodesOf(range)[0]?.focus?.();
        } else if (event.key === 'Escape' && !state.editing && !state.menu && !state.info && !root.querySelector('.nfe-picker, .nfe-dialog') && (state.selected !== null || state.item)) {
            // A second Esc ends the selection.
            event.preventDefault();
            deselect();
        }
    }

    function deselect() {
        state.selected = null;
        state.item = null;
        frame.hidden = label.hidden = insertAbove.hidden = insertBelow.hidden = true;
        hideNote();
        showHover();
        renderStructure();
    }

    // Leaving the view, or the page in the frame, with unsaved changes: the browser asks first.
    function warnUnsaved(event) {
        if (state.dirty && !(state.reloading && event.currentTarget === pageWin)) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('beforeunload', warnUnsaved);

    // ------------------------------------------------------------------ structure: insert, move, duplicate, hide, delete

    // Contao's operations change the page; the page in the frame is loaded again, the editor comes
    // back with the same element selected, the same scroll position and the focus where it was.
    function reloadPage(restore) {
        state.restore = { ...restore, scroll: pageWin?.scrollY || 0 };
        // The editor's own reload: waiting changes are kept in the editor, the browser need not ask.
        state.reloading = true;
        showVeil(true);
        pageWin?.location.replace(frameUrl(pageWin.location.href));
    }

    async function structureCall(path, body, restore) {
        if (state.busy || state.form?.isNew || !(await settle())) {
            return null;
        }

        state.busy = true;
        closeMenu(false);

        // The page changes and loads again afterwards: the veil over it shows that from the start (also after a
        // drag or a key, which have no button).
        if (restore) {
            showVeil(true);
        }

        try {
            const result = await api(path, { method: 'POST', body: JSON.stringify(body || {}) });

            if (restore) {
                reloadPage({ select: result.id, ...restore });
            }

            return result;
        } catch (error) {
            showVeil(false);
            showToast(error.status ? error.message : t('save_failed'));

            return null;
        } finally {
            state.busy = false;
        }
    }

    // Moves within the article: up behind the element before the previous one (or to the top), down behind the next one.
    function moveStep(id, direction, focus) {
        const article = articleOf(id);
        const list = article ? article.elements : [];
        const index = list.findIndex((element) => element.id === id);

        if (index === -1 || (direction < 0 && index === 0) || (direction > 0 && index === list.length - 1)) {
            return;
        }

        let target;

        if (direction > 0) {
            target = { after: list[index + 1].id };
        } else {
            target = index > 1 ? { after: list[index - 2].id } : topOf(article);
        }

        structureCall('/element/' + id + '/move', target, { select: id, focus, announce: t(direction < 0 ? 'move_up' : 'move_down') });
    }

    // Moves before or after another element (drag and drop), in the same or another article of the page.
    function moveTo(id, targetId, before, focus) {
        const article = articleOf(targetId);
        const list = article ? article.elements.filter((element) => element.id !== id) : [];
        const index = list.findIndex((element) => element.id === targetId);

        if (index === -1 || !sameContainer(article, articleOf(id))) {
            return;
        }

        const target = before ? (index > 0 ? { after: list[index - 1].id } : topOf(article)) : { after: targetId };
        const current = articleOf(id).elements;
        const currentIndex = current.findIndex((element) => element.id === id);
        const unchanged = article === articleOf(id) && (target.after ? current[currentIndex - 1]?.id === target.after : currentIndex === 0);

        if (!unchanged) {
            structureCall('/element/' + id + '/move', target, { select: id, focus, announce: t('move') });
        }
    }

    function moveInto(id, articleId, focus) {
        const article = groupById(articleId);
        const last = article?.elements.filter((element) => element.id !== id).pop();

        if (!sameContainer(article, articleOf(id))) {
            return;
        }

        structureCall('/element/' + id + '/move', last ? { after: last.id } : topOf(article), { select: id, focus, announce: t('move') });
    }

    function duplicate(id, focus) {
        structureCall('/element/' + id + '/copy', {}, { focus, announce: t('duplicate') });
    }

    function toggleHidden(id, focus) {
        // The focus comes back to the switch, which tells its state itself.
        structureCall('/element/' + id + '/toggle', {}, { select: id, focus });
    }

    // Deleting asks first, with Contao's own question; the element stays in Contao's undo list.
    function remove(id) {
        if (!window.confirm((config.texts.deleteConfirm || '%s').replace('%s', String(id)))) {
            return;
        }

        const article = articleOf(id);
        const list = article ? article.elements : [];
        const index = list.findIndex((element) => element.id === id);
        const next = list[index + 1] || list[index - 1] || null;

        structureCall('/element/' + id + '/delete', {}, { select: next ? next.id : null, focus: { where: 'tree' }, announce: t('delete') });
    }

    // A new element is a draft until Contao's form is saved: its form opens in the sidebar.
    async function createElement(articleId, afterId) {
        if (state.busy || !(await settle())) {
            return;
        }

        const group = groupById(articleId);
        const path = isPost(group)
            ? '/content/' + group.table + '/' + group.postId + '/element'
            : isNested(group) ? '/element/' + group.parentId + '/nested' : '/article/' + articleId + '/element';
        const result = await structureCall(path, { ...(afterId ? { after: afterId } : {}), ...(isPost(group) ? { page: config.page } : {}) }, null);

        if (result) {
            openRecordForm({ table: 'tl_content', id: result.id, title: t('insert_element'), isNew: true });
        }
    }

    async function createArticle(column) {
        const result = await structureCall('/page/' + config.page + '/article', { column }, null);

        if (result) {
            openRecordForm({ table: 'tl_article', id: result.id, title: t('create_article'), isNew: true });
        }
    }

    // ------------------------------------------------------------------ menu

    // A menu of actions (WAI-ARIA menu button pattern): arrow keys, Home, End, Esc closes and returns the focus.
    // A menu at a button or a point; a second press on the same button closes it again. Entries can come in
    // groups with a heading ({ group: 'Label' } starts one) and with separators ({ separator: true }), carry a note
    // (the field a text is saved into), the keys that do the same (keys: 'Alt+ArrowUp'), a name for tests and the
    // documentation (key: data-action) and, when rights or the edition do not allow them, are shown disabled with the
    // reason as their note. Deleting is marked (danger). $options.last: the focus on the last entry (arrow up).
    function openMenu(anchor, items, labelText, options = {}) {
        if (state.menu?.anchor === anchor) {
            closeMenu(true);

            return;
        }

        closeMenu(false);

        const menu = h('div', { class: 'nfe-menu', role: 'menu', 'aria-label': labelText });
        let into = menu;
        let groups = 0;
        let separate = false;
        const entries = [];

        for (const item of items.filter(Boolean)) {
            // A hint, not an entry: a group with its text as the name, without anything to choose.
            if (item.hint !== undefined) {
                const id = 'nfe-menu-group-' + (groups += 1);

                menu.append(h('div', { class: 'nfe-menu__group', role: 'group', 'aria-labelledby': id }, [h('div', { class: 'nfe-menu__heading nfe-menu__hint', id, text: item.hint })]));
                continue;
            }

            if (item.group !== undefined) {
                const id = 'nfe-menu-group-' + (groups += 1);

                into = h('div', { class: 'nfe-menu__group', role: 'group', 'aria-labelledby': id }, [h('div', { class: 'nfe-menu__heading', id, text: item.group })]);
                menu.append(into);
                separate = false;
                continue;
            }

            // A separator only between entries: none at the start of a group, none twice, none at the end.
            if (item.separator) {
                separate = into.querySelector('.nfe-menu__item') !== null;
                continue;
            }

            if (separate) {
                into.append(h('div', { class: 'nfe-menu__sep', role: 'separator' }));
                separate = false;
            }

            const note = item.disabled && item.reason ? item.reason : item.note;
            const entry = h('button', {
                type: 'button',
                class: 'nfe-menu__item' + (item.danger ? ' nfe-menu__item--danger' : ''),
                role: item.checked === undefined ? 'menuitem' : 'menuitemcheckbox',
                'aria-checked': item.checked === undefined ? null : (item.checked ? 'true' : 'false'),
                'aria-disabled': item.disabled ? 'true' : null,
                'aria-keyshortcuts': item.keys && !item.disabled ? item.keys : null,
                'data-action': item.key,
                tabindex: '-1',
                onclick: () => {
                    if (!item.disabled) {
                        const owner = state.menu?.anchor;

                        // From the tab of a part the focus goes back to the tab first: a window the entry opens returns it
                        // there when it closes.
                        closeMenu(owner === label);
                        // Work the entry starts shows at the button the menu belongs to; without one (the tab, a right
                        // click) a note at the bottom edge says that it runs.
                        lastClick = { node: owner?.matches?.('.nfe-button') ? owner : null, action: owner?.dataset?.action || null, at: performance.now() };
                        item.action();
                    }
                },
            }, [
                item.glyph ? glyph(item.glyph) : (icon(item.icon) || h('span', { class: 'nfe-icon' })),
                h('span', { class: 'nfe-menu__label' }, [h('span', { text: item.label }), note ? h('span', { class: 'nfe-menu__note', text: note }) : null]),
                item.badge ? h('span', { class: 'nfe-plus-badge nfe-menu__badge', text: item.badge }) : null,
                item.keys && !item.disabled ? h('span', { class: 'nfe-menu__keys', 'aria-hidden': 'true', text: keyNames(item.keys) }) : null,
            ]);

            entries.push(entry);
            into.append(entry);
        }

        if (!entries.length) {
            return;
        }
        menu.addEventListener('keydown', (event) => {
            const index = entries.indexOf(root.activeElement);
            const focusAt = (to) => entries[(to + entries.length) % entries.length].focus();

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusAt(index + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                focusAt(index - 1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                focusAt(0);
            } else if (event.key === 'End') {
                event.preventDefault();
                focusAt(entries.length - 1);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeMenu(true);
            } else if (event.key === 'Tab') {
                closeMenu(false);
            }
        });

        root.append(menu);
        anchor.setAttribute('aria-expanded', 'true');
        anchor.setAttribute('aria-haspopup', 'menu');
        state.menu = { node: menu, anchor };

        // Below the anchor, above it when there is no room, else as high as needed; inside the editor's part of the
        // window, below the bar of Contao's preview (an anchor in the page: in the frame's place). A long menu scrolls.
        const rect = viewRect(anchor);
        const minTop = Math.max(4, Math.min(stage.getBoundingClientRect().top, panel.hidden ? Infinity : panel.getBoundingClientRect().top) + 4);

        menu.style.maxHeight = Math.max(160, window.innerHeight - minTop - 4) + 'px';

        const height = menu.offsetHeight;
        const width = menu.offsetWidth;
        let top = rect.bottom + 4;

        if (top + height > window.innerHeight - 4) {
            top = rect.top - 4 - height >= minTop ? rect.top - 4 - height : Math.max(minTop, window.innerHeight - 4 - height);
        }

        Object.assign(menu.style, { top: top + 'px', left: Math.max(4, Math.min(rect.left, window.innerWidth - width - 4)) + 'px' });
        (options.last ? [...entries].reverse() : entries).find((entry) => !entry.hasAttribute('aria-disabled'))?.focus();
    }

    // Keys as the keyboard names them (aria-keyshortcuts: "Alt+ArrowUp"): "Alt + ↑", on Apple devices "⌥ ↑".
    function keyNames(keys) {
        const apple = /Mac|iPhone|iPad/.test(navigator.userAgentData?.platform || navigator.platform || '');
        const names = { Alt: apple ? '⌥' : 'Alt', ArrowUp: '↑', ArrowDown: '↓', Enter: t('key_enter'), Delete: t('key_delete') };

        return keys.split('+').map((key) => names[key] || key).join(apple ? ' ' : ' + ');
    }

    function closeMenu(returnFocus) {
        if (!state.menu) {
            return;
        }

        const { node, anchor, returnTo } = state.menu;

        state.menu = null;
        node.remove();
        anchor.setAttribute('aria-expanded', 'false');

        // Back to the button, or for a context menu opened by key to what had the focus on the page.
        const back = returnTo?.isConnected ? returnTo : anchor;

        if (returnFocus && back.isConnected) {
            back.focus();
        }
    }

    // A press outside the open menu (in the view or in the page) closes it.
    function closeOnOutside(event) {
        if (state.menu && !event.composedPath().includes(state.menu.node) && !event.composedPath().includes(state.menu.anchor)) {
            closeMenu(false);
        }
    }

    document.addEventListener('pointerdown', closeOnOutside, true);

    // ------------------------------------------------------------------ drag and drop

    // Dragging starts after a few pixels, so a click on the handle stays a click. Targets are the
    // elements of the page (on the page) or the rows of the structure (in the sidebar).
    function startDrag(event, id, source, handle = event.currentTarget) {
        if (event.button !== 0 || state.busy) {
            return;
        }

        // Dragging ends an edit (its changes wait; the drop asks about them before the page changes).
        if (state.editing) {
            parkEditing();
        }

        // No text selection and no native dragging of the icon while dragging.
        event.preventDefault();

        const startX = event.clientX;
        const startY = event.clientY;
        let target = null;

        handle.setPointerCapture(event.pointerId);

        const move = (moveEvent) => {
            if (!state.drag) {
                if (Math.hypot(moveEvent.clientX - startX, moveEvent.clientY - startY) < 5) {
                    return;
                }

                state.drag = { id, source };
                host.classList.add('nfe-dragging');
                pageHost?.classList.add('nfe-dragging');
                insertAbove.hidden = insertBelow.hidden = true;
                panel.querySelector('.nfe-row[data-id="' + id + '"]')?.classList.add('nfe-row--dragging');
            }

            target = source === 'page' ? pageTarget(id, moveEvent.clientX, moveEvent.clientY) : treeTarget(id, moveEvent.clientX, moveEvent.clientY);
            showDropTarget(target);
            autoScroll(moveEvent.clientY, source);
        };

        const end = (endEvent) => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', end);
            handle.removeEventListener('pointercancel', end);
            window.removeEventListener('keydown', escape, true);
            pageWin?.removeEventListener('keydown', escape, true);

            const dragged = state.drag;

            if (dragged) {
                draggedAt = performance.now();
            }

            state.drag = null;
            host.classList.remove('nfe-dragging');
            pageHost?.classList.remove('nfe-dragging');
            showDropTarget(null);
            panel.querySelector('.nfe-row--dragging')?.classList.remove('nfe-row--dragging');

            if (!dragged || endEvent.type !== 'pointerup' || !target) {
                showAt(state.selected);

                return;
            }

            const focus = source === 'page' ? { where: 'tab' } : { where: 'tree' };

            if (target.article && target.before) {
                // On the title of an article: to its top.
                const group = groupById(target.article);

                if (sameContainer(group, articleOf(id))) {
                    structureCall('/element/' + id + '/move', topOf(group), { select: id, focus, announce: t('move') });
                }
            } else if (target.article) {
                moveInto(id, target.article, focus);
            } else {
                moveTo(id, target.id, target.before, focus);
            }
        };

        const escape = (keyEvent) => {
            if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                keyEvent.stopPropagation();
                target = null;
                handle.releasePointerCapture(event.pointerId);
                end({ type: 'pointercancel' });
            }
        };

        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
        window.addEventListener('keydown', escape, true);
        pageWin?.addEventListener('keydown', escape, true);
    }

    // The element under the pointer (or the nearest one in the same column) and whether to drop before or after it.
    function pageTarget(id, clientX, clientY) {
        const x = clientX;
        const y = clientY;
        let best = null;
        let bestDistance = Infinity;

        for (const article of state.structure.articles) {
            for (const element of article.elements) {
                const box = element.id === id ? null : boxOf(element.id);

                if (!box) {
                    continue;
                }

                const dx = x < box.x ? box.x - x : (x > box.x + box.w ? x - box.x - box.w : 0);
                const dy = y < box.y ? box.y - y : (y > box.y + box.h ? y - box.y - box.h : 0);
                const distance = dx * 4 + dy;

                if (distance < bestDistance) {
                    best = { id: element.id, before: y < box.y + box.h / 2, box };
                    bestDistance = distance;
                }
            }
        }

        return best;
    }

    function treeTarget(id, clientX, clientY) {
        const node = root.elementsFromPoint ? root.elementsFromPoint(clientX, clientY).find((entry) => entry.closest?.('.nfe-row, .nfe-article')) : null;
        const row = node?.closest('.nfe-row, .nfe-article');

        if (!row || Number(row.dataset.id) === id) {
            return null;
        }

        const rect = row.getBoundingClientRect();

        if (row.classList.contains('nfe-article') || row.classList.contains('nfe-row--insert')) {
            return { article: row.dataset.article, row, before: row.classList.contains('nfe-article') };
        }

        return { id: Number(row.dataset.id), row, before: clientY < rect.top + rect.height / 2 };
    }

    function showDropTarget(target) {
        dropLine.hidden = true;

        for (const row of panel.querySelectorAll('.nfe-drop-before, .nfe-drop-after, .nfe-drop-into')) {
            row.classList.remove('nfe-drop-before', 'nfe-drop-after', 'nfe-drop-into');
        }

        if (!target) {
            return;
        }

        if (target.row) {
            target.row.classList.add(target.article ? 'nfe-drop-into' : (target.before ? 'nfe-drop-before' : 'nfe-drop-after'));

            return;
        }

        const box = target.box;

        Object.assign(dropLine.style, { left: box.x - 6 + 'px', top: (target.before ? box.y - 10 : box.y + box.h + 8) + 'px', width: box.w + 12 + 'px' });
        dropLine.hidden = false;
    }

    function autoScroll(clientY, source) {
        if (source === 'tree') {
            const body = panel.querySelector('.nfe-panel__body');
            const rect = body?.getBoundingClientRect();

            if (rect && clientY < rect.top + 32) {
                body.scrollBy(0, -12);
            } else if (rect && clientY > rect.bottom - 32) {
                body.scrollBy(0, 12);
            }

            return;
        }

        if (clientY < 40) {
            pageWin.scrollBy(0, -16);
        } else if (clientY > pageView().height - 40) {
            pageWin.scrollBy(0, 16);
        }
    }

    // ------------------------------------------------------------------ language versions (Plus)

    const editions = () => state.structure?.editions || {};

    // At an element of a page with language versions: translate it between them, mark as current.
    function translationItems(id, data) {
        const ed = editions();
        const versions = ed.languages || [];
        const items = [];

        // Every element of the page, its posts and the elements in elements: of the main language or of a version.
        if (!versions.length || !(data.translation || versions.some((version) => version.main && version.id === config.page))) {
            return items;
        }

        items.push({ key: 'translate', label: t('tr_window'), icon: 'translate', action: () => openTranslation(id, defaultTranslationTarget(), null, config.page) });

        if (data.canUpdate && data.translation?.status === 'outdated') {
            items.push({ key: 'current', label: t('mark_current'), icon: 'ok', action: () => markCurrent(id) });
        }

        return items;
    }

    // ------------------------------------------------------------------ translation window (Plus)

    // Where a translation of the page shown goes by default: from a version into the main language; from the main
    // language into the version translated into last, otherwise (null) the first one.
    function defaultTranslationTarget() {
        const versions = editions().languages || [];
        const main = versions.find((version) => version.main);

        if (main && main.id !== config.page) {
            return main.id;
        }

        return versions.some((version) => !version.main && version.id === state.translationTarget) ? state.translationTarget : null;
    }

    // Rich text from the database shown or edited in the window: no scripts, no handlers, no active links.
    function safeHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html || '');

        for (const node of template.content.querySelectorAll('script, style, iframe, object, embed, link, meta, form')) {
            node.remove();
        }

        for (const node of template.content.querySelectorAll('*')) {
            for (const attribute of [...node.attributes]) {
                if (attribute.name.startsWith('on') || (/^(href|src|action|formaction|xlink:href)$/i.test(attribute.name) && /^\s*javascript:/i.test(attribute.value))) {
                    node.removeAttribute(attribute.name);
                }
            }
        }

        return template.innerHTML;
    }

    // Every text of an element: its fields (also those of other extensions), its entries and the texts of its images,
    // in the language translated from on the left, in the language translated into on the right; both are chosen
    // above among the language versions of the page ($source and $page: their pages, null for the default). Each
    // text can get a proposal of the translation service; Save writes what changed into the language on the right.
    // $preset: proposals made before (whole page).
    async function openTranslation(id, page = null, preset = null, source = null, record = null) {
        if (state.translation || !(await settle())) {
            return;
        }

        // Where the focus goes back to: in the page of the frame the control itself (the tab of the part), not the frame.
        const before = root.activeElement === pageFrame ? (pageRoot?.activeElement || pageDoc?.activeElement) : root.activeElement;
        const tr = { id, page, source, record, data: null, initial: new Map(), values: new Map(), proposed: new Set(), controls: new Map(), busy: false, message: '', before: before || document.activeElement };

        state.translation = tr;
        tr.overlay = h('div', { class: 'nfe-dialog-backdrop' });
        tr.overlay.style.top = toolbarHeight() + 'px';
        root.append(tr.overlay);
        renderTranslation();
        await loadTranslation(preset);
    }

    // A record translated on its own (a post, its archive, the title and description of a page, a module, an entry
    // of another back-end module): the same window, with the record's version in the other language, which can be
    // linked or made as a copy when there is none. $page: a page in the language translated into (null: the next one).
    function openRecordTranslation(table, id, page = null) {
        return openTranslation(id, page, null, null, { table, id });
    }

    // Language versions to translate into: Plus, the switch for languages and a page with versions.
    const hasVersions = () => (editions().languages || []).length > 1;

    // The address of the window's requests: of the element, or of the record (its main record once known).
    function trUrl(tr, suffix = '') {
        return tr.record
            ? '/record-translation/' + tr.record.table + '/' + (tr.data?.record?.id || tr.record.id) + suffix
            : '/translation/' + (tr.data?.main.id || tr.id) + suffix;
    }

    async function loadTranslation(preset = null, proposeAfter = false) {
        const tr = state.translation;

        if (!tr) {
            return;
        }

        tr.busy = true;
        renderTranslation();

        try {
            const query = new URLSearchParams(Object.entries({ source: tr.source, page: tr.page, at: tr.record ? config.page : null }).filter(([, value]) => value));
            const data = await api(trUrl(tr) + (query.size ? '?' + query : ''));

            tr.data = data;
            tr.page = data.target.page;
            tr.source = data.source.page;

            if (data.target.page !== data.main.page) {
                state.translationTarget = data.target.page;
            }
            tr.initial = new Map(data.units.map((unit) => [unit.address, unit.target]));
            tr.values = new Map(tr.initial);
            tr.proposed.clear();
            tr.message = '';

            for (const [address, value] of Object.entries(preset || {})) {
                if (tr.values.has(address)) {
                    tr.values.set(address, value);
                    tr.proposed.add(address);
                }
            }
        } catch (error) {
            tr.busy = false;
            closeTranslation(true);
            showToast(error.status === 404 && !tr.record ? t('tr_main_missing') : (error.status ? error.message : t('save_failed')));

            return;
        }

        tr.busy = false;
        renderTranslation();

        // After another language was chosen the focus stays on that control; otherwise it goes to the first text.
        if (!tr.keepFocus) {
            tr.overlay.querySelector('.nfe-tr__target [data-address], .nfe-tr__bar select')?.focus();
        }

        tr.keepFocus = false;

        if (proposeAfter) {
            await proposeTranslation(null);
        }
    }

    const translationDirty = () => {
        const tr = state.translation;

        return Boolean(tr) && [...tr.values].some(([address, value]) => value !== tr.initial.get(address));
    };

    // Leaving the window with changes that are not saved asks first (save, discard or stay).
    async function closeTranslation(force = false) {
        const tr = state.translation;

        // While the window works, it closes without asking: a proposal is dropped, a save that runs finishes.
        if (!tr || (!force && !tr.busy && !(await settleTranslation()))) {
            return false;
        }

        tr.overlay.remove();
        state.translation = null;
        // Back to where the window was opened; that control may have been drawn again meanwhile.
        (tr.before?.isConnected ? tr.before : panel.querySelector('.nfe-panel__head button'))?.focus?.();

        return true;
    }

    // Changes in the window that are not saved, before another language or closing: Save (then go on), Discard or
    // Cancel (stay). True: go on.
    async function settleTranslation() {
        const tr = state.translation;

        if (!translationDirty()) {
            return true;
        }

        const choice = await openDialog({
            title: t('unsaved_title'),
            text: t('tr_unsaved_text', { language: tr.data.target.name }),
            buttons: [
                { label: t('save'), value: 'save', primary: true },
                { label: t('discard'), value: 'discard' },
                { label: t('unsaved_cancel'), value: null },
            ],
        });

        if (choice === 'save') {
            return saveTranslation(false);
        }

        return choice === 'discard';
    }

    // Another direction: $source and $page are the pages of the languages translated from and into.
    async function switchTranslation(source, page) {
        const tr = state.translation;

        // The choice shows the languages that are still open until the window goes on.
        if (tr.busy || !(await settleTranslation())) {
            renderTranslation();

            return;
        }

        tr.source = source;
        tr.page = page;
        tr.keepFocus = true;
        await loadTranslation();
    }

    // The language translated from changes; choosing the language translated into swaps both.
    function chooseSource(page) {
        const tr = state.translation;

        switchTranslation(page, page === tr.page ? tr.source : tr.page);
    }

    // Both languages swap: possible while the language translated into has the element.
    function swapTranslation() {
        const tr = state.translation;

        if (tr.data?.target.id) {
            switchTranslation(tr.page, tr.source);
        }
    }

    // Proposals of the translation service for the given texts (null: every one the user may change).
    async function proposeTranslation(addresses) {
        const tr = state.translation;

        if (!tr?.data || tr.busy) {
            return;
        }

        if (!editions().translateReady) {
            tr.message = t('error.translate_not_configured');
            renderTranslation();

            return;
        }

        tr.busy = true;
        // The fields that get a proposal show it (and cannot be written in) until it is there.
        tr.pending = addresses ? new Set(addresses) : null;
        tr.message = t('tr_translating');
        renderTranslation();

        try {
            const result = await api(trUrl(tr, '/propose'), { method: 'POST', body: JSON.stringify({ source: tr.source, page: tr.page, addresses }) });

            for (const [address, value] of Object.entries(result.values || {})) {
                tr.values.set(address, value);
                tr.proposed.add(address);
            }

            tr.message = Object.keys(result.values || {}).length ? t('proposal_note', { service: serviceName() }) : '';
        } catch (error) {
            tr.message = error.status ? error.message : t('save_failed');
        }

        tr.busy = false;
        tr.pending = undefined;
        renderTranslation();
    }

    // Saves what changed; closes the window afterwards unless $close is false (another language follows). True: saved.
    async function saveTranslation(close = true) {
        const tr = state.translation;

        if (!tr?.data || tr.busy || !tr.data.target.id || !tr.data.target.canUpdate) {
            return false;
        }

        const values = Object.fromEntries([...tr.values].filter(([address, value]) => value !== tr.initial.get(address)));

        if (!Object.keys(values).length) {
            if (close) {
                await closeTranslation(true);
            }

            return true;
        }

        tr.busy = true;
        tr.message = t('busy_saving');
        renderTranslation();

        let result = null;

        try {
            result = await api(trUrl(tr, '/save'), { method: 'POST', body: JSON.stringify({ source: tr.source, page: tr.page, values }) });
        } catch (error) {
            const data = error.data || {};
            const problems = [...(data.invalid || []), ...(data.failed || []).map((name) => t('tr_not_saved', { field: name }))];

            tr.busy = false;
            tr.message = problems.length ? problems.join(' ') : (error.status ? error.message : t('save_failed'));
            renderTranslation();
            tr.overlay.querySelector('.nfe-tr__status')?.focus();

            return false;
        }

        const targetPage = tr.page;
        const sourcePage = tr.source;
        const targetId = result.id;
        const record = tr.record;

        if (!record) {
            state.lang?.proposals.delete(targetId);
        }

        if (close) {
            await closeTranslation(true);
        } else {
            tr.initial = new Map(tr.values);
            tr.proposed.clear();
            tr.busy = false;
            tr.message = t('saved');
            renderTranslation();
        }

        announce(t('saved'));

        if (record) {
            await afterRecordTranslation(targetPage);
        } else {
            await afterTranslation(targetId, targetPage, sourcePage);
        }

        return true;
    }

    // After a record was translated, linked or made: the list of posts, or the page when it shows the language.
    async function afterRecordTranslation(targetPage) {
        if (state.view === 'posts') {
            await loadPosts();
        } else if (targetPage === config.page) {
            reloadPage({ announce: t('saved') });
        }
    }

    // The record has no version in the language yet: link one that exists (the proposal is preselected) …
    async function linkInTranslation(targetId) {
        const tr = state.translation;

        if (tr.busy || !targetId) {
            return;
        }

        tr.busy = true;
        renderTranslation();

        try {
            await api(trUrl(tr, '/link'), { method: 'POST', body: JSON.stringify({ page: tr.page, target: targetId }) });
        } catch (error) {
            tr.busy = false;
            tr.message = error.status ? error.message : t('save_failed');
            renderTranslation();

            return;
        }

        tr.busy = false;
        announce(t('rec_linked'));
        await loadTranslation();
        await afterRecordTranslation(tr.page);
    }

    // … or make it as a copy of the record, then get proposals for it.
    async function createInTranslation() {
        const tr = state.translation;

        if (tr.busy) {
            return;
        }

        tr.busy = true;
        renderTranslation();

        try {
            await api(trUrl(tr, '/create'), { method: 'POST', body: JSON.stringify({ page: tr.page }) });
        } catch (error) {
            tr.busy = false;
            tr.message = error.status ? error.message : t('save_failed');
            renderTranslation();

            return;
        }

        tr.busy = false;
        await loadTranslation(null, editions().translate && editions().translateReady);
        await afterRecordTranslation(tr.page);
    }

    // The record's archive has no version in the language yet: the window goes to the archive, to link it first.
    async function switchToArchive(archive) {
        const tr = state.translation;

        if (!(await settleTranslation())) {
            return;
        }

        tr.record = { table: archive.table, id: archive.id };
        tr.data = null;
        await loadTranslation();
    }

    // After a translation was saved: the states of both languages, the page when it shows one of them, and the form
    // "All fields" of the element translated into, which would otherwise save its old texts again.
    async function afterTranslation(targetId, targetPage, sourcePage) {
        if (state.view === 'languages') {
            await afterLanguageChange(targetId, targetPage);
        } else if (targetPage === config.page) {
            await loadStructure();
            await refreshElement(targetId);
        }

        if (targetPage !== config.page && sourcePage === config.page) {
            await loadStructure();
            showAt(state.selected);
            renderStructure();
        }

        if (state.form?.table === 'tl_content' && state.form.id === targetId) {
            state.form.reload?.();
        }
    }

    // The version has no element yet: a copy of the main element at the matching place, then proposals for it.
    async function adoptInTranslation() {
        const tr = state.translation;

        if (tr.busy) {
            return;
        }

        tr.busy = true;
        renderTranslation();

        try {
            await api('/element/' + tr.data.main.id + '/adopt', { method: 'POST', body: JSON.stringify({ page: tr.page }) });
        } catch (error) {
            tr.busy = false;
            tr.message = error.status ? error.message : t('save_failed');
            renderTranslation();

            return;
        }

        tr.busy = false;
        await loadTranslation(null, editions().translate && editions().translateReady);

        if (state.view === 'languages') {
            await reloadLanguages();
        } else if (tr.page === config.page) {
            await loadStructure();
            await refreshElement(tr.data.main.id);
        }
    }

    function renderTranslation() {
        const tr = state.translation;

        if (!tr) {
            return;
        }

        const data = tr.data;
        const ed = editions();
        const titleId = 'nfe-tr-title';
        // The focus stays on the same control when the window is drawn again (proposals, errors, another language).
        const active = root.activeElement && tr.overlay.contains(root.activeElement) ? root.activeElement : null;
        const focusKey = active?.dataset.address ? '[data-address="' + CSS.escape(active.dataset.address) + '"]' : (active?.dataset.action ? '[data-action="' + CSS.escape(active.dataset.action) + '"]' : (active?.id ? '#' + CSS.escape(active.id) : null));
        const box = h('div', { class: 'nfe-dialog nfe-dialog--translate', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, 'aria-busy': tr.busy ? 'true' : null });
        const close = button(t('close'), { icon: 'close', iconOnly: true, action: 'tr-close', onclick: () => closeTranslation() });

        const subject = data?.record ? t('rec_subject', { type: data.record.typeLabel, title: data.record.title }) : (data?.source.typeLabel || data?.source.type || '');

        box.append(h('div', { class: 'nfe-tr__head' }, [h('h2', { class: 'nfe-dialog__title', id: titleId, text: t('tr_title', { element: subject }) }), close]));

        if (!data) {
            box.append(loadingNote('nfe-tr__empty'));
            tr.overlay.replaceChildren(box);
            trapTranslation(box, focusKey);

            return;
        }

        const target = data.target;
        const languageName = (version) => (version.main ? t('main_language', { language: version.name }) : version.name);
        // The selects show the languages that are still open until a question about unsaved changes is answered.
        const choice = (id, label, versions, selected, choose) => h('span', { class: 'nfe-tr__choice' }, [
            h('label', { for: id, text: label }),
            h('select', { class: 'tl_select', id, onchange: (event) => { const page = Number(event.target.value); event.target.value = String(selected); choose(page); } },
                versions.map((version) => h('option', { value: version.page, text: languageName(version), selected: version.page === selected }))),
        ]);
        const machine = ed.translate && target.id && target.canUpdate && data.units.some((unit) => unit.machine && !unit.locked);

        box.append(h('div', { class: 'nfe-tr__bar' }, [
            // From any language that has the element into any other one of the page.
            h('div', { class: 'nfe-tr__pair', role: 'group', 'aria-label': t('tr_direction') }, [
                choice('nfe-tr-source', t('tr_from'), data.languages.filter((version) => version.element), tr.source, chooseSource),
                target.id ? button(t('tr_swap'), { icon: 'swap', iconOnly: true, action: 'tr-swap', onclick: swapTranslation }) : h('span', { class: 'nfe-tr__arrow', 'aria-hidden': 'true' }, [icon('arrow')]),
                choice('nfe-tr-language', t('tr_into'), data.languages.filter((version) => version.page !== tr.source), tr.page, (page) => switchTranslation(tr.source, page)),
            ]),
            // Highlighted until something is translated or changed; then Save is the next step.
            machine ? button(t('tr_propose_all'), { icon: 'translate', className: translationDirty() ? '' : 'nfe-button--primary', action: 'tr-propose-all', onclick: () => proposeTranslation(null) }) : null,
        ]));

        const body = h('div', { class: 'nfe-tr__body' });

        if (!target.id && data.record) {
            // A record: link the version that exists (the proposal first), make it as a copy, or link the archive first.
            const candidates = target.candidates || [];
            const proposed = candidates.find((candidate) => candidate.proposed) || candidates[0];
            const select = candidates.length ? h('select', { class: 'tl_select', id: 'nfe-tr-link' }, candidates.map((candidate) => h('option', {
                value: String(candidate.id),
                selected: candidate === proposed,
                text: candidate.proposed ? t('rec_suggested', { title: candidate.title }) : candidate.title,
            }))) : null;

            body.append(h('div', { class: 'nfe-tr__missing nfe-tr__missing--record' }, [
                h('p', { text: t('missing_in', { language: target.name }) }),
                target.archive ? h('p', { text: t('rec_archive_missing', { language: target.name }) }) : null,
                target.archive ? button(t('rec_link_archive'), { icon: 'link', className: 'nfe-button--primary', action: 'tr-archive', onclick: () => switchToArchive(target.archive) }) : null,
                select ? h('label', { for: 'nfe-tr-link', text: t('rec_link_label', { language: target.name }) }) : null,
                select ? h('div', { class: 'nfe-tr__link' }, [select, button(t('rec_link'), { icon: 'link', className: 'nfe-button--primary', action: 'tr-link', onclick: () => linkInTranslation(Number(select.value)) })]) : null,
                !select && !target.archive && !target.canCreate ? h('p', { text: t('rec_none', { language: target.name }) }) : null,
                target.canCreate ? button(t('rec_create', { language: target.name }), { icon: 'copy', className: select ? '' : 'nfe-button--primary', action: 'tr-create', onclick: createInTranslation }) : null,
            ]));
        } else if (!target.id) {
            // Without the container there (article, the element it is in, the post), the element cannot be taken over.
            const container = { element: 'parent_missing', post: 'post_missing' }[target.container] || 'article_missing';

            body.append(h('div', { class: 'nfe-tr__missing' }, [
                h('p', { text: t('missing_in', { language: target.name }) }),
                target.parent === false ? h('p', { text: t(container, { language: target.name }) }) : null,
                target.parent !== false && state.structure?.permissions.editStructure ? button(t('adopt'), { icon: 'copy', className: 'nfe-button--primary', action: 'tr-adopt', onclick: adoptInTranslation }) : null,
            ]));
        } else if (!target.canUpdate) {
            body.append(h('p', { class: 'nfe-tr__notice', text: t('no_permission') }));
        } else if (target.status === 'outdated') {
            body.append(h('p', { class: 'nfe-tr__notice', text: t('rec_outdated') }));
        }

        if (!data.units.length && (target.id || !data.record)) {
            body.append(h('p', { class: 'nfe-tr__empty', text: data.record ? t('rec_nothing') : t('tr_nothing') }));
        }

        if (data.units.length) {
            body.append(h('div', { class: 'nfe-tr__columns', 'aria-hidden': 'true' }, [h('span', { text: data.source.name }), h('span', { text: target.name })]));
        }

        let group = null;
        let index = 0;

        for (const unit of data.units) {
            if (unit.group !== group) {
                group = unit.group;
                body.append(h('h3', { class: 'nfe-tr__group', text: group || (data.record ? data.record.typeLabel : t('tr_element')) }));
            }

            body.append(translationRow(unit, index++, data));
        }

        // While something runs, the line says what (with a spinner); afterwards the result.
        const message = tr.busy ? tr.message || t('loading') : tr.message;
        const status = h('div', { class: 'nfe-tr__status' + (tr.busy ? ' nfe-loading' : ''), role: 'status', tabindex: '-1' }, message ? [tr.busy ? h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }) : null, h('span', { text: message })] : []);
        // Nothing can be saved without the element in the version or the permission; the notice above says why.
        const save = target.id && target.canUpdate ? button(t('save'), { icon: 'save', className: machine && !translationDirty() ? '' : 'nfe-button--primary', action: 'tr-save', onclick: saveTranslation }) : null;

        box.append(body, h('div', { class: 'nfe-tr__foot' }, [status, h('div', { class: 'nfe-dialog__buttons' }, [save, button(t('cancel'), { action: 'tr-cancel', onclick: () => closeTranslation() })])]));
        tr.overlay.replaceChildren(box);

        // Every "Translate this field" button as high and wide as a one-line field of the back-end theme.
        const line = box.querySelector('.nfe-tr__target > input.tl_text');

        if (line?.offsetHeight) {
            box.style.setProperty('--nfe-tr-line', line.offsetHeight + 'px');
        }

        if (tr.busy) {
            lockTranslation(box, tr.pending);

            // One or a few fields: the veil over them; everything else (all fields, loading, saving): over all of them.
            if (!(tr.pending instanceof Set)) {
                coverArea(body, message);
            }
        }

        trapTranslation(box, focusKey);
    }

    // While the window works (proposals, saving, loading): nothing can be typed or chosen, only closing; the fields
    // that wait for a proposal ($pending: their addresses, null: all of them) carry a spinner over them.
    function lockTranslation(box, pending) {
        for (const control of box.querySelectorAll('.nfe-tr__target [data-address]')) {
            if (control.isContentEditable) {
                control.setAttribute('contenteditable', 'false');
                control.setAttribute('aria-readonly', 'true');
            } else {
                control.readOnly = true;
            }

            if (pending instanceof Set && pending.has(control.dataset.address)) {
                const cell = control.closest('.nfe-tr__target');

                cell.classList.add('nfe-tr__target--busy');
                cell.setAttribute('aria-busy', 'true');
                cell.append(busyCover(t('tr_translating'), true));
            }
        }

        lockButtons(box, ['tr-close', 'tr-cancel']);
    }

    // Work runs: the selects of $box are locked, its other buttons keep the focus (aria-disabled, not disabled) but
    // do nothing; $keep: actions that stay usable.
    function lockButtons(box, keep = []) {
        for (const select of box.querySelectorAll('select')) {
            select.disabled = true;
        }

        for (const node of box.querySelectorAll('.nfe-button')) {
            if (!keep.includes(node.dataset.action) && node.getAttribute('aria-busy') !== 'true') {
                node.setAttribute('aria-disabled', 'true');
            }
        }

        box.addEventListener('click', blockLocked, true);
    }

    // A locked button, or one whose work still runs: its click ends here (no second request).
    function blockLocked(event) {
        if (event.target.closest?.('.nfe-button[aria-disabled="true"], .nfe-button[aria-busy="true"]')) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    // One text: its name, its text in the language translated from, in the one translated into (editable unless
    // locked) and its proposal.
    function translationRow(unit, index, data) {
        const tr = state.translation;
        const ed = editions();
        const labelId = 'nfe-tr-l-' + index;
        const value = tr.values.get(unit.address) ?? '';
        const proposed = tr.proposed.has(unit.address);
        const row = h('div', { class: 'nfe-tr__row' + (proposed ? ' nfe-tr__row--proposed' : '') });
        const notes = [];

        row.append(h('div', { class: 'nfe-tr__label', id: labelId, text: unit.label }));

        const source = h('div', { class: 'nfe-tr__source nfe-tr__text--' + unit.kind, lang: data.source.language });

        if (unit.kind === 'rich') {
            source.innerHTML = safeHtml(unit.source);
            source.querySelectorAll('a').forEach((link) => link.setAttribute('tabindex', '-1'));
        } else if (unit.kind === 'code') {
            source.append(h('pre', { translate: 'no' }, [h('code', { text: unit.source })]));
        } else {
            source.textContent = unit.source;
        }

        row.append(source);

        const cell = h('div', { class: 'nfe-tr__target' });
        const name = unit.label + ' (' + data.target.name + ')';

        if (unit.locked) {
            const shown = h('div', { class: 'nfe-tr__locked nfe-tr__text--' + unit.kind, lang: data.target.language });

            if (unit.kind === 'rich') {
                shown.innerHTML = safeHtml(value);
            } else {
                shown.textContent = value || '—';
            }

            cell.append(shown);

            if (data.target.id && data.target.canUpdate) {
                notes.push(unit.place ? t('no_permission') : t('tr_locked_place', { language: data.target.name }));
            }
        } else {
            cell.append(translationControl(unit, value, name, data.target.language));

            if (ed.translate && unit.machine) {
                cell.append(button(t('tr_propose', { field: unit.label }), { icon: 'translate', iconOnly: true, action: 'tr-propose:' + unit.address, onclick: () => proposeTranslation([unit.address]) }));
            }
        }

        row.append(cell);

        if (unit.kind === 'code' && !unit.machine) {
            notes.push(t('tr_code_note'));
        }

        if (unit.kind === 'meta') {
            notes.push(t('tr_image_note'));
        }

        if (proposed) {
            notes.unshift(t('tr_proposed', { service: serviceName() }));
        }

        if (notes.length) {
            row.append(h('p', { class: 'nfe-tr__note' }, notes.map((text) => h('span', { text }))));
        }

        return row;
    }

    function translationControl(unit, value, name, language) {
        const tr = state.translation;
        const remember = (next) => {
            tr.values.set(unit.address, next);
            tr.proposed.delete(unit.address);
            highlightTranslationStep();
        };

        if (unit.kind === 'rich') {
            // Contao's text area look (border, spacing, focus), as the other fields of the window.
            const area = h('div', {
                class: 'tl_textarea nfe-tr__rich', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': name, lang: language, 'data-address': unit.address,
                oninput: (event) => remember(cleanHtml(event.currentTarget.innerHTML)),
                onpaste: (event) => { event.preventDefault(); document.execCommand('insertText', false, event.clipboardData.getData('text/plain')); },
            });
            area.innerHTML = safeHtml(value);

            return area;
        }

        if (unit.kind === 'lines' || unit.kind === 'code') {
            // As high as the longer of both texts (lines and wrapped length), the field grows as it is written in (CSS).
            const longer = [String(unit.source), String(value)].reduce((rows, text) => Math.max(rows, text.split('\n').reduce((sum, line) => sum + Math.max(1, Math.ceil(line.length / 64)), 0)), 0);
            const lines = Math.min(12, Math.max(unit.kind === 'code' ? 4 : 2, longer + 1));

            return h('textarea', {
                class: 'tl_textarea' + (unit.kind === 'code' ? ' monospace' : ''), rows: lines, 'aria-label': name, lang: language, 'data-address': unit.address,
                spellcheck: unit.kind === 'code' ? 'false' : null, translate: unit.kind === 'code' ? 'no' : null,
                oninput: (event) => remember(event.currentTarget.value),
            }, [document.createTextNode(value)]);
        }

        return h('input', { type: 'text', class: 'tl_text', value, 'aria-label': name, lang: language, 'data-address': unit.address, oninput: (event) => remember(event.currentTarget.value) });
    }

    // Before anything is translated "Translate all fields" is the highlighted button, afterwards "Save".
    function highlightTranslationStep() {
        const dirty = translationDirty();

        state.translation?.overlay.querySelector('[data-action="tr-propose-all"]')?.classList.toggle('nfe-button--primary', !dirty);
        state.translation?.overlay.querySelector('[data-action="tr-save"]')?.classList.toggle('nfe-button--primary', dirty || !state.translation.overlay.querySelector('[data-action="tr-propose-all"]'));
    }

    // Focus stays in the window (Tab), Esc closes it (asking first when something is not saved).
    function trapTranslation(box, focusKey = null) {
        box.addEventListener('keydown', (event) => {
            const focusable = [...box.querySelectorAll('button:not([disabled]), input, textarea, select:not([disabled]), [contenteditable="true"]')];

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeTranslation();
            } else if (event.key === 'Tab' && focusable.length) {
                const index = focusable.indexOf(root.activeElement);
                const next = event.shiftKey ? (index <= 0 ? focusable.length - 1 : index - 1) : (index + 1) % focusable.length;

                event.preventDefault();
                focusable[next].focus();
            }
        });

        if (!box.contains(root.activeElement)) {
            ((focusKey ? box.querySelector(focusKey) : null) || box.querySelector('.nfe-tr__head button'))?.focus();
        }
    }

    // Writes values into the fields being edited on the page (proposal of the translation or the writing aid).
    function applyFields(fields) {
        for (const target of state.editing.targets) {
            // A proposal replaces the whole value (it is saved as it is, not mapped into the stored one).
            if (target.field === 'headline' && fields.headline) {
                target.node.textContent = typeof fields.headline === 'string' ? fields.headline : fields.headline.value;
                target.replaced = true;
            } else if (target.field === 'text' && typeof fields.text === 'string') {
                if (target.editor) {
                    target.editor.setContent(fields.text);
                } else {
                    target.node.innerHTML = fields.text;
                }

                target.replaced = true;
            } else if (target.field === 'caption' && typeof fields.caption === 'string') {
                target.node.textContent = fields.caption;
            }
        }

        if (typeof fields.alt === 'string' && state.editing.data.fields.includes('alt')) {
            state.editing.values.alt = fields.alt;
            const altField = pageRoot?.getElementById('nfe-alt');

            if (altField) {
                altField.value = fields.alt;
            }
        }
    }

    async function markCurrent(id) {
        try {
            await api('/element/' + id + '/synced', { method: 'POST', body: '{}' });
            await loadStructure();
            showAt(id);
            renderStructure();
            announce(t('status_current'));
        } catch (error) {
            showToast(error.status ? error.message : t('save_failed'));
        }
    }

    function languagesButton() {
        const ed = editions();
        const compact = narrow();

        if ((ed.languages || []).length) {
            return button(t('languages'), { icon: 'languages', iconOnly: compact, action: 'languages', onclick: () => openLanguages() });
        }

        if (ed.upgrade || ed.changelanguageMissing) {
            const node = button(t('languages'), { icon: 'languages', iconOnly: compact, action: 'languages', onclick: () => { state.view = 'upgrade'; renderPanel(); panel.querySelector('.nfe-panel__head button')?.focus(); } });

            if (ed.upgrade) {
                node.append(h('span', { class: 'nfe-plus-badge', text: t('plus') }));
            }

            return node;
        }

        return null;
    }

    async function openLanguages(target = null) {
        const ed = editions();
        const versions = ed.languages || [];
        const others = versions.filter((version) => !version.main);
        const current = versions.find((version) => version.id === config.page && !version.main);

        if (!(await settle())) {
            return;
        }

        state.lang = { target: target || current?.id || others[0]?.id, data: null, proposals: new Map(), busy: false };
        state.view = 'languages';
        openPanel();
        renderPanel();
        await reloadLanguages();
        panel.querySelector('.nfe-panel__head button')?.focus();
    }

    async function reloadLanguages() {
        if (!state.lang) {
            return;
        }

        // The posts shown on the page (the reader of a news, an event …) are compared as well.
        const posts = (state.structure?.articles || []).filter(isPost).map((group) => group.table + ':' + group.postId);

        try {
            state.lang.data = await api('/languages/' + config.page + '/' + state.lang.target + (posts.length ? '?posts=' + encodeURIComponent(posts.join(',')) : ''));
        } catch (error) {
            showToast(error.status ? error.message : t('save_failed'));
        }

        if (state.view === 'languages') {
            renderLanguages();
        }
    }

    // Proposals of "Translate whole page" that are not saved, before another language, leaving the view or loading the
    // page again: Save (apply all), Discard or Cancel (stay). True: go on.
    async function settleProposals() {
        const lang = state.lang;

        if (!lang?.proposals.size) {
            return true;
        }

        const version = (editions().languages || []).find((entry) => entry.id === lang.target);
        const choice = await openDialog({
            title: t('unsaved_title'),
            text: t('tr_unsaved_proposals', { language: version?.name || '' }),
            buttons: [
                { label: t('save'), value: 'save', primary: true },
                { label: t('discard'), value: 'discard' },
                { label: t('unsaved_cancel'), value: null },
            ],
        });

        if (choice === 'save') {
            await applyAllProposals();

            return !lang.proposals.size;
        }

        if (choice === 'discard') {
            lang.proposals.clear();

            return true;
        }

        return false;
    }

    async function closeLanguages() {
        if (!(await settleProposals())) {
            return;
        }

        state.view = 'structure';
        state.lang = null;
        renderPanel();
        panel.querySelector('[data-action="languages"]')?.focus();
    }

    function renderLanguages() {
        const lang = state.lang;
        const data = lang?.data;
        const ed = editions();
        // Drawn again while work runs (one step of the whole page): the focus stays on the same button.
        const focusAction = panel.contains(root.activeElement) ? root.activeElement?.dataset.action : null;
        const versions = ed.languages || [];
        const main = versions.find((version) => version.main);
        const title = t('languages_of', { page: data?.main.title || main?.title || '' });

        const head = h('div', { class: 'nfe-panel__head' }, [
            button(t('back_to_structure'), { icon: 'back', iconOnly: true, onclick: closeLanguages }),
            h('h2', { class: 'nfe-panel__title', text: title }),
            button(t('close'), { icon: 'close', iconOnly: true, onclick: closeLanguages }),
        ]);

        const select = h('select', {
            class: 'tl_select',
            'aria-label': t('languages'),
            onchange: async (event) => {
                const target = Number(event.target.value);

                // The select shows the language that is still open until the question is answered.
                event.target.value = String(lang.target);

                if (!(await settleProposals())) {
                    return;
                }

                lang.target = target;
                lang.data = null;
                lang.proposals.clear();
                renderLanguages();
                reloadLanguages();
            },
        }, versions.filter((version) => !version.main).map((version) => h('option', { value: version.id, text: version.name, selected: version.id === lang?.target })));

        const rows = data ? data.articles.flatMap((group) => group.rows) : [];
        const counts = { outdated: rows.filter((row) => row.status === 'outdated').length, missing: rows.filter((row) => row.status === 'missing').length };
        const applyAll = lang?.proposals.size ? button(t('apply_all'), { icon: 'save', className: 'nfe-button--primary', action: 'lang-apply-all', onclick: applyAllProposals }) : null;
        // The main action of the view while translations are behind and no proposals wait for "apply".
        const translateButton = ed.translate ? button(t('translate_page'), { icon: 'translate', className: counts.outdated && !applyAll ? 'nfe-button--primary' : '', action: 'lang-translate-page', onclick: translatePage }) : null;

        if (translateButton && !data && !lang?.busy) {
            translateButton.disabled = true;
        }

        // The buttons in one row of their own (fitRow): what does not fit is behind "…"; "Apply all" stays.
        applyAll?.setAttribute('data-keep', '');

        const actions = [
            translateButton,
            // The page's own texts: its title, the title and description for search engines.
            lang?.target ? button(t('rec_page_meta'), { icon: 'translate', onclick: () => openRecordTranslation('tl_page', main?.id || config.page, lang.target) }) : null,
            applyAll,
        ].filter(Boolean);
        const actionRow = actions.length ? h('div', { class: 'nfe-lang-bar__actions', 'data-fit': '' }, actions) : null;
        const bar = h('div', { class: 'nfe-lang-bar' }, [
            h('span', { class: 'nfe-lang-bar__pair' }, [t('main_language', { language: main?.name || '' }), h('span', { 'aria-hidden': 'true', text: '→' }), select]),
            // While the whole page is translated or all proposals are saved: how far it is.
            lang?.progress ? h('span', { class: 'nfe-lang-bar__summary nfe-loading', role: 'status' }, [
                h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }),
                h('span', { text: t(lang.progress.kind === 'apply' ? 'apply_progress' : 'tr_page_progress', { n: Math.min(lang.progress.done + 1, lang.progress.total), total: lang.progress.total }) }),
            ]) : (data ? h('span', { class: 'nfe-lang-bar__summary', text: t('summary', { outdated: counts.outdated, missing: counts.missing }) }) : null),
            actionRow,
        ]);

        if (actionRow) {
            fitRow(actionRow);
        }

        const body = h('div', { class: 'nfe-panel__body nfe-cmp' + (lang?.busy ? ' nfe-cmp--busy' : '') });

        // The comparison is being loaded (another language, after a change).
        if (!data) {
            body.append(loadingNote('nfe-cmp__loading'));
        }

        // Nothing in the cards can be used or written in while the work runs.
        body.inert = Boolean(lang?.busy);

        for (const group of data?.articles || []) {
            const post = group.kind === 'post';
            const heading = (side) => t(post ? 'post' : 'article', { title: side.title });

            body.append(h('div', { class: 'nfe-cmp__article' }, [
                h('h3', { text: group.main ? heading(group.main) : '' }),
                h('h3', { text: group.target ? heading(group.target) : t(post ? 'post_missing' : 'article_missing', { language: data.target.name }) }),
            ]));

            // Elements in an element follow it, indented by their depth as in the structure.
            for (const row of group.rows) {
                const line = h('div', { class: 'nfe-cmp__row' + (row.depth ? ' nfe-cmp__row--nested' : '') }, [
                    row.main ? mainCard(row) : unlinkedCard(row, group, data),
                    row.target ? targetCard(row) : missingCard(row, group, data),
                ]);

                if (row.depth) {
                    line.style.setProperty('--nfe-depth', String(Math.min(row.depth, 4)));
                }

                body.append(line);
            }
        }

        panel.replaceChildren(head, bar, body, h('div', { class: 'nfe-panel__foot' }, [h('div', { 'data-status': '' })]));
        panel.setAttribute('aria-label', title);
        renderStatus();

        if (lang?.busy) {
            lockButtons(head);
            lockButtons(bar);

            if (lang.progress) {
                coverArea(body, t(lang.progress.kind === 'apply' ? 'apply_progress' : 'tr_page_progress', { n: Math.min(lang.progress.done + 1, lang.progress.total), total: lang.progress.total }), body.querySelector('.nfe-card--busy'));
            }
        }

        if (focusAction) {
            panel.querySelector('[data-action="' + CSS.escape(focusAction) + '"]')?.focus();
        }
    }

    const chip = (status) => h('span', { class: 'nfe-chip nfe-chip--' + status, text: t('status_' + status) });

    function mainCard(row) {
        const element = row.main;
        const card = h('div', { class: 'nfe-card' }, [h('div', { class: 'nfe-card__head' }, [h('span', { class: 'nfe-card__type', text: element.typeLabel })])]);

        if (element.values.headline.value) {
            card.append(h('p', { class: 'nfe-card__headline', text: decode(element.values.headline.value) }));
        }

        if (element.values.text) {
            const text = h('div', { class: 'nfe-card__text' });
            text.innerHTML = element.values.text;
            text.querySelectorAll('a').forEach((link) => link.setAttribute('tabindex', '-1'));
            card.append(text);
        }

        if (element.values.image?.alt) {
            card.append(h('p', { class: 'nfe-card__text', text: element.values.image.alt }));
        }

        return card;
    }

    // The element of the version: what it shows, how many texts are still open, proposals of "Translate the whole
    // page" (to check in the window or to save as they are). Every text is edited in the window "Translate".
    function targetCard(row) {
        const lang = state.lang;
        const element = row.target;
        const proposal = lang.proposals.get(element.id);
        const count = proposal ? Object.keys(proposal.values).length : 0;
        const working = lang.progress?.current === element.id;
        const card = h('div', { class: 'nfe-card' + (proposal ? ' nfe-card--proposal' : '') + (working ? ' nfe-card--busy' : ''), 'aria-busy': working ? 'true' : null }, [
            h('div', { class: 'nfe-card__head' }, [h('span', { class: 'nfe-card__type', text: element.typeLabel }), chip(row.status)]),
            proposal ? h('p', { class: 'nfe-proposal-note' }, [icon('translate'), h('span', { text: t('tr_proposals', { n: count, service: serviceName() }) })]) : null,
        ]);

        if (element.values.headline.value) {
            card.append(h('p', { class: 'nfe-card__headline', text: decode(element.values.headline.value) }));
        }

        if (element.values.text) {
            const text = h('div', { class: 'nfe-card__text' });
            text.innerHTML = safeHtml(element.values.text);
            text.querySelectorAll('a').forEach((link) => link.setAttribute('tabindex', '-1'));
            card.append(text);
        }

        if (row.open && !proposal) {
            card.append(h('p', { class: 'nfe-card__open', text: t('tr_open', { n: row.open }) }));
        }

        const actions = h('div', { class: 'nfe-card__actions nfe-actions' });
        card.append(actions);
        renderRowActions(row, actions);

        return card;
    }

    function renderRowActions(row, actions) {
        const lang = state.lang;
        const element = row.target;
        const proposal = lang.proposals.get(element.id);

        actions.replaceChildren();

        if (proposal) {
            actions.append(
                button(t('apply_save'), { icon: 'save', className: 'nfe-button--primary', onclick: () => saveRow(element.id) }),
                button(t('tr_review'), { icon: 'edit', onclick: () => openTranslation(row.main.id, lang.target, proposal.values) }),
                button(t('discard'), { icon: 'close', iconOnly: true, onclick: () => { lang.proposals.delete(element.id); renderLanguages(); } }),
            );
            titleActions(actions);

            return;
        }

        // One action per card, the main one where the translation is behind; the rest in "…".
        const outdated = element.canUpdate && row.status === 'outdated';
        const more = [
            outdated ? { label: t('mark_current'), icon: 'ok', action: () => rowCall('/element/' + element.id + '/synced', {}) } : null,
            element.canUpdate && row.link ? { label: t('unlink'), icon: 'link', action: () => rowCall('/link/' + row.link + '/delete', {}) } : null,
        ].filter(Boolean);

        if (row.main) {
            actions.append(button(t('tr_window'), { icon: 'translate', className: outdated ? 'nfe-button--primary' : '', onclick: () => openTranslation(row.main.id, lang.target) }));
        }

        if (more.length) {
            actions.append(button(t('more'), { icon: 'more', iconOnly: true, onclick: (event) => openMenu(event.currentTarget, more, t('more')) }));
        }

        titleActions(actions);
    }

    // An element missing in the version: taken over into the matching container there (article, the element it is
    // in, the post), or linked to an element of the version in that container that has no counterpart.
    function missingCard(row, group, data) {
        const candidates = group.rows.filter((other) => other.status === 'unlinked' && other.target?.canUpdate && other.targetParent === row.targetParent);
        const actions = h('div', { class: 'nfe-card__actions nfe-actions' });
        const parentMissing = row.depth && !row.targetParent;

        if ((state.structure?.permissions.editStructure) && row.targetParent) {
            actions.append(button(t('adopt'), { icon: 'copy', className: 'nfe-button--primary', onclick: () => adopt(row.main.id) }));
        }

        if (candidates.length) {
            actions.append(button(t('link_element'), { icon: 'link', onclick: (event) => openMenu(event.currentTarget, candidates.map((other) => ({ label: other.target.typeLabel + ': ' + other.target.summary, action: () => rowCall('/link', { main: row.main.id, element: other.target.id }) })), t('link_element')) }));
        }

        titleActions(actions);

        return h('div', { class: 'nfe-card nfe-card--empty' }, [
            h('div', { class: 'nfe-card__head' }, [chip('missing')]),
            h('p', { text: t('missing_in', { language: data.target.name }) }),
            parentMissing ? h('p', { text: t('parent_missing', { language: data.target.name }) }) : null,
            actions,
        ]);
    }

    // An element only the version has (often written there first): taken over into the main language, or linked to an
    // element missing in the version. Where taking over is not possible, the card says why.
    function unlinkedCard(row, group, data) {
        const candidates = group.rows.filter((other) => other.status === 'missing' && other.main && other.mainParent === row.mainParent && row.mainParent);
        const actions = h('div', { class: 'nfe-card__actions nfe-actions' });
        // Without the container in the main language (article, the element it is in, the post) there is no place for it.
        const missingParent = row.depth ? 'parent_missing' : (group.kind === 'post' ? 'post_missing' : 'article_missing');
        const reason = !row.mainParent ? t(missingParent, { language: data.main.name }) : (row.canAdoptMain ? null : t('adopt_main_denied', { language: data.main.name }));

        if (!reason) {
            actions.append(button(t('adopt_main', { language: data.main.name }), { icon: 'copy', className: 'nfe-button--primary', onclick: () => adoptIntoMain(row.target.id) }));
        }

        if (candidates.length && row.target.canUpdate) {
            actions.append(button(t('link_element'), { icon: 'link', onclick: (event) => openMenu(event.currentTarget, candidates.map((other) => ({ label: other.main.typeLabel + ': ' + other.main.summary, action: () => rowCall('/link', { main: other.main.id, element: row.target.id }) })), t('link_element')) }));
        }

        titleActions(actions);

        return h('div', { class: 'nfe-card nfe-card--empty' }, [
            h('div', { class: 'nfe-card__head' }, [chip('unlinked')]),
            h('p', { text: t('no_counterpart', { language: data.main.name }) }),
            reason ? h('p', { text: reason }) : null,
            actions,
        ]);
    }

    // A change in the language view; afterwards the comparison (and, for the current page, the page) is loaded again.
    async function rowCall(path, body) {
        try {
            const result = await api(path, { method: 'POST', body: JSON.stringify(body) });
            await afterLanguageChange();

            return result;
        } catch (error) {
            showToast(error.status ? error.message : t('save_failed'));

            return null;
        }
    }

    // $page: the page the change was saved on (default: the version shown; the main page for a translation into it).
    async function afterLanguageChange(savedId = null, page = state.lang?.target) {
        await reloadLanguages();

        if (page === config.page) {
            await loadStructure();

            if (savedId) {
                await refreshElement(savedId);
            }
        }
    }

    // Saves the proposals of "Translate the whole page" for an element as they are.
    async function saveRow(id) {
        const lang = state.lang;
        const proposal = lang.proposals.get(id);

        try {
            await api('/translation/' + proposal.main + '/save', { method: 'POST', body: JSON.stringify({ page: lang.target, values: proposal.values }) });
            lang.proposals.delete(id);
            await afterLanguageChange(id);
            announce(t('saved'));

            return true;
        } catch (error) {
            const data = error.data || {};
            const problems = [...(data.invalid || []), ...(data.failed || []).map((name) => t('tr_not_saved', { field: name }))];

            showToast(problems.length ? problems.join(' ') : (error.status ? error.message : t('save_failed')));
            await afterLanguageChange(id);

            return false;
        }
    }

    // Proposals for every element of the version whose translation is outdated (all its texts) or that has texts
    // not translated yet (those texts); nothing is saved: the proposals wait in the cards.
    async function translatePage() {
        const lang = state.lang;
        const all = lang.data.articles.flatMap((group) => group.rows);
        const behind = all.filter((row) => row.main && row.target && (row.status === 'outdated' || row.open > 0));
        const rows = behind.filter((row) => row.target.canUpdate);

        // Nothing to translate: a note says why, instead of a click without an answer.
        if (!rows.length) {
            showToast(behind.length ? t('no_permission') : nothingToTranslate(all), null, 'note');

            return;
        }

        if (!editions().translateReady) {
            showToast(t('error.translate_not_configured'));

            return;
        }

        let made = 0;
        let failed = false;

        // One element after the other: the bar says how far it is, the card being translated shows it, its
        // proposals appear as soon as they are there; nothing else can be used meanwhile.
        lang.busy = true;
        lang.progress = { kind: 'translate', done: 0, total: rows.length, current: null };

        await whileAction('lang-translate-page', async () => {
            for (const row of rows) {
                lang.progress.current = row.target.id;
                renderLanguages();

                try {
                    const result = await api('/translation/' + row.main.id + '/propose', { method: 'POST', body: JSON.stringify({ page: lang.target, open: row.status !== 'outdated' }) });

                    if (Object.keys(result.values || {}).length) {
                        lang.proposals.set(row.target.id, { main: row.main.id, values: result.values });
                        made++;
                    }
                } catch (error) {
                    showToast(error.status ? error.message : t('save_failed'));
                    failed = true;
                    break;
                }

                lang.progress.done++;
            }
        });

        lang.busy = false;
        lang.progress = null;
        renderLanguages();

        // The proposals show in the cards. None at all (the server leaves out texts that are code other than HTML or
        // Markdown, or that the user may not change): a visible note, announced as the toast is; after an error its
        // message stays.
        if (made) {
            announce(t('tr_page_done', { n: made }));
        } else if (!failed) {
            showToast(t('tr_page_none'), null, 'note');
        }
    }

    // The version is up to date: what is missing there has to be taken over first, and what only the version has is
    // named (it can be taken over into the main language).
    function nothingToTranslate(rows) {
        const data = state.lang.data;
        const only = rows.filter((row) => row.status === 'unlinked').map((row) => row.target.typeLabel + (row.target.summary ? ': ' + row.target.summary : ''));
        const message = t(rows.some((row) => row.status === 'missing') ? 'tr_page_missing' : 'tr_page_nothing', { language: data.target.name });

        return only.length ? message + ' ' + t('tr_page_only', { language: data.target.name, main: data.main.name, elements: only.join('; ') }) : message;
    }

    async function applyAllProposals() {
        const lang = state.lang;
        const ids = [...lang.proposals.keys()];

        lang.busy = true;
        lang.progress = { kind: 'apply', done: 0, total: ids.length, current: null };

        await whileAction('lang-apply-all', async () => {
            for (const id of ids) {
                lang.progress.current = id;
                renderLanguages();

                if (!(await saveRow(id))) {
                    break;
                }

                lang.progress.done++;
            }
        });

        lang.busy = false;
        lang.progress = null;
        renderLanguages();
    }

    // The window "Translate" for an element just taken over, with proposals right away where a service is set up.
    async function openAdopted(mainId, page) {
        await openTranslation(mainId, page);

        if (state.translation?.data?.target.id && editions().translate && editions().translateReady) {
            await proposeTranslation(null);
        }
    }

    // Copy of the element in the language version, linked; with a translation service the translation is proposed right away.
    async function adopt(mainId) {
        const lang = state.lang;

        // On the current page the page is loaded again, and proposals not saved would be lost.
        if (lang.target === config.page && !(await settleProposals())) {
            return;
        }

        try {
            const result = await api('/element/' + mainId + '/adopt', { method: 'POST', body: JSON.stringify({ page: lang.target }) });

            if (lang.target === config.page) {
                reloadPage({ select: result.id, focus: { where: 'languages' }, view: 'languages', target: lang.target, translate: mainId });

                return;
            }

            await reloadLanguages();
            await openAdopted(mainId, lang.target);
        } catch (error) {
            showToast(error.status ? error.message : t('save_failed'));
        }
    }

    // The other way round: an element written in the version first is copied into the main language and linked; the
    // window "Translate" then translates its texts into the main language, with proposals right away where a
    // translation service is set up.
    async function adoptIntoMain(id) {
        const lang = state.lang;
        const mainPage = lang.data.main.id;

        // On the current page the page is loaded again, and proposals not saved would be lost.
        if (mainPage === config.page && !(await settleProposals())) {
            return;
        }

        try {
            const result = await api('/element/' + id + '/adopt-main', { method: 'POST', body: '{}' });

            if (mainPage === config.page) {
                reloadPage({ select: result.id, focus: { where: 'languages' }, view: 'languages', target: lang.target, translate: id, translatePage: mainPage });

                return;
            }

            await reloadLanguages();
            await openAdopted(id, mainPage);
        } catch (error) {
            showToast(error.status ? error.message : t('save_failed'));
        }
    }

    // Without a licence (or without changelanguage): what language versions would need.
    function renderUpgrade() {
        const ed = editions();
        const head = h('div', { class: 'nfe-panel__head' }, [
            button(t('back_to_structure'), { icon: 'back', iconOnly: true, onclick: () => { state.view = 'structure'; renderPanel(); } }),
            h('h2', { class: 'nfe-panel__title', text: t('languages') }),
            button(t('close'), { icon: 'close', iconOnly: true, onclick: () => { state.view = 'structure'; renderPanel(); } }),
        ]);
        const body = h('div', { class: 'nfe-panel__body nfe-info' });

        if (ed.upgrade) {
            body.append(...[
                h('h3', {}, [t('languages'), ' ', h('span', { class: 'nfe-plus-badge', text: t('plus') })]),
                h('p', { text: t('plus_intro') }),
                h('ul', {}, ['plus_languages', 'plus_translate', 'plus_outdated'].map((key) => h('li', { text: t(key) }))),
                ed.canOpenAccess ? h('p', {}, [h('a', { href: config.backend + '?do=neo_access', target: '_blank', rel: 'noopener', text: t('enter_license') })]) : null,
            ].filter(Boolean));
        } else {
            body.append(h('p', { text: t('changelanguage_missing') }));
        }

        panel.replaceChildren(head, body);
        panel.setAttribute('aria-label', t('languages'));
    }

    // ------------------------------------------------------------------ writing aid (Pro)

    function openWritingAid() {
        if (!state.editing) {
            return;
        }

        const fields = state.editing.targets.map((target) => target.field).filter((field) => field === 'headline' || field === 'text');

        state.aid = { fields, field: fields.includes('text') ? 'text' : fields[0], term: state.aid?.term || '', proposals: [], busy: false, error: '' };
        state.view = 'aid';
        openPanel();
        renderPanel();
        format.querySelector('[data-aid]')?.setAttribute('aria-pressed', 'true');
        panel.querySelector('.nfe-aid__term input')?.focus();
    }

    function closeWritingAid() {
        state.view = 'structure';
        renderPanel();
        format.querySelector('[data-aid]')?.setAttribute('aria-pressed', 'false');
        state.editing?.targets.find((target) => target.field === state.aid?.field)?.node.focus();
    }

    function renderWritingAid() {
        const aid = state.aid;
        const ed = editions();
        const fieldLabel = (field) => t(field === 'headline' ? 'headline_field' : 'text_field');
        const title = t('writing_aid_of', { field: fieldLabel(aid.field) });
        const head = h('div', { class: 'nfe-panel__head' }, [
            button(t('back_to_structure'), { icon: 'back', iconOnly: true, onclick: closeWritingAid }),
            h('h2', { class: 'nfe-panel__title' }, [glyph('✦'), ' ', title]),
            button(t('close'), { icon: 'close', iconOnly: true, onclick: closeWritingAid }),
        ]);
        const body = h('div', { class: 'nfe-panel__body nfe-aid' }, [
            h('p', { class: 'nfe-aid__note' }, [h('span', { class: 'nfe-ai-badge', text: t('ai') }), ' ', t('writing_aid_note')]),
        ]);

        if (aid.fields.length > 1) {
            body.append(h('fieldset', { class: 'nfe-aid__fields' }, [
                h('legend', { text: t('for') }),
                ...aid.fields.map((field) => h('label', {}, [h('input', { type: 'radio', name: 'nfe-aid-field', value: field, checked: field === aid.field, onchange: () => { aid.field = field; aid.proposals = []; renderWritingAid(); } }), ' ', fieldLabel(field)])),
            ]));
        }

        const term = h('input', { type: 'text', class: 'tl_text', id: 'nfe-aid-term', value: aid.term, maxlength: '120', oninput: (event) => { aid.term = event.target.value; } });
        const create = button(t('create_proposals'), { className: 'nfe-button--primary', action: 'aid-create', onclick: createProposals });

        if (aid.busy || !ed.writingAidReady || ed.writingAidLeft < 1) {
            create.disabled = true;
        }

        create.setAttribute('aria-busy', aid.busy ? 'true' : 'false');
        body.append(h('div', { class: 'nfe-aid__term' }, [h('label', { for: 'nfe-aid-term' }, [h('span', { text: t('search_term') })]), h('div', { class: 'nfe-aid__term-row' }, [term, create])]));

        const problem = !ed.writingAidReady ? t('error.ai_not_configured') : (ed.writingAidLeft < 1 ? t('error.ai_limit_reached') : aid.error);

        if (problem) {
            body.append(h('p', { class: 'nfe-status nfe-status--error', role: 'alert', text: problem }));
        }

        if (aid.proposals.length) {
            body.append(h('ol', { class: 'nfe-aid__list' }, aid.proposals.map((proposal, index) => {
                const preview = h('div', { class: 'nfe-aid__text' });

                if (aid.field === 'text') {
                    preview.innerHTML = proposal;
                } else {
                    preview.textContent = proposal;
                }

                return h('li', { class: 'nfe-aid__item' }, [
                    h('div', { class: 'nfe-aid__item-head' }, [h('span', { text: t('proposal_n', { n: index + 1 }) }), h('span', { class: 'nfe-ai-badge', text: t('ai') })]),
                    preview,
                    button(t('apply'), { onclick: () => applyProposal(proposal) }),
                ]);
            })));
        }

        // While the AI writes: the veil over the writing aid, and the same in a status line for screen readers.
        if (aid.busy) {
            body.append(h('p', { class: 'nfe-visually-hidden', role: 'status', text: t('aid_busy') }));
            coverArea(body, t('aid_busy'));
        }

        panel.replaceChildren(head, body, h('div', { class: 'nfe-panel__foot' }, [h('p', { class: 'nfe-aid__foot', text: t('requests_left', { left: ed.writingAidLeft ?? 0, limit: ed.writingAidLimit ?? 0 }) })]));
        panel.setAttribute('aria-label', title);
    }

    async function createProposals() {
        const aid = state.aid;

        if (!state.editing || aid.busy) {
            return;
        }

        aid.busy = true;
        aid.error = '';
        renderWritingAid();

        try {
            const result = await api('/element/' + state.editing.id + '/suggest', { method: 'POST', body: JSON.stringify({ field: aid.field, term: aid.term }) });
            aid.proposals = result.proposals;
            state.structure.editions.writingAidLeft = result.left;
        } catch (error) {
            aid.error = error.status ? error.message : t('save_failed');
        } finally {
            aid.busy = false;
        }

        renderWritingAid();
        (panel.querySelector('.nfe-aid__list button') || panel.querySelector('[role="alert"]'))?.focus();
    }

    function applyProposal(proposal) {
        if (!state.editing) {
            return;
        }

        applyFields(state.aid.field === 'headline' ? { headline: proposal } : { text: proposal });
        markDirty();
        placeSelection();
        announce(t('apply'));
    }

    // ------------------------------------------------------------------ views, pages, posts, modules (Plus)

    const PLUS_VIEWS = { posts: 'hintPosts', modules: 'hintModules' };

    // Views of the sidebar: page, posts, modules, languages; each only for users who may use it.
    function viewsNav(active) {
        const ed = editions();
        const languages = (ed.languages || []).length > 0 || ed.upgrade || ed.changelanguageMissing;
        const items = [
            ['structure', t('view_page'), 'page', true, false],
            ['posts', t('view_posts'), 'posts', ed.posts || ed.hintPosts, ed.hintPosts],
            ['modules', t('view_modules'), 'modules', ed.modules || ed.hintModules, ed.hintModules],
            ['languages', t('languages'), 'translate', languages, ed.upgrade],
            ['check', t('view_check'), 'search', ed.check || ed.hintCheck, ed.hintCheck ? 'pro' : false],
        ].filter((item) => item[3]);

        if (items.length < 2) {
            return null;
        }

        return h('nav', { class: 'nfe-views', 'aria-label': t('views') }, items.map(([key, label, name, , hint]) => h('button', {
            type: 'button',
            class: 'nfe-views__item',
            'aria-pressed': String(active === key),
            'data-view': key,
            onclick: () => openView(key),
        }, [
            h('span', { class: 'nfe-views__icon' }, [maskIcon(name), hint ? h('span', { class: 'nfe-plus-badge', text: t(hint === 'pro' ? 'pro' : 'plus') }) : null]),
            h('span', { text: label }),
        ])));
    }

    // The tabs of the sidebar: a text being edited is left, its changes wait for Save (they stay on the page).
    function openView(key) {
        const ed = editions();

        if (state.editing) {
            parkEditing();
        }

        if (key === 'languages') {
            if ((ed.languages || []).length) {
                openLanguages();
            } else {
                state.view = 'upgrade';
                renderPanel();
            }
        } else if (PLUS_VIEWS[key] && ed[PLUS_VIEWS[key]]) {
            state.view = 'upgrade_plus';
            state.upgradeFrom = key;
            renderPanel();
        } else if (key === 'check') {
            if (ed.hintCheck) {
                state.view = 'upgrade_pro';
                renderPanel();
            } else {
                openCheck();
            }
        } else if (key === 'posts') {
            openPosts();
        } else if (key === 'modules') {
            openModules();
        } else {
            state.view = 'structure';
            renderPanel();
        }

        panel.querySelector('.nfe-views__item[aria-pressed="true"]')?.focus();
    }

    const dateText = (timestamp) => new Intl.DateTimeFormat(config.lang || undefined, { dateStyle: 'medium' }).format(new Date(timestamp * 1000));

    // State of a page or post: published, not published, from a date, expired.
    function stateChip(status, start) {
        const labels = { published: t('state_published'), draft: t('state_draft'), planned: t('state_planned', { date: start ? dateText(start) : '' }), expired: t('state_expired') };

        return h('span', { class: 'nfe-state nfe-state--' + status }, [icon(status === 'draft' || status === 'expired' ? 'hide' : 'visible'), h('span', { text: labels[status] || status })]);
    }

    function pageStatus(page) {
        const now = Date.now() / 1000;

        return !page.published ? 'draft' : (page.start && page.start > now ? 'planned' : (page.stop && page.stop <= now ? 'expired' : 'published'));
    }

    // Above the structure of the page (Plus): its state, publish/withdraw, new page, more.
    function pageBar() {
        const page = state.structure?.page;

        const ed = editions();

        if (!page || (!ed.pages && !ed.design)) {
            return null;
        }

        const status = pageStatus(page);
        const more = [
            page.canSettings ? { label: t('page_settings'), icon: 'settings', action: openPageSettings } : null,
            ed.style && page.style?.can ? { label: t('style_menu'), icon: 'code', action: openStyle } : null,
            ed.hintStyle ? { label: t('style_menu'), icon: 'code', badge: t('pro'), action: () => openView('upgrade_pro') } : null,
            ed.design ? { label: t('page_templates'), icon: 'info', action: openPageTemplates } : null,
            ed.design && page.layout?.canEdit ? { label: t('layout_edit'), icon: 'layout', action: openLayout } : null,
            page.canCopy ? { label: t('page_duplicate'), icon: 'copy', action: copyPage } : null,
            page.canDelete ? { label: t('page_delete'), icon: 'delete', action: deletePage } : null,
        ].filter(Boolean);

        return h('div', { class: 'nfe-pagebar' }, [
            ed.pages ? h('div', { class: 'nfe-statusline' }, [stateChip(status, page.start), styleChip(page)]) : null,
            actionRow([
                page.canPublish ? button(page.published ? t('withdraw') : t('publish'), { icon: page.published ? 'hide' : 'visible', className: page.published ? '' : 'nfe-button--primary', onclick: togglePage }) : null,
                page.canCreate ? button(t('new_page'), { icon: 'new', onclick: openNewPage }) : null,
                more.length ? button(t('page_more'), { icon: 'more', iconOnly: true, onclick: (event) => openMenu(event.currentTarget, more, t('page_more')) }) : null,
            ]),
        ]);
    }

    // Opens another page or post in Contao's preview (its own address resolution, also for other domains).
    // A draft is only visible with "Unpublished: show" in Contao's preview bar, which is switched on first.
    async function goToPreview(query, draft = false) {
        if (draft) {
            await showUnpublished();
        }

        state.dirty = false;
        location.href = config.backend + '/preview?' + query;
    }

    // Contao's preview bar, with its own form and request token: "Unpublished" set to "show", the member kept.
    async function showUnpublished() {
        // The bar is a custom element with an open shadow root (cto-toolbar), older versions put it in the page.
        const roots = [document, ...[...document.querySelectorAll('*')].filter((node) => node.shadowRoot && node !== host).map((node) => node.shadowRoot)];
        const form = roots.flatMap((rootNode) => [...rootNode.querySelectorAll('form')]).find((node) => /preview_switch/.test(node.getAttribute('action') || ''));
        const select = form?.querySelector('[name=unpublished]');

        if (!form || !select || select.value === 'show') {
            return;
        }

        const data = new FormData(form);

        data.set('unpublished', 'show');

        try {
            await fetch(form.action, { method: 'POST', body: data, credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        } catch (e) {
            // Without the switch, the preview shows the draft once "Unpublished: show" is chosen in its bar.
        }
    }

    async function pageCall(path, body = {}) {
        if (state.busy || !(await settle())) {
            return null;
        }

        state.busy = true;

        try {
            return await api(path, { method: 'POST', body: JSON.stringify(body) });
        } catch (error) {
            showToast(error.message || t('save_failed'));

            return null;
        } finally {
            state.busy = false;
        }
    }

    async function togglePage() {
        const page = state.structure.page;

        if (page.published && !window.confirm(t(page.hasSubpages ? 'page_withdraw_confirm_children' : 'page_withdraw_confirm', { page: page.title }))) {
            return;
        }

        if (await pageCall('/page/' + page.id + '/toggle')) {
            reloadPage({ focus: { where: 'tree' }, announce: t(page.published ? 'page_withdrawn' : 'page_published') });
        }
    }

    async function copyPage() {
        const result = await pageCall('/page/' + state.structure.page.id + '/copy');

        if (result) {
            goToPreview('page=' + result.id, true);
        }
    }

    async function deletePage() {
        const page = state.structure.page;

        if (!window.confirm(t('page_delete_confirm', { page: page.title }))) {
            return;
        }

        const result = await pageCall('/page/' + page.id + '/delete');

        if (result) {
            goToPreview('page=' + result.parent);
        }
    }

    async function openPageSettings() {
        if (!(await settle())) {
            return;
        }

        openRecordForm({
            table: 'tl_page',
            id: state.structure.page.id,
            module: 'page',
            view: 'page_settings',
            title: t('page_settings'),
            isNew: false,
            onMessage: (data, loads) => showSavedForm(loads),
        });
    }

    // New page: Contao's own page tree in create mode, then Contao's form (name); after saving, the new page opens.
    // All pages: Contao's page tree, the place where new pages are created (as "New page").
    function openPageTree() {
        openNewPage(t('all_pages'));
    }

    function openNewPage(title = null) {
        openRecordForm({
            table: 'tl_page',
            id: 0,
            title: title || t('new_page'),
            isNew: true,
            hint: t('new_page_hint'),
            src: config.api + '/page/' + config.page + '/tree?popup=1&origin=' + encodeURIComponent(location.origin),
            onMessage: async (data) => {
                const params = new URLSearchParams(data.search || '');
                // The form of the new page, or (after "Save and close") the list Contao returns to.
                const id = params.get('act') === 'edit' ? Number(params.get('id')) : (state.form?.id || 0);

                if (!id || !state.form) {
                    return;
                }

                state.form.id = id;
                state.form.hint = null;
                panel.querySelector('.nfe-form-hint')?.remove();

                try {
                    const page = await api('/page/' + id + '/state');

                    if (page.saved) {
                        state.form.saved = true;

                        // The same page in the other languages of the website (Plus), before the new page opens.
                        const plan = await api('/page/' + id + '/versions').catch(() => null);

                        if (plan?.languages?.length) {
                            await startLanguageVersions(id, plan);

                            return;
                        }

                        goToPreview('page=' + id, !page.published);
                    }
                } catch (e) {
                    // The form stays open; closing it removes the draft.
                }
            },
        });
    }

    // ---- language versions of a new page (Plus): the same page in the other languages, linked

    // The page in the main language is always needed (changelanguage links every version to it): it is
    // created right away, without a click. The step then offers only the other languages.
    async function startLanguageVersions(pageId, plan) {
        const main = plan.languages.find((language) => language.required);
        let auto = null;

        if (main && main.target && main.canCreate && plan.canLink) {
            auto = await api('/page/' + pageId + '/versions', { method: 'POST', body: JSON.stringify({ page: config.page, versions: [{ root: main.root, title: main.title, alias: '' }] }) })
                .catch((error) => ({ created: [], errors: [{ language: main.name, message: error.status ? error.message : t('save_failed') }] }));
            auto.translated = main.translated;
            plan = (await api('/page/' + pageId + '/versions').catch(() => null)) || { ...plan, languages: [] };
        }

        if (auto?.created?.length && !plan.languages.length) {
            rememberNotice(autoNotice(auto));
            goToPreview('page=' + pageId, true);

            return;
        }

        openLanguageVersions(pageId, plan, auto);
    }

    function autoNotice(auto) {
        const entry = auto.created[0];

        return t('lv_auto', { language: entry.language, name: entry.title, alias: entry.alias }) + (auto.translated ? '' : ' ' + t('lv_auto_untranslated'));
    }

    // A message for the next page view (e.g. after the new page opened).
    function rememberNotice(message) {
        try {
            sessionStorage.setItem('nfe:notice', message);
        } catch (e) {
            // Without storage the message is left out; the page itself is created.
        }
    }

    function showRememberedNotice() {
        let message = null;

        try {
            message = sessionStorage.getItem('nfe:notice');
            sessionStorage.removeItem('nfe:notice');
        } catch (e) {
            message = null;
        }

        if (message) {
            showToast(message, null, 'ok');
        }
    }

    function openLanguageVersions(pageId, plan, auto = null) {
        state.form?.stop?.();
        state.form = null;
        state.versions = { pageId, plan, auto, result: null, busy: false };
        state.view = 'versions';
        renderPanel();
        panel.querySelector('.nfe-panel__body input, .nfe-panel__body button')?.focus();
    }

    function finishLanguageVersions() {
        const pageId = state.versions?.pageId;

        state.versions = null;
        goToPreview('page=' + pageId, true);
    }

    function renderLanguageVersions() {
        const data = state.versions;
        const plan = data.plan;
        const title = t('lv_title');
        const body = h('div', { class: 'nfe-panel__body nfe-versions' });

        panel.replaceChildren(
            h('div', { class: 'nfe-panel__head' }, [
                h('h2', { class: 'nfe-panel__title', text: title }),
                button(t('close'), { icon: 'close', iconOnly: true, onclick: finishLanguageVersions }),
            ]),
            body,
        );
        panel.setAttribute('aria-label', title);

        if (data.result) {
            const created = [...(data.auto?.created || []), ...(data.result.created || [])];
            const errors = data.result.errors || [];

            if (created.length) {
                body.append(h('p', { text: t('lv_done') }), h('ul', { class: 'nfe-versions__done' }, created.map((entry) => h('li', {}, [
                    h('strong', { text: entry.language + ': ' }),
                    h('span', { text: entry.title + ' (' + entry.alias + ')' }),
                    button(t('lv_open'), { icon: 'preview', onclick: () => goToPreview('page=' + entry.id, true) }),
                ]))));
            }

            errors.forEach((error) => body.append(h('p', { class: 'nfe-versions__error', role: 'alert', text: t('lv_failed', { language: error.language, reason: (error.message || '').replace(/\.$/, '') || t('lv_reason_' + (['forbidden', 'place'].includes(error.reason) ? error.reason : 'failed')) }) })));

            // Back to the step with the entered values, for the languages that are still missing.
            const retry = errors.length ? button(t('lv_retry'), { icon: 'undo', onclick: async () => {
                const next = await api('/page/' + data.pageId + '/versions').catch(() => null);

                if (!next?.languages?.length) {
                    finishLanguageVersions();

                    return;
                }

                data.plan = next;
                data.result = null;
                renderPanel();
                panel.querySelector('.nfe-panel__body input:not([disabled]), .nfe-panel__body select')?.focus();
            } }) : null;

            body.append(h('div', { class: 'nfe-versions__actions' }, [button(t('lv_finish'), { icon: 'ok', className: 'nfe-button--primary', onclick: finishLanguageVersions }), retry]));

            return;
        }

        if (data.auto?.created?.length) {
            body.append(h('p', { class: 'nfe-versions__auto', role: 'status', text: autoNotice(data.auto) }));
        }

        (data.auto?.errors || []).forEach((error) => body.append(h('p', { class: 'nfe-versions__error', role: 'alert', text: t('lv_failed', { language: error.language, reason: (error.message || '').replace(/\.$/, '') || t('lv_reason_' + (['forbidden', 'place'].includes(error.reason) ? error.reason : 'failed')) }) })));
        body.append(h('p', { text: data.auto?.created?.length ? t('lv_others') : t('lv_intro', { page: plan.page.title, language: plan.page.language }) }));

        if (!plan.canLink) {
            body.append(h('p', { class: 'nfe-versions__error', text: t('lv_no_link') }), h('div', { class: 'nfe-versions__actions' }, [button(t('lv_skip'), { onclick: finishLanguageVersions })]));

            return;
        }

        const rows = plan.languages.map((language) => {
            const id = 'nfe-lv-' + language.root;
            const possible = language.target ? language.canCreate : (language.parents || []).length > 0;
            const check = h('input', { type: 'checkbox', id: id + '-on', checked: possible ? '' : null, disabled: language.required || !possible ? '' : null });
            const entered = data.values?.[language.root] || {};
            const name = h('input', { class: 'tl_text', type: 'text', id: id + '-title', value: entered.title ?? language.title, required: '' });
            const alias = plan.canAlias ? h('input', { class: 'tl_text', type: 'text', id: id + '-alias', value: entered.alias || null, placeholder: language.aliasProposal || '', 'aria-describedby': id + '-alias-hint' }) : null;
            const parent = language.target ? null : h('select', { class: 'tl_select', id: id + '-parent' }, (language.parents || []).map((entry) => h('option', { value: String(entry.id), selected: entry.id === entered.parent ? '' : null, text: '\u00a0\u00a0'.repeat(entry.depth) + entry.title })));
            const fields = [
                h('div', { class: 'nfe-versions__field' }, [h('label', { for: id + '-title', text: t('lv_name') }), name, h('span', { class: 'nfe-versions__hint', text: language.translated ? t('lv_proposal', { service: serviceName() }) : t('lv_untranslated') })]),
                alias ? h('div', { class: 'nfe-versions__field' }, [h('label', { for: id + '-alias', text: t('lv_alias') }), alias, h('span', { class: 'nfe-versions__hint', id: id + '-alias-hint', text: t('lv_alias_hint') })]) : null,
                language.target
                    ? h('p', { class: 'nfe-versions__place' }, [h('strong', { text: t('lv_place') + ': ' }), h('span', { text: language.target.afterTitle ? t('lv_place_after', { parent: language.target.parentTitle, page: language.target.afterTitle }) : t('lv_place_first', { parent: language.target.parentTitle }) })])
                    : h('div', { class: 'nfe-versions__field' }, [h('p', { class: 'nfe-versions__missing', text: t('lv_missing', { page: language.missing || '', language: language.name }) }), h('label', { for: id + '-parent', text: t('lv_choose') }), parent]),
                language.target && !language.canCreate ? h('p', { class: 'nfe-versions__error', text: t('lv_no_right', { language: language.name }) }) : null,
            ];

            return {
                language,
                node: h('fieldset', { class: 'nfe-versions__row' }, [
                    h('legend', {}, [check, h('label', { for: id + '-on', text: language.name }), language.main ? h('span', { class: 'nfe-versions__main', text: t('lv_main_badge') }) : null]),
                    language.required ? h('p', { class: 'nfe-versions__hint', text: t('lv_main') }) : null,
                    ...fields,
                ]),
                collect: () => (check.checked ? { root: language.root, title: name.value.trim(), alias: alias ? alias.value.trim() : '', ...(parent ? { parent: Number(parent.value) } : {}) } : null),
            };
        });

        const required = plan.languages.some((language) => language.required);
        const create = button(t('lv_create'), { icon: 'new', className: 'nfe-button--primary', onclick: async () => {
            const versions = rows.map((row) => row.collect()).filter(Boolean);

            data.values = Object.fromEntries(versions.map((version) => [version.root, version]));

            if (!versions.length || data.busy) {
                return;
            }

            if (versions.some((version) => !version.title)) {
                showToast(t('lv_name_missing'));

                return;
            }

            data.busy = true;
            create.disabled = true;

            try {
                data.result = await api('/page/' + data.pageId + '/versions', { method: 'POST', body: JSON.stringify({ page: config.page, versions }) });
                renderPanel();
                panel.querySelector('.nfe-panel__body button')?.focus();
            } catch (error) {
                showToast(error.status ? error.message : t('save_failed'));
                create.disabled = false;
            } finally {
                data.busy = false;
            }
        } });

        body.append(
            ...rows.map((row) => row.node),
            h('p', { class: 'nfe-versions__hint', text: t('lv_note') }),
            h('div', { class: 'nfe-versions__actions' }, [create, required ? null : button(t('lv_skip'), { onclick: finishLanguageVersions })]),
        );
    }

    // ---- templates, modules, layout, form fields, comments, posts in lists (Basic; posts: Plus)

    // The marked parts of the page and what the user may do with them (one request for all).
    async function loadItems() {
        state.items = findItems();
        state.itemData = {};
        state.moduleTexts = {};

        const ed = editions();
        const keys = [...state.items.keys()].filter((key) => (key.startsWith('lp:') ? ed.posts : ed.design));

        await loadModuleTexts([...state.items.keys()].filter(hasTexts));

        if (!keys.length) {
            return;
        }

        try {
            const data = await api('/design/items?page=' + config.page + '&keys=' + encodeURIComponent(keys.join(',')));

            state.itemData = { ...state.itemData, ...(data.items || {}) };
            state.studio = Boolean(data.studio);
        } catch (e) {
            // Without the facts the parts stay as they are on the page.
        }
    }

    // Parts whose texts are edited like an element's: modules, and posts in lists and readers (Plus).
    const hasTexts = (key) => key.startsWith('fm:') || (key.startsWith('lp:') && Boolean(editions().posts));

    // The texts of the modules on the page with their sources (also without the switch for web designers):
    // a module with texts becomes a part of its own, whose texts are edited like an element's. The same for the
    // posts a list or a reader shows: their headline, teaser … are saved into the post.
    async function loadModuleTexts(keys) {
        if (!keys.length) {
            return;
        }

        try {
            const data = await api('/texts?page=' + config.page + '&keys=' + encodeURIComponent(keys.join(',')));

            for (const [key, texts] of Object.entries(data.modules || {})) {
                state.moduleTexts[key] = texts;

                if (texts.sources.length && !state.itemData[key] && key.startsWith('fm:')) {
                    state.itemData[key] = { kind: 'module', id: Number(key.slice(3)), label: texts.name, typeLabel: texts.typeLabel, canEdit: false, textsOnly: true };
                }
            }

            // Elements may show the modules' values: their targets are found again.
            targetCache.clear();
        } catch (e) {
            // The modules' texts stay as they are.
        }
    }

    function itemLabel(data) {
        return t('item_' + data.kind, { name: data.label || data.typeLabel });
    }

    function selectItem(key) {
        if (state.editing && state.editing.item !== key) {
            parkEditing();
        }

        state.reveal = state.item !== key;
        state.item = key;
        showItem(key);
    }

    // Frame, name and buttons of a marked part, in the places of an element's.
    function showItem(key) {
        const item = state.items?.get(key);
        const data = state.itemData?.[key];
        const box = item ? boxOfRange(item) : null;

        insertAbove.hidden = insertBelow.hidden = true;

        if (!box || !data) {
            frame.hidden = label.hidden = true;

            return;
        }

        frame.hidden = label.hidden = false;
        fillTab(itemLabel(data), null, itemMenuItems(key, data).some((item) => !item.separator) ? () => itemMenuItems(key, data) : null, null);
        showHover();
        placeSelection();
    }

    // Why Contao does not allow a change: the permissions of the user group that are missing, in Contao's words.
    function missingNodes(item) {
        if (!item || item.canEdit || !Array.isArray(item.missing)) {
            return [];
        }

        return [h('div', { class: 'nfe-missing' }, [
            h('h3', { text: t('missing_title') }),
            h('p', { text: item.missing.length ? t('missing_intro') : t('missing_other') }),
            item.missing.length ? h('ul', {}, item.missing.map((entry) => h('li', {}, [h('strong', { text: entry.field + ': ' }), h('span', { translate: 'no', text: entry.value })]))) : null,
        ])];
    }

    // Contao's form of a module, form field or comment in the sidebar; a post's properties as in the view "Posts".
    async function openItemForm(data) {
        closeInfo(false);

        // Contao's form saves what is stored and the page loads again: waiting changes are saved or dropped first.
        if (!(await settle())) {
            return;
        }

        if (data.kind === 'post') {
            openPostForm(data.post, false, data.post.table, data.post.module);

            return;
        }

        openRecordForm({
            table: data.form.table,
            id: data.form.id,
            module: data.form.module,
            title: t(data.kind + '_of', { name: data.label }),
            isNew: false,
            onMessage: (message, loads) => showSavedForm(loads),
        });
    }

    // A new field is a draft until Contao's form is saved; left without saving, it is removed (discardPath).
    async function createField(data) {
        closeInfo(false);

        const result = await structureCall('/design/field/' + data.id + '/after', { page: config.page }, null);

        if (result) {
            openRecordForm({
                table: 'tl_form_field',
                id: result.id,
                module: 'form',
                title: t('field_new'),
                isNew: true,
                onMessage: (message, loads) => showSavedForm(loads),
            });
        }
    }

    // Deleting asks first; the field stays in Contao's undo list. The page loads again without it.
    function deleteField(data) {
        closeInfo(false);

        if (window.confirm(t('field_delete_confirm', { name: data.label }))) {
            structureCall('/design/field/' + data.id + '/delete', { page: config.page }, { focus: { where: 'tree' } });
        }
    }

    function openLayout() {
        const layout = state.structure.page.layout;

        openRecordForm({
            table: 'tl_layout',
            id: layout.id,
            module: 'themes',
            title: t('layout_of', { name: layout.name }),
            isNew: false,
            onMessage: (message, loads) => showSavedForm(loads),
        });
    }

    const sourceText = (template) => ({
        contao: t('source_contao'),
        extension: t('source_extension', { package: template.package || '' }),
        custom: t('source_custom'),
        theme: t('source_theme'),
    }[template.source] || t('source_unknown'));

    // A template with the templates it uses, indented below it.
    function templateNodes(list, depth = 0) {
        return list.flatMap((template) => [
            h('div', { class: 'nfe-tpl' + (depth ? ' nfe-tpl--inner' : ''), style: depth ? '--nfe-tpl-depth:' + depth : null }, [
                h('div', { class: 'nfe-tpl__head' }, [
                    h('span', { class: 'nfe-tpl__name', translate: 'no', text: template.name }),
                    h('span', { class: 'nfe-tpl__source', text: sourceText(template) }),
                ]),
                template.path ? h('span', { class: 'nfe-tpl__path', translate: 'no', text: template.path }) : null,
            ]),
            ...templateNodes(template.inner || [], depth + 1),
        ]);
    }

    // Where a template is changed: Twig templates in Contao's Template Studio; legacy .html5 templates in Contao's
    // template editor (a copy in the templates folder, or the copy that already exists).
    function templateAction(template) {
        if (!state.studio || !template || template.source === 'unknown') {
            return null;
        }

        if (template.kind === 'twig') {
            return button(t('template_studio_open'), { icon: 'editor', onclick: () => openStudio(template) });
        }

        if (template.source === 'custom' && template.path) {
            return button(t('template_edit_file'), { icon: 'edit', onclick: () => openTemplateFile(template) });
        }

        return template.path ? button(t('template_customize'), { icon: 'new', onclick: () => openTemplateFile(template) }) : null;
    }

    function openStudio(template) {
        closeInfo(false);

        const origin = encodeURIComponent(location.origin);

        openRecordForm({
            table: 'tl_templates',
            id: 0,
            title: t('template_studio') + ': ' + template.name,
            isNew: false,
            returnTo: 'structure',
            src: config.backend + '/template-studio?popup=1&nfe_origin=' + origin + '&nfe_view=template&nfe_template=' + encodeURIComponent(template.name),
            onMessage: () => {},
            wide: true,
        });
        // Template Studio saves without loading its page; after closing, the page shows the changed template.
        state.form.changed = true;
    }

    function openTemplateFile(template) {
        closeInfo(false);

        const origin = encodeURIComponent(location.origin);
        const query = template.source === 'custom'
            ? 'do=tpl_editor&act=source&id=' + encodeURIComponent(template.path)
            : 'do=tpl_editor&key=new_tpl&original=' + encodeURIComponent(template.path) + '&target=templates';

        openRecordForm({
            table: 'tl_templates',
            id: 0,
            title: template.name,
            isNew: false,
            src: config.backend + '?' + query + '&popup=1&nb=1&nfe_origin=' + origin,
            onMessage: () => {},
        });
        state.form.changed = true;
    }

    // The card "Info": templates of an element or a marked part, the way to change them, and for elements the classes for the page style.
    async function openInfo(target, anchor) {
        closeInfo(false);
        closeMenu(false);

        const card = h('div', { class: 'nfe-info-card', role: 'dialog', 'aria-label': t('info_of', { name: target.title }), tabindex: '-1' });
        const close = button(t('close'), { icon: 'close', iconOnly: true, onclick: () => closeInfo(true) });

        card.append(h('div', { class: 'nfe-info-card__head' }, [h('h2', { text: target.title }), close]), loadingNote('nfe-info-card__note'));
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                closeInfo(true);
            } else if (event.key === 'Tab') {
                // Focus stays in the card while it is open.
                const focusable = [...card.querySelectorAll('button')];
                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (event.shiftKey && root.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && root.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
        root.append(card);
        state.info = { card, anchor };
        placeInfo();
        close.focus();

        let templates = target.data?.templates || [];
        let classes = null;

        if (target.kind === 'content') {
            try {
                const data = await api('/design/content/' + target.id + '?page=' + config.page);

                templates = data.templates || [];
                classes = data.cssClass;
                state.studio = Boolean(data.studio);
            } catch (e) {
                templates = [];
            }
        }

        if (state.info?.card !== card) {
            return;
        }

        const body = templates.length ? [h('h3', { text: t('templates') }), ...templateNodes(templates)] : [];
        const action = templateAction(templates[0]);

        if (action || (target.data?.canEdit && target.kind !== 'content')) {
            body.push(h('div', { class: 'nfe-info-card__actions' }, [
                target.data?.canEdit && target.kind !== 'content' ? button(t('edit_' + target.kind), { icon: 'edit', onclick: () => openItemForm(target.data) }) : null,
                action,
            ]));
        }

        if (target.kind !== 'content') {
            body.push(...missingNodes(target.data));
        }

        if (classes) {
            body.push(h('h3', { text: t('style_hint_title') }), h('p', {}, [
                t('style_hint_class', { class: '.' + classes.type }) + ' ',
                classes.own ? t('style_hint_own', { class: classes.own.split(/\s+/).map((name) => '.' + name).join(' ') }) : t('style_hint_add'),
            ]));
        }

        card.querySelector('.nfe-info-card__note').replaceWith(...body);
        placeInfo();
    }

    // Below its button, within the page's area of the view (left of the sidebar); it follows the page when it scrolls.
    function placeInfo() {
        const info = state.info;

        if (!info) {
            return;
        }

        const box = info.anchor?.isConnected ? viewRect(info.anchor) : null;
        const area = stage.getBoundingClientRect();
        const width = Math.min(400, area.width - 16);
        const left = box ? Math.max(area.left + 8, Math.min(box.right - width, area.right - width - 8)) : area.left + 16;

        Object.assign(info.card.style, { width: width + 'px', left: left + 'px', top: Math.max(area.top + 8, box ? box.bottom + 6 : area.top + 16) + 'px' });
    }

    function closeInfo(returnFocus) {
        const info = state.info;

        if (!info) {
            return;
        }

        state.info = null;
        info.card.remove();

        if (returnFocus && info.anchor?.isConnected) {
            info.anchor.focus();
        }
    }

    // Overview: the templates of the page, its layout's modules and its elements.
    async function openPageTemplates() {
        state.view = 'templates';
        state.pageTemplates = null;
        renderPanel();

        try {
            state.pageTemplates = await api('/design/page/' + config.page);
            state.studio = Boolean(state.pageTemplates.studio);
        } catch (e) {
            state.pageTemplates = { error: e.message || t('save_failed') };
        }

        if (state.view === 'templates') {
            renderPanel();
        }
    }

    function renderPageTemplates() {
        const data = state.pageTemplates;
        const title = t('page_templates_of', { page: state.structure?.page.title || '' });
        const body = h('div', { class: 'nfe-panel__body nfe-tpl-overview' });
        const row = (what, templates, item = null) => h('div', { class: 'nfe-tpl-row' }, [
            h('div', { class: 'nfe-tpl-row__what', text: what }),
            ...templateNodes(templates),
            ...missingNodes(item),
            h('div', { class: 'nfe-tpl-row__actions' }, [
                item?.canEdit && item.kind === 'layout' ? button(t('layout_edit'), { icon: 'layout', onclick: openLayout }) : null,
                item?.canEdit && item.kind !== 'layout' ? button(t('edit_' + item.kind), { icon: 'edit', onclick: () => openItemForm(item) }) : null,
                templateAction(templates[0]),
            ]),
        ]);

        panel.replaceChildren(
            h('div', { class: 'nfe-panel__head' }, [
                button(t('back_to_structure'), { icon: 'back', iconOnly: true, onclick: () => openView('structure') }),
                h('h2', { class: 'nfe-panel__title', text: title }),
                button(t('close'), { icon: 'close', iconOnly: true, onclick: () => openView('structure') }),
            ]),
            body,
        );
        panel.setAttribute('aria-label', title);

        if (!data) {
            body.append(loadingNote());

            return;
        }

        if (data.error) {
            body.append(h('p', { text: data.error }));

            return;
        }

        body.append(h('p', { class: 'nfe-tpl-overview__intro', text: t('page_templates_intro') }));
        body.append(h('h3', { text: t('tpl_group_page') }), row(t('tpl_page_layout', { layout: data.page.layout }), data.page.templates || [], state.structure?.page.layout ? { kind: 'layout', ...state.structure.page.layout } : null));

        if ((data.modules || []).length) {
            body.append(h('h3', { text: t('tpl_group_modules') }), ...data.modules.map((module) => row(module.label + ' (' + module.column + ')', module.templates || [], module)));
        }

        if ((data.elements || []).length) {
            body.append(h('h3', { text: t('tpl_group_elements') }), ...data.elements.map((group) => row(group.count > 1 ? tn('tpl_elements_count', group.count, { type: group.typeLabel, n: group.count }) : group.typeLabel, group.templates || [])));
        }

        // The other parts on the page (modules in the content, form fields, comments, posts in lists), also reachable with the keyboard here.
        const layoutModules = new Set((data.modules || []).map((module) => 'fm:' + module.id));
        const parts = Object.entries(state.itemData || {}).filter(([key, item]) => !layoutModules.has(key) && (item.canEdit || (item.templates || []).length || Array.isArray(item.missing)));

        if (parts.length) {
            body.append(h('h3', { text: t('tpl_group_parts') }), ...parts.map(([, item]) => row(itemLabel(item), item.templates || [], item)));
        }
    }

    // ---- page style (Pro): CSS or SCSS of the page in Contao's form, shown on the page while typing

    // Next to the state of the page: its own style, or the style of a page above that applies to it.
    function styleChip(page) {
        if (!page.style || (!page.style.own && !page.style.from)) {
            return null;
        }

        return h('span', { class: 'nfe-state' }, [icon('code'), h('span', { text: page.style.own ? t('style_own') : t('style_from', { page: page.style.from }) })]);
    }

    // The <style> element of a page's style in the head (delivered by the server, or added for the preview).
    function pageStyleElement(pageId, create) {
        if (!pageDoc?.head) {
            return null;
        }

        let element = [...pageDoc.head.querySelectorAll('style[data-nfe-page-style]')].find((node) => node.dataset.nfePageStyle === String(pageId));

        if (!element && create) {
            element = pageDoc.createElement('style');
            element.dataset.nfePageStyle = String(pageId);

            if (config.styleNonce) {
                element.setAttribute('nonce', config.styleNonce);
            }

            pageDoc.head.append(element);
        }

        return element || null;
    }

    async function openStyle() {
        const page = state.structure.page;
        let opened;

        if (!(await settle())) {
            return;
        }

        try {
            opened = await api('/style/' + page.id + '/open', { method: 'POST', body: JSON.stringify({ page: page.id }) });
        } catch (e) {
            showToast(e.message || t('save_failed'));

            return;
        }

        const saved = pageStyleElement(page.id, false);
        const style = { page: page.id, tstamp: opened.tstamp, savedCss: saved ? saved.textContent : null, request: 0 };
        const backendOrigin = new URL(config.backend, location.href).origin;

        openRecordForm({
            table: 'tl_neo_fe_style',
            id: opened.id,
            module: 'neo_frontendeditor_styles',
            view: 'style',
            title: t('style_of', { page: page.title }),
            isNew: opened.created,
            onMessage: async (data, loads) => {
                if (loads < 2 || !state.form) {
                    return;
                }

                // After "Save": Contao has stored it when the record is newer; otherwise its form shows the error.
                try {
                    const info = await api('/style/' + page.id);

                    if (info.saved && info.tstamp > style.tstamp) {
                        style.tstamp = info.tstamp;
                        style.savedCss = info.css;
                        state.form.saved = true;
                        state.form.changed = true;
                        styleStatus('saved');
                    }
                } catch (e) {
                    // The form stays as Contao shows it.
                }
            },
        });

        const form = state.form;
        const iframe = panel.querySelector('iframe.nfe-panel__form');
        const status = h('p', { class: 'nfe-style-status', role: 'status' });

        panel.querySelector('.nfe-panel__head').after(status);
        form.style = style;
        form.styleStatus = status;
        styleStatus('ready');

        // Every change in Contao's code editor arrives here (FormBridgeListener), half a second after typing stops.
        const listener = async (event) => {
            if (event.origin !== backendOrigin || event.source !== iframe.contentWindow || event.data?.type !== 'nfe:style' || state.form !== form) {
                return;
            }

            const request = ++style.request;

            try {
                const result = await api('/style/' + page.id + '/preview', { method: 'POST', body: JSON.stringify({ scss: String(event.data.scss || '') }) });

                if (request !== style.request || state.form !== form) {
                    return;
                }

                style.previewCss = result.css;
                pageStyleElement(page.id, true).textContent = result.css;
                styleStatus('preview');
                iframe.contentWindow.postMessage({ type: 'nfe:style-error', line: null }, backendOrigin);
            } catch (e) {
                if (request !== style.request || state.form !== form) {
                    return;
                }

                const text = e.data?.fields?.scss?.[0] || e.message || t('save_failed');

                styleStatus('error', text);
                iframe.contentWindow.postMessage({ type: 'nfe:style-error', line: e.data?.line || null, text }, backendOrigin);
            }
        };
        const stop = form.stop;

        window.addEventListener('message', listener);
        form.stop = () => {
            stop?.();
            window.removeEventListener('message', listener);

            // Left without saving: the page shows the saved style again.
            if (!form.saved) {
                const element = pageStyleElement(page.id, false);

                if (element && style.savedCss === null) {
                    element.remove();
                } else if (element) {
                    element.textContent = style.savedCss;
                }
            }
        };
    }

    // The line under the head of the sidebar: what the page shows right now.
    function styleStatus(kind, text = '') {
        const status = state.form?.styleStatus;

        if (!status) {
            return;
        }

        const icons = { ready: 'edit', preview: 'visible', saved: 'ok', error: 'error' };
        const texts = { ready: t('style_ready'), preview: t('style_preview'), saved: t('style_saved'), error: text + ' ' + t('style_kept') };

        status.className = 'nfe-style-status' + (kind === 'error' ? ' nfe-style-status--error' : '');
        status.replaceChildren(icon(icons[kind]), h('span', { text: texts[kind] }));
    }

    // ---- posts

    async function openPosts(keep = false) {
        state.view = 'posts';
        openPanel();

        if (!keep || !state.posts) {
            state.posts = { types: null, type: null, archive: null, items: null, q: '', loading: true };
            renderPanel();

            try {
                const result = await api('/posts?page=' + config.page);
                const types = result.types || [];

                state.posts.types = types;
                state.posts.type = types[0] || null;
                state.posts.archive = state.posts.type?.archives[0] || null;
            } catch (error) {
                showToast(error.message || t('save_failed'));
            }
        }

        await loadPosts();
    }

    async function loadPosts() {
        const posts = state.posts;

        posts.loading = true;
        renderPanel();

        if (posts.type && posts.archive) {
            try {
                const result = await api('/posts/' + posts.type.table + '/' + posts.archive.id + '?page=' + config.page + '&q=' + encodeURIComponent(posts.q));
                posts.items = result.items || [];
            } catch (error) {
                posts.items = [];
                showToast(error.message || t('save_failed'));
            }
        } else {
            posts.items = [];
        }

        posts.loading = false;

        if (state.view === 'posts') {
            renderPanel();
        }
    }

    function viewHead(title) {
        return h('div', { class: 'nfe-panel__head' }, [
            h('h2', { class: 'nfe-panel__title', text: title }),
            guideButton(),
            screenButton(),
            button(t('collapse'), { icon: 'collapse', iconOnly: true, action: 'collapse', onclick: () => setCollapsed(true) }),
            button(t('close_editor'), { icon: 'close', iconOnly: true, onclick: stopEditor }),
        ]);
    }

    function renderPosts() {
        const posts = state.posts || {};
        const type = posts.type;
        const body = h('div', { class: 'nfe-panel__body nfe-posts' });

        panel.replaceChildren(viewHead(t('view_posts')), viewsNav('posts') || '', body, h('div', { class: 'nfe-panel__foot' }, [h('div', { 'data-status': '' })]));
        panel.setAttribute('aria-label', t('view_posts'));
        renderStatus();

        if (!posts.types) {
            body.append(loadingNote('nfe-posts__context'));

            return;
        }

        if (!posts.types.length) {
            body.append(h('p', { class: 'nfe-posts__context', text: t('no_post_types') }));

            return;
        }

        const typeSelect = h('select', {
            class: 'tl_select',
            id: 'nfe-post-type',
            onchange: (event) => {
                posts.type = posts.types[Number(event.target.value)];
                posts.archive = posts.type.archives[0] || null;
                posts.q = '';
                loadPosts();
            },
        }, posts.types.map((entry, index) => h('option', { value: String(index), selected: entry === type ? '' : null, text: entry.label })));
        const archiveSelect = h('select', {
            class: 'tl_select',
            id: 'nfe-post-archive',
            onchange: (event) => {
                posts.archive = type.archives.find((archive) => String(archive.id) === event.target.value) || null;
                loadPosts();
            },
        }, type.archives.map((archive) => h('option', { value: String(archive.id), selected: archive === posts.archive ? '' : null, text: archive.title })));
        const search = h('input', {
            class: 'tl_text',
            type: 'search',
            value: posts.q,
            placeholder: t('search'),
            'aria-label': t('search_posts'),
            onkeydown: (event) => {
                if (event.key === 'Enter') {
                    posts.q = event.target.value;
                    loadPosts();
                }
            },
            onsearch: (event) => {
                posts.q = event.target.value;
                loadPosts();
            },
        });
        const kind = type.kind in { news: 1, events: 1, faq: 1 } ? type.kind : 'other';

        body.append(...[
            h('div', { class: 'nfe-posts__filters' }, [
                h('label', { for: 'nfe-post-type' }, [h('span', { text: t('post_kind') })]),
                h('label', { for: 'nfe-post-archive' }, [h('span', { text: t('archive_' + kind) })]),
                typeSelect,
                archiveSelect,
            ]),
            type.onPage.length && posts.archive?.onPage ? h('p', { class: 'nfe-posts__context', text: t('on_page', { modules: type.onPage.join(', ') }) + (type.sortable ? ' ' + t('order_hint') : '') }) : null,
            // The archive's versions in the other languages: linked, its posts paired, new posts made in each.
            hasVersions() && posts.archive && type.archiveTable ? h('div', { class: 'nfe-posts__versions' }, [button(t('rec_archive_translate'), { icon: 'translate', onclick: () => openRecordTranslation(type.archiveTable, posts.archive.id) })]) : null,
            h('div', { class: 'nfe-posts__search' }, [search]),
        ].filter(Boolean));

        const items = posts.items || [];
        const head = h('div', { class: 'nfe-posts__head' }, [
            posts.loading ? h('h3', { class: 'nfe-loading' }, [h('span', { class: 'nfe-spinner', 'aria-hidden': 'true' }), h('span', { text: t('loading') })]) : h('h3', { text: tn('entries', items.length, { n: items.length }) }),
            posts.archive?.canCreate ? button(t('new_' + kind), { icon: 'new', className: 'nfe-button--primary', onclick: newPost }) : null,
        ]);

        body.append(head);

        if (!posts.loading && !items.length) {
            body.append(h('p', { class: 'nfe-posts__context', text: t('no_entries') }));

            return;
        }

        body.append(h('ul', { class: 'nfe-list', 'aria-label': type.label + ': ' + (posts.archive?.title || '') }, items.map((item, index) => {
            const more = postMenuItems(item, index, items);

            return h('li', { class: 'nfe-list__item' }, [
                h('span', { class: 'nfe-list__main' }, [
                    h('span', { class: 'nfe-list__title', text: item.title || '–' }),
                    h('span', { class: 'nfe-list__meta' }, [item.date ? h('span', { text: dateText(item.date) }) : null, stateChip(item.status, item.start)]),
                ]),
                item.preview ? button(t('open_on_page'), { icon: 'preview', iconOnly: true, onclick: () => goToPreview(item.preview, item.status !== 'published') }) : null,
                !item.preview && item.canEdit ? button(t('properties'), { icon: 'edit', iconOnly: true, onclick: () => openPostForm(item, false) }) : null,
                more.length ? button(t('more'), { icon: 'more', iconOnly: true, onclick: (event) => openMenu(event.currentTarget, more, item.title) }) : null,
            ]);
        })));
    }

    function postMenuItems(item, index, items) {
        const table = state.posts?.type?.table || item.table;

        return [
            hasVersions() ? { label: t('rec_translate'), icon: 'translate', action: () => openRecordTranslation(table, item.id) } : null,
            item.canToggle ? { label: item.published ? t('withdraw') : t('publish'), icon: item.published ? 'hide' : 'visible', action: () => postCall(item, 'toggle') } : null,
            item.canEdit && item.preview ? { label: t('properties'), icon: 'edit', action: () => openPostForm(item, false) } : null,
            item.canMove && index > 0 ? { label: t('move_up'), icon: 'drag', action: () => postCall(item, 'move', index > 1 ? { after: items[index - 2].id } : {}) } : null,
            item.canMove && index < items.length - 1 ? { label: t('move_down'), icon: 'drag', action: () => postCall(item, 'move', { after: items[index + 1].id }) } : null,
            item.canCopy ? { label: t('duplicate'), icon: 'copy', action: () => postCall(item, 'copy') } : null,
            item.canDelete ? { label: t('delete'), icon: 'delete', action: () => { if (window.confirm(t('post_delete_confirm', { title: item.title }))) { postCall(item, 'delete'); } } } : null,
        ].filter(Boolean);
    }

    async function postCall(item, action, body = {}) {
        const table = state.posts?.type?.table || item.table;
        const result = await pageCall('/post/' + table + '/' + item.id + '/' + action, { page: config.page, ...body });

        if (result && state.view === 'posts') {
            await loadPosts();
            announce(t('saved'));
        }

        return result;
    }

    async function newPost() {
        const posts = state.posts;
        const result = await pageCall('/posts/' + posts.type.table + '/' + posts.archive.id + '/create', { page: config.page });

        if (result) {
            openPostForm({ id: result.id, title: '' }, true, posts.type.table, result.module);
        }
    }

    // Contao's form of a post (its properties); a new post opens on its page once it is saved.
    function openPostForm(item, isNew, table = null, module = null) {
        const type = state.posts?.type;
        const postTable = table || item.table || type?.table;
        const kind = type && type.table === postTable ? type.kind : 'other';

        openRecordForm({
            table: postTable,
            id: item.id,
            module: module || item.module || type?.module || null,
            title: isNew ? t('new_' + (kind in { news: 1, events: 1, faq: 1 } ? kind : 'other')) : t('properties_of', { title: item.title }),
            isNew,
            returnTo: state.view === 'posts' ? 'posts' : 'structure',
            onMessage: async (data, loads) => {
                if (loads < 2 || !state.form) {
                    return;
                }

                if (!isNew) {
                    await showSavedForm(loads);

                    return;
                }

                try {
                    const result = await api('/post/' + postTable + '/' + item.id + '/preview?page=' + config.page);
                    state.form.saved = result.saved;

                    // A new post gets its versions in the languages whose archives are linked, at once and only once.
                    if (result.saved && !item.versionsMade && hasVersions()) {
                        item.versionsMade = true;
                        const made = await api('/record-translation/' + postTable + '/' + item.id + '/versions', { method: 'POST', body: JSON.stringify({ at: config.page }) }).catch(() => null);

                        if (made?.created?.length) {
                            showToast(t('rec_versions_created', { languages: made.created.map((version) => version.name).join(', ') }), null, 'ok');
                        }
                    }

                    if (result.saved && result.preview) {
                        goToPreview(result.preview, true);
                    }
                } catch (e) {
                    // The form stays open.
                }
            },
        });
    }

    // On the page of a post: the post above its elements, with its state and properties.
    function postHead(group) {
        const post = group.post;

        return h('div', { class: 'nfe-post' }, [
            h('p', { class: 'nfe-post__kind', text: [post.typeLabel, post.archive].filter(Boolean).join(' › ') + (post.date ? ' · ' + dateText(post.date) : '') }),
            h('div', { class: 'nfe-post__bar' }, [
                h('div', { class: 'nfe-statusline' }, [stateChip(post.status, post.start)]),
                // The main actions of a post keep their whole label in every language: they move to a further line.
                actionRow([
                post.canEdit ? button(t('properties'), { icon: 'edit', onclick: () => openPostForm({ ...post, table: group.table }, false, group.table) }) : null,
                hasVersions() ? button(t('rec_translate'), { icon: 'translate', onclick: () => openRecordTranslation(group.table, post.id) }) : null,
                post.canToggle ? button(post.published ? t('withdraw') : t('publish'), {
                    icon: post.published ? 'hide' : 'visible',
                    onclick: async () => {
                        if (post.published && !window.confirm(t('post_withdraw_confirm', { title: post.title }))) {
                            return;
                        }

                        if (await pageCall('/post/' + group.table + '/' + post.id + '/toggle', { page: config.page })) {
                            reloadPage({ focus: { where: 'tree' }, announce: t(post.published ? 'page_withdrawn' : 'page_published') });
                        }
                    },
                }) : null,
                ], 'nfe-actions--wrap'),
            ]),
        ]);
    }

    // ---- modules

    async function openModules() {
        state.view = 'modules';
        openPanel();
        state.modules = null;
        renderPanel();

        try {
            state.modules = (await api('/modules?page=' + config.page)).modules || [];
        } catch (error) {
            state.modules = [];
            showToast(error.message || t('save_failed'));
        }

        if (state.view === 'modules') {
            renderPanel();
        }
    }

    function renderModules() {
        const body = h('div', { class: 'nfe-panel__body' });

        panel.replaceChildren(viewHead(t('view_modules')), viewsNav('modules') || '', body, h('div', { class: 'nfe-panel__foot' }, [h('div', { 'data-status': '' })]));
        panel.setAttribute('aria-label', t('view_modules'));
        renderStatus();

        if (!state.modules) {
            body.append(loadingNote('nfe-posts__context'));

            return;
        }

        body.append(
            h('p', { class: 'nfe-posts__context', text: t('modules_intro') }),
            state.modules.some((module) => module.group) ? h('p', { class: 'nfe-posts__context', text: t('modules_own') }) : null,
            state.modules.length
                ? h('ul', { class: 'nfe-modules' }, state.modules.map((module) => h('li', {}, [
                    h('span', { class: 'nfe-modules__main' }, [
                        h('strong', { text: module.label }),
                        module.description ? h('span', { text: module.description }) : null,
                        module.group ? h('span', { class: 'nfe-modules__group', text: module.group }) : null,
                    ]),
                    button(t('open'), { onclick: () => openModule(module) }),
                ])))
                : h('p', { text: t('no_modules') }),
        );
    }

    // A module's own back-end page in the wide sidebar, with Contao's permissions.
    function openModule(module) {
        state.view = 'module';
        renderPanel();

        const iframe = h('iframe', {
            class: 'nfe-panel__form',
            title: module.label,
            src: config.backend + '?do=' + encodeURIComponent(module.id) + '&popup=1&nfe_origin=' + encodeURIComponent(location.origin),
        });
        // The module's list asks for the window "Translate" of one of its records (FormBridgeListener).
        const backendOrigin = new URL(config.backend, location.href).origin;
        const listener = (event) => {
            if (event.origin === backendOrigin && event.source === iframe.contentWindow && event.data?.type === 'nfe:translate' && /^[a-z0-9_]+$/.test(String(event.data.table)) && Number(event.data.id) > 0) {
                openRecordTranslation(String(event.data.table), Number(event.data.id));
            }
        };
        const back = () => {
            window.removeEventListener('message', listener);
            state.view = 'modules';
            renderPanel();
        };

        window.addEventListener('message', listener);

        panel.replaceChildren(
            h('div', { class: 'nfe-panel__head' }, [
                button(t('back_to_modules'), { icon: 'back', iconOnly: true, onclick: back }),
                h('h2', { class: 'nfe-panel__title', text: module.label }),
                button(t('close'), { icon: 'close', iconOnly: true, onclick: back }),
            ]),
            framed(iframe),
        );
        panel.setAttribute('aria-label', module.label);
        panel.querySelector('.nfe-panel__head button')?.focus();
    }

    // Without a licence: what Plus adds.
    function renderUpgradePlus() {
        const ed = editions();
        const title = state.upgradeFrom === 'modules' ? t('view_modules') : t('view_posts');
        const body = h('div', { class: 'nfe-panel__body nfe-info' }, [
            h('h3', {}, [t('plus_intro'), ' ', h('span', { class: 'nfe-plus-badge', text: t('plus') })]),
            h('ul', {}, ['plus_pages', 'plus_posts', 'plus_modules', 'plus_languages', 'plus_translate'].map((key) => h('li', { text: t(key) }))),
            ed.canOpenAccess ? h('p', {}, [h('a', { href: config.backend + '?do=neo_access', target: '_blank', rel: 'noopener', text: t('enter_license') })]) : null,
        ]);

        panel.replaceChildren(viewHead(title), viewsNav(state.upgradeFrom) || '', body);
        panel.setAttribute('aria-label', title);
    }

    // ------------------------------------------------------------------ checks and screen sizes (Pro)

    // Weights of the SEO checks; "improve" counts half (README, "SEO check").
    const SEO_WEIGHTS = { title: 10, titleKeyword: 10, description: 10, descriptionKeyword: 5, h1: 10, h1Keyword: 5, headings: 5, urlKeyword: 5, paragraphKeyword: 5, words: 10, alt: 5, links: 5, index: 10, elsewhere: 5 };
    const PROBLEM_IMPACTS = ['critical', 'serious'];

    const outsideEditor = (node) => !pageHost?.contains(node) && !node.closest?.('cto-toolbar, .cto-toolbar');

    // Shown on the page: no display:none or visibility:hidden on the node or an ancestor, and a size.
    const rendered = (node) => {
        const visible = typeof node.checkVisibility === 'function'
            ? node.checkVisibility({ visibilityProperty: true })
            : node.getClientRects().length > 0 && styleOf(node).visibility !== 'hidden';
        const box = node.getBoundingClientRect();

        return visible && box.width > 0 && box.height > 0;
    };

    // What a visitor or a screen reader actually gets: rendered and not hidden from assistive technology.
    const exposed = (node) => !node.closest('[aria-hidden="true"]') && rendered(node);

    // An image that needs an alternative text: exposed, not decorative by its role, with an image to show.
    // alt="" counts on such an image: Contao writes it when no text was entered.
    const lacksAlt = (img) => !(img.getAttribute('alt') || '').trim()
        && !['presentation', 'none'].includes((img.getAttribute('role') || '').trim().toLowerCase())
        && !!(img.currentSrc || img.getAttribute('src'))
        && exposed(img);
    const mainArea = () => pageDoc.querySelector('main, #main') || pageDoc.body;
    const normalize = (text) => String(text || '').toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').normalize('NFKD').replace(/[̀-ͯ]/g, '');
    // All words of the term in the text (also inside longer words: "Ferienwohnung" in "Ferienwohnungen").
    const hasTerm = (text, term) => {
        const words = normalize(term).split(/[^a-z0-9]+/).filter(Boolean);

        return words.length > 0 && words.every((word) => normalize(text).includes(word));
    };

    function seoFacts() {
        const main = mainArea();
        const headings = [...pageDoc.querySelectorAll('h1, h2, h3, h4, h5, h6')].filter((node) => outsideEditor(node) && exposed(node));
        const images = [...main.querySelectorAll('img')].filter(outsideEditor);
        const address = pageWin.location;

        return {
            title: pageDoc.title.trim(),
            description: (pageDoc.querySelector('meta[name="description"]')?.getAttribute('content') || '').trim(),
            robots: (pageDoc.querySelector('meta[name="robots"]')?.getAttribute('content') || '').toLowerCase(),
            url: address.host + address.pathname.replace(/^\/preview\.php/, ''),
            path: decodeURIComponent(address.pathname),
            headings,
            // As search engines read them: every h1 of the page's source, also one the layout shows only at some
            // widths (a variant for phones), but not one hidden from everybody.
            h1: [...pageDoc.querySelectorAll('h1')].filter((node) => outsideEditor(node) && !node.closest('[aria-hidden="true"], [hidden]')),
            paragraph: [...main.querySelectorAll('p')].filter(outsideEditor).find((node) => node.textContent.trim().length > 30)?.textContent || '',
            words: (main.innerText || '').split(/\s+/).filter(Boolean).length,
            missingAlt: images.filter(lacksAlt),
            links: [...main.querySelectorAll('a[href]')].filter((link) => {
                try {
                    const url = new URL(link.href, address.href);

                    return url.host === address.host && url.pathname !== address.pathname;
                } catch (e) {
                    return false;
                }
            }).length,
        };
    }

    // The first skipped heading level: a heading more than one level below the one before it.
    function skippedHeading(headings) {
        let previous = 0;

        for (const node of headings) {
            const level = Number(node.tagName[1]);

            if (previous && level > previous + 1) {
                return { node, from: previous, to: level };
            }

            previous = level;
        }

        return null;
    }

    function seoChecks() {
        const f = seoFacts();
        const seo = state.check.seo;
        const term = seo.keyword;
        const checks = [];
        const add = (key, status, title, text, action = null) => checks.push({ key, status, title, text, action });
        const settings = editions().pages && state.structure?.page?.canSettings ? { label: t('page_settings'), icon: 'settings', run: openPageSettings } : null;
        const jump = (nodes) => jumpAction([].concat(nodes || []).filter(Boolean));

        if (!f.title) {
            add('title', 'problem', t('seo_title_missing'), t('seo_title_missing_text'), settings);
        } else if (f.title.length < 30 || f.title.length > 60) {
            add('title', 'improve', t(f.title.length < 30 ? 'seo_title_short' : 'seo_title_long'), tn('seo_title_length_text', f.title.length, { title: f.title, n: f.title.length }), settings);
        } else {
            add('title', 'good', t('seo_title_ok'), tn('seo_title_length_text', f.title.length, { title: f.title, n: f.title.length }));
        }

        if (!f.description) {
            add('description', 'problem', t('seo_description_missing'), t('seo_description_missing_text'), settings);
        } else if (f.description.length < 120 || f.description.length > 158) {
            add('description', 'improve', t(f.description.length < 120 ? 'seo_description_short' : 'seo_description_long'), t('seo_description_length_text', { n: f.description.length }), settings);
        } else {
            add('description', 'good', t('seo_description_ok'), t('seo_description_length_text', { n: f.description.length }));
        }

        if (f.h1.length !== 1) {
            add('h1', 'problem', t(f.h1.length ? 'seo_h1_many' : 'seo_h1_missing'), t(f.h1.length ? 'seo_h1_many_text' : 'seo_h1_missing_text', { n: f.h1.length }), jump(f.h1.slice(1)));
        } else {
            add('h1', 'good', t('seo_h1_ok'), t('seo_h1_ok_text'));
        }

        const skipped = skippedHeading(f.headings);

        add('headings', skipped ? 'improve' : 'good', t(skipped ? 'seo_heading_skip' : 'seo_headings_ok'), skipped ? t('seo_heading_skip_text', { heading: skipped.node.textContent.trim().slice(0, 60), from: skipped.from, to: skipped.to }) : t('seo_headings_ok_text'), skipped ? jump(skipped.node) : null);
        add('words', f.words < 300 ? 'improve' : 'good', t(f.words < 300 ? 'seo_words_few' : 'seo_words_ok'), tn('seo_words_text', f.words, { n: f.words }));
        add('alt', f.missingAlt.length ? 'improve' : 'good', t(f.missingAlt.length ? 'seo_alt_missing' : 'seo_alt_ok'), f.missingAlt.length ? tn('seo_alt_missing_text', f.missingAlt.length, { n: f.missingAlt.length }) : t('seo_alt_ok_text'), jump(f.missingAlt));
        add('links', f.links ? 'good' : 'improve', t(f.links ? 'seo_links_ok' : 'seo_links_none'), f.links ? tn('seo_links_ok_text', f.links, { n: f.links }) : t('seo_links_none_text'));
        add('index', f.robots.includes('noindex') ? 'problem' : 'good', t(f.robots.includes('noindex') ? 'seo_noindex' : 'seo_index_ok'), t(f.robots.includes('noindex') ? 'seo_noindex_text' : 'seo_index_ok_text'), f.robots.includes('noindex') ? settings : null);

        if (term) {
            add('titleKeyword', hasTerm(f.title, term) ? 'good' : 'improve', t(hasTerm(f.title, term) ? 'seo_title_keyword_ok' : 'seo_title_keyword'), t('seo_keyword_in_text', { term, place: t('seo_place_title') }), hasTerm(f.title, term) ? null : settings);
            add('descriptionKeyword', hasTerm(f.description, term) ? 'good' : 'improve', t(hasTerm(f.description, term) ? 'seo_description_keyword_ok' : 'seo_description_keyword'), t('seo_keyword_in_text', { term, place: t('seo_place_description') }), hasTerm(f.description, term) ? null : settings);

            if (f.h1.length === 1) {
                add('h1Keyword', hasTerm(f.h1[0].textContent, term) ? 'good' : 'improve', t(hasTerm(f.h1[0].textContent, term) ? 'seo_h1_keyword_ok' : 'seo_h1_keyword'), t('seo_keyword_in_text', { term, place: t('seo_place_h1') }), hasTerm(f.h1[0].textContent, term) ? null : jump(f.h1[0]));
            }

            add('urlKeyword', hasTerm(f.path.replace(/[-_/]/g, ' '), term) ? 'good' : 'improve', t(hasTerm(f.path.replace(/[-_/]/g, ' '), term) ? 'seo_url_keyword_ok' : 'seo_url_keyword'), t('seo_keyword_in_text', { term, place: t('seo_place_url') }), hasTerm(f.path.replace(/[-_/]/g, ' '), term) ? null : settings);
            add('paragraphKeyword', hasTerm(f.paragraph, term) ? 'good' : 'improve', t(hasTerm(f.paragraph, term) ? 'seo_paragraph_keyword_ok' : 'seo_paragraph_keyword'), t('seo_keyword_in_text', { term, place: t('seo_place_paragraph') }));
            add('elsewhere', seo.usedOn.length ? 'improve' : 'good', seo.usedOn.length ? t('seo_keyword_elsewhere', { page: seo.usedOn[0].title }) : t('seo_keyword_unique'), t(seo.usedOn.length ? 'seo_keyword_elsewhere_text' : 'seo_keyword_unique_text'));
        }

        const total = checks.reduce((sum, check) => sum + SEO_WEIGHTS[check.key], 0);
        const reached = checks.reduce((sum, check) => sum + SEO_WEIGHTS[check.key] * ({ good: 1, improve: 0.5, problem: 0 }[check.status]), 0);

        return { facts: f, checks, score: total ? Math.round((reached / total) * 100) : 0 };
    }

    async function openCheck(tab = null) {
        state.view = 'check';
        openPanel();
        state.check = state.check || { tab: 'seo', seo: { keyword: '', usedOn: [], canEdit: false, loaded: false }, a11y: null };

        if (tab) {
            state.check.tab = tab;
        }

        renderPanel();

        if (!state.check.seo.loaded) {
            try {
                const data = await api('/seo/' + config.page);
                Object.assign(state.check.seo, { keyword: data.keyword, usedOn: data.usedOn || [], canEdit: data.canEdit, loaded: true });
            } catch (error) {
                state.check.seo.loaded = true;
                showToast(error.message || t('save_failed'));
            }
        }

        if (state.check.tab === 'a11y') {
            await runA11y();
        }

        if (state.view === 'check') {
            renderPanel();
        }
    }

    async function saveKeyword(value) {
        const seo = state.check.seo;

        if (value === seo.keyword) {
            return;
        }

        try {
            const data = await api('/seo/' + config.page, { method: 'POST', body: JSON.stringify({ keyword: value }) });
            Object.assign(seo, { keyword: data.keyword, usedOn: data.usedOn || [] });
            announce(t('saved'));
        } catch (error) {
            showToast(error.message || t('save_failed'));
        }

        renderPanel();
    }

    // After a change on the page: the checks run again.
    function afterChange() {
        if (state.view === 'check' && state.check) {
            state.check.a11y = null;
            openCheck();
        }
    }

    function scoreRing(score) {
        const radius = 30;
        const length = 2 * Math.PI * radius;
        const color = score >= 80 ? 'var(--green, #589b0e)' : (score >= 50 ? 'var(--orange, #f47c00)' : 'var(--red, #c33)');
        const node = h('span', { class: 'nfe-score__ring', 'aria-hidden': 'true' });

        node.innerHTML = '<svg width="76" height="76" viewBox="0 0 76 76"><circle cx="38" cy="38" r="30" fill="none" stroke="var(--nfe-border)" stroke-width="8"/>'
            + '<circle cx="38" cy="38" r="30" fill="none" stroke="' + color + '" stroke-width="8" stroke-linecap="round" stroke-dasharray="' + (length * score) / 100 + ' ' + length + '" transform="rotate(-90 38 38)"/>'
            + '<text x="38" y="44" text-anchor="middle" font-size="18" font-weight="700" fill="currentColor">' + Number(score) + '</text></svg>';
        void radius;

        return node;
    }

    const statusLabel = (status) => t('finding_' + status);

    function findingNode(finding, number = null) {
        return h('li', { class: 'nfe-finding nfe-finding--' + finding.status }, [
            h('div', { class: 'nfe-finding__head' }, [
                number ? h('span', { class: 'nfe-finding__num', text: String(number), 'aria-label': t('marker', { n: number }) }) : null,
                h('span', { text: finding.title }),
                h('span', { class: 'nfe-chip-state', text: statusLabel(finding.status) }),
            ]),
            finding.text ? h('p', {}, finding.textNodes || [finding.text]) : null,
            (finding.actions || [finding.action]).filter(Boolean).length
                ? h('div', { class: 'nfe-finding__actions' }, (finding.actions || [finding.action]).filter(Boolean).map((action) => button(action.label, { icon: action.icon, onclick: action.run })))
                : null,
        ]);
    }

    function renderCheck() {
        const check = state.check;
        const body = h('div', { class: 'nfe-panel__body nfe-check' });
        const tabButton = (key, label) => h('button', {
            type: 'button',
            'aria-pressed': String(check.tab === key),
            onclick: () => {
                check.tab = key;
                openCheck();
            },
        }, [label]);

        panel.replaceChildren(viewHead(t('view_check')), viewsNav('check') || '', body, h('div', { class: 'nfe-panel__foot' }, [h('div', { 'data-status': '' })]));
        panel.setAttribute('aria-label', t('view_check'));
        renderStatus();
        body.append(h('div', { class: 'nfe-check__bar' }, [
            h('div', { class: 'nfe-seg', role: 'group', 'aria-label': t('check') }, [tabButton('seo', t('check_seo')), tabButton('a11y', t('check_a11y'))]),
            busyWhile(button(t('check_again'), { icon: 'sync', iconOnly: true, onclick: () => { if (check.tab === 'a11y') { check.a11y = null; } return openCheck(); } }), check.tab === 'a11y' && (!check.a11y || check.a11y.loading)),
        ]));

        if (check.tab === 'seo') {
            renderSeo(body);
        } else {
            renderA11y(body);
        }

        showMarkers(check.tab === 'a11y' ? check.a11y?.findings || [] : []);
    }

    function renderSeo(body) {
        const seo = state.check.seo;

        if (!seo.loaded) {
            body.append(loadingNote('nfe-posts__context'));

            return;
        }

        const result = seoChecks();
        const f = result.facts;
        const label = result.score >= 80 ? 'score_good' : (result.score >= 50 ? 'score_ok' : 'score_low');
        const input = h('input', {
            class: 'tl_text',
            type: 'text',
            id: 'nfe-keyword',
            value: seo.keyword,
            maxlength: '128',
            disabled: !seo.canEdit,
            'aria-describedby': 'nfe-keyword-hint',
            onkeydown: (event) => {
                if (event.key === 'Enter') {
                    saveKeyword(event.target.value.trim());
                }
            },
            onchange: (event) => saveKeyword(event.target.value.trim()),
        });
        const groups = { problem: [], improve: [], good: [] };

        for (const check of result.checks) {
            groups[check.status].push(check);
        }

        body.append(
            h('div', { class: 'nfe-score' }, [scoreRing(result.score), h('div', {}, [
                h('p', { class: 'nfe-score__label', text: t(label, { n: result.score }) }),
                h('p', { class: 'nfe-score__note', text: seo.keyword ? t('score_for', { term: seo.keyword }) : t('score_no_term') }),
            ])]),
            h('div', {}, [
                h('label', { for: 'nfe-keyword' }, [h('span', { text: t('keyword') })]),
                input,
                h('p', { class: 'nfe-check__hint', id: 'nfe-keyword-hint', text: t('keyword_hint') }),
            ]),
            h('div', { class: 'nfe-serp' }, [
                h('p', { class: 'nfe-serp__caption', text: t('serp_caption') }),
                h('p', { class: 'nfe-serp__url', text: f.url }),
                h('p', { class: 'nfe-serp__title', text: f.title.length > 65 ? f.title.slice(0, 62) + ' …' : f.title }),
                h('p', { class: 'nfe-serp__text', text: f.description.length > 160 ? f.description.slice(0, 157) + ' …' : f.description }),
            ]),
        );

        for (const status of ['problem', 'improve']) {
            if (groups[status].length) {
                body.append(h('div', { class: 'nfe-group' }, [
                    h('h3', { text: t('group_' + status, { n: groups[status].length }) }),
                    h('ul', { class: 'nfe-findings' }, groups[status].map((check) => findingNode(check))),
                ]));
            }
        }

        if (groups.good.length) {
            body.append(h('details', { class: 'nfe-more-good' }, [
                h('summary', { text: tn('group_good', groups.good.length, { n: groups.good.length }) }),
                h('ul', { class: 'nfe-findings' }, groups.good.map((check) => findingNode(check))),
            ]));
        }
    }

    // ---- accessibility (axe-core, loaded when the check runs)

    // axe-core checks the document it runs in: it is loaded into the page of the frame.
    async function loadAxe() {
        if (pageWin.axe) {
            return;
        }

        await new Promise((resolve, reject) => {
            const script = pageDoc.createElement('script');

            script.src = config.axe;

            if (config.nonce) {
                script.nonce = config.nonce;
            }

            script.onload = resolve;
            script.onerror = reject;
            pageDoc.head.append(script);
        });

        if (config.axeLocale) {
            try {
                pageWin.axe.configure({ locale: await (await fetch(config.axeLocale)).json() });
            } catch (e) {
                // axe-core's own English texts are used then.
            }
        }
    }

    // Own, plain texts for the most frequent findings; the others with axe-core's description.
    const A11Y_TEXTS = {
        'color-contrast': 'contrast',
        'image-alt': 'alt',
        'input-image-alt': 'alt',
        'role-img-alt': 'alt',
        'svg-img-alt': 'alt',
        'link-name': 'link_name',
        'button-name': 'button_name',
        'heading-order': 'heading_order',
        'empty-heading': 'empty_heading',
        label: 'label',
        'select-name': 'label',
        'valid-lang': 'lang',
        'html-has-lang': 'lang',
        'html-lang-valid': 'lang',
        'frame-title': 'frame_title',
    };

    function a11yNode(target) {
        let scope = pageDoc;
        let node = null;

        for (const selector of [].concat(target)) {
            node = Array.isArray(selector) ? null : scope.querySelector(selector);

            if (Array.isArray(selector)) {
                node = a11yNode(selector);
            }

            if (!node) {
                return null;
            }

            scope = node.shadowRoot || node;
        }

        return node;
    }

    async function runA11y() {
        const check = state.check;

        if (check.a11y?.findings || check.a11y?.loading) {
            return;
        }

        check.a11y = { loading: true, findings: null };
        renderPanel();

        try {
            await loadAxe();

            const axe = pageWin.axe;
            const rules = [...new Set([...axe.getRules(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).map((rule) => rule.ruleId), 'heading-order', 'empty-heading'])];
            const result = await axe.run({ exclude: [['nfe-editor'], ['cto-toolbar'], ['.cto-toolbar']] }, { runOnly: { type: 'rule', values: rules }, resultTypes: ['violations'] });
            const findings = [];

            for (const violation of result.violations) {
                for (const item of violation.nodes) {
                    const node = a11yNode(item.target);
                    const id = node ? elementAt(node) : null;
                    const element = id ? elementData(id) : null;
                    const key = A11Y_TEXTS[violation.id];
                    const data = item.any?.[0]?.data || {};

                    findings.push({
                        rule: violation.id,
                        node,
                        id,
                        inContent: Boolean(element?.canUpdate),
                        status: PROBLEM_IMPACTS.includes(violation.impact) ? 'problem' : 'improve',
                        title: key ? t('a11y_' + key) : violation.help,
                        text: key ? t('a11y_' + key + '_text', { text: (node?.textContent || '').trim().slice(0, 60) }) : violation.description,
                        data,
                    });
                }
            }

            // In the order of the page.
            findings.sort((a, b) => (a.node && b.node ? (a.node.compareDocumentPosition(b.node) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1) : 0));
            check.a11y = { loading: false, findings };
        } catch (e) {
            check.a11y = { loading: false, findings: null, error: true };
        }
    }

    function contrastNodes(finding) {
        const d = finding.data;

        if (finding.rule !== 'color-contrast' || !d.contrastRatio) {
            return null;
        }

        const swatch = (color) => h('span', { class: 'nfe-swatch', style: 'background:' + color, 'aria-hidden': 'true' });
        const parts = t('a11y_contrast_detail', { ratio: String(d.contrastRatio).replace('.', config.lang === 'en' ? '.' : ','), needed: String(parseFloat(d.expectedContrastRatio) || 4.5).replace('.', config.lang === 'en' ? '.' : ','), fg: '\u0001', bg: '\u0002' }).split(/(\u0001|\u0002)/);

        return parts.map((part) => (part === '\u0001' ? [swatch(d.fgColor), d.fgColor] : (part === '\u0002' ? [swatch(d.bgColor), d.bgColor] : part))).flat();
    }

    function a11yActions(finding) {
        const actions = [];
        const element = finding.id ? elementData(finding.id) : null;

        if (finding.node) {
            actions.push({ label: t('to_element'), run: () => jumpTo(finding.node) });
        }

        // A skipped heading level that is the element's own headline: set the right level.
        if (finding.rule === 'heading-order' && finding.inContent && element?.fields.includes('headline') && finding.node?.textContent.trim() === element.values.headline.value.trim()) {
            const before = [...pageDoc.querySelectorAll('h1, h2, h3, h4, h5, h6')].filter((node) => outsideEditor(node) && finding.node.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING).pop();
            const level = Math.min(6, (before ? Number(before.tagName[1]) : 1) + 1);

            actions.push({
                label: t('a11y_set_level', { n: level }),
                run: async () => {
                    try {
                        const result = await api('/element/' + finding.id, { method: 'POST', body: JSON.stringify({ fields: { headline: { value: element.values.headline.value, unit: 'h' + level } } }) });

                        replaceElementData(result.element);
                        await refreshElement(finding.id);
                        state.check.a11y = null;
                        openCheck();
                        announce(t('saved'));
                    } catch (error) {
                        showToast(error.message || t('save_failed'));
                    }
                },
            });
        }

        // An image without alternative text in an element the user may edit: the image's own dialogue.
        if (['image-alt', 'role-img-alt'].includes(finding.rule) && finding.inContent && element?.fields.includes('alt') && finding.node?.tagName === 'IMG') {
            actions.push({ label: t('a11y_enter_alt'), run: () => { select(finding.id, true); startImage(finding.node, element); } });
        }

        return actions;
    }

    function renderA11y(body) {
        const a11y = state.check.a11y;

        if (!a11y || a11y.loading) {
            body.append(h('p', { class: 'nfe-posts__context', text: t('checking') }));

            return;
        }

        if (a11y.error) {
            body.append(h('p', { class: 'nfe-posts__context', text: t('check_failed') }));

            return;
        }

        const content = a11y.findings.filter((finding) => finding.inContent);
        const layout = a11y.findings.filter((finding) => !finding.inContent);

        body.append(h('p', { class: 'nfe-note-small' }, [
            h('strong', { text: t('a11y_summary', { n: a11y.findings.length, a: content.length, b: layout.length }) }),
            ' ',
            t('a11y_note'),
        ]));

        if (!a11y.findings.length) {
            body.append(h('p', { class: 'nfe-posts__context', text: t('a11y_none') }));

            return;
        }

        const list = (findings) => h('ul', { class: 'nfe-findings' }, findings.map((finding) => findingNode({ ...finding, textNodes: contrastNodes(finding), actions: a11yActions(finding) }, a11y.findings.indexOf(finding) + 1)));

        if (content.length) {
            body.append(h('div', { class: 'nfe-group' }, [h('h3', { text: t('a11y_in_content', { n: content.length }) }), list(content)]));
        }

        if (layout.length) {
            body.append(h('div', { class: 'nfe-group' }, [
                h('h3', { text: t('a11y_in_layout', { n: layout.length }) }),
                h('p', { class: 'nfe-note-small', text: t('a11y_layout_note') }),
                list(layout),
            ]));
        }
    }

    // Numbered marks at the places on the page, the same numbers as in the list.
    function showMarkers(findings) {
        markers.replaceChildren();

        findings.forEach((finding, index) => {
            const box = finding.node?.getBoundingClientRect();

            if (!box || (!box.width && !box.height)) {
                return;
            }

            // Left of the place where there is room, otherwise just above it, so the number does not cover the text.
            const roomLeft = box.left >= 32;
            const left = roomLeft ? box.left - 30 : Math.max(2, box.left - 4);
            const top = roomLeft ? box.top + Math.min(box.height / 2 - 12, 4) : box.top - 22;

            const outline = h('div', { class: 'nfe-mark-frame' });
            const mark = h('div', { class: 'nfe-mark', 'aria-hidden': 'true', text: String(index + 1) });

            // Positions through the style object: a page's Content Security Policy may forbid style attributes.
            Object.assign(outline.style, { left: box.left + 'px', top: box.top + 'px', width: box.width + 'px', height: box.height + 'px' });
            Object.assign(mark.style, { left: left + 'px', top: top + 'px' });
            markers.append(outline, mark);
        });
    }

    // "Go to element" for one place, or for several one after the other: the button says which comes next.
    function jumpAction(nodes) {
        if (!nodes.length) {
            return null;
        }

        if (nodes.length === 1) {
            return { label: t('to_element'), run: () => jumpTo(nodes[0]) };
        }

        let next = 0;
        const label = () => t('to_element_of', { n: next + 1, total: nodes.length });

        return {
            label: label(),
            run: (event) => {
                const target = event?.currentTarget;

                jumpTo(nodes[next]);
                next = (next + 1) % nodes.length;
                target?.querySelector('span:not(.nfe-icon)')?.replaceChildren(label());
            },
        };
    }

    function jumpTo(node) {
        const id = elementAt(node);

        if (id && elementData(id)) {
            select(id, false);
        }

        // A place the page does not show (hidden, no size) cannot be scrolled to: the status line says so.
        if (!node.isConnected || !rendered(node)) {
            checkNotice(t('jump_hidden'));

            return;
        }

        node.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    // A message in the status line of the check's panel, read out by screen readers.
    function checkNotice(text) {
        for (const container of panel.querySelectorAll('[data-status]')) {
            container.replaceChildren(h('span', { class: 'nfe-status' }, [icon('info'), h('span', { text })]));
        }

        announce(text);
    }

    // Without Pro: what Pro adds.
    function renderUpgradePro() {
        const ed = editions();
        const body = h('div', { class: 'nfe-panel__body nfe-info' }, [
            h('h3', {}, [t('pro_intro'), ' ', h('span', { class: 'nfe-plus-badge', text: t('pro') })]),
            h('ul', {}, ['pro_seo', 'pro_a11y', 'pro_screens', 'pro_style', 'pro_writing_aid'].map((key) => h('li', { text: t(key) }))),
            ed.canOpenAccess ? h('p', {}, [h('a', { href: config.backend + '?do=neo_access', target: '_blank', rel: 'noopener', text: t('enter_license') })]) : null,
        ]);

        panel.replaceChildren(viewHead(t('view_check')), viewsNav('check') || '', body);
        panel.setAttribute('aria-label', t('view_check'));
    }

    // ---- screen sizes: the page's frame as wide as a phone or a tablet; the editor works there, too

    const SCREENS = { phone: [390, 844], tablet: [820, 1180] };

    function screenButton() {
        return editions().check && !narrow()
            ? button(t('screen_size'), { icon: 'monitor', iconOnly: true, onclick: (event) => openMenu(event.currentTarget, [
                { label: t('screen_computer'), icon: 'monitor', action: closeScreen },
                { label: t('screen_tablet', { w: SCREENS.tablet[0] }), icon: 'monitor', action: () => openScreen('tablet') },
                { label: t('screen_phone', { w: SCREENS.phone[0] }), icon: 'monitor', action: () => openScreen('phone') },
            ], t('screen_size')) })
            : null;
    }

    function openScreen(device, landscape = false) {
        state.screen = { device, landscape };
        renderScreen();
        screenBar.querySelector('select')?.focus();
    }

    function closeScreen() {
        state.screen = null;
        renderScreen();
    }

    // The bar above the page names the size; the frame gets its width (wider than the area: the area scrolls).
    function renderScreen() {
        const current = narrow() ? null : state.screen;

        stage.classList.toggle('nfe-stage--screen', Boolean(current));
        screenBar.hidden = !current;

        if (!current) {
            pageFrame.style.width = '';
            screenBar.replaceChildren();

            return;
        }

        const width = SCREENS[current.device][current.landscape ? 1 : 0];

        pageFrame.style.width = width + 'px';
        screenBar.replaceChildren(
            h('strong', { text: t('screen_' + current.device, { w: width }) + (current.landscape ? ' · ' + t('screen_landscape') : '') }),
            h('select', { class: 'tl_select', 'aria-label': t('screen_size'), onchange: (event) => (event.target.value === 'computer' ? closeScreen() : openScreen(event.target.value, current.landscape)) }, [
                h('option', { value: 'computer', text: t('screen_computer') }),
                h('option', { value: 'tablet', selected: current.device === 'tablet' ? '' : null, text: t('screen_tablet', { w: SCREENS.tablet[0] }) }),
                h('option', { value: 'phone', selected: current.device === 'phone' ? '' : null, text: t('screen_phone', { w: SCREENS.phone[0] }) }),
            ]),
            button(t('screen_rotate'), { icon: 'sync', iconOnly: true, onclick: () => openScreen(current.device, !current.landscape) }),
            button(t('close'), { icon: 'close', iconOnly: true, onclick: closeScreen }),
        );
    }

    // ------------------------------------------------------------------ status, toast

    function statusNode() {
        if (state.error) {
            return h('span', { class: 'nfe-status nfe-status--error' }, [icon('error'), h('span', { text: t('not_saved') })]);
        }

        if (state.dirty) {
            return h('span', { class: 'nfe-status nfe-status--dirty' }, [h('span', { class: 'nfe-dot', 'aria-hidden': 'true' }), h('span', { text: t('dirty') })]);
        }

        return h('span', { class: 'nfe-status' }, [icon('ok'), h('span', { text: t('saved') })]);
    }

    function actionButtons() {
        const disabled = !state.editing && !state.pending.length;
        const cancelButton = button(t('cancel'), { onclick: cancel });
        const saveButton = button(t('save'), { icon: 'save', action: 'save', onclick: save, className: 'nfe-button--primary' });

        cancelButton.disabled = disabled;
        saveButton.disabled = disabled;
        // Saving stays in a row whose buttons do not fit (fitRow).
        saveButton.setAttribute('data-keep', '');

        return [cancelButton, saveButton];
    }

    function renderStatus() {
        for (const container of root.querySelectorAll('[data-status]')) {
            container.replaceChildren(statusNode());
        }

        for (const container of root.querySelectorAll('[data-actions]')) {
            container.replaceChildren(...actionButtons());
        }

        for (const row of root.querySelectorAll('[data-fit]')) {
            fitRow(row);
        }
    }

    // Ctrl, or ⌘ on Apple devices: with it a click follows a link of the page.
    const modifierKey = () => (/Mac|iPhone|iPad/.test(navigator.userAgentData?.platform || navigator.platform || '') ? '⌘' : t('key_ctrl'));

    function guideButton() {
        return button(t('guide'), { icon: 'help', iconOnly: true, onclick: openGuide });
    }

    // The quick guide: how to select, edit and move around in the editor; behind the button in the head of the sidebar.
    function openGuide() {
        const key = modifierKey();
        // Translating only where the page has language versions (Plus).
        const sections = ['select', 'links', 'menu', 'panel', ...((editions().languages || []).length ? ['languages'] : []), 'keys'];
        const body = h('div', { class: 'nfe-guide' }, sections.map((name) => h('section', {}, [
            h('h3', { text: t('guide_' + name + '_title') }),
            h('p', { text: t('guide_' + name, { key }) }),
        ])));

        return openDialog({ title: t('guide_title'), body, buttons: [{ label: t('close'), value: null, primary: true }] });
    }

    function showToast(message, retry, kind = 'error', action = null) {
        // After the session ended, saving again cannot work: the page has to be loaded again.
        if (state.sessionExpired && kind === 'error') {
            message = t('session_expired');
            retry = null;
        }

        toast.classList.toggle('nfe-toast--ok', kind === 'ok');
        toast.classList.toggle('nfe-toast--note', kind === 'note');
        toast.replaceChildren(
            icon({ ok: 'ok', note: 'info' }[kind] || 'error') || '',
            h('div', { class: 'nfe-toast__text', text: message }),
            retry ? button(t('save_again'), { onclick: retry }) : '',
            action ? button(action.label, { onclick: () => { hideToast(); action.run(); } }) : '',
            state.sessionExpired && kind === 'error' ? button(t('reload'), { icon: 'sync', onclick: () => window.location.reload() }) : '',
            kind !== 'error' ? button(t('close'), { icon: 'close', iconOnly: true, onclick: hideToast }) : '',
        );
        toast.hidden = false;
    }

    function hideToast() {
        toast.hidden = true;
    }

    function announce(message) {
        live.textContent = '';
        window.setTimeout(() => { live.textContent = message; }, 50);
    }

    // ------------------------------------------------------------------ sidebar: structure and "all fields"

    // The view the sidebar shows; drawn again (a check run, a saved value …), it stays where it was scrolled to.
    let renderedView = null;

    function renderPanel() {
        const open = !state.collapsed;
        const viewKey = state.view + (state.view === 'check' ? ':' + state.check?.tab : '');
        const keep = open && renderedView === viewKey ? panel.querySelector('.nfe-panel__body')?.scrollTop || 0 : 0;

        if (state.view !== 'check') {
            markers.replaceChildren();
        }

        panel.hidden = !open;
        floating.hidden = open;
        view.classList.toggle('nfe-view--overlay', state.overlay && !narrow());
        renderRail();
        panel.classList.toggle('nfe-panel--form', state.view === 'form');
        panel.classList.toggle('nfe-panel--wide', state.view === 'languages' || state.view === 'module' || Boolean(state.view === 'form' && state.form?.wide));

        if (open) {
            renderPanelContent();
            renderedView = viewKey;

            const body = panel.querySelector('.nfe-panel__body');

            if (body && keep) {
                body.scrollTop = keep;
            }
        } else {
            renderedView = null;
            // On wide screens the strip at the right edge opens the sidebar; on narrow ones this bar has the button.
            floating.replaceChildren(
                h('span', { 'data-status': '' }),
                h('div', { class: 'nfe-float__row', 'data-fit': '' }, [
                    narrow() ? button(t('structure'), { icon: 'children', onclick: () => setCollapsed(false) }) : '',
                    h('span', { class: 'nfe-actions', 'data-actions': '' }),
                ]),
            );
            floating.setAttribute('aria-label', t('page', { page: state.structure?.page.title || '' }));
            renderStatus();
        }

        // The page's frame gets the rest of the view (its width comes from --nfe-panel-width / --nfe-form-width).
        applyPanelWidth();
        renderScreen();
        window.setTimeout(() => (state.editing ? showEditedPart() : state.item ? showItem(state.item) : showAt(state.selected)), 0);
    }

    const panelMode = () => (panel.classList.contains('nfe-panel--form') ? 'form' : (panel.classList.contains('nfe-panel--wide') ? 'wide' : 'panel'));
    const widthLimits = () => ({ min: 320, max: Math.max(360, window.innerWidth - 360) });

    // A width the user chose for this kind of view, within the limits of the window; on narrow screens the sidebar is a sheet.
    function applyPanelWidth() {
        const open = !state.collapsed && !panel.hidden;
        const stored = Number(storage.get('width:' + panelMode())) || 0;
        const { min, max } = widthLimits();

        panel.style.width = open && !narrow() && stored ? Math.min(max, Math.max(min, stored)) + 'px' : '';
        resizer.hidden = !open || narrow();

        if (!resizer.hidden) {
            const box = panel.getBoundingClientRect();

            Object.assign(resizer.style, { left: box.left - 4 + 'px', top: box.top + 'px', height: box.height + 'px' });
            resizer.setAttribute('aria-label', t('resize_panel'));
            resizer.title = t('resize_panel');
            resizer.setAttribute('aria-valuemin', String(min));
            resizer.setAttribute('aria-valuemax', String(max));
            resizer.setAttribute('aria-valuenow', String(Math.round(box.width)));
        }
    }

    function setPanelWidth(width) {
        const { min, max } = widthLimits();

        storage.set('width:' + panelMode(), String(Math.round(Math.min(max, Math.max(min, width)))));
        applyPanelWidth();

        if (state.editing) {
            showEditedPart();
        } else if (state.item) {
            showItem(state.item);
        } else {
            showAt(state.selected);
        }
    }

    resizer.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        resizer.setPointerCapture(event.pointerId);
        // The form in the sidebar and the page's frame would take the pointer events while dragging.
        panel.style.pointerEvents = 'none';
        pageFrame.style.pointerEvents = 'none';

        const move = (moveEvent) => setPanelWidth(window.innerWidth - moveEvent.clientX);
        const up = () => {
            panel.style.pointerEvents = '';
            pageFrame.style.pointerEvents = '';
            resizer.removeEventListener('pointermove', move);
            resizer.removeEventListener('pointerup', up);
        };

        resizer.addEventListener('pointermove', move);
        resizer.addEventListener('pointerup', up);
    });

    resizer.addEventListener('keydown', (event) => {
        const width = panel.getBoundingClientRect().width;
        const { min, max } = widthLimits();
        const next = { ArrowLeft: width + 32, ArrowRight: width - 32, Home: max, End: min }[event.key];

        if (next !== undefined) {
            event.preventDefault();
            setPanelWidth(next);
        }
    });

    // Opened or closed with the buttons: beside the page the browser keeps it; over the page it opens and closes by itself.
    function setCollapsed(collapsed, focus = true) {
        state.collapsed = collapsed;
        state.autoOpened = false;

        if (!state.overlay) {
            storage.set('collapsed', collapsed ? '1' : '0');
        }

        renderPanel();

        if (focus) {
            (narrow() ? (collapsed ? floating.querySelector('button') : panel.querySelector('.nfe-panel__head button')) : rail.querySelector('[data-action="panel"]'))?.focus();
        }
    }

    // A view that needs the sidebar opens it; over the page, it closes again when that view is left (closeAutoOpened).
    function openPanel() {
        if (state.collapsed) {
            state.autoOpened = true;
        }

        state.collapsed = false;
    }

    function closeAutoOpened() {
        if (state.overlay && state.autoOpened) {
            state.collapsed = true;
            state.autoOpened = false;
        }
    }

    // Over the page (not pinned): the sidebar closes when the page is clicked, unless it shows work in progress.
    const AUTO_CLOSING_VIEWS = ['structure', 'posts', 'modules', 'check', 'templates'];

    function closeOnPageClick(event) {
        if (state.overlay && !state.collapsed && !narrow() && AUTO_CLOSING_VIEWS.includes(state.view) && !event.composedPath().includes(pageHost)) {
            setCollapsed(true, false);
        }
    }

    function setOverlay(overlay) {
        state.overlay = overlay;
        storage.set('overlay', overlay ? '1' : '0');
        renderPanel();
        rail.querySelector('[data-action="pin"]')?.focus();
    }

    function renderRail() {
        const open = !state.collapsed;
        const toggle = button(t(open ? 'collapse' : 'expand'), { icon: open ? 'panel_close' : 'panel_open', iconOnly: true, action: 'panel', onclick: () => setCollapsed(open) });

        toggle.setAttribute('aria-expanded', String(open));
        rail.hidden = narrow();
        rail.setAttribute('aria-label', t('sidebar'));
        rail.replaceChildren(
            toggle,
            button(t('panel_pin'), { icon: 'pin', iconOnly: true, action: 'pin', pressed: String(!state.overlay), onclick: () => setOverlay(!state.overlay) }),
        );
    }

    function renderPanelContent() {
        const page = state.structure?.page;

        if (state.view === 'form') {
            return;
        }

        if (state.view === 'languages') {
            renderLanguages();

            return;
        }

        if (state.view === 'aid') {
            renderWritingAid();

            return;
        }

        if (state.view === 'upgrade') {
            renderUpgrade();

            return;
        }

        if (state.view === 'posts') {
            renderPosts();

            return;
        }

        if (state.view === 'modules') {
            renderModules();

            return;
        }

        if (state.view === 'templates') {
            renderPageTemplates();

            return;
        }

        if (state.view === 'versions') {
            renderLanguageVersions();

            return;
        }

        if (state.view === 'upgrade_plus') {
            renderUpgradePlus();

            return;
        }

        if (state.view === 'check') {
            renderCheck();

            return;
        }

        if (state.view === 'upgrade_pro') {
            renderUpgradePro();

            return;
        }

        if (state.view === 'module') {
            return;
        }

        const nav = viewsNav('structure');
        const post = (state.structure?.articles || []).find(isPost);
        const title = post ? t('post', { title: post.post.title }) : (page ? t('page', { page: page.title }) : '');
        const head = h('div', { class: 'nfe-panel__head' }, [
            h('h2', { class: 'nfe-panel__title', text: title }),
            // The views take the place of the button "Languages" when there are several.
            nav ? null : languagesButton(),
            guideButton(),
            screenButton(),
            button(t('collapse'), { icon: 'collapse', iconOnly: true, action: 'collapse', onclick: () => setCollapsed(true) }),
            button(t('close_editor'), { icon: 'close', iconOnly: true, onclick: stopEditor }),
        ]);

        const tree = h('ul', { class: 'nfe-tree', role: 'tree', 'aria-label': title });

        panel.replaceChildren(head, nav || '', h('div', { class: 'nfe-panel__body' }, [h('div', { class: 'nfe-tree-head' }), tree, h('div', { class: 'nfe-create-article' })]), h('div', { class: 'nfe-panel__foot' }, [
            h('div', { 'data-status': '' }),
            h('div', { class: 'nfe-actions', 'data-actions': '' }),
        ]));
        panel.setAttribute('aria-label', title);
        renderStructure();
        renderStatus();
    }

    // The levels above the selected element as Contao's breadcrumb (#tl_breadcrumb, the back end's look), one click
    // each: all pages (Contao's page tree, where pages are created; Plus), the page, the article, the element. A click
    // on a level selects it and leaves the levels below.
    function levelBar() {
        const page = state.structure?.page;

        if (!page) {
            return null;
        }

        const current = state.editing?.id || state.selected;
        const article = current ? state.structure.articles.find((entry) => entry.elements.some((element) => element.id === current)) : null;
        const element = article?.elements.find((entry) => entry.id === current);
        const crumb = (symbol, text, action) => h('li', {}, [
            symbol ? icon(symbol) : null,
            symbol ? ' ' : null,
            action ? h('button', { type: 'button', class: 'nfe-crumb', onclick: action, text }) : h('span', { 'aria-current': 'location', text }),
        ].filter(Boolean));
        const leave = () => {
            if (state.editing) {
                parkEditing();
            }

            deselect();
        };
        const toPage = () => {
            leave();
            panel.querySelector('.nfe-panel__body')?.scrollTo({ top: 0 });
            pageWin?.scrollTo({ top: 0 });
        };
        const toArticle = () => {
            const first = state.ranges.get(article.elements[0]?.id);

            leave();
            panel.querySelector('.nfe-article[data-article="' + article.id + '"]')?.scrollIntoView({ block: 'start' });
            (first ? nodesOf(first).find((node) => node.nodeType === Node.ELEMENT_NODE) : null)?.scrollIntoView({ block: 'start' });
        };
        const items = [
            editions().pages ? crumb('pagemounts', t('all_pages'), openPageTree) : null,
            crumb('page', page.title, current ? toPage : null),
            article ? crumb('article', isPost(article) ? t('post_content') : (isNested(article) ? article.typeLabel : t('article', { title: article.title })), toArticle) : null,
            element ? crumb(null, element.typeLabel, null) : null,
        ].filter(Boolean);

        // Contao's separator stays in the item before it.
        items.slice(0, -1).forEach((item) => item.append(' › '));

        return h('nav', { class: 'nfe-levels', 'aria-label': t('levels') }, [h('ul', { id: 'tl_breadcrumb' }, items)]);
    }

    function renderStructure() {
        const tree = panel.querySelector('.nfe-tree');

        if (!tree || !state.structure) {
            return;
        }

        const focused = root.activeElement?.closest?.('.nfe-row') ? root.activeElement.dataset.key : null;
        const current = state.editing?.id || state.selected;
        const firstKey = state.structure.articles.flatMap((article) => [
            ...article.elements.map((element) => 'e' + element.id),
            ...(article.canCreate ? ['a' + article.id] : []),
        ])[0];

        tree.replaceChildren();

        for (const article of state.structure.articles) {
            const items = article.elements.map((element) => {
                const selected = element.id === current;
                const key = 'e' + element.id;
                const shortcuts = [element.canMove ? 'Alt+ArrowUp Alt+ArrowDown' : '', element.canDelete ? 'Delete' : '', 'Shift+F10'].filter(Boolean).join(' ');
                const row = h('div', {
                    class: 'nfe-row' + (selected ? ' nfe-row--selected' : '') + (element.invisible ? ' nfe-row--hidden' : ''),
                    role: 'treeitem',
                    tabindex: selected || (!current && key === firstKey) ? '0' : '-1',
                    'aria-selected': selected ? 'true' : 'false',
                    'aria-keyshortcuts': shortcuts,
                    'data-id': element.id,
                    'data-key': key,
                    onclick: () => select(element.id, true),
                    onkeydown: (event) => treeKey(event, element.id),
                    oncontextmenu: (event) => {
                        event.preventDefault();
                        rowMenu(element.id, event.currentTarget);
                    },
                }, [
                    element.canMove ? treeGrip(element.id) : h('span', { class: 'nfe-row__grip', 'aria-hidden': 'true' }),
                    h('span', { class: 'nfe-row__type', text: element.typeLabel }),
                    h('span', { class: 'nfe-row__text', text: element.summary }),
                    element.invisible ? h('span', { class: 'nfe-row__flag', text: t('hidden') }) : null,
                    element.translation?.status === 'outdated' ? h('span', { class: 'nfe-row__flag nfe-row__flag--outdated', title: t('status_outdated'), text: t('outdated_short') }) : null,
                    moreItems(element.id, element).length
                        ? h('button', {
                            type: 'button',
                            class: 'nfe-row__more',
                            tabindex: '-1',
                            title: t('more'),
                            'aria-hidden': 'true',
                            onclick: (event) => {
                                event.stopPropagation();
                                select(element.id, true);
                                rowMenu(element.id, panel.querySelector('.nfe-row[data-id="' + element.id + '"] .nfe-row__more'));
                            },
                        }, [icon('more')])
                        : null,
                ]);

                return h('li', { role: 'none' }, [row]);
            });

            // "Insert element" at the end of the article, reachable like the rows.
            if (article.canCreate) {
                const key = 'a' + article.id;

                items.push(h('li', { role: 'none' }, [h('div', {
                    class: 'nfe-row nfe-row--insert',
                    role: 'treeitem',
                    tabindex: !current && key === firstKey ? '0' : '-1',
                    'aria-selected': 'false',
                    'data-article': article.id,
                    'data-key': key,
                    onclick: () => createElement(article.id, article.elements.length ? article.elements[article.elements.length - 1].id : null),
                    onkeydown: (event) => treeKey(event, null, article.id),
                }, [h('span', { class: 'nfe-row__grip', 'aria-hidden': 'true' }), icon('new'), h('span', { text: t('insert_element') })])]));
            }

            const label = isPost(article)
                ? t('post_content')
                : isNested(article)
                    ? (article.title ? t('nested_content', { type: article.typeLabel, title: article.title }) : t('nested_content_untitled', { type: article.typeLabel }))
                    : t('article', { title: article.title });
            const previous = state.structure.articles[state.structure.articles.indexOf(article) - 1];

            tree.append(h('li', { role: 'none' }, [
                // On the page of a post, the page's own articles come below, under the name of the page.
                previous && isPost(previous) && !isPost(article) ? h('p', { class: 'nfe-section-title', text: t('page', { page: state.structure.page.title }) }) : null,
                h('div', { class: 'nfe-article' + (isNested(article) ? ' nfe-article--nested' : ''), 'data-article': article.id, text: label }),
                h('ul', { role: 'group', 'aria-label': label }, items),
            ]));
        }

        // Above the tree (outside of it, its buttons are no tree items): the post of the page, or the page bar.
        const treeHead = panel.querySelector('.nfe-tree-head');
        const post = state.structure.articles.find(isPost);

        treeHead?.replaceChildren(...[levelBar(), post ? postHead(post) : pageBar()].filter(Boolean));

        // "Create article", with the choice of the layout column when the layout has several.
        const create = panel.querySelector('.nfe-create-article');
        const columns = state.structure.columns || [];

        if (create) {
            create.replaceChildren();

            if (columns.length) {
                create.append(button(t('create_article'), {
                    icon: 'article',
                    onclick: (event) => {
                        if (columns.length === 1) {
                            createArticle(columns[0].id);
                        } else {
                            openMenu(event.currentTarget, columns.map((column) => ({ label: column.label, icon: 'article', action: () => createArticle(column.id) })), t('create_article'));
                        }
                    },
                }));
            }
        }

        if (focused) {
            panel.querySelector('.nfe-row[data-key="' + focused + '"]')?.focus();
        }
    }

    // Handle for dragging a row with mouse or finger; the keyboard uses Alt+arrow keys on the row.
    function treeGrip(id) {
        const grip = h('span', { class: 'nfe-row__grip nfe-row__grip--active', 'aria-hidden': 'true', title: t('move') }, [icon('drag')]);

        grip.addEventListener('pointerdown', (event) => {
            event.stopPropagation();
            startDrag(event, id, 'tree');
        });
        grip.addEventListener('click', (event) => event.stopPropagation());

        return grip;
    }

    function rowMenu(id, anchor) {
        const data = elementData(id);
        const items = data ? moreItems(id, data) : [];

        if (items.length) {
            openMenu(anchor, items, data.typeLabel);
        }
    }

    function treeKey(event, id, articleId = null) {
        const rows = [...panel.querySelectorAll('.nfe-tree .nfe-row')];
        const index = rows.indexOf(event.currentTarget);
        const focusRow = (to) => {
            const row = rows[Math.max(0, Math.min(rows.length - 1, to))];

            if (!row) {
                return;
            }

            for (const entry of rows) {
                entry.tabIndex = entry === row ? 0 : -1;
            }

            if (row.dataset.id) {
                select(Number(row.dataset.id), true);
            }

            panel.querySelector('.nfe-row[data-key="' + row.dataset.key + '"]')?.focus();
        };

        if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            event.preventDefault();

            if (id && elementData(id)?.canMove) {
                moveStep(id, event.key === 'ArrowUp' ? -1 : 1, { where: 'tree' });
            }
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusRow(index + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            focusRow(index - 1);
        } else if (event.key === 'Home') {
            event.preventDefault();
            focusRow(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            focusRow(rows.length - 1);
        } else if (event.key === 'Enter' || (event.key === ' ' && !id)) {
            event.preventDefault();

            if (articleId) {
                event.currentTarget.click();

                return;
            }

            const data = elementData(id);

            if (!data?.canUpdate) {
                return;
            }

            if (inlineTargets(id, data).length) {
                startEditing(id);
            } else {
                openForm(id);
            }
        } else if (event.key === 'Delete' && id && elementData(id)?.canDelete) {
            event.preventDefault();
            remove(id);
        } else if ((event.key === 'F10' && event.shiftKey) || event.key === 'ContextMenu') {
            event.preventDefault();

            if (id) {
                rowMenu(id, event.currentTarget);
            }
        }
    }

    // "All fields": Contao's own form of the element in the sidebar.
    // "field": the form opens at this field (Contao's TinyMCE of a text, the HTML of a value with tags).
    async function openForm(id, field = null) {
        const data = elementData(id);

        if (!data?.canUpdate) {
            return;
        }

        // The form shows what is stored and the page loads again after it: changes that wait for Save are saved or
        // dropped first.
        if (!(await settle())) {
            return;
        }

        // Plus: the translation window of the element also from its form.
        const translate = translationItems(id, data).find((item) => item.key === 'translate');
        const actions = translate ? [button(translate.label, { icon: 'translate', action: 'form-translate', onclick: translate.action })] : null;

        openRecordForm({ table: 'tl_content', id, title: t('all_fields_of', { type: data.typeLabel }), isNew: false, actions, field });
    }

    // Contao's form of a record in the sidebar: "all fields" of an element, or the form of a new
    // element or article. A new record counts as created once Contao has saved it (it is then part
    // of the structure); left without saving, the draft is removed again.
    function openRecordForm({ table, id, title, isNew, module = 'article', view = null, src = null, hint = null, returnTo = 'structure', onMessage = null, wide = false, actions = null, field = null }) {
        // Without its back-end module Contao answers "Forbidden"; say so instead of opening that page.
        if (!src && !module) {
            showToast(t('form_no_module'), null, 'note');

            return;
        }

        closeMenu(false);
        state.view = 'form';
        openPanel();
        state.form = { table, id, isNew, saved: false, changed: false, returnTo, hint, wide };
        renderPanel();

        // Contao's form saves without loading the frame again (Turbo); each page the frame
        // shows reports itself (FormBridgeListener), the first one is the opened form.
        let loads = 0;
        const origin = encodeURIComponent(location.origin);
        const iframe = h('iframe', {
            class: 'nfe-panel__form',
            title,
            src: src || (config.backend + '?do=' + module + '&table=' + table + '&id=' + id + '&act=edit&popup=1&nb=1&nfe_origin=' + origin + (view ? '&nfe_view=' + view : '') + (field ? '&nfe_field=' + encodeURIComponent(field) : '')),
        });
        const backendOrigin = new URL(config.backend, location.href).origin;
        const listener = async (event) => {
            if (event.origin !== backendOrigin || event.source !== iframe.contentWindow || event.data?.type !== 'nfe:form') {
                return;
            }

            loads += 1;

            // Pages and posts handle their forms themselves (new page, properties, settings).
            if (onMessage) {
                onMessage(event.data, loads);

                return;
            }

            if (loads < 2) {
                return;
            }

            // Saved: the page loads again at once (the structure comes with it) and shows the change while the form
            // stays open; a veil over the page says that it is being updated.
            if (!isNew) {
                await refreshElement(id);
                renderStructure();

                return;
            }

            await loadStructure();

            if (state.form?.id === id) {
                state.form.saved = table === 'tl_content' ? Boolean(elementData(id)) : state.structure.articles.some((article) => article.id === id);
            }
        };

        window.addEventListener('message', listener);
        state.form.stop = () => window.removeEventListener('message', listener);
        // The record changed elsewhere (translation window): the form shows what is stored now.
        state.form.reload = () => {
            loads = 0;
            waitForm(iframe);
            iframe.src = iframe.src;
        };

        panel.replaceChildren(
            h('div', { class: 'nfe-panel__head' }, [
                button(t('back_to_structure'), { icon: 'back', iconOnly: true, onclick: closeForm }),
                h('h2', { class: 'nfe-panel__title', text: title }),
                button(t('close'), { icon: 'close', iconOnly: true, onclick: closeForm }),
            ]),
            hint ? h('p', { class: 'nfe-form-hint', text: hint }) : '',
            actions ? actionRow(actions, 'nfe-form-actions') : '',
            framed(iframe),
        );
        panel.setAttribute('aria-label', title);
        panel.querySelector('.nfe-panel__head button')?.focus();
    }

    // After "Save" in Contao's form in the sidebar (properties, page settings, layout, form fields): the page loads
    // again at once and shows the change, the form stays open. Elements: openRecordForm itself.
    async function showSavedForm(loads) {
        if (loads < 2 || !state.form) {
            return;
        }

        // The structure comes with the page (attach).
        await reloadFrame();
    }

    async function closeForm() {
        const form = state.form;
        const id = state.selected;

        state.form = null;
        form?.stop?.();
        closeAutoOpened();

        if (form?.isNew) {
            if (form.saved && ['tl_content', 'tl_article'].includes(form.table)) {
                reloadPage({ select: form.table === 'tl_content' ? form.id : null, focus: { where: 'tree' } });

                return;
            }

            if (!form.saved) {
                try {
                    await api(discardPath(form), { method: 'POST', body: JSON.stringify({ page: config.page }) });
                } catch (e) {
                    // Contao removes unsaved drafts itself the next time the list is shown in the back end.
                }
            }
        }

        // Settings or properties changed: the page shows them after loading again.
        if (form?.changed && form.returnTo === 'structure') {
            reloadPage({ focus: { where: 'tree' } });

            return;
        }

        if (form?.returnTo === 'posts') {
            openPosts(true);

            return;
        }

        state.view = 'structure';
        renderPanel();
        panel.querySelector('.nfe-row[data-id="' + id + '"]')?.focus();
    }

    // Where a new record whose form was left without saving is removed (a new page still in the tree: leave the tree).
    function discardPath(form) {
        if (form.table === 'tl_content') {
            return '/element/' + form.id + '/discard';
        }

        if (form.table === 'tl_article') {
            return '/article/' + form.id + '/discard';
        }

        if (form.table === 'tl_page') {
            return form.id ? '/page/' + form.id + '/discard' : '/page/tree/leave';
        }

        if (form.table === 'tl_neo_fe_style') {
            return '/style/record/' + form.id + '/discard';
        }

        if (form.table === 'tl_form_field') {
            return '/design/field/' + form.id + '/discard';
        }

        return '/post/' + form.table + '/' + form.id + '/discard';
    }

    async function stopEditor() {
        if (!(await settle())) {
            return;
        }

        state.dirty = false;
        document.cookie = config.cookie + '=; Path=/; SameSite=Lax; Max-Age=0' + (location.protocol === 'https:' ? '; Secure' : '');
        location.reload();
    }

    // ------------------------------------------------------------------ start

    async function loadStructure() {
        const data = await api('/page/' + config.page);

        // The elements in an element follow the article that contains it.
        if ((data.nested || []).length) {
            data.articles = data.articles.flatMap((article) => [article, ...data.nested.filter((group) => group.article === article.id)]);
        }

        // Elements on the page that are not in its articles belong to a post (the reader of a news, an event …).
        if (data.editions?.posts) {
            const known = new Set(data.articles.flatMap((article) => article.elements.map((element) => element.id)));
            const ids = [...findRanges().keys()].filter((id) => !known.has(id));

            // Also by the address of the page: the post of a reader page without elements yet.
            try {
                const result = await api('/posts/on-page?page=' + config.page + '&ids=' + ids.join(',') + '&path=' + encodeURIComponent(pageWin.location.pathname));

                data.articles = [
                    ...(result.posts || []).map((post) => ({ id: 'p:' + post.table + ':' + post.id, kind: 'post', table: post.table, postId: post.id, title: post.title, canCreate: post.canCreate, elements: post.elements, post })),
                    ...data.articles,
                ];
            } catch (e) {
                // The page's own structure is enough to work with.
            }
        }

        state.structure = data;
    }

    // ------------------------------------------------------------------ the page in the frame

    // Views that belong to one page: after a link to another page the sidebar shows its structure.
    const PAGE_VIEWS = ['languages', 'check', 'templates', 'aid', 'versions'];
    // The document the editor last attached to, and the closed areas it has added to documents.
    let lastDoc = null;
    let watching = 0;
    const areas = new WeakMap();

    // The frame shows a new document (first load, a link followed, loaded again): the editor attaches to
    // it once its content is there.
    function watchFrame() {
        window.clearInterval(watching);
        watching = window.setInterval(checkFrame, 50);
    }

    function checkFrame() {
        let doc = null;

        try {
            doc = pageFrame.contentDocument;
        } catch (e) {
            doc = null;
        }

        if (!doc || doc === lastDoc || doc.URL === 'about:blank') {
            return;
        }

        window.clearInterval(watching);

        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', () => attachPage(doc), { once: true });
        } else {
            attachPage(doc);
        }
    }

    async function attachPage(doc) {
        if (doc === lastDoc || doc !== pageFrame.contentDocument) {
            return;
        }

        lastDoc = doc;

        const win = doc.defaultView;
        const address = plainUrl(win.location.href);
        let page = null;

        try {
            page = JSON.parse(doc.getElementById('nfe-page')?.textContent || 'null');
        } catch (e) {
            page = null;
        }

        // Not a page in edit mode (an error page, a file, a page of another kind): the view opens it itself.
        if (!page) {
            if (address !== plainUrl(location.href)) {
                location.href = address;
            }

            return;
        }

        const changed = page.page !== config.page || !state.structure;

        Object.assign(config, { page: page.page, nonce: page.nonce || null, styleNonce: page.styleNonce || null });
        pageDoc = doc;
        state.reloading = false;
        pageWin = win;

        // The view shows the address and the title of the page in the frame (for reloading, sharing, the preview bar).
        if (address !== location.href) {
            history.replaceState(history.state, '', address);
        }

        document.title = doc.title || document.title;
        pageFrame.title = document.title;

        if (!areas.has(doc)) {
            const node = doc.createElement('nfe-editor');
            const area = closedArea(node, 'page');

            areas.set(doc, { node, area });
            listenToTab(area);
            listenToPage(doc, win);
        }

        ({ node: pageHost, area: pageRoot } = areas.get(doc));
        pageRoot.append(markers, layer);
        (doc.body || doc.documentElement).append(pageHost);
        adoptVariables();
        win.addEventListener('load', adoptVariables, { once: true });

        if (changed) {
            state.selected = null;
            state.item = null;
            state.check = null;

            if (PAGE_VIEWS.includes(state.view)) {
                state.view = 'structure';
            }
        }

        try {
            await loadStructure();
        } catch (error) {
            showVeil(false);
            showToast(error.message || t('save_failed'));

            return;
        }

        if (doc !== pageDoc) {
            return;
        }

        state.ranges = findRanges();
        targetCache.clear();
        state.itemsLoaded = loadItems();

        // Elements can be reached with the keyboard on the page, too.
        for (const range of state.ranges.values()) {
            const first = nodesOf(range)[0];

            if (first && !first.hasAttribute('tabindex') && !first.matches('a,button,input,select,textarea')) {
                first.setAttribute('tabindex', '0');
            }
        }

        if (state.view === 'check' && editions().check) {
            openCheck();
        } else {
            renderPanel();
        }

        const restore = state.restore;

        state.restore = null;

        if (restore) {
            applyRestore(restore);
        } else if (state.selected && elementData(state.selected)) {
            select(state.selected);
        }

        // Callers waiting for the page to load again (reloadFrame) go on, once its parts are known.
        if (state.reloadDone) {
            await state.itemsLoaded;
            state.reloadDone?.();
        }

        if (doc === pageDoc) {
            showVeil(false);
        }
    }

    // CSS variables a website sets on nfe-editor in its layout (customising.md) reach the editor's area in the
    // page; the view, which has no style sheet of the website, takes over the values that differ from its own.
    const VARIABLES = ['--nfe-accent', '--nfe-surface', '--nfe-border', '--nfe-text', '--nfe-muted', '--nfe-error', '--nfe-hover', '--nfe-selected', '--nfe-selected-border', '--nfe-radius', '--nfe-frame-width', '--nfe-panel-width', '--nfe-form-width'];

    function adoptVariables() {
        if (!pageHost) {
            return;
        }

        const page = styleOf(pageHost);
        const own = window.getComputedStyle(host);

        for (const name of VARIABLES) {
            const value = page.getPropertyValue(name).trim();

            if (value && value !== own.getPropertyValue(name).trim()) {
                host.style.setProperty(name, value);
            }
        }

        applyPanelWidth();
    }

    // The page in the frame goes: what belongs to it ends with it; the next one is awaited.
    function detachPage(doc) {
        if (doc !== pageDoc) {
            return;
        }

        if (state.editing) {
            state.editing = null;
            state.dirty = state.pending.length > 0;
            state.error = false;
            renderStatus();
        }

        closeInfo(false);
        closeMenu(false);
        state.hover = null;
        state.ranges = new Map();
        state.items = new Map();
        targetCache.clear();
        frame.hidden = label.hidden = insertAbove.hidden = insertBelow.hidden = hoverFrame.hidden = format.hidden = imageTools.hidden = true;
        markers.replaceChildren();
        pageDoc = pageWin = pageHost = pageRoot = null;
        watchFrame();
    }

    // After a change to the structure (reloadPage): the same element selected, the focus where it was.
    function applyRestore(restore) {
        pageWin.scrollTo({ top: restore.scroll || 0, left: 0, behavior: 'instant' });

        if (restore.view === 'languages' && (editions().languages || []).length) {
            openLanguages(restore.target).then(() => {
                if (restore.translate) {
                    openAdopted(restore.translate, restore.translatePage || restore.target);
                }
            });

            return;
        }

        // After a save the page stays where it was; after a change of the structure the element is shown.
        if (restore.select && elementData(restore.select)) {
            select(restore.select, !restore.keepScroll);
        } else if (restore.item) {
            const items = state.itemsLoaded || Promise.resolve();

            items.then(() => {
                if (state.itemData?.[restore.item] || state.moduleTexts?.[restore.item]) {
                    selectItem(restore.item);
                }
            });
        }

        window.setTimeout(() => {
            if (restore.focus?.where === 'tab') {
                showAt(restore.select);
                label.focus();
            } else if (restore.focus?.where === 'part') {
                focusNodeOf(restore.select)?.focus();
            } else if (restore.focus?.where === 'tree' && !state.collapsed) {
                (panel.querySelector('.nfe-row[data-id="' + restore.select + '"]') || panel.querySelector('.nfe-tree .nfe-row'))?.focus();
            }

            if (restore.announce) {
                announce(restore.announce);
            }
        }, 450);
    }

    async function start() {
        renderPanel();
        watchToolbar();
        pageFrame.addEventListener('load', checkFrame);
        pageFrame.src = frameUrl(location.href);
        watchFrame();
        window.matchMedia('(max-width: 768px)').addEventListener('change', renderPanel);
        window.addEventListener('resize', () => {
            applyPanelWidth();
            placeInfo();
        });
        screenBar.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeScreen();
            }
        });
        showRememberedNotice();

        // The first time in this browser: where the quick guide is (it stays behind the button in the head).
        if (!storage.get('guide')) {
            storage.set('guide', '1');
            showToast(t('guide_hint'), null, 'note', { label: t('guide_open'), run: openGuide });
        }

        try {
            state.token = (await api('/session')).token;
        } catch (error) {
            showToast(error.message || t('save_failed'));
        }
    }

    // follow(): an editor view that a browser loaded into the frame (see the start of this script).
    window.NeoFrontendEditor = { version: 1, follow: (href) => pageFrame.contentWindow?.location.replace(frameUrl(href)), textMap: () => (pageDoc ? textMap() : []) };
    start();
})();
