'use strict';
// A separate preview container and synthetic login are required. No shop account
// is read or modified. Native Chromium exercises the actual authenticated app.
const fs = require('node:fs'),
  path = require('node:path'),
  os = require('node:os'),
  { spawn } = require('node:child_process');
const statePath = process.env.HOMAS_PREVIEW_STATE;
if (!statePath) throw Error('Set HOMAS_PREVIEW_STATE to the isolated preview state file.');
const fixture = JSON.parse(fs.readFileSync(statePath, 'utf8'));
if (!/^homas_auth_check_[a-f0-9]{16}$/.test(fixture.schema))
  throw Error('A disposable preview schema is required.');
const base = 'http://127.0.0.1:8787';
const output =
  process.env.HOMAS_RENDER_DIR ||
  path.join(os.tmpdir(), 'homas-admin-redesign-20261109', 'renders');
fs.mkdirSync(output, { recursive: true });
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-studio-browser-'));
const browser = spawn(
  process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  [
    '--headless',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--remote-debugging-port=0',
    '--user-data-dir=' + profile,
    'about:blank',
  ],
  { windowsHide: true, stdio: 'ignore' },
);
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let socket,
  sequence = 0,
  dialogs = 0,
  checks = 0;
const pending = new Map(),
  errors = [];
const send = (method, params = {}) =>
  new Promise((resolve, reject) => {
    const id = ++sequence;
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(Error('CDP timeout: ' + method));
    }, 12000);
    pending.set(id, {
      resolve: (result) => {
        clearTimeout(timer);
        resolve(result);
      },
      reject,
    });
    socket.send(JSON.stringify({ id, method, params }));
  });
const evaluate = async (source) => {
  const result = await send('Runtime.evaluate', {
    expression: '(async()=>{' + source + '})()',
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  });
  if (result.exceptionDetails)
    throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
  return result.result.value;
};
const check = (ok, message) => {
  if (!ok) throw Error(message);
  checks++;
  console.log('[PASS] ' + message);
};
const navigate = async (route) => {
  const url = new URL(base + '/' + route);
  await send('Page.navigate', { url: url.href });
  for (let i = 0; i < 100; i++) {
    await pause(50);
    if (
      await evaluate(
        'return document.readyState==="complete" && location.pathname+location.search===' +
          JSON.stringify(url.pathname + url.search),
      )
    )
      break;
  }
  await pause(180);
  const info = await evaluate(
    'return {title:document.title,text:document.body.innerText,url:location.href}',
  );
  if (
    /Service unavailable|Unable to show|incident reference|temporarily unavailable/i.test(
      info.title,
    )
  )
    throw Error('Page unavailable: ' + route);
  return info;
};
const capture = async (name) => {
  const image = await send('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: false,
  });
  fs.writeFileSync(path.join(output, name + '.png'), Buffer.from(image.data, 'base64'));
};
const viewport = async (width, height = 900, mobile = width <= 768) => {
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
  await send('Emulation.setTouchEmulationEnabled', { enabled: mobile });
};
// Hit-test and dispatch actual touch events rather than calling element.click().
const tap = async (selector) => {
  const point = await evaluate(
    'const el=document.querySelector(' +
      JSON.stringify(selector) +
      ');if(!el)throw Error("Missing tap target");el.scrollIntoView({block:"center"});const r=el.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2;if(!el.contains(document.elementFromPoint(x,y)))throw Error("Covered tap target: "+' +
      JSON.stringify(selector) +
      ');return {x,y};',
  );
  await send('Input.dispatchTouchEvent', {
    type: 'touchStart',
    touchPoints: [{ ...point, radiusX: 1, radiusY: 1 }],
  });
  await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await pause(150);
};
(async () => {
  try {
    let port;
    for (let i = 0; i < 150; i++) {
      await pause(50);
      const file = path.join(profile, 'DevToolsActivePort');
      if (fs.existsSync(file)) {
        port = Number(fs.readFileSync(file, 'utf8').split('\n')[0]);
        break;
      }
    }
    if (!port) throw Error('Chromium did not start.');
    const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
    socket = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      socket.addEventListener('open', resolve, { once: true });
      socket.addEventListener('error', reject, { once: true });
    });
    socket.addEventListener('message', (event) => {
      const data = JSON.parse(event.data);
      if (data.id) {
        const request = pending.get(data.id);
        if (request) {
          pending.delete(data.id);
          data.error ? request.reject(Error(data.error.message)) : request.resolve(data.result);
        }
      } else if (data.method === 'Runtime.exceptionThrown')
        errors.push(
          data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text,
        );
      else if (data.method === 'Page.javascriptDialogOpening') {
        dialogs++;
        send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
      }
    });
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Network.setBlockedURLs', { urls: ['https://cdn.jsdelivr.net/*'] });
    await send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await send('Emulation.setDeviceMetricsOverride', {
      width: 1440,
      height: 1050,
      deviceScaleFactor: 1,
      mobile: false,
    });
    await navigate('admin/login.php');
    await evaluate(
      `document.querySelector('[name="login_identifier"]').value=${JSON.stringify(fixture.email)};document.querySelector('[name="password"]').value=${JSON.stringify(fixture.password)};document.querySelector('form').requestSubmit();`,
    );
    for (let i = 0; i < 100; i++) {
      await pause(50);
      if (
        await evaluate(
          'return location.pathname==="/admin/index.php" && document.readyState==="complete"',
        )
      )
        break;
    }
    check(
      await evaluate(
        'return location.pathname==="/admin/index.php" && !!document.querySelector(".admin-hero")',
      ),
      'Synthetic administrator signs into the actual Today workspace.',
    );
    check(
      await evaluate(
        'return !document.querySelector(".admin-alert--error") && document.querySelector(".admin-today-columns")!==null',
      ),
      'Today loads live synthetic data rather than an unavailable fallback.',
    );
    check(
      await evaluate(
        'return document.querySelector(".admin-hero").textContent.includes("Suggested next step") && document.querySelector(".admin-hero a").href.includes("orders/detail.php?id=")',
      ),
      'Today proposes a real next task from the deadline queue.',
    );
    check(
      await evaluate(
        'return document.querySelectorAll(".admin-nav-group").length===4 && document.querySelectorAll(".admin-nav__icon svg").length===11',
      ),
      'Grouped navigation uses local icons for all destinations.',
    );
    check(
      await evaluate(
        'return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1',
      ),
      'Today has no page overflow at desktop width.',
    );
    await capture('today-desktop');
    check(
      await evaluate(
        'return getComputedStyle(document.body).backgroundColor==="rgb(248, 236, 223)" && getComputedStyle(document.querySelector(".admin-sidebar")).backgroundColor==="rgb(57, 28, 11)" && getComputedStyle(document.querySelector(".admin-hero .admin-button")).backgroundColor==="rgb(109, 59, 7)"',
      ),
      'Today uses the original Hom’As cream, chocolate and brown brand tokens.',
    );
    await navigate('admin/orders/detail.php?id=' + fixture.orders[0]);
    check(
      await evaluate(
        'return document.querySelectorAll("[role=tab]").length===5 && document.body.innerText.includes("Start preparation")',
      ),
      'Order workspace exposes five focused sections and the current action.',
    );
    await capture('order-overview');
    await evaluate('document.querySelector("#order-payments-tab").click()');
    check(
      await evaluate(
        'return !document.querySelector("#order-payments").hidden && !!document.querySelector("#order-payments [data-payment-record-form]")',
      ),
      'Payment recording is available inside the order workspace.',
    );
    await capture('order-payments');
    if (process.env.HOMAS_CAPTURE_ONLY !== '1') {
      await evaluate(
        'const form=document.querySelector("[data-payment-record-form]");form.elements.amount.value="1.2345";form.elements.amount.dispatchEvent(new Event("input",{bubbles:true}));form.elements.received_confirmation.checked=true;form.requestSubmit();',
      );
      for (let i = 0; i < 100; i++) {
        await pause(50);
        if (
          await evaluate(
            'return document.readyState==="complete" && !!document.querySelector(".admin-error-summary")',
          )
        )
          break;
      }
      check(
        await evaluate(
          'const field=document.querySelector("#payment-amount");return field.value==="1.2345" && field.getAttribute("aria-invalid")==="true" && !!field.getAttribute("aria-describedby") && [...document.querySelectorAll(".admin-error-summary a")].some(link=>link.hash==="#payment-amount")',
        ),
        'A rejected payment retains its amount and links its error summary to the exact field.',
      );
    }
    await navigate('admin/production/queue.php');
    check(
      await evaluate(
        'return /readiness/i.test(document.body.innerText) && document.body.innerText.includes("Soy wax") && document.body.innerText.includes("Resolve blockers")',
      ),
      'Production exposes recipe/stock readiness and a resolution path.',
    );
    await capture('production-desktop');
    await navigate('admin/requests/detail.php?id=' + fixture.request);
    check(
      await evaluate(
        'return document.querySelectorAll("[role=tab]").length>=3 && document.body.innerText.includes("Confirm what you have agreed")',
      ),
      'Custom request has connected sections and an agreement task.',
    );
    await capture('request-desktop');
    await navigate('admin/catalogue/products.php?id=new');
    check(
      await evaluate(
        'return !!document.querySelector("[name=name]") && document.body.innerText.includes("Save draft")',
      ),
      'New product starts with a named private draft.',
    );
    await capture('new-product');
    if (process.env.HOMAS_CAPTURE_ONLY !== '1') {
      await evaluate(
        'document.querySelector("[name=name]").value="Browser peony draft";document.querySelector("[name=category_id]").value="1";document.querySelector("[name=category_id]").dispatchEvent(new Event("change",{bubbles:true}));document.querySelector("[name=product_type_id]").value="1";document.querySelector("form[data-product-core]").requestSubmit();',
      );
      for (let i = 0; i < 100; i++) {
        await pause(50);
        if (
          await evaluate(
            'return location.hash==="#product-media" && !!document.querySelector("#product-media-tab")',
          )
        )
          break;
      }
      check(
        await evaluate(
          'return location.hash==="#product-media" && document.querySelectorAll("[role=tab]").length===6 && document.body.innerText.includes("Browser peony draft")',
        ),
        'Saving a named draft opens its photo step with all product sections available.',
      );
      await capture('product-workspace');
      await evaluate('document.querySelector("#product-basics-tab").click()');
      check(
        await evaluate(
          'return !document.querySelector("#product-basics").hidden && document.querySelector("#product-basics [name=name]").value==="Browser peony draft"',
        ),
        'Basics exposes the saved product name before the description is complete.',
      );
      await capture('product-basics');
      check(
        await evaluate(
          'return document.querySelector("#product-slug").closest("details")!==null && document.querySelector("#product-purchase-mode").closest("details")!==null',
        ),
        'Product Basics keeps website-address and selling-rule controls in named secondary sections.',
      );
      await evaluate('document.querySelector("#product-pricing-tab").click()');
      check(
        await evaluate(
          'return !document.querySelector("#product-pricing").hidden && !!document.querySelector("#product-pricing .admin-price-field")',
        ),
        'The calculated selling price lives beside the recipe and costing work.',
      );
      await evaluate('document.querySelector(".admin-workspace-tabs button:last-child").click()');
      check(
        await evaluate(
          'return document.body.innerText.includes("A main image is uploaded") && !!document.querySelector("[data-workspace-panel=Publish] form")',
        ),
        'Publish presents the actual readiness requirements and publication control.',
      );
      await capture('product-publish');
    }
    await viewport(390);
    await navigate('admin/orders/create.php');
    check(
      await evaluate(
        'return document.querySelectorAll(".admin-stepper button").length===4 && !!document.querySelector(".admin-step-actions [value=save_draft]")',
      ),
      'Order entry has four steps and draft saving at every step.',
    );
    await capture('new-order');
    check(
      await evaluate(
        'const input=document.querySelector("[name=guest_customer_name]");return input.getBoundingClientRect().width>=240 && parseFloat(getComputedStyle(input).fontSize)>=16 && [...document.querySelectorAll(".admin-stepper button")].every(b=>b.scrollWidth<=b.clientWidth+1 && b.getBoundingClientRect().height>=44)',
      ),
      'Phone order entry has wide 16px inputs and fully visible touch-sized steps.',
    );
    if (process.env.HOMAS_CAPTURE_ONLY !== '1') {
      await evaluate(
        'const field=document.querySelector("[name=guest_customer_name]");field.value="Browser saved customer";field.dispatchEvent(new Event("input",{bubbles:true}));',
      );
      await tap('.admin-step-actions [value=save_draft]');
      for (let i = 0; i < 100; i++) {
        await pause(50);
        if (
          await evaluate('return location.search==="?resume=1" && document.readyState==="complete"')
        )
          break;
      }
      check(
        await evaluate(
          'return document.querySelector("[name=guest_customer_name]").value==="Browser saved customer" && document.body.innerText.includes("Your saved order draft")',
        ),
        'An incomplete guided order saves and resumes through the real browser form.',
      );
      await evaluate(
        'const phone=document.querySelector("[name=guest_customer_phone]");phone.value="98110045";phone.dispatchEvent(new Event("input",{bubbles:true}));',
      );
      await tap('.admin-step-next');
      check(
        await evaluate('return !document.querySelector("[data-form-step=Products]").hidden'),
        'Continue advances a valid customer step.',
      );
      await evaluate(
        'document.querySelector("[data-product-select]").parentElement.querySelector(".custom-select__trigger").id="phone-product-picker";',
      );
      await tap('#phone-product-picker');
      check(
        await evaluate(
          'const menu=document.querySelector("#phone-product-picker").parentElement.querySelector(".custom-select__menu");const r=menu.getBoundingClientRect();return !menu.hidden && r.left>=0 && r.right<=innerWidth && r.top>=0 && r.bottom<=innerHeight',
        ),
        'Phone product picker is visible and contained after a real touch.',
      );
      await evaluate(
        'document.querySelector("#phone-product-picker").parentElement.querySelector("[data-value=\\"1\\"]").id="phone-product-choice";',
      );
      await tap('#phone-product-choice');
      check(
        await evaluate('return document.querySelector("[data-product-select]").value==="1"'),
        'A real touch selects the product in the phone form.',
      );
      await evaluate(
        'for(const option of document.querySelectorAll("[data-option-select]")){option.value="1";option.dispatchEvent(new Event("change",{bubbles:true}));}',
      );
      await tap('.admin-step-next');
      check(
        await evaluate(
          'return [...document.querySelectorAll("[data-form-step]")].findIndex(step=>!step.hidden)===2',
        ),
        'Configured product options survive the guided product step.',
      );
      await evaluate(
        'const date=document.querySelector("[name=required_date]");date.value="2027-01-15";date.dispatchEvent(new Event("change",{bubbles:true}));',
      );
      await tap('.admin-step-next');
      await pause(550);
      check(
        await evaluate(
          'return [...document.querySelectorAll("[data-form-step]")].findIndex(step=>!step.hidden)===3 && document.querySelector("[data-summary-amount=total]").textContent.includes("TND") && !document.querySelector("[data-price-error]").textContent.trim() && /Browser saved customer/.test(document.querySelector(".admin-review-summary").textContent) && /Peony candle/.test(document.querySelector(".admin-review-summary").textContent)',
        ),
        'Review shows the customer, product, fulfilment and server-confirmed total.',
      );
      await capture('order-review');
      await tap('.admin-conversion-form__actions button');
      for (let i = 0; i < 100; i++) {
        await pause(50);
        if (
          await evaluate(
            'return location.pathname==="/admin/orders/detail.php" && document.readyState==="complete"',
          )
        )
          break;
      }
      check(
        await evaluate(
          'return location.pathname==="/admin/orders/detail.php" && document.body.innerText.includes("Browser saved customer") && document.querySelectorAll("[role=tab]").length===5',
        ),
        'The guided form creates the order and opens its connected workspace.',
      );
    }
    await viewport(1440, 1050);
    await navigate('admin/requests/create.php');
    check(
      await evaluate('return document.querySelectorAll(".admin-stepper button").length===3'),
      'Request entry has three focused steps.',
    );
    if (fixture.customer) {
      await navigate('admin/accounts.php?kind=customers&id=' + fixture.customer);
      check(
        await evaluate(
          'return !!document.querySelector("#account-history") && document.querySelector("#account-history").textContent.includes("Created")',
        ),
        'The customer profile exposes its durable creation and change history.',
      );
      if (process.env.HOMAS_CAPTURE_ONLY !== '1') {
        await evaluate(
          'const form=document.querySelector("form:has([name=profile_version])");form.elements.first_name.value="Leila Updated "+Date.now();form.elements.first_name.dispatchEvent(new Event("input",{bubbles:true}));form.requestSubmit();',
        );
        for (let i = 0; i < 100; i++) {
          await pause(50);
          if (
            await evaluate(
              'return location.pathname==="/admin/customers/detail.php" && document.readyState==="complete"',
            )
          )
            break;
        }
        check(
          await evaluate(
            'return location.pathname==="/admin/customers/detail.php" && document.querySelector("h1").textContent.includes("Leila Updated")',
          ),
          'Saving customer details returns directly to the updated customer.',
        );
        await navigate('admin/accounts.php?kind=customers&id=' + fixture.customer);
        check(
          await evaluate(
            'return document.querySelector("#account-history").textContent.includes("Updated") && document.querySelector("#account-history").textContent.includes("Audit Administrator") && !document.querySelector("#account-history").textContent.includes("password_hash")',
          ),
          'The saved edit is visible with its administrator actor and without credentials.',
        );
        await evaluate(
          'const group=document.querySelector("[data-optional-address]");group.open=true;const values={address_label:"Studio",street_address:"34 Studio street",delegation:"Tunis",governorate:"Tunis",postal_code:"1000"};for(const field of group.querySelectorAll("input")){const key=field.name.slice(field.name.lastIndexOf("[")+1,-1);field.value=values[key]||"";field.dispatchEvent(new Event("input",{bubbles:true}));}const form=group.closest("form");form.elements.second_email.value="leila-secondary@example.test";form.elements.second_email.dispatchEvent(new Event("input",{bubbles:true}));form.requestSubmit();',
        );
        for (let i = 0; i < 100; i++) {
          await pause(50);
          if (
            await evaluate(
              'return location.pathname==="/admin/customers/detail.php" && document.readyState==="complete"',
            )
          )
            break;
        }
        check(
          await evaluate(
            'return location.pathname==="/admin/customers/detail.php" && document.body.innerText.includes("34 Studio street")',
          ),
          'An additional address saves through the customer workflow and appears on that customer.',
        );
        await navigate('admin/accounts.php?kind=customers&id=' + fixture.customer);
        check(
          await evaluate(
            'return document.querySelectorAll("[data-account-address]").length>=2 && document.querySelector("[name=second_email]").value==="leila-secondary@example.test"',
          ),
          'Customer editing retains secondary email and presents addresses in separate named sections.',
        );
      }
      await capture('account-history');
    }
    await navigate('admin/settings/index.php');
    check(
      await evaluate(
        'return document.body.innerText.includes("Restore verified") || document.body.innerText.includes("Last restore check") || document.body.innerText.includes("No successful restore check")',
      ),
      'Settings explicitly distinguishes a verified restore from an unverified backup.',
    );
    await navigate('admin/orders/index.php');
    check(
      await evaluate(
        'const input=document.querySelector("input[type=search]");return input.getBoundingClientRect().width>0 && input.form!==null && document.querySelector(".admin-filter-panel").dataset.collapsed==="true"',
      ),
      'Keyword search stays visible while additional filters are collapsed.',
    );
    await evaluate(
      'const search=document.querySelector("input[type=search]");search.value="Browser saved customer";document.querySelector(".admin-quick-search button").click();',
    );
    await pause(300);
    check(
      await evaluate(
        'return new URLSearchParams(location.search).get("q")==="Browser saved customer" && !!document.querySelector(".admin-table")',
      ),
      'Quick search submits through its original filter form.',
    );
    check(
      await evaluate(
        'return [...document.querySelectorAll(".admin-filter-summary a")].some(a=>a.textContent==="Clear filters" && new URL(a.href).search==="")',
      ),
      'Applied filters expose a direct reset beside the search.',
    );
    await evaluate('document.querySelector(".admin-filter-summary a").click()');
    await pause(300);
    check(
      await evaluate(
        'return location.search==="" && document.querySelector("input[type=search]").value===""',
      ),
      'Clearing filters restores the full list without a stale search.',
    );

    // Exercise the shared actions in every directory, including all taxonomy routes.
    const directories = [
      'admin/catalogue/products.php',
      'admin/inventory/materials.php',
      'admin/catalogue/categories.php',
      'admin/catalogue/product-types.php',
      'admin/catalogue/colors.php',
      'admin/catalogue/scents.php',
      'admin/customers/index.php?q=Leila',
      'admin/accounts.php',
    ];
    for (const route of directories) {
      await navigate(route);
      check(
        await evaluate(
          'const controls=[...document.querySelectorAll(".admin-record-actions a")];return controls.length>=2 && controls.every(a=>a.querySelector("svg") && a.getAttribute("aria-label").length>8 && a.title===a.getAttribute("aria-label") && a.getBoundingClientRect().width>=44 && a.getBoundingClientRect().height>=44)',
        ),
        'Named 44px edit/status icon controls render on ' + route,
      );
    }
    await navigate('admin/catalogue/products.php?active=0');
    await evaluate(
      'const action=document.querySelector(".admin-record-actions a");action.focus();',
    );
    check(
      await evaluate(
        'return getComputedStyle(document.activeElement.querySelector(".admin-action-tooltip")).visibility==="visible"',
      ),
      'Keyboard focus reveals the icon action’s full record label.',
    );
    await capture('record-actions');
    await evaluate(
      'window.managementOpener=document.querySelector(".admin-record-actions a[href*=lifecycle]");window.managementOpener.click();',
    );
    for (let i = 0; i < 100; i++) {
      await pause(50);
      if (
        await evaluate(
          'return !!document.querySelector("dialog[open] [data-admin-confirmation-content],dialog[open] .admin-lifecycle-panel")',
        )
      )
        break;
    }
    check(
      await evaluate(
        'return !!document.querySelector("dialog[open] .admin-lifecycle-panel") && location.search==="?active=0"',
      ),
      'Status icon opens record management without losing list filters.',
    );
    await evaluate(
      'const link=[...document.querySelectorAll("dialog[open] a")].find(a=>a.textContent.trim()==="Cancel");link.click();',
    );
    check(
      await evaluate(
        'return !document.querySelector("dialog[open]") && document.activeElement===window.managementOpener && location.search==="?active=0"',
      ),
      'Cancelling record management restores focus and the filtered list.',
    );
    await navigate('admin/lifecycle.php?kind=products&id=1');
    check(
      await evaluate(
        'const link=document.querySelector(".admin-page-header a");return new URL(link.href).pathname==="/admin/catalogue/products.php" && new URL(link.href).searchParams.get("id")==="1"',
      ),
      'The standalone management screen returns to the exact product.',
    );
    await navigate('admin/requests/detail.php?id=' + fixture.request);
    await evaluate('document.querySelector("#request-conversation-tab").click()');
    check(
      await evaluate(
        'const panel=document.querySelector("#request-conversation");return !panel.hidden && panel.textContent.includes("Change status") && panel.textContent.includes("Log contact")',
      ),
      'Request discussion and status controls share the declared Conversation section.',
    );
    check(
      await evaluate(
        'return location.hash==="#request-conversation" && [...document.querySelectorAll("[role=tab]")].every(tab=>tab.querySelector("svg"))',
      ),
      'Record sections have local icons and a linkable selected state.',
    );
    await navigate('admin/requests/detail.php?id=' + fixture.request + '#request-conversation');
    check(
      await evaluate(
        'return document.querySelector("#request-conversation-tab").getAttribute("aria-selected")==="true"',
      ),
      'A copied section link reopens the same request workspace.',
    );
    await navigate('admin/catalogue/products.php?id=1#%invalid');
    check(
      await evaluate('return document.querySelectorAll("[role=tab]").length===6'),
      'Malformed copied fragments leave product editing usable.',
    );
    if (fixture.customer) {
      await navigate('admin/customers/detail.php?id=' + fixture.customer);
      check(
        await evaluate(
          'return !!document.querySelector(".admin-customer-requests-table .admin-icon-action[href*=\\"requests/detail.php\\"]")',
        ),
        'Customer request history opens the request through its View icon.',
      );
    }

    await navigate('admin/catalogue/promotions.php');
    await evaluate(
      'const f=document.querySelector("#promotion-editor");f.elements.code.value="STUDIO-UX-CHECK";f.elements.discount_value.value="10";f.requestSubmit();',
    );
    await pause(400);
    await evaluate(
      'const row=[...document.querySelectorAll("tbody tr")].find(r=>r.textContent.includes("STUDIO-UX-CHECK"));row.querySelector(".admin-icon-action").click();',
    );
    await pause(300);
    check(
      await evaluate(
        'const f=document.querySelector("#promotion-editor");return f.querySelector("h2").textContent==="Edit promotion STUDIO-UX-CHECK" && f.elements.code.readOnly && f.elements.edit.value && location.hash==="#promotion-editor"',
      ),
      'Promotion Edit identifies the existing code and opens its distinct edit form.',
    );
    await evaluate(
      'const f=document.querySelector("#promotion-editor");f.elements.discount_value.value="20";f.requestSubmit();',
    );
    await pause(400);
    check(
      await evaluate(
        'return new URLSearchParams(location.search).has("edit") && document.querySelector("#promotion-editor").elements.discount_value.value.startsWith("20") && [...document.querySelectorAll("tbody tr")].filter(r=>r.textContent.includes("STUDIO-UX-CHECK")).length===1',
      ),
      'Saving a promotion retains edit context and updates one existing code.',
    );
    await capture('promotion-edit');
    await navigate('admin/catalogue/products.php?id=1');
    await evaluate(
      'document.querySelector("#product-basics-tab").click();document.querySelector("#product-basics-tab").focus();document.querySelector("#product-basics-tab").dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowRight",bubbles:true}));',
    );
    check(
      await evaluate(
        'return document.activeElement.id==="product-media-tab" && !document.querySelector("#product-media").hidden',
      ),
      'Record tabs support arrow-key navigation and move focus with selection.',
    );
    await navigate('admin/inventory/materials.php?id=1&return_order=' + fixture.orders[0]);
    await evaluate(
      'const form=document.querySelector("[data-stock-movement]"),type=form.elements.movement_type,quantity=form.elements.quantity_change;type.value="ADJUSTMENT_OUT";type.dispatchEvent(new Event("change",{bubbles:true}));quantity.value="1.250";quantity.dispatchEvent(new Event("input",{bubbles:true}));',
    );
    check(
      await evaluate(
        'const form=document.querySelector("[data-stock-movement]");return form.elements.movement_note.required && form.querySelector("[data-stock-preview]").textContent.includes("98.750") && form.elements.return_order.value==="' +
          fixture.orders[0] +
          '"',
      ),
      'Stock correction previews the exact new quantity, requires its reason and preserves the production return.',
    );
    await capture('stock-preview');
    const previousDialogs = dialogs;
    await navigate('admin/index.php');
    check(
      dialogs > previousDialogs,
      'Leaving edited stock work presents the browser’s unsaved-change warning.',
    );
    const responsiveRoutes = [
      'admin/orders/detail.php?id=' + fixture.orders[0],
      'admin/orders/create.php',
      'admin/requests/detail.php?id=' + fixture.request,
      'admin/requests/create.php',
      'admin/catalogue/products.php?id=1',
      'admin/catalogue/products.php',
      'admin/catalogue/categories.php',
      'admin/catalogue/product-types.php',
      'admin/catalogue/scents.php',
      'admin/catalogue/colors.php',
      'admin/catalogue/promotions.php',
      'admin/lifecycle.php?kind=products&id=1',
      'admin/inventory/materials.php?id=1',
      'admin/inventory/materials.php',
      'admin/production/queue.php',
      'admin/production/calendar.php',
      'admin/payments/index.php',
      'admin/customers/index.php?q=Leila',
      'admin/accounts.php',
      ...(fixture.customer ? ['admin/accounts.php?kind=customers&id=' + fixture.customer] : []),
      'admin/reports/index.php',
      'admin/settings/index.php',
    ];
    for (const width of [1024, 768, 430, 390, 320]) {
      await viewport(width, 1000);
      for (const route of responsiveRoutes) {
        await navigate(route);
        const overflow = await evaluate(
          'const excess=()=>document.documentElement.scrollWidth-document.documentElement.clientWidth;let max=excess();for(const button of document.querySelectorAll("[role=tab],.admin-stepper button")){button.click();max=Math.max(max,excess());}return {maxOverflow:max,width:document.documentElement.scrollWidth,viewport:document.documentElement.clientWidth,offenders:[...document.querySelectorAll(".admin-main *")].filter(el=>el.getBoundingClientRect().right>document.documentElement.clientWidth+2&&getComputedStyle(el).position!=="absolute"&&!el.closest(".admin-table-scroll,.admin-calendar-scroll,.admin-workspace-tabs,.admin-stepper")).slice(0,5).map(el=>el.tagName+"."+el.className)}',
        );
        check(
          overflow.maxOverflow <= 1,
          route + ' fits width ' + width + ' ' + JSON.stringify(overflow),
        );
      }
      if (width <= 640) {
        await navigate('admin/orders/index.php');
        check(
          await evaluate(
            'const t=document.querySelector(".admin-table--cards"),r=t?.tBodies[0]?.rows[0];return !!r && getComputedStyle(r).display==="grid" && t.scrollWidth<=t.clientWidth+1 && [...r.cells].every(c=>c.querySelector(".admin-card-label") && getComputedStyle(c).display!=="none")',
          ),
          'Orders expose every labeled card field without sideways scrolling at ' + width + '.',
        );
        await capture('orders-' + width);
        if (width === 390) {
          const tree = await send('Accessibility.getFullAXTree');
          check(
            tree.nodes.some((n) => !n.ignored && n.role?.value === 'table') &&
              tree.nodes.some(
                (n) =>
                  !n.ignored &&
                  n.role?.value === 'columnheader' &&
                  n.name?.value?.toLowerCase() === 'order',
              ) &&
              tree.nodes.some((n) => !n.ignored && n.role?.value === 'cell'),
            'Phone cards preserve table headers and cells in the browser accessibility tree.',
          );
        }
        await tap('.admin-table--cards tbody tr:first-child a[href*="orders/detail.php"]');
        check(
          await evaluate(
            'return location.pathname==="/admin/orders/detail.php" && !!document.querySelector("[data-workspace]")',
          ),
          'A touch opens the phone order card at ' + width + '.',
        );
        check(
          await evaluate(
            'return [...document.querySelectorAll("[role=tab]")].every(b=>{const r=b.getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && r.height>=44 && b.scrollWidth<=b.clientWidth+1})',
          ),
          'Every order section stays visible and touch-sized at ' + width + '.',
        );
        await tap('#order-payments-tab');
        check(
          await evaluate(
            'return !document.querySelector("#order-payments").hidden && document.querySelector("#order-payments-tab").getAttribute("aria-selected")==="true"',
          ),
          'Touch switches to the Payments section at ' + width + '.',
        );
        await capture('payment-' + width);
        await navigate('admin/catalogue/products.php?id=1');
        await tap('#product-basics-tab');
        await capture('product-' + width);
        check(
          await evaluate(
            'const f=document.querySelector("#product-basics [name=name]");return f.getBoundingClientRect().width>=220 && parseFloat(getComputedStyle(f).fontSize)>=16',
          ),
          'Product editing keeps usable field width and text size at ' + width + '.',
        );
      }
      await navigate('admin/settings/index.php');
      await capture('settings-' + width);
    }
    for (const width of [1024, 768, 390, 320]) {
      await viewport(width, 1000);
      await navigate('admin/index.php');
      check(
        await evaluate(
          'return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1',
        ),
        'Today fits width ' + width + '.',
      );
      if (width <= 768) {
        check(
          await evaluate('return document.querySelector("#admin-navigation").inert'),
          'Closed mobile navigation cannot receive focus at ' + width + '.',
        );
        await evaluate('document.querySelector(".admin-menu-button").click()');
        check(
          await evaluate(
            'return !document.querySelector("#admin-navigation").inert && document.querySelector(".admin-shell__workspace").inert',
          ),
          'Open mobile drawer isolates keyboard focus at ' + width + '.',
        );
        await evaluate('document.querySelector(".admin-nav-close").click()');
      }
      if (width === 390 || width === 320) await capture('today-' + width);
    }
    await viewport(844, 390, true);
    await navigate('admin/catalogue/products.php?id=1');
    await tap('#product-basics-tab');
    check(
      await evaluate(
        'const field=document.querySelector("#product-basics [name=name]");return document.documentElement.scrollWidth<=innerWidth+1 && parseFloat(getComputedStyle(field).fontSize)>=16',
      ),
      'Landscape touch editing stays contained with 16px input text.',
    );
    await tap('.admin-menu-button');
    check(
      await evaluate(
        'return !document.querySelector("#admin-navigation").inert && document.querySelector(".admin-shell__workspace").inert',
      ),
      'Landscape phone navigation opens by touch and isolates focus.',
    );
    await tap('.admin-nav-close');
    check(
      await evaluate(
        'return document.querySelector("#admin-navigation").inert && !document.querySelector(".admin-shell__workspace").inert',
      ),
      'Landscape phone navigation closes by touch and restores the workspace.',
    );
    check(
      errors.length === 0,
      'Rendered workflow pages emit no unhandled JavaScript errors. ' + errors.join('\n'),
    );
    console.log('Rendered captures: ' + output);
    console.log('Completed ' + checks + ' real-browser assertions.');
  } catch (error) {
    if (socket?.readyState === WebSocket.OPEN) {
      await capture('failure');
      console.error(
        await evaluate(
          'return JSON.stringify({title:document.title,heading:document.querySelector("h1")?.textContent,tabs:document.querySelectorAll("[role=tab]").length,task:document.querySelector(".admin-task-panel")?.textContent?.trim().slice(0,400),alerts:[...document.querySelectorAll(".admin-alert")].map(el=>el.textContent.trim().slice(0,160))})',
        ),
      );
    }
    throw error;
  } finally {
    if (socket?.readyState === WebSocket.OPEN) {
      try {
        await send('Browser.close');
      } catch {}
    }
    browser.kill();
    await pause(250);
    const resolved = path.resolve(profile);
    if (!resolved.startsWith(path.resolve(os.tmpdir()) + path.sep))
      throw Error('Unexpected browser profile path');
    try {
      fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 8, retryDelay: 100 });
    } catch {}
  }
})().catch((error) => {
  console.error(error.stack);
  process.exitCode = 1;
});
