'use strict';
// Real Chromium DOM coverage for the shared control and manual-order integration.
const fs = require('node:fs'),
  path = require('node:path'),
  os = require('node:os'),
  { spawnSync } = require('node:child_process');
const browser = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!fs.existsSync(browser)) throw new Error('Set CHROME_PATH to a Chromium browser executable.');
const root = path.resolve(__dirname, '../..'),
  scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-selector-'));
const catalogue = {
  1: {
    name: 'White Flower Candle',
    category_id: '10',
    category_name: 'Candles',
    type_id: '20',
    type_name: 'Flowers',
    standard_price: '12.000',
    minimum_quantity: 1,
    colors: [
      [31, 'Ivory'],
      [33, 'White'],
    ],
    scents: [
      [41, 'Vanilla'],
      [42, 'Rose'],
      [43, 'Amber'],
    ],
  },
  2: {
    name: 'Pink Bouquet',
    category_id: '11',
    category_name: 'Bouquets',
    type_id: '21',
    type_name: 'Floral',
    standard_price: '20.000',
    minimum_quantity: 1,
    colors: [[32, 'Pink']],
    scents: [],
  },
  3: {
    name: 'Moon Jar',
    category_id: '10',
    category_name: 'Candles',
    type_id: '22',
    type_name: 'Jar',
    standard_price: '8.000',
    minimum_quantity: 1,
    colors: [],
    scents: [],
  },
};
function select(field, attrs) {
  return `<div class="admin-field"><label for="${field}">${field}</label><select id="${field}" ${attrs}><option value="">Choose</option></select></div>`;
}
const row = `<section data-order-item><span data-item-number></span><button data-remove-item></button>
${select('category', 'data-product-guide="category"')}${select('type', 'data-product-guide="type"')}
${select('product', 'data-product-select data-item-field="product_id" data-searchable required')}<small data-product-results></small>
<div data-option-group>${select('color', 'data-option-select="colors" data-item-field="color_id" data-searchable')}</div>
<div data-option-group>${select('scent', 'data-option-select="scents" data-item-field="scent_id" data-searchable')}</div>
<div class="admin-field"><label for="quantity">Quantity</label><input id="quantity" data-item-field="quantity" value="1"></div>
<div data-line-base></div><div data-line-tier-row><span data-line-tier></span></div><div data-line-bulk-row><span data-line-bulk></span></div><div data-line-total></div>
</section>`;
const js = (p) => fs.readFileSync(path.join(root, p), 'utf8').replace(/<\/script/gi, '<\\/script');
const assertions = `
const check=(ok,message)=>{if(!ok)throw Error(message);};
const change=el=>el.dispatchEvent(new Event('change',{bubbles:true}));
const input=el=>el.dispatchEvent(new Event('input',{bubbles:true}));
const key=(el,k)=>el.dispatchEvent(new KeyboardEvent('keydown',{key:k,bubbles:true}));
const settle=()=>new Promise(resolve=>setTimeout(resolve,350));
const native=s=>document.querySelector(s), control=el=>el.parentElement.querySelector('.custom-select__trigger'), menu=el=>el.parentElement.querySelector('.custom-select__menu');
await settle();
const product=native('[data-product-select]'),category=native('[data-product-guide="category"]'),type=native('[data-product-guide="type"]'),color=native('[data-option-select="colors"]'),scent=native('[data-option-select="scents"]'),field=control(product);
const exact=()=>{const checked=[...menu(product).querySelectorAll('[aria-selected="true"]')];check(checked.length===1&&checked[0].dataset.value===product.value,'Exactly the submitted product is checked');check(checked[0].classList.contains('is-active'),'Selected product is highlighted on reopening');check(new FormData(native('form')).get('items[0][product_id]')===product.value,'Submitted product ID agrees');};
field.click();field.value='White Flower Candle';input(field);key(field,'ArrowDown');key(field,'Enter');
check(product.value==='1'&&field.value==='White Flower Candle','A direct search chooses the correct name and ID');check(category.value==='10'&&control(category).textContent.includes('Candles'),'A category reflects Candles');check(type.value==='20'&&control(type).textContent.includes('Flowers'),'A type reflects Flowers');
check(color.options[1].value==='31'&&scent.options[1].value==='41','A assigned options appear');check(native('[data-line-base]').textContent==='12.000 TND','A standard price appears immediately');await settle();check(native('[data-line-total]').textContent==='12.000 TND','A authoritative line price appears');
field.click();exact();check(menu(product).querySelector('.custom-select__primary').textContent==='White Flower Candle','Primary product name is separate');check(menu(product).querySelector('.custom-select__secondary').textContent.includes('Flowers'),'Secondary taxonomy is separate');check(parseInt(menu(product).style.maxHeight)<=360,'Dropdown height is bounded');
// E: Both assigned option fields list on click, filter, and support keyboard selection.
for(const [el,query,id] of [[color,'Ivory','31'],[scent,'Vanilla','41']]){const c=control(el);c.click();check(menu(el).querySelectorAll('[role="option"]').length===el.options.length-1,'E click lists valid choices');c.value=query;input(c);key(c,'ArrowDown');key(c,'Enter');check(el.value===id,'E keyboard chooses configured ID');}
// C: Direct programmatic replacement clears A options and synchronizes B.
product.value='2';change(product);check(field.value==='Pink Bouquet','C programmatic product name synchronizes');check(category.value==='11'&&type.value==='21','C new taxonomy synchronizes');check(color.value===''&&color.options[1].value==='32'&&scent.value===''&&scent.disabled&&control(scent).disabled,'C stale options disappear');check(control(scent).placeholder.includes('No configured scents'),'C empty state');check(native('[data-line-base]').textContent==='20.000 TND','C standard price refreshes');await settle();check(native('[data-line-total]').textContent==='20.000 TND','C authoritative line refreshes');
// B: Category-first discovery removes incompatible product/type selections.
category.value='10';change(category);await Promise.resolve();check([...type.options].map(o=>o.value).join(',')===',20,22','B types narrow to Candles');check([...product.options].map(o=>o.value).join(',')===',1,3','B products narrow to Candles');check(product.value===''&&field.value==='','B incompatible product and visible label clear');
// D: Search/select/reopen, and refreshing the same native option list.
field.click();field.value='White Flower Candle';input(field);key(field,'Enter');field.click();exact();const saved=product.value;product.replaceChildren(...[...product.options].map(o=>o.cloneNode(true)));product.value=saved;product.dispatchEvent(new Event('select-sync'));await Promise.resolve();exact();
field.value='Moon';input(field);product.value='3';change(product);check(field.value==='Moon Jar','Programmatic replacement cancels a stale search label');field.click();exact();product.value='1';change(product);
const qty=native('[data-item-field="quantity"]');qty.value='2';input(qty);await settle();check(native('[data-line-total]').textContent==='24.000 TND','Quantity refresh uses authoritative preview');
window.previewFailure='Review the delivery method and fee.';qty.value='3';input(qty);await settle();check(native('[data-line-base]').textContent==='12.000 TND','Failed order quote retains a valid catalogue standard price');check(native('[data-line-total]').textContent===window.previewFailure,'Failed preview explains the actual problem');window.previewFailure='';
native('[data-add-item]').click();await Promise.resolve();check(document.querySelectorAll('[data-order-item]').length===2,'Add Product retains multiple rows');check(document.querySelectorAll('[data-order-item]')[1].querySelectorAll('select[data-custom-select-ready="true"]').length===5,'Added Product Color Scent and guides enhance');
document.body.dataset.result='passed';document.querySelector('#result').textContent='Scenarios A-E passed in Chromium';
`;
const html = `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="file:///${root.replaceAll('\\', '/')}/public/assets/css/pages/admin.css"><body class="admin-body"><main class="admin-main"><form data-manual-order-form data-price-preview="preview"><div data-order-items>${row}</div><button type="button" data-add-item>Add Product</button><p data-price-error></p><span data-promo-label></span><span data-promo-description></span><span data-admin-estimate="cost"></span></form><template id="manual-order-item-template">${row}</template><script type="application/json" id="manual-order-catalogue">${JSON.stringify(catalogue)}</script><pre id="result"></pre></main>
<script>window.fetch=async(url,request)=>{const body=request.body;return {ok:true,json:async()=>window.previewFailure ? {success:false,error:window.previewFailure} : ({success:true,lines:[...document.querySelectorAll('[data-order-item]')].map((r,i)=>{const p=${JSON.stringify(catalogue)}[body.get('items['+i+'][product_id]')];return {base:p.standard_price,quantity_price:p.standard_price,bulk_from:null,line:(Number(p.standard_price)*Number(body.get('items['+i+'][quantity]'))).toFixed(3)};}),amounts:{},savings:'0.000',admin_estimate:null})};};</script>
<script type="module" src="file:///${root.replaceAll('\\', '/')}/public/assets/js/admin/selects.js?v=browser-test"></script>
<script>${js('public/assets/js/admin/manual-order.js')}</script><script>document.addEventListener('DOMContentLoaded',async()=>{try{${assertions}}catch(e){document.body.dataset.result='failed';document.querySelector('#result').textContent=e.stack;}});</script>`;
try {
  const file = path.join(scratch, 'selector.html');
  fs.writeFileSync(file, html);
  const result = spawnSync(
    browser,
    [
      '--headless',
      '--disable-gpu',
      '--no-first-run',
      '--allow-file-access-from-files',
      '--user-data-dir=' + path.join(scratch, 'profile'),
      '--virtual-time-budget=5000',
      '--dump-dom',
      'file:///' + file.replaceAll('\\', '/'),
    ],
    { encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024, windowsHide: true },
  );
  if (result.error) throw result.error;
  if (!result.stdout.includes('data-result="passed"'))
    throw Error(result.stdout.match(/<pre id="result">([\s\S]*?)<\/pre>/)?.[1] || result.stderr);
  console.log(
    'Manual selector browser: scenarios A-E, native submission, option refresh, quantity prices and added rows passed in Chromium.',
  );
} finally {
  fs.rmSync(scratch, { recursive: true, force: true });
}
