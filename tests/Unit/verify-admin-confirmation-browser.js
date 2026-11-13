'use strict';
// Exercise the shared floating confirmations without accounts or application writes.
const fs = require('node:fs'),
  path = require('node:path'),
  os = require('node:os'),
  { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const browser = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const php =
  process.env.HOMAS_PHP || (fs.existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
const render = spawnSync(php, [], {
  cwd: root,
  encoding: 'utf8',
  input: `<?php
define('BASE_URL', 'file:///admin/../');
require 'app/Bootstrap/autoload.php';
$escape=static fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$csrf=new class { public function token():string{return 'fixture-token';} };
ob_start();require 'app/Views/Components/Admin/confirmation-dialog.php';$dialog=ob_get_clean();
$kind='requests';$id=7;$lifecycleFlash=['errors'=>[],'success'=>null];
$lifecycle=['record'=>['request_number'=>'REQ-7'],'active'=>true,'dependencies'=>['conversion_history'=>1],'can_delete'=>false,'can_deactivate'=>true];
ob_start();require 'app/Views/Pages/admin/records/manage.php';$archive=ob_get_clean();
$kind='products';$lifecycle=['record'=>['name'=>'Protected candle'],'active'=>true,'dependencies'=>['order_items'=>2],'can_delete'=>false,'can_deactivate'=>true];
ob_start();require 'app/Views/Pages/admin/records/manage.php';$protected=ob_get_clean();
$lifecycle['dependencies']=[];$lifecycle['can_delete']=true;
ob_start();require 'app/Views/Pages/admin/records/manage.php';$unused=ob_get_clean();
echo json_encode(compact('dialog','archive','protected','unused'));
`,
});
if (render.error || render.status !== 0 || render.stderr.trim())
  throw Error(render.error || render.stderr);
const fixtures = JSON.parse(render.stdout),
  scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-confirmation-'));
const url = (p) => 'file:///' + p.replaceAll('\\', '/');
const script = fs.readFileSync(path.join(root, 'public/assets/js/admin/confirmation.js'), 'utf8');
const order =
  '<div data-admin-confirmation-content><header class="admin-page-header"><h1>Delete order</h1></header><section class="admin-panel"><form method="post" action="file:///admin/orders/delete.php"><p>Move order ORD-7 to Trash? Payments and stock history will be kept.</p><input name="csrf_token" type="hidden" value="fixture-token"><input name="id" type="hidden" value="7"><input name="version" type="hidden" value="fresh-version"><a href="file:///admin/orders/detail.php?id=7">Cancel</a><button type="submit" class="admin-button">Move to Trash</button></form></section></div>';
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${url(path.join(root, 'public/assets/css/pages/admin.css'))}">
<body class="admin-body"><main class="admin-main"><a id="order" href="file:///admin/orders/delete.php?id=7">Delete order</a><a id="archive" href="file:///admin/lifecycle.php?kind=requests&id=7">Archive request</a><a id="protected" href="file:///admin/lifecycle.php?kind=products&id=7">Manage protected record</a><a id="unused" href="file:///admin/lifecycle.php?kind=products&id=8">Delete unused record</a><a id="failure" href="file:///admin/lifecycle.php?kind=products&id=404">Missing record</a>
<form id="inline" method="post"><input name="csrf_token" value="original-token" type="hidden"><input name="action" value="remove_line" type="hidden"><button type="submit" name="line_id" value="42">Remove recipe line</button></form>
<button type="button" data-remove-item>Remove product</button><pre id="result"></pre></main>${fixtures.dialog}
<script>
const fixtures=${JSON.stringify({ ...fixtures, order })};let posts=[],nextResult={success:false,message:'The security token expired.',csrf_token:'fresh-token'};
let unblock=null;
window.fetch=async(href,options={})=>{
 if(options.method==='POST'){posts.push({href,data:options.body});if(unblock!==null)await new Promise(resolve=>{unblock=resolve;});return {ok:true,json:async()=>nextResult};}
 const u=new URL(href);if(u.searchParams.get('id')==='404')return {ok:false,text:async()=>''};
 const key=u.pathname.endsWith('delete.php')?'order':u.searchParams.get('kind')==='requests'?'archive':u.searchParams.get('id')==='8'?'unused':'protected';
 return {ok:true,text:async()=>fixtures[key]};
};
</script><script>${script}</script><script>
(async()=>{try{
const check=(ok,msg)=>{if(!ok)throw Error(msg);},q=s=>document.querySelector(s),pause=()=>new Promise(r=>setTimeout(r,50));
const dialog=q('dialog'),body=q('[data-confirmation-body]'),original=location.href;
q('#order').click();await pause();
check(dialog.open&&dialog.matches(':modal'),'Order confirmation opens as a modal');
check(location.href===original&&posts.length===0,'Opening does not navigate or mutate');
check(body.querySelector('[name=version]').value==='fresh-version','Fresh order version remains in confirmation');
const rect=dialog.getBoundingClientRect();check(rect.left>=0&&rect.right<=innerWidth+1&&rect.height<=innerHeight,'Floating window fits viewport '+innerWidth);
check(getComputedStyle(dialog).position==='absolute'||getComputedStyle(dialog).position==='fixed','Dialog is outside page flow');
body.querySelector('a').click();await pause();check(!dialog.open&&document.activeElement===q('#order'),'Cancel closes and restores focus');
q('#order').click();await pause();body.querySelector('button[type=submit]').click();await pause();
check(posts.length===1&&posts[0].data.get('version')==='fresh-version'&&posts[0].data.get('id')==='7','Order deletion preserves the version and record ID');
check(dialog.open&&body.textContent.includes('security token expired'),'Rejected order deletion stays in the dialog');
q('[data-confirmation-close]').click();await pause();posts=[];
q('#protected').click();await pause();check(body.textContent.includes('2 Order Items')&&!body.querySelector('button[value=delete]'),'Dependency checks block permanent deletion');
q('[data-confirmation-close]').click();await pause();
q('#unused').click();await pause();check(body.querySelector('button[value=delete]'),'Unused records retain permanent-delete action');
q('[data-confirmation-close]').click();await pause();
q('#archive').click();await pause();check(body.textContent.includes('references must remain intact')&&body.querySelector('button[value=archive]')&&!body.querySelector('button[value=delete]'),'Archive retains dependency explanation and cannot permanently delete requests');
body.querySelector('button[value=archive]').click();await pause();
check(posts.length===1&&posts[0].data.get('action')==='archive'&&posts[0].data.get('csrf_token')==='fixture-token','Archive sends original identity, action and CSRF: '+JSON.stringify(posts.map(p=>[...p.data]))+' '+body.textContent);
check(dialog.open&&body.textContent.includes('security token expired'),'Rejected action stays in the dialog');
check(body.querySelector('[name=csrf_token]').value==='fresh-token'&&!body.querySelector('button[value=archive]').disabled,'Rejected request receives a usable fresh token');
q('[data-confirmation-close]').click();await pause();
q('#failure').click();await pause();check(dialog.open&&body.textContent.includes('could not be loaded')&&posts.length===1,'Loading error never mutates or redirects');
q('[data-confirmation-close]').click();await pause();
let inlinePosts=0;
q('#inline').addEventListener('submit',event=>{event.preventDefault();inlinePosts++;check(event.submitter.value==='42','Original submitter value is preserved');});
q('#inline button').click();check(dialog.open&&inlinePosts===0,'Inline removal waits for confirmation');
body.querySelector('button').click();await pause();check(inlinePosts===0&&!dialog.open,'Cancelling inline removal does nothing');
q('#inline button').click();body.querySelector('button:last-child').click();await pause();check(inlinePosts===1&&!dialog.open,'Confirmed inline removal submits exactly once');
let removals=0;q('[data-remove-item]').addEventListener('click',()=>removals++);
q('[data-remove-item]').click();check(removals===0&&dialog.open,'Unsaved product removal also waits');
body.querySelector('button:last-child').click();await pause();check(removals===1&&!dialog.open,'Unsaved removal replays once');
q('#archive').click();await pause();unblock=()=>{};nextResult={success:true,message:'Request archived. History kept.',csrf_token:'rotated-token',removed:false,record_id:7};
body.querySelector('button[value=archive]').click();await pause();q('[data-confirmation-close]').click();check(dialog.open&&dialog.getAttribute('aria-busy')==='true','Cannot dismiss or double-submit during write');
body.querySelector('button[value=archive]').click();check(posts.length===2,'Disabled confirmation prevents double submit');
unblock();await pause();check(dialog.open&&body.textContent.includes('Request archived.')&&!body.querySelector('form'),'Success is shown inside the same floating window');
check(location.href===original,'Confirmation workflow stays on the original page');
document.body.dataset.result='passed';q('#result').textContent='Floating confirmations passed at '+innerWidth;
}catch(e){document.body.dataset.result='failed';document.querySelector('#result').textContent=e.stack;}
parent.postMessage({result:document.body.dataset.result,message:document.querySelector('#result').textContent},'*');})();
</script>`;
try {
  const file = path.join(scratch, 'confirmation.html');
  fs.writeFileSync(file, html);
  for (const width of [1440, 768, 390, 320]) {
    const frame = path.join(scratch, 'frame-' + width + '.html');
    fs.writeFileSync(
      frame,
      `<!doctype html><iframe src="${url(file)}" style="width:${width}px;height:900px;border:0"></iframe><pre id="result"></pre><script>addEventListener('message',e=>{document.body.dataset.result=e.data.result;document.querySelector('#result').textContent=e.data.message;});</script>`,
    );
    const run = spawnSync(
      browser,
      [
        '--headless',
        '--disable-gpu',
        '--no-first-run',
        '--allow-file-access-from-files',
        '--force-device-scale-factor=1',
        '--window-size=' + Math.max(600, width + 50) + ',1050',
        '--user-data-dir=' + path.join(scratch, 'profile-' + width),
        '--virtual-time-budget=4000',
        '--dump-dom',
        url(frame),
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
