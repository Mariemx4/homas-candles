'use strict';
// Render the real PHP Product cards, then check responsive geometry and preview states.
// No account, database, pricing calculations, or application writes are needed.
const fs = require('node:fs'),
  path = require('node:path'),
  os = require('node:os');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const browser = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const php =
  process.env.HOMAS_PHP || (fs.existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
const quote = { base: '15.780', quantity_price: '14.500', bulk_from: '12.000', line: '145.000' };
const render = spawnSync(php, [], {
  cwd: root,
  encoding: 'utf8',
  input: `<?php
require 'app/Bootstrap/autoload.php';
$escape = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$manualOrderValues = []; $manualOrderFlash = ['errors'=>[]];
$manualOrderOptions = ['products'=>[1=>['id'=>1,'name'=>'Flower Candle','category_name'=>'Candles','type_name'=>'Flowers','minimum_quantity'=>1,'colors'=>[31=>'Ivory'],'scents'=>[41=>'Rose']]]];
$manualOrderPricing = ['lines'=>[
 ['base'=>'15.780','quantity_price'=>'15.780','bulk_from'=>null,'line'=>'15.780'],
 ['base'=>'15.780','quantity_price'=>'14.500','bulk_from'=>'12.000','line'=>'145.000'],
 ['base'=>'15.780','quantity_price'=>'14.500','bulk_from'=>'12.000','line'=>'14500000.000']
]];
$view = file_get_contents('app/Views/Pages/admin/orders/create.php');
eval(substr($view, 5, strpos($view, '// The browser only needs') - 5));
foreach ([0,1,2] as $i) $renderItem($i, ['product_id'=>'1','quantity'=>'10']);
echo '<template id="manual-order-item-template">'; $renderItem(null, $blankItem); echo '</template>';
`,
});
if (render.error || render.status !== 0 || render.stderr.trim())
  throw Error(render.error || render.stderr);
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-pricing-'));
const fileUrl = (p) => 'file:///' + p.replaceAll('\\', '/');
const script = fs.readFileSync(path.join(root, 'public/assets/js/admin/manual-order.js'), 'utf8');
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="${fileUrl(path.join(root, 'public/assets/css/pages/admin.css'))}">
<body class="admin-body"><div class="admin-shell"><aside class="admin-sidebar"></aside><div class="admin-shell__workspace"><main class="admin-main">
<form class="admin-panel admin-conversion-form" data-manual-order-form data-price-preview="fixture">
<fieldset><legend>Products</legend><div data-order-items>${render.stdout}</div><button type="button" data-add-item>Add product</button></fieldset>
<p data-price-error></p><span data-promo-label></span><span data-promo-description></span>
<section class="admin-order-price-summary"><dl class="admin-money-list"><div data-summary-row="total"><dt>Total Customer Pays</dt><dd data-summary-amount="total">327.000 TND</dd></div></dl>
<details class="admin-gross-estimate" open><summary>Admin estimate</summary><dl class="admin-money-list"><div><dt>Estimated gross profit</dt><dd data-admin-estimate="profit">142.700 TND</dd></div></dl></details></section></form>
<script type="application/json" id="manual-order-catalogue">${JSON.stringify({ 1: { name: 'Flower Candle', category_id: '1', category_name: 'Candles', type_id: '1', type_name: 'Flowers', minimum_quantity: 1, standard_price: '15.780', colors: [[31, 'Ivory']], scents: [[41, 'Rose']] } })}</script>
<pre id="result"></pre></main></div></div>
<script>
const check=(ok,message)=>{if(!ok)throw Error(message);};
const cards=()=>[...document.querySelectorAll('[data-order-item]')];
check(cards()[0].querySelector('[data-line-tier-row]').hidden,'Server hides equal applied price');
check(!cards()[1].querySelector('[data-line-tier-row]').hidden,'Server retains adjusted applied price');
check(!cards()[1].querySelector('[data-line-bulk-row]').hidden,'Server retains supplied bulk information');
window.quote=${JSON.stringify(quote)};
window.fetch=async()=>({ok:true,json:async()=>window.failed?{success:false,error:'Quote unavailable'}:{success:true,lines:cards().map(()=>window.quote),amounts:{total:'327.000'},savings:'0.000'}});
</script>
<script>document.addEventListener('DOMContentLoaded',async()=>{try{
const box=el=>el.getBoundingClientRect(), style=el=>getComputedStyle(el);
const row=cards()[0], block=row.querySelector('.admin-price-summary'), quantity=row.querySelector('[data-item-field="quantity"]').closest('.admin-field');
check(document.documentElement.scrollWidth<=innerWidth+1,'Page fits viewport '+innerWidth);
check(innerWidth>1024 ? Math.abs(box(block).top-box(quantity).top)<1 : box(block).top>=box(quantity).bottom,'Pricing belongs beside or below Quantity');
for(const card of cards()){
 const panel=card.querySelector('.admin-price-summary');
 for(const el of panel.querySelectorAll('dt,dd')){
  if(el.closest('[hidden]'))continue;
  const b=box(el),p=box(panel);check(b.left>=p.left&&b.right<=p.right+1,'Price label and amount fit panel');
  check(el.scrollWidth<=el.clientWidth+1,'Price text is not clipped: '+el.textContent);
 }
 const rows=[...panel.querySelectorAll('.admin-money-list > div')].filter(el=>!el.hidden);
 for(let i=1;i<rows.length;i++)check(box(rows[i]).top>=box(rows[i-1]).bottom-1,'Price rows do not overlap');
 check(card.querySelector('[data-line-total]').textContent.endsWith(' TND'),'Currency remains visible');
}
const customer=document.querySelector('[data-summary-amount="total"]'),line=row.querySelector('[data-line-total]'),profit=document.querySelector('[data-admin-estimate]');
check(parseFloat(style(customer).fontSize)>parseFloat(style(line).fontSize),'Customer total is stronger than line total');
check(parseFloat(style(customer).fontSize)>parseFloat(style(profit).fontSize),'Customer total is stronger than admin profit');
const settle=()=>new Promise(resolve=>setTimeout(resolve,400));
const refresh=()=>{const q=row.querySelector('[data-item-field="quantity"]');q.dispatchEvent(new Event('input',{bubbles:true}));};
refresh();await settle();
check(!row.querySelector('[data-line-tier-row]').hidden&&row.querySelector('[data-line-tier]').textContent==='14.500 TND','Preview shows authoritative adjusted unit price');
check(row.querySelector('[data-line-total]').textContent==='145.000 TND','Preview renders authoritative line total');
check(row.querySelector('[data-line-bulk]').textContent==='From 12.000 TND/unit','Preview renders supplied bulk price');
window.quote={base:'15.780',quantity_price:'15.780',bulk_from:null,line:'15.780'};refresh();await settle();
check(row.querySelector('[data-line-tier-row]').hidden&&row.querySelector('[data-line-bulk-row]').hidden,'Preview hides equal applied and absent bulk prices');
window.failed=true;refresh();await settle();check(row.querySelector('[data-line-total]').textContent==='Quote unavailable','Failed quote remains readable');
check(row.querySelector('[data-line-tier-row]').hidden,'Failed quote clears adjustment');
window.failed=false;document.querySelector('[data-add-item]').click();await settle();
check(cards().length===4&&cards()[3].querySelector('.admin-price-summary'),'Added cards retain pricing component');
document.body.dataset.result='passed';document.querySelector('#result').textContent='Pricing geometry and preview states passed at '+innerWidth;
}catch(e){document.body.dataset.result='failed';document.querySelector('#result').textContent=e.stack;}
parent.postMessage({result:document.body.dataset.result,message:document.querySelector('#result').textContent},'*');});</script><script>${script}</script>`;
try {
  const file = path.join(scratch, 'pricing.html');
  fs.writeFileSync(file, html);
  for (const width of [1440, 1024, 768, 390, 320]) {
    const frame = path.join(scratch, 'frame-' + width + '.html');
    fs.writeFileSync(
      frame,
      `<!doctype html><iframe src="${fileUrl(file)}" style="width:${width}px;height:1000px;border:0"></iframe><pre id="result"></pre><script>addEventListener('message',e=>{document.body.dataset.result=e.data.result;document.querySelector('#result').textContent=e.data.message;});</script>`,
    );
    const run = spawnSync(
      browser,
      [
        '--headless',
        '--disable-gpu',
        '--no-first-run',
        '--allow-file-access-from-files',
        '--force-device-scale-factor=1',
        '--window-size=' + Math.max(600, width + 50) + ',1150',
        '--user-data-dir=' + path.join(scratch, 'profile-' + width),
        '--virtual-time-budget=4000',
        '--dump-dom',
        fileUrl(frame),
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
    throw Error('Unexpected test directory');
  fs.rmSync(resolved, { recursive: true, force: true });
}
