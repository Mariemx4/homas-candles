'use strict';
// Real CSS geometry checks with no accounts, database, or writes to application data.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const browser = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-admin-layout-'));
const source = (p) =>
  fs.readFileSync(path.join(root, p), 'utf8').replace(/<\/script/gi, '<\\/script');
const css = 'file:///' + root.replaceAll('\\', '/') + '/public/assets/css/pages/admin.css';
const longName = 'White Flower Candle with a very long customer customization and product label';
const field = (id, type = 'text') =>
  `<div class="admin-field"><label for="${id}">${id}</label><input id="${id}" type="${type}"></div>`;
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}">
<body class="admin-body"><div class="admin-shell"><aside class="admin-sidebar" id="admin-navigation"><button class="admin-nav-close">Close navigation</button><nav class="admin-nav"><a class="admin-nav__item" href="#">Orders</a></nav></aside>
<div class="admin-shell__workspace"><header class="admin-topbar"><button class="admin-menu-button" aria-expanded="false"><span class="admin-menu-button__lines"></span><span class="admin-menu-button__label admin-visually-hidden">Open navigation</span></button><div class="admin-topbar__context"><span class="admin-topbar__eyebrow">HOM’AS</span><span class="admin-page-name">Manual order</span></div></header>
<main class="admin-main"><header class="admin-page-header"><div><h1>Orders and custom requests</h1><p>${longName}</p></div><a class="admin-button" href="#">Create order</a></header>
<section class="admin-filter-panel"><div class="admin-filter-form">${field('Search')}<div class="admin-field"><label for="filter-status">Status</label><select id="filter-status"><option>Pending verification</option></select></div><div class="admin-filter-form__actions"><button class="admin-button">Apply</button><button class="admin-button admin-button--secondary">Reset</button></div></div></section>
<form class="admin-panel admin-conversion-form"><fieldset><legend>Customer</legend><div class="admin-customer-mode-options">${['Existing account', 'Guest customer', 'New account'].map((label, i) => `<label class="admin-customer-mode-option"><input type="radio" name="mode" ${i === 0 ? 'checked' : ''}><span>${label}</span></label>`).join('')}</div><div class="admin-form-grid">${field('customer-name')}${field('customer-date', 'date')}</div></fieldset>
<fieldset><legend>Products</legend><section class="admin-manual-item"><div class="admin-manual-item__heading"><h3>Requested product</h3><button type="button" class="admin-button admin-button--secondary">Remove product</button></div><div class="admin-form-grid"><div class="admin-field"><label for="product">Product</label><select id="product" data-searchable><option value="">Choose a product</option>${Array.from({ length: 20 }, (_, i) => `<option value="${i}" data-label="${longName} ${i}" data-context="Candles · Flowers">${longName} ${i}</option>`).join('')}</select></div>${field('quantity', 'number')}<div class="admin-field admin-field--wide"><label for="notes">Notes</label><textarea id="notes"></textarea><small class="admin-field-error">Please review the requested customization.</small></div></div></section></fieldset>
<section class="admin-order-price-summary"><h2>Order summary</h2><dl class="admin-money-list"><div data-summary-row="total"><dt>Total Customer Pays</dt><dd>198.000 TND</dd></div></dl><p class="admin-gross-estimate">Admin only · Estimated cost 100.000 TND</p></section><div class="admin-conversion-form__actions"><button class="admin-button">Save order</button><button class="admin-button admin-button--secondary" disabled>Unavailable</button></div></form>
<section class="admin-panel admin-panel--table"><header class="admin-panel__header"><h2>Recent records</h2><span class="admin-badge admin-badge--warning">Pending verification</span></header><div class="admin-table-scroll" tabindex="0"><table class="admin-table admin-orders-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr><td>ORD-0001<small>Manual order</small></td><td>${longName}</td><td><span class="admin-badge admin-badge--success">Paid</span></td><td><button class="admin-button admin-button--secondary">View</button></td></tr><tr><td>ORD-0002</td><td>Guest</td><td>Draft</td><td>Edit</td></tr></tbody></table></div></section>
<section class="admin-panel admin-lifecycle-panel"><h2>Manage record</h2><p>Review dependencies before changing status.</p><form><button class="admin-button admin-button--warning">Archive</button><details><summary>Delete permanently</summary><p>This cannot be undone.</p><button class="admin-button admin-button--danger">Delete</button></details><a class="admin-button admin-button--secondary" href="#">Cancel</a></form></section>
<section class="admin-panel admin-calendar-panel"><header class="admin-calendar-nav"><button class="admin-button admin-button--secondary">Previous month</button><h2>October 2026</h2><button class="admin-button admin-button--secondary">Next month</button></header><div class="admin-calendar-scroll" tabindex="0"><div class="admin-calendar-weekdays">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => '<span>' + d + '</span>').join('')}</div><div class="admin-calendar-grid">${Array.from({ length: 7 }, (_, i) => '<div class="admin-calendar-day"><strong>' + i + '</strong><span class="admin-badge admin-badge--warning">99 orders</span></div>').join('')}</div></div></section>
<p hidden class="admin-field">Hidden content</p><pre id="result"></pre></main></div></div><button class="admin-nav-backdrop">Close navigation</button>
<script>${source('public/assets/js/custom_select.js').replace('export function', 'function')}</script><script>${source('public/assets/js/admin/navigation.js')}</script>
<script>window.addEventListener('load',()=>{try{
const check=(ok,message)=>{if(!ok)throw Error(message);};
const q=s=>document.querySelector(s),style=el=>getComputedStyle(el),box=el=>el.getBoundingClientRect();
initCustomSelects();
check(document.documentElement.scrollWidth<=innerWidth+1,'Page overflows at '+innerWidth);
check(box(q('#customer-name')).height>=44 && Math.abs(box(q('#customer-name')).height-box(q('#quantity')).height)<1,'Consistent controls with at least 44px targets');
check(style(q('input[type=radio]')).width==='17.5938px'||box(q('input[type=radio]')).width<20,'Radios remain compact');
check(style(q('[hidden]')).display==='none','Hidden contract survives component display');
check(style(q('.admin-field-error')).color==='rgb(151, 53, 40)','Validation helper retains error contrast');
check(style(q('.admin-panel')).overflowY==='visible','Panels allow dropdown overflow');
const rows=q('tbody').rows;check(Math.abs(box(rows[0].cells[1]).left-box(rows[1].cells[1]).left)<1,'Table columns align');
check(style(rows[0].cells[0]).display==='table-cell','Table semantics remain intact');
const scroll=q('.admin-table-scroll');check(style(scroll).overflowX==='auto'&&scroll.tabIndex===0,'Keyboard scroll region');
check(innerWidth>1100||scroll.scrollWidth>scroll.clientWidth,'Narrow tables scroll instead of shrinking');
const calendar=q('.admin-calendar-scroll');check(style(calendar).overflowX==='auto'&&(innerWidth>900||calendar.scrollWidth>calendar.clientWidth),'Calendar scrolls on mobile');
const trigger=q('#product').parentElement.querySelector('.custom-select__trigger');trigger.focus();trigger.click();
const menu=trigger.parentElement.querySelector('.custom-select__menu'),option=menu.querySelector('[role=option]');
check(Math.abs(box(trigger).height-box(q('#customer-name')).height)<1,'Searchable input matches native input');
check(style(menu).backgroundColor==='rgb(255, 250, 244)','Opaque brand-paper workspace menu');
check(box(menu).left>=0&&box(menu).right<=innerWidth+1,'Dropdown fits viewport');
check(menu.scrollHeight>menu.clientHeight,'Dropdown scrolls internally');
const r=box(option),hit=document.elementFromPoint(r.left+10,Math.max(0,r.top)+10);check(option.contains(hit),'Dropdown is not clipped or covered: '+JSON.stringify({option:{top:r.top,bottom:r.bottom},menu:{top:box(menu).top,bottom:box(menu).bottom},trigger:{top:box(trigger).top,bottom:box(trigger).bottom},hit:hit?.className,viewport:innerHeight}));
trigger.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));check(menu.hidden,'Escape closes menu');
trigger.focus();check(style(trigger).outlineStyle!=='none','Focus remains visible');
if(innerWidth<=896){q('.admin-menu-button').click();check(document.body.classList.contains('admin-nav-open'),'Mobile drawer opens');check(q('.admin-menu-button').getAttribute('aria-expanded')==='true','Drawer state announced');q('.admin-nav-close').click();check(!document.body.classList.contains('admin-nav-open'),'Drawer closes');}
check(box(q('[data-summary-row=total]')).height>box(q('.admin-gross-estimate')).height,'Customer total dominates estimate');
document.body.dataset.result='passed';q('#result').textContent='Geometry passed at '+innerWidth+' × '+innerHeight;
}catch(e){document.body.dataset.result='failed';document.querySelector('#result').textContent=e.stack;}
parent.postMessage({result:document.body.dataset.result,message:document.querySelector('#result').textContent},'*');});</script>`;
try {
  const file = path.join(scratch, 'layout.html');
  fs.writeFileSync(file, html);
  for (const [width, height] of [
    [1440, 1000],
    [1024, 900],
    [390, 844],
    [320, 740],
  ]) {
    // Chromium on Windows imposes a 500px top-level minimum. An iframe gives
    // the fixture the exact viewport, including true mobile media queries.
    const frame = path.join(scratch, 'frame-' + width + '.html');
    fs.writeFileSync(
      frame,
      `<!doctype html><body><iframe src="file:///${file.replaceAll('\\', '/')}" style="width:${width}px;height:${height}px;border:0"></iframe><pre id="result"></pre><script>addEventListener('message',e=>{document.body.dataset.result=e.data.result;document.querySelector('#result').textContent=e.data.message;});</script>`,
    );
    const run = spawnSync(
      browser,
      [
        '--headless',
        '--disable-gpu',
        '--no-first-run',
        '--allow-file-access-from-files',
        '--force-device-scale-factor=1',
        `--window-size=${Math.max(600, width + 50)},${height + 150}`,
        '--user-data-dir=' + path.join(scratch, 'profile-' + width),
        '--virtual-time-budget=2500',
        '--dump-dom',
        'file:///' + frame.replaceAll('\\', '/'),
      ],
      { encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024, windowsHide: true },
    );
    if (run.error) throw run.error;
    const message = run.stdout.match(/<pre id="result">([\s\S]*?)<\/pre>/)?.[1];
    if (!run.stdout.includes('data-result="passed"')) throw Error(message || run.stderr);
    console.log(message);
  }
} finally {
  const resolved = path.resolve(scratch);
  if (!resolved.startsWith(path.resolve(os.tmpdir()) + path.sep))
    throw Error('Unexpected temporary test directory');
  fs.rmSync(resolved, { recursive: true, force: true });
}
