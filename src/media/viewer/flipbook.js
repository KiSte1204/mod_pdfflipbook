/*
 * PDF Flipbook viewer
 * (C) 2026 Stefan Kirchhoff, webkiste.net – GNU General Public License version 2 or later
 *
 * Shows a PDF as a flipbook. Runs completely on the own server:
 * PDF.js (Mozilla, Apache-2.0) is bundled in lib/pdfjs, no external services are loaded.
 *
 * Usage:  index.html?pdf=/path/to/catalogue.pdf&lang=en&title=Main%20Catalogue#page=5
 *   pdf        path to the PDF (same domain only)
 *   lang       interface language (two letters, e.g. de, en, fr)
 *   title      title in the toolbar (otherwise PDF title or file name)
 *   cover      image shown as cover while the PDF loads (same domain only)
 *   langswitch 0 hides the language selector
 *   flip       flip duration in milliseconds (100–3000)
 *   accent, bar, barfg, stage   colours as hex without # (accent, toolbar background,
 *              toolbar text, page background), e.g. accent=e30613
 *   #page=5    start page (#seite=5 works too)
 */
// PDF.js "legacy" build: also runs in Safari and older browsers.
// The files use the .js extension so every web server delivers them with a JavaScript MIME type.
import * as pdfjsLib from './lib/pdfjs/pdf.min.js?v=6.2.108-legacy';
import { STRINGS, LANGUAGE_NAMES } from './lang.js?v=1.0.0';

const LIB_URL = new URL('./lib/pdfjs/', import.meta.url).href;
pdfjsLib.GlobalWorkerOptions.workerSrc = LIB_URL + 'pdf.worker.min.js?v=6.2.108-legacy';

// ------------------------------------------------------------------
// Konfiguration aus der URL
// ------------------------------------------------------------------
const params = new URLSearchParams(location.search);

function readStartPage() {
  const m = location.hash.match(/(?:seite|page)=(\d+)/i);
  return m ? parseInt(m[1], 10) : (parseInt(params.get('page'), 10) || 1);
}

function resolvePdfUrl(raw) {
  try {
    if (!raw) return null;
    const url = new URL(raw, location.href);
    // Nur PDFs der eigenen Domain anzeigen (verhindert Missbrauch des Viewers für fremde Dateien)
    return url.origin === location.origin ? url.href : null;
  } catch (e) {
    return null;
  }
}

let lang = (params.get('lang') || navigator.language || 'de').slice(0, 2).toLowerCase();
if (!STRINGS[lang]) lang = 'en';

const CONFIG = {
  pdfUrl: resolvePdfUrl(params.get('pdf')),
  coverUrl: params.get('cover') ? resolvePdfUrl(params.get('cover')) : null,
  title: params.get('title') || '',
  startPage: readStartPage()
};

// ------------------------------------------------------------------
// Colours from the URL (?accent=2a6fdb&bar=1f2328&barfg=f2f2f2&stage=e9ecef)
// ------------------------------------------------------------------
function parseHex(value) {
  if (!value) return null;
  let v = String(value).trim().replace(/^#/, '').toLowerCase();
  if (/^[0-9a-f]{3}$/.test(v)) v = v.split('').map((c) => c + c).join('');
  return /^[0-9a-f]{6}$/.test(v) ? [0, 2, 4].map((i) => parseInt(v.slice(i, i + 2), 16)) : null;
}

function applyColors() {
  const root = document.documentElement.style;
  const toHex = (rgb) => '#' + rgb.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('');

  const accent = parseHex(params.get('accent'));
  if (accent) {
    root.setProperty('--bk-accent', toHex(accent));
    root.setProperty('--bk-accent-dark', toHex(accent.map((c) => c * 0.82)));
    root.setProperty('--bk-accent-soft', `rgba(${accent.join(',')},0.1)`);
    root.setProperty('--bk-accent-mark', `rgba(${accent.join(',')},0.28)`);
    root.setProperty('--bk-accent-ring', `rgba(${accent.join(',')},0.2)`);
  }
  const map = { bar: '--bk-bar-bg', barfg: '--bk-bar-fg', stage: '--bk-stage-bg' };
  Object.keys(map).forEach((key) => {
    const rgb = parseHex(params.get(key));
    if (rgb) root.setProperty(map[key], toHex(rgb));
  });
}
applyColors();

// ------------------------------------------------------------------
// DOM
// ------------------------------------------------------------------
const $ = (id) => document.getElementById(id);
const el = {
  root: $('bk'), title: $('bk-title'), stage: $('bk-stage'), book: $('bk-book'),
  left: $('bk-left'), right: $('bk-right'),
  first: $('bk-first'), prev: $('bk-prev'), next: $('bk-next'), last: $('bk-last'),
  arrowPrev: $('bk-arrow-prev'), arrowNext: $('bk-arrow-next'),
  pageInput: $('bk-pageinput'), pageTotal: $('bk-pagetotal'),
  overviewBtn: $('bk-overview-btn'), overview: $('bk-overview'), thumbs: $('bk-thumbs'),
  searchBtn: $('bk-search-btn'), search: $('bk-search'), searchForm: $('bk-searchform'),
  searchInput: $('bk-searchinput'), searchStatus: $('bk-searchstatus'), results: $('bk-results'),
  zoomBtn: $('bk-zoom-btn'), zoom: $('bk-zoom'), zoomScroll: $('bk-zoom-scroll'), zoomPages: $('bk-zoom-pages'),
  zoomIn: $('bk-zoom-in'), zoomOut: $('bk-zoom-out'),
  fsBtn: $('bk-fs-btn'), download: $('bk-download'), langSelect: $('bk-lang'),
  loading: $('bk-loading'), loadingText: $('bk-loading-text'), progressBar: $('bk-progress-bar'),
  progress: $('bk-progress'), progressPct: $('bk-progress-pct'), loadingCover: $('bk-loading-cover')
};

// ------------------------------------------------------------------
// Sprache
// ------------------------------------------------------------------
const t = (key) => (STRINGS[lang] && STRINGS[lang][key]) || STRINGS.en[key] || key;

function applyLanguage() {
  document.documentElement.lang = lang;
  document.querySelectorAll('[data-i18n]').forEach((n) => { n.textContent = t(n.dataset.i18n); });
  document.querySelectorAll('[data-i18n-title]').forEach((n) => {
    n.title = t(n.dataset.i18nTitle);
    n.setAttribute('aria-label', t(n.dataset.i18nTitle));
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach((n) => { n.placeholder = t(n.dataset.i18nPlaceholder); });
  if (state.pdf) updateControls();
}

function buildLanguageSelect() {
  Object.keys(STRINGS).forEach((code) => {
    const opt = document.createElement('option');
    opt.value = code;
    opt.textContent = LANGUAGE_NAMES[code] || code.toUpperCase();
    el.langSelect.appendChild(opt);
  });
  el.langSelect.value = lang;
  el.langSelect.addEventListener('change', () => {
    lang = el.langSelect.value;
    params.set('lang', lang);
    history.replaceState(null, '', '?' + params.toString() + location.hash);
    applyLanguage();
  });
  // Für den Einsatz in Joomla kann die Auswahl mit ?langswitch=0 ausgeblendet werden
  if (params.get('langswitch') === '0') el.langSelect.hidden = true;
}

// ------------------------------------------------------------------
// Zustand
// ------------------------------------------------------------------
const state = {
  pdf: null,
  numPages: 0,
  ratio: 0.707,        // Breite / Höhe der ersten Seite
  mode: 'spread',      // 'spread' = Doppelseite, 'single' = Einzelseite
  page: 1,             // erste sichtbare Seite
  pageWidthPx: 0,      // angezeigte Breite einer Seite in CSS-Pixeln
  busy: false,
  pendingTarget: null,
  zoomLevel: 1
};

// Flip duration: ?flip=900 (milliseconds) overrides the value from the CSS file
const flipParam = parseInt(params.get('flip'), 10);
if (flipParam >= 100 && flipParam <= 3000) {
  document.documentElement.style.setProperty('--bk-flip-ms', flipParam + 'ms');
}
const FLIP_MS = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--bk-flip-ms'), 10) || 800;

// ------------------------------------------------------------------
// Seiten-Logik (Doppelseiten: Titel allein rechts, danach 2|3, 4|5 …)
// ------------------------------------------------------------------
const spreadOf = (p) => (p <= 1 ? 0 : Math.floor(p / 2));
const lastSpread = () => Math.floor(state.numPages / 2);

function pagesOfSpread(s) {
  if (s <= 0) return [null, 1];
  const l = 2 * s;
  const r = 2 * s + 1;
  return [l <= state.numPages ? l : null, r <= state.numPages ? r : null];
}

function visiblePages() {
  if (state.mode === 'single') return [state.page];
  return pagesOfSpread(spreadOf(state.page)).filter(Boolean);
}

function canonicalPage(p) {
  p = Math.min(Math.max(1, p | 0), state.numPages);
  if (state.mode === 'single') return p;
  const [l, r] = pagesOfSpread(spreadOf(p));
  return l || r;
}

// ------------------------------------------------------------------
// Rendern (mit Zwischenspeicher)
// ------------------------------------------------------------------
const renderCache = new Map(); // "seite@breite" -> Promise<string objectURL>
const linkCache = new Map();   // seite -> Promise<Array>
let activeRenders = 0;
const renderQueue = [];

function bucketWidth(w) {
  // Auf 100er-Schritte runden, damit beim Fenster-Verändern der Zwischenspeicher greift
  return Math.min(4096, Math.max(100, Math.ceil(w / 100) * 100));
}

function runQueue() {
  while (activeRenders < 2 && renderQueue.length) {
    const job = renderQueue.shift();
    activeRenders++;
    job().finally(() => { activeRenders--; runQueue(); });
  }
}

function renderPage(n, width, priority = false) {
  const w = bucketWidth(width);
  const key = n + '@' + w;
  if (renderCache.has(key)) return renderCache.get(key);

  const promise = new Promise((resolve, reject) => {
    const job = async () => {
      try {
        const page = await state.pdf.getPage(n);
        const vp1 = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: w / vp1.width });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);
        const ctx = canvas.getContext('2d', { alpha: false });
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport }).promise;
        const blob = await new Promise((res) => canvas.toBlob(res, 'image/webp', 0.92));
        canvas.width = canvas.height = 0;
        resolve(URL.createObjectURL(blob));
      } catch (e) {
        renderCache.delete(key);
        reject(e);
      }
    };
    priority ? renderQueue.unshift(job) : renderQueue.push(job);
    runQueue();
  });
  renderCache.set(key, promise);
  return promise;
}

function evictCache(keepAround) {
  const keep = 14;
  for (const [key, promise] of renderCache) {
    const [n, w] = key.split('@').map(Number);
    if (w <= 300) continue; // Vorschaubilder behalten
    if (Math.abs(n - keepAround) > keep) {
      renderCache.delete(key);
      promise.then((url) => URL.revokeObjectURL(url)).catch(() => {});
    }
  }
}

// Links aus dem PDF (externe URLs und Sprünge innerhalb des Katalogs)
function getLinks(n) {
  if (linkCache.has(n)) return linkCache.get(n);
  const promise = (async () => {
    const page = await state.pdf.getPage(n);
    const vp = page.getViewport({ scale: 1 });
    const annots = await page.getAnnotations();
    const links = [];
    for (const a of annots) {
      if (a.subtype !== 'Link' || !a.rect) continue;
      const [x1, y1] = vp.convertToViewportPoint(a.rect[0], a.rect[1]);
      const [x2, y2] = vp.convertToViewportPoint(a.rect[2], a.rect[3]);
      const box = {
        left: (Math.min(x1, x2) / vp.width) * 100,
        top: (Math.min(y1, y2) / vp.height) * 100,
        width: (Math.abs(x2 - x1) / vp.width) * 100,
        height: (Math.abs(y2 - y1) / vp.height) * 100
      };
      if (a.url) {
        links.push({ ...box, url: a.url });
      } else if (a.dest) {
        try {
          const dest = typeof a.dest === 'string' ? await state.pdf.getDestination(a.dest) : a.dest;
          if (dest && dest[0]) {
            const index = typeof dest[0] === 'number' ? dest[0] : await state.pdf.getPageIndex(dest[0]);
            links.push({ ...box, page: index + 1 });
          }
        } catch (e) { /* ungültiges Sprungziel ignorieren */ }
      }
    }
    return links;
  })().catch(() => []);
  linkCache.set(n, promise);
  return promise;
}

function addLinks(container, n) {
  getLinks(n).then((links) => {
    if (!links.length || container.dataset.page !== String(n)) return;
    const layer = document.createElement('div');
    layer.className = 'bk-links';
    for (const l of links) {
      const a = document.createElement('a');
      a.className = 'bk-link';
      a.style.cssText = `left:${l.left}%;top:${l.top}%;width:${l.width}%;height:${l.height}%`;
      if (l.url) {
        a.href = l.url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.title = l.url;
      } else {
        a.href = '#seite=' + l.page;
        a.title = t('page') + ' ' + l.page;
        a.addEventListener('click', (e) => { e.preventDefault(); closeZoom(); goTo(l.page); });
      }
      a.addEventListener('click', (e) => e.stopPropagation());
      layer.appendChild(a);
    }
    container.appendChild(layer);
  });
}

// Seite in einen Container (Buchseite, Blatt, Zoom) einsetzen
function fillInto(container, n, width, { links = false } = {}) {
  container.replaceChildren();
  container.dataset.page = n || '';
  container.classList.toggle('is-empty', !n);
  if (!n) return Promise.resolve();

  const ph = document.createElement('div');
  ph.className = 'bk-placeholder';
  ph.textContent = n;
  container.appendChild(ph);

  if (links) addLinks(container, n);

  return renderPage(n, width, true).then((url) => {
    if (container.dataset.page !== String(n)) return;
    const img = new Image();
    img.className = 'bk-pageimg';
    img.alt = t('page') + ' ' + n;
    img.decoding = 'async';
    img.src = url;
    return img.decode().catch(() => {}).then(() => {
      if (container.dataset.page !== String(n)) return;
      ph.replaceWith(img);
    });
  }).catch((e) => console.error('Seite ' + n, e));
}

const renderWidth = () => state.pageWidthPx * Math.min(window.devicePixelRatio || 1, 2);

function preloadAround(p) {
  // Reihenfolge: nächste Doppelseite zuerst, dann vorherige, dann die übernächste
  const w = renderWidth();
  const vis = visiblePages();
  const first = vis[0];
  const last = vis[vis.length - 1];
  const order = state.mode === 'spread'
    ? [last + 1, last + 2, first - 1, first - 2, last + 3, last + 4]
    : [last + 1, first - 1, last + 2, first - 2];
  order.forEach((n) => { if (n >= 1 && n <= state.numPages) renderPage(n, w); });
}

function waitFor(pages, maxMs = 1200) {
  const w = renderWidth();
  const all = Promise.all(pages.filter(Boolean).map((n) => renderPage(n, w, true).catch(() => {})));
  return Promise.race([all, new Promise((r) => setTimeout(r, maxMs))]);
}

// ------------------------------------------------------------------
// Layout
// ------------------------------------------------------------------
function layout() {
  const sw = el.stage.clientWidth;
  const sh = el.stage.clientHeight;
  const padX = sw < 760 ? 48 : 120;
  const padY = sw < 760 ? 16 : 40;
  const availW = Math.max(100, sw - padX);
  const availH = Math.max(100, sh - padY);

  const spread = sw >= 760 && availW / availH > state.ratio * 1.25;
  state.mode = spread ? 'spread' : 'single';

  let h, w;
  if (spread) {
    h = Math.min(availH, availW / (2 * state.ratio));
    w = h * 2 * state.ratio;
    state.pageWidthPx = w / 2;
  } else {
    h = Math.min(availH, availW / state.ratio);
    w = h * state.ratio;
    state.pageWidthPx = w;
  }
  el.book.style.width = Math.round(w) + 'px';
  el.book.style.height = Math.round(h) + 'px';
  el.book.classList.toggle('is-single', !spread);
}

function updateBookShift(page) {
  if (state.mode !== 'spread') {
    el.book.classList.remove('is-cover', 'is-back');
    return;
  }
  const [l, r] = pagesOfSpread(spreadOf(page));
  el.book.classList.toggle('is-cover', !l);
  el.book.classList.toggle('is-back', !!l && !r);
}

function showCurrent() {
  const w = renderWidth();
  if (state.mode === 'spread') {
    const [l, r] = pagesOfSpread(spreadOf(state.page));
    fillInto(el.left, l, w, { links: true });
    fillInto(el.right, r, w, { links: true });
  } else {
    fillInto(el.right, state.page, w, { links: true });
  }
  updateBookShift(state.page);
  updateControls();
  preloadAround(state.page);
}

function updateControls() {
  const vis = visiblePages();
  const atStart = vis[0] <= 1;
  const atEnd = vis[vis.length - 1] >= state.numPages;
  [el.first, el.prev, el.arrowPrev].forEach((b) => { b.disabled = atStart; });
  [el.next, el.last, el.arrowNext].forEach((b) => { b.disabled = atEnd; });
  if (document.activeElement !== el.pageInput) {
    el.pageInput.value = vis.length > 1 ? vis[0] + '–' + vis[1] : String(vis[0]);
  }
  el.pageTotal.textContent = t('of') + ' ' + state.numPages;
  history.replaceState(null, '', location.pathname + location.search + '#seite=' + vis[0]);

  el.thumbs.querySelectorAll('.bk-thumb').forEach((b) => {
    b.classList.toggle('is-current', vis.includes(Number(b.dataset.page)));
  });
}

// ------------------------------------------------------------------
// Blättern
// ------------------------------------------------------------------
function next() {
  const vis = visiblePages();
  goTo(vis[vis.length - 1] + 1);
}
function prev() {
  if (state.mode === 'single') return goTo(state.page - 1);
  const s = spreadOf(state.page);
  if (s > 0) goTo((pagesOfSpread(s - 1)[0]) || 1);
}

async function goTo(target) {
  if (!state.pdf) return;
  target = canonicalPage(target);
  if (state.busy) { state.pendingTarget = target; return; }
  if (target === state.page) { updateControls(); return; }

  state.busy = true;
  const from = state.page;
  try {
    if (state.mode === 'spread') await flipSpread(from, target);
    else await slideSingle(from, target);
  } finally {
    state.page = target;
    state.busy = false;
    evictCache(target);
    updateControls();
    preloadAround(target);
    if (state.pendingTarget !== null) {
      const p = state.pendingTarget;
      state.pendingTarget = null;
      goTo(p);
    }
  }
}

function afterTransition(node, ms) {
  return new Promise((resolve) => {
    let done = false;
    const finish = () => { if (!done) { done = true; resolve(); } };
    node.addEventListener('transitionend', finish, { once: true });
    setTimeout(finish, ms + 120);
  });
}

async function flipSpread(fromPage, toPage) {
  const w = renderWidth();
  const sFrom = spreadOf(fromPage);
  const sTo = spreadOf(toPage);
  const [lFrom, rFrom] = pagesOfSpread(sFrom);
  const [lTo, rTo] = pagesOfSpread(sTo);
  const forward = sTo > sFrom;

  await waitFor(forward ? [rFrom, lTo, rTo] : [lFrom, rTo, lTo]);

  const leaf = document.createElement('div');
  leaf.className = 'bk-leaf ' + (forward ? 'fwd' : 'bwd');
  const front = document.createElement('div');
  front.className = 'bk-face front';
  const back = document.createElement('div');
  back.className = 'bk-face back';
  leaf.append(front, back);

  // Vorderseite = aktuell sichtbare Seite, Rückseite = Seite, die nach dem Umblättern erscheint
  await Promise.all([
    fillInto(front, forward ? rFrom : lFrom, w),
    fillInto(back, forward ? lTo : rTo, w)
  ]);
  el.book.appendChild(leaf);

  // Darunterliegende Seite schon austauschen
  if (forward) fillInto(el.right, rTo, w, { links: true });
  else fillInto(el.left, lTo, w, { links: true });
  updateBookShift(toPage);

  leaf.getBoundingClientRect(); // Reflow, damit die Animation startet
  leaf.classList.add('is-turning');
  leaf.style.transform = forward ? 'rotateY(-180deg)' : 'rotateY(180deg)';
  await afterTransition(leaf, FLIP_MS);

  if (forward) await fillInto(el.left, lTo, w, { links: true });
  else await fillInto(el.right, rTo, w, { links: true });
  leaf.remove();
}

async function slideSingle(fromPage, toPage) {
  const w = renderWidth();
  await waitFor([toPage]);
  const overlay = document.createElement('div');
  overlay.className = 'bk-slide';
  await fillInto(overlay, fromPage, w);
  el.book.appendChild(overlay);
  // Neue Seite unter dem Overlay einsetzen, dann alte Seite wegschieben
  await fillInto(el.right, toPage, w, { links: true });
  overlay.getBoundingClientRect();
  overlay.style.transform = `translateX(${toPage > fromPage ? '-100%' : '100%'})`;
  overlay.style.opacity = '0';
  await afterTransition(overlay, 350);
  overlay.remove();
}

// ------------------------------------------------------------------
// Seitenübersicht
// ------------------------------------------------------------------
let thumbsBuilt = false;
let thumbObserver = null;

function buildThumbs() {
  if (thumbsBuilt) return;
  thumbsBuilt = true;
  thumbObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      const img = entry.target;
      thumbObserver.unobserve(img);
      renderPage(Number(img.dataset.page), 240).then((url) => { img.src = url; }).catch(() => {});
    });
  }, { root: el.thumbs, rootMargin: '300px' });

  const frag = document.createDocumentFragment();
  for (let n = 1; n <= state.numPages; n++) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'bk-thumb';
    btn.dataset.page = n;
    const img = document.createElement('img');
    img.className = 'bk-thumb-img';
    img.alt = '';
    img.dataset.page = n;
    img.style.aspectRatio = String(state.ratio);
    const label = document.createElement('span');
    label.textContent = n;
    btn.append(img, label);
    btn.addEventListener('click', () => { closePanels(); goTo(n); });
    frag.appendChild(btn);
    thumbObserver.observe(img);
  }
  el.thumbs.appendChild(frag);
}

// ------------------------------------------------------------------
// Suche
// ------------------------------------------------------------------
const textCache = [];
let searchRun = 0;

async function pageText(n) {
  if (textCache[n] !== undefined) return textCache[n];
  const page = await state.pdf.getPage(n);
  const content = await page.getTextContent();
  const text = content.items.map((it) => (it.str || '') + (it.hasEOL ? ' ' : '')).join('').replace(/\s+/g, ' ');
  textCache[n] = text;
  return text;
}

function escapeHtml(s) {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function runSearch(query) {
  const run = ++searchRun;
  const q = query.trim().replace(/\s+/g, ' ');
  el.results.replaceChildren();
  if (q.length < 2) { el.searchStatus.textContent = ''; return; }
  const needle = q.toLocaleLowerCase();
  let hits = 0;

  for (let n = 1; n <= state.numPages; n++) {
    if (run !== searchRun) return; // neue Suche gestartet
    if (textCache[n] === undefined) {
      el.searchStatus.textContent = `${t('searchIndexing')} ${n} / ${state.numPages}`;
    }
    const text = await pageText(n);
    const lower = text.toLocaleLowerCase();
    let idx = lower.indexOf(needle);
    let perPage = 0;
    while (idx !== -1 && perPage < 5 && hits < 300) {
      const start = Math.max(0, idx - 45);
      const end = Math.min(text.length, idx + needle.length + 45);
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.innerHTML =
        `<span class="bk-res-page">${escapeHtml(t('page'))} ${n}</span>` +
        (start > 0 ? '… ' : '') +
        escapeHtml(text.slice(start, idx)) +
        '<mark>' + escapeHtml(text.slice(idx, idx + needle.length)) + '</mark>' +
        escapeHtml(text.slice(idx + needle.length, end)) +
        (end < text.length ? ' …' : '');
      btn.addEventListener('click', () => {
        if (window.innerWidth < 760) closePanels();
        goTo(n);
      });
      li.appendChild(btn);
      el.results.appendChild(li);
      hits++;
      perPage++;
      idx = lower.indexOf(needle, idx + needle.length);
    }
  }
  if (run !== searchRun) return;
  el.searchStatus.textContent = hits ? `${hits}${hits >= 300 ? '+' : ''} ${t('searchHits')}` : t('searchNone');
}

// ------------------------------------------------------------------
// Panels, Zoom, Vollbild
// ------------------------------------------------------------------
function closePanels() {
  el.overview.hidden = true;
  el.search.hidden = true;
  el.overviewBtn.classList.remove('is-active');
  el.searchBtn.classList.remove('is-active');
}

function togglePanel(panel, btn) {
  const open = panel.hidden;
  closePanels();
  if (!open) return;
  panel.hidden = false;
  btn.classList.add('is-active');
  if (panel === el.overview) {
    buildThumbs();
    const cur = el.thumbs.querySelector('.bk-thumb.is-current') ||
      el.thumbs.querySelector(`.bk-thumb[data-page="${state.page}"]`);
    updateControls();
    if (cur) cur.scrollIntoView({ block: 'center' });
  } else {
    el.searchInput.focus();
    el.searchInput.select();
  }
}

const ZOOM_LEVELS = [1, 1.5, 2, 3];

function renderZoom() {
  const pages = visiblePages();
  const vw = el.zoomScroll.clientWidth - 40;
  const vh = el.zoomScroll.clientHeight - 20;
  const base = Math.min(vw / pages.length, vh * state.ratio);
  const cssW = base * state.zoomLevel;
  const pxW = Math.min(4096, cssW * Math.min(window.devicePixelRatio || 1, 2));

  if (el.zoomPages.children.length !== pages.length ||
      [...el.zoomPages.children].some((c, i) => c.dataset.page !== String(pages[i]))) {
    el.zoomPages.replaceChildren(...pages.map(() => {
      const d = document.createElement('div');
      d.className = 'bk-zoom-page';
      d.style.aspectRatio = String(state.ratio);
      return d;
    }));
    pages.forEach((n, i) => fillInto(el.zoomPages.children[i], n, pxW, { links: true }));
  } else {
    // Bisheriges Bild sofort skalieren, schärfere Version nachladen
    pages.forEach((n, i) => {
      const box = el.zoomPages.children[i];
      renderPage(n, pxW, true).then((url) => {
        const img = box.querySelector('img');
        if (img) img.src = url;
      }).catch(() => {});
    });
  }
  [...el.zoomPages.children].forEach((d) => { d.style.width = cssW + 'px'; });
  el.zoomIn.disabled = state.zoomLevel >= ZOOM_LEVELS[ZOOM_LEVELS.length - 1];
  el.zoomOut.disabled = state.zoomLevel <= ZOOM_LEVELS[0];
}

function openZoom() {
  closePanels();
  state.zoomLevel = ZOOM_LEVELS[1];
  el.zoom.hidden = false;
  el.zoomPages.replaceChildren();
  renderZoom();
}
function closeZoom() { el.zoom.hidden = true; }

function changeZoom(dir) {
  const i = ZOOM_LEVELS.indexOf(state.zoomLevel) + dir;
  if (i < 0 || i >= ZOOM_LEVELS.length) return;
  const sc = el.zoomScroll;
  const cx = (sc.scrollLeft + sc.clientWidth / 2) / sc.scrollWidth;
  const cy = (sc.scrollTop + sc.clientHeight / 2) / sc.scrollHeight;
  state.zoomLevel = ZOOM_LEVELS[i];
  renderZoom();
  sc.scrollLeft = cx * sc.scrollWidth - sc.clientWidth / 2;
  sc.scrollTop = cy * sc.scrollHeight - sc.clientHeight / 2;
}

function enableDragScroll(sc) {
  let sx, sy, sl, st, dragging = false;
  sc.addEventListener('pointerdown', (e) => {
    if (e.pointerType !== 'mouse' || e.target.closest('a')) return;
    dragging = true; sx = e.clientX; sy = e.clientY; sl = sc.scrollLeft; st = sc.scrollTop;
    sc.classList.add('is-dragging');
  });
  window.addEventListener('pointermove', (e) => {
    if (!dragging) return;
    sc.scrollLeft = sl - (e.clientX - sx);
    sc.scrollTop = st - (e.clientY - sy);
  });
  window.addEventListener('pointerup', () => { dragging = false; sc.classList.remove('is-dragging'); });
}

function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen();
  else el.root.requestFullscreen().catch(() => {});
}

// ------------------------------------------------------------------
// Bedienung
// ------------------------------------------------------------------
function bindEvents() {
  el.first.addEventListener('click', () => goTo(1));
  el.last.addEventListener('click', () => goTo(state.numPages));
  el.prev.addEventListener('click', prev);
  el.next.addEventListener('click', next);
  el.arrowPrev.addEventListener('click', prev);
  el.arrowNext.addEventListener('click', next);

  el.pageInput.addEventListener('focus', () => { el.pageInput.value = String(visiblePages()[0]); el.pageInput.select(); });
  el.pageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const n = parseInt(el.pageInput.value, 10);
      el.pageInput.blur();
      if (n) goTo(n);
    }
  });
  el.pageInput.addEventListener('blur', updateControls);

  // Klick auf linke/rechte Buchseite blättert, Doppelklick vergrößert
  el.left.addEventListener('click', prev);
  el.right.addEventListener('click', (e) => {
    if (state.mode === 'single') {
      const r = el.right.getBoundingClientRect();
      (e.clientX - r.left < r.width / 3) ? prev() : next();
    } else {
      next();
    }
  });
  el.book.addEventListener('dblclick', openZoom);

  // Wischen auf Touch-Geräten
  let tx = null, ty = null;
  el.stage.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1 || e.target.closest('.bk-panel')) { tx = null; return; }
    tx = e.touches[0].clientX; ty = e.touches[0].clientY;
  }, { passive: true });
  el.stage.addEventListener('touchend', (e) => {
    if (tx === null) return;
    const dx = e.changedTouches[0].clientX - tx;
    const dy = e.changedTouches[0].clientY - ty;
    tx = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) (dx < 0 ? next : prev)();
  }, { passive: true });

  el.overviewBtn.addEventListener('click', () => togglePanel(el.overview, el.overviewBtn));
  el.searchBtn.addEventListener('click', () => togglePanel(el.search, el.searchBtn));
  document.querySelectorAll('.bk-panel [data-close]').forEach((b) => b.addEventListener('click', closePanels));
  el.searchForm.addEventListener('submit', (e) => { e.preventDefault(); runSearch(el.searchInput.value); });

  el.zoomBtn.addEventListener('click', openZoom);
  el.zoom.querySelector('[data-close]').addEventListener('click', closeZoom);
  el.zoomIn.addEventListener('click', () => changeZoom(1));
  el.zoomOut.addEventListener('click', () => changeZoom(-1));
  enableDragScroll(el.zoomScroll);

  if (document.fullscreenEnabled) el.fsBtn.addEventListener('click', toggleFullscreen);
  else el.fsBtn.hidden = true;

  document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    if (e.key === 'Escape') {
      if (!el.zoom.hidden) closeZoom(); else closePanels();
      return;
    }
    if (tag === 'input' || tag === 'select' || tag === 'textarea' || !el.zoom.hidden) return;
    if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); next(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); prev(); }
    else if (e.key === 'Home') { e.preventDefault(); goTo(1); }
    else if (e.key === 'End') { e.preventDefault(); goTo(state.numPages); }
  });

  window.addEventListener('hashchange', () => {
    const p = readStartPage();
    if (!visiblePages().includes(p)) goTo(p);
  });

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const oldMode = state.mode;
      layout();
      if (oldMode !== state.mode) state.page = canonicalPage(state.page);
      showCurrent();
      if (!el.zoom.hidden) renderZoom();
    }, 150);
  });
}

// ------------------------------------------------------------------
// Start
// ------------------------------------------------------------------
function showError(detail) {
  el.loading.hidden = false;
  el.loading.classList.add('is-error');
  el.loadingText.textContent = t('error');
  if (detail) {
    console.error(detail);
    // Technischen Grund klein anzeigen (hilft bei der Fehlersuche)
    const info = document.createElement('small');
    info.style.cssText = 'color:#888; max-width:90%; text-align:center;';
    info.textContent = String((detail && (detail.name ? detail.name + ': ' : '') + (detail.message || detail)) || '');
    el.loading.appendChild(info);
  }
}

async function init() {
  buildLanguageSelect();
  applyLanguage();
  bindEvents();

  if (!CONFIG.pdfUrl) { showError('Missing or invalid PDF path (only files on this domain are allowed)'); return; }
  el.download.href = CONFIG.pdfUrl;

  // Titelbild sofort anzeigen (kommt vom Katalog-Modul), bis die echte erste Seite berechnet ist
  if (CONFIG.coverUrl && CONFIG.startPage <= 1) {
    el.loadingCover.onload = () => {
      const img = el.loadingCover;
      const ratio = img.naturalWidth / img.naturalHeight || 0.707;
      const maxW = (el.stage.clientWidth - 40) * 0.95;
      const maxH = Math.max(150, el.stage.clientHeight - 110);
      const h = Math.min(maxH, maxW / ratio);
      img.style.height = Math.round(h) + 'px';
      img.style.width = Math.round(h * ratio) + 'px';
      img.hidden = false;
      el.loading.classList.add('has-cover');
    };
    el.loadingCover.src = CONFIG.coverUrl;
  }
  if (CONFIG.title) el.title.textContent = CONFIG.title;

  try {
    const task = pdfjsLib.getDocument({
      url: CONFIG.pdfUrl,
      cMapUrl: LIB_URL + 'cmaps/',
      cMapPacked: true,
      standardFontDataUrl: LIB_URL + 'standard_fonts/'
    });
    let shownPct = 0;
    const setProgress = (pct) => {
      shownPct = Math.max(shownPct, Math.min(99, Math.round(pct)));
      el.progressBar.style.width = Math.max(8, shownPct) + '%';
      el.progressPct.textContent = shownPct + ' %';
      el.progress.setAttribute('aria-valuenow', String(shownPct));
    };
    task.onProgress = ({ loaded, total }) => { if (total) setProgress((loaded / total) * 100); };
    state.pdf = await task.promise;
    state.numPages = state.pdf.numPages;

    const first = await state.pdf.getPage(1);
    const vp = first.getViewport({ scale: 1 });
    state.ratio = vp.width / vp.height;

    let title = CONFIG.title;
    if (!title) {
      try {
        const meta = await state.pdf.getMetadata();
        title = (meta.info && meta.info.Title) || '';
      } catch (e) { /* kein Titel */ }
    }
    if (!title) title = decodeURIComponent(CONFIG.pdfUrl.split('/').pop().replace(/\.pdf$/i, ''));
    el.title.textContent = title;
    document.title = title;

    layout();
    state.page = canonicalPage(CONFIG.startPage);
    setProgress(Math.max(shownPct, 60));
    await waitFor(visiblePages(), 4000);
    showCurrent();
    setProgress(100);
    el.progressPct.textContent = '100 %';
    // Ladeanzeige sanft ausblenden
    await new Promise((r) => setTimeout(r, 150));
    el.loading.classList.add('is-fading');
    setTimeout(() => { el.loading.hidden = true; el.loading.classList.remove('is-fading'); }, 320);
  } catch (e) {
    showError(e);
  }
}

init();
