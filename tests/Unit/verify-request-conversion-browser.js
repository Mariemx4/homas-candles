'use strict';
// Real PHP form markup and browser FormData/events; fetch uses synthetic quotes.
const fs = require('node:fs'),
  path = require('node:path'),
  os = require('node:os'),
  { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..'),
  scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-conversion-'));
const browser = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const render = spawnSync(process.env.HOMAS_PHP || 'php', [], {
  cwd: root,
  encoding: 'utf8',
  input: `<?php
require 'app/Bootstrap/autoload.php'; define('BASE_URL','http://localhost/');
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$csrf=new class {public function token():string{return 'fixture-token';}};
$requestDetail=['request'=>['id'=>1,'request_number'=>'REQ-FIXTURE','budget'=>null,'custom_products'=>[]]];
$requestResult=['httpStatus'=>200];$conversionFlash=['errors'=>[]];$minimumQuantity=1;
$conversionPricing=['success'=>false];$conversionValues=['product_name'=>'Sample candle','quantity'=>'3','unit_price'=>'33.334','delivery_method'=>'PICKUP','required_date'=>'2026-12-10'];
ob_start();require 'app/Views/Pages/admin/requests/convert.php';$conversion=ob_get_clean();
$creationOptions=['categories'=>[['id'=>1,'name'=>'Candles']],'types'=>[['id'=>1,'name'=>'Flowers']],'components'=>[],'colors'=>[],'scents'=>[]];
$creationValues=['custom_products'=>[['working_name'=>'Crème شمعة','category_id'=>'1','product_type_id'=>'1','requested_quantity'=>'2','components'=>[['source'=>'CUSTOM','custom_name'=>'Flower','quantity'=>'2','specifications'=>'Unicode شمعة']]]]];$creationFlash=['errors'=>[]];
ob_start();require 'app/Views/Components/Admin/custom-request-products.php';$products=ob_get_clean();
ob_start();require 'app/Views/Components/Admin/confirmation-dialog.php';$dialog=ob_get_clean();
echo json_encode(compact('conversion','products','dialog'),JSON_THROW_ON_ERROR);
`,
});
if (render.error || render.status !== 0 || render.stderr.trim())
  throw Error(render.error || render.stderr);
const fixtures = JSON.parse(render.stdout),
  read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const conversion = fixtures.conversion.replace(/<script[^>]*src=[^>]*><\/script>/g, '');
const html = `<!doctype html><meta charset="utf-8"><body>${conversion}
<form id="brief">${fixtures.products}<input type="hidden" name="request_payload_complete" value="1"></form>
<form data-order-status-form id="cancel-order"><input type="hidden" name="csrf_token" value="cancel-token"><select name="new_status"><option value="CONFIRMED">Confirmed</option><option value="CANCELLED">Cancelled</option></select><button type="submit" data-status-save>Save</button></form>
${fixtures.dialog}<pre id="result"></pre>
<script>
let pending=[],hold=false,fail=false;
window.fetch=async(url,options)=>{
 if(hold)return new Promise(resolve=>pending.push(resolve));
 return {ok:true,json:async()=>fail?{success:false,errors:{price:'Check the agreed price.'}}:{success:true,totals:{subtotal:'100.002',delivery_fee:'0.000',total:'100.002',deposit:'40.001'}}};
};
</script><script>${read('public/assets/js/admin/order-details.js')}</script>
<script>${read('public/assets/js/admin/request-conversion.js')}</script>
<script>${read('public/assets/js/admin/custom-request.js')}</script>
<script>${read('public/assets/js/admin/confirmation.js')}</script>
<script>${read('public/assets/js/admin/order-status.js')}</script>
<script>document.addEventListener('DOMContentLoaded',async()=>{try{
const q=s=>document.querySelector(s),check=(ok,msg)=>{if(!ok)throw Error(msg);},pause=ms=>new Promise(r=>setTimeout(r,ms)),change=el=>el.dispatchEvent(new Event('change',{bubbles:true}));
const form=q('[data-conversion-form]'),method=form.elements.delivery_method,fee=form.elements.delivery_fee,address=form.elements.street_address;
await pause(50);
check(address.disabled&&address.closest('.admin-field').hidden&&!address.required,'Pickup excludes and hides the address without an account picker');
method.value='DELIVERY';change(method);check(fee.value==='9.000'&&address.required&&!address.disabled,'Delivery restores minimum fee and requires address');
address.value='Retained address';fee.value='12.000';method.value='PICKUP';change(method);check(fee.value==='0.000'&&address.disabled,'Pickup clears the charge');
method.value='DELIVERY';change(method);check(fee.value==='12.000'&&address.value==='Retained address','Switching method preserves fee and address edits');
await pause(400);check(form.elements.quote_total.value==='100.002'&&!q('[data-conversion-create]').disabled,'A successful server quote enables creation');
hold=true;form.elements.unit_price.value='35.000';form.elements.unit_price.dispatchEvent(new Event('input',{bubbles:true}));
check(form.elements.quote_total.value===''&&q('[data-conversion-create]').disabled,'Editing invalidates the reviewed total immediately');await pause(400);
form.elements.unit_price.value='40.000';form.elements.unit_price.dispatchEvent(new Event('input',{bubbles:true}));
pending.shift()({ok:true,json:async()=>({success:true,totals:{subtotal:'999.000',delivery_fee:'0.000',total:'999.000',deposit:'399.600'}})});
await pause(20);check(form.elements.quote_total.value===''&&!q('[data-conversion-quote]').textContent.includes('999.000'),'Late quotes cannot restore an obsolete total');
await pause(400);pending.shift()({ok:true,json:async()=>({success:true,totals:{subtotal:'120.000',delivery_fee:'12.000',total:'132.000',deposit:'52.800'}})});
await pause(20);check(form.elements.quote_total.value==='132.000','The latest quote becomes the reviewed total');hold=false;fail=true;
form.elements.unit_price.dispatchEvent(new Event('input',{bubbles:true}));await pause(400);check(q('[data-conversion-create]').disabled&&q('[data-conversion-quote]').textContent.includes('Check the agreed price'),'Quote errors keep creation disabled with actionable feedback');
const brief=q('#brief'),data=new FormData(brief),products=JSON.parse(data.get('custom_products_json'));
check(![...data.keys()].some(key=>key.startsWith('custom_products['))&&data.get('request_payload_complete')==='1','Actual browser FormData replaces nested fields and retains the completion marker');
check(products[0].working_name==='Crème شمعة'&&products[0].components[0].custom_name==='Flower'&&products[0].components[0].specifications==='Unicode شمعة','Unicode product/component definitions survive serialization');
brief.querySelector('[data-add-custom]').click();check(JSON.parse(new FormData(brief).get('custom_products_json')).length===2,'Newly added product rows are serialized');
let cancellationPosts=0;const cancellation=q('#cancel-order'),dialog=q('dialog');
cancellation.addEventListener('submit',event=>{event.preventDefault();cancellationPosts++;});
cancellation.elements.new_status.value='CANCELLED';change(cancellation.elements.new_status);cancellation.querySelector('button').click();
check(dialog.open&&cancellationPosts===0&&dialog.textContent.includes('refund review'),'Cancellation waits for an explanation and confirmation');
dialog.querySelector('[data-confirmation-body] button:first-of-type').click();check(cancellationPosts===0&&!dialog.open,'Dismissing cancellation makes no submission');
cancellation.querySelector('button').click();dialog.querySelector('[data-confirmation-body] button:last-of-type').click();check(cancellationPosts===1&&!dialog.open,'Confirmed cancellation submits once');
document.body.dataset.result='passed';q('#result').textContent='Conversion, late previews, fulfilment, payload serialization and cancellation passed.';
}catch(error){document.body.dataset.result='failed';document.querySelector('#result').textContent=error.stack;}});</script>`;
try {
  const file = path.join(scratch, 'conversion.html');
  fs.writeFileSync(file, html);
  const run = spawnSync(
    browser,
    [
      '--headless',
      '--disable-gpu',
      '--no-first-run',
      '--allow-file-access-from-files',
      '--user-data-dir=' + path.join(scratch, 'profile'),
      '--virtual-time-budget=6000',
      '--dump-dom',
      'file:///' + file.replaceAll('\\', '/'),
    ],
    { encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024, windowsHide: true },
  );
  if (run.error) throw run.error;
  const message = run.stdout.match(/<pre id="result">([\s\S]*?)<\/pre>/)?.[1];
  if (!run.stdout.includes('data-result="passed"')) throw Error(message || run.stderr);
  console.log(message);
} finally {
  const resolved = path.resolve(scratch);
  if (!resolved.startsWith(path.resolve(os.tmpdir()) + path.sep))
    throw Error('Unexpected fixture directory');
  fs.rmSync(resolved, { recursive: true, force: true });
}
