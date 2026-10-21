// Tests for the inline scripts in the .phtml templates, without a browser or Magento.
// Run from the module root:  node --test Test/js/
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function scriptOf(template, phpReplacements) {
    const src = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/gtm', template), 'utf8');
    let js = /<script>([\s\S]*?)<\/script>/.exec(src)[1];
    for (const [needle, value] of Object.entries(phpReplacements)) {
        js = js.replace(needle, value);
    }
    return js;
}

function run(js, storage = new Map(), opts = {}) {
    const window = {};
    const localStorage = opts.brokenStorage
        ? {getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }}
        : {getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => storage.set(k, v)};
    window.addEventListener = (name, fn) => { ((window.listeners ||= {})[name] ||= []).push(fn); };
    window.fire = (name, detail) => (window.listeners?.[name] || []).forEach((fn) => fn({detail}));
    vm.runInNewContext(js, {window, localStorage, JSON});
    // Objects created inside the vm context have foreign prototypes; normalise for deepStrictEqual.
    if (window.dataLayer) {
        window.dataLayer = JSON.parse(JSON.stringify(window.dataLayer));
    }
    return window;
}

const PURCHASE_TAG = '<?= /* @noEscape */ $checkoutSuccesEvent ?>';
const purchaseJs = (event) => scriptOf('checkout-purchase.phtml', {[PURCHASE_TAG]: JSON.stringify(event)});
const order = (id) => ({event: 'purchase', ga4_event: 'purchase', ecommerce: {transaction_id: id, value: 10}});

test('purchase is pushed once, preceded by an ecommerce reset', () => {
    const win = run(purchaseJs(order('000000001')));
    assert.deepStrictEqual(win.dataLayer.map((e) => e.event ?? 'reset'), ['reset', 'purchase']);
    assert.strictEqual(win.dataLayer[0].ecommerce, null);
});

test('reloading the success page does not push the same order again', () => {
    const storage = new Map();
    run(purchaseJs(order('000000001')), storage);
    const win = run(purchaseJs(order('000000001')), storage);
    assert.strictEqual(win.dataLayer, undefined);
});

test('a different order is pushed even after a previous one', () => {
    const storage = new Map();
    run(purchaseJs(order('000000001')), storage);
    const win = run(purchaseJs(order('000000002')), storage);
    assert.strictEqual(win.dataLayer.length, 2);
});

test('nothing is pushed when there is no order', () => {
    const win = run(purchaseJs({event: 'purchase', ecommerce: {transaction_id: null}}));
    assert.strictEqual(win.dataLayer, undefined);
});

test('excluded zero-value order: the block renders null and nothing is pushed', () => {
    const js = scriptOf('checkout-purchase.phtml', {[PURCHASE_TAG]: 'null'});
    const win = run(js);
    assert.strictEqual(win.dataLayer, undefined);
});

test('unavailable storage still pushes the purchase', () => {
    const win = run(purchaseJs(order('000000003')), new Map(), {brokenStorage: true});
    assert.strictEqual(win.dataLayer.length, 2);
});

test('only the last 20 orders are remembered', () => {
    const storage = new Map();
    for (let i = 1; i <= 25; i++) {
        run(purchaseJs(order(String(i))), storage);
    }
    assert.strictEqual(JSON.parse(storage.get('mageos_gtm_tracked_purchases')).length, 20);
});

const BASE_TAG = '<?= /* @noEscape */ $block->getJsonCheckoutStepBase() ?>';
const stepsJs = () => scriptOf('checkout-steps.phtml', {[BASE_TAG]: JSON.stringify({currency: 'EUR', value: 25, items: [{item_id: 'A'}]})});

test('checkout step helper merges the base ecommerce data with the selected method', () => {
    const win = run(stepsJs());
    assert.strictEqual(win.mageosGtmCheckout.push('add_shipping_info', {shipping_tier: 'flatrate_flatrate'}), true);
    const event = JSON.parse(JSON.stringify(win.dataLayer))[1];
    assert.strictEqual(event.event, 'add_shipping_info');
    assert.strictEqual(event.ga4_event, 'add_shipping_info');
    assert.deepStrictEqual(event.ecommerce, {currency: 'EUR', value: 25, items: [{item_id: 'A'}], shipping_tier: 'flatrate_flatrate'});
});

test('checkout step helper skips an identical consecutive push but allows a changed value', () => {
    const win = run(stepsJs());
    assert.strictEqual(win.mageosGtmCheckout.push('add_payment_info', {payment_type: 'checkmo'}), true);
    assert.strictEqual(win.mageosGtmCheckout.push('add_payment_info', {payment_type: 'checkmo'}), false);
    assert.strictEqual(win.mageosGtmCheckout.push('add_payment_info', {payment_type: 'banktransfer'}), true);
    assert.strictEqual(win.dataLayer.filter((e) => e.event).length, 2);
});

// --- Hyvä Checkout wiring (event names and payloads taken from the Hyvä Checkout 1.3.15 sources)
const events = (win) => JSON.parse(JSON.stringify(win.dataLayer || [])).filter((e) => e.event);

test('nothing is pushed when a method is only activated (page load with a preselected default)', () => {
    const win = run(stepsJs());
    win.fire('checkout:shipping:method-activate', {method: 'flatrate_flatrate'});
    win.fire('checkout:payment:method-activate', {method: 'checkmo'});
    assert.strictEqual(win.dataLayer, undefined);
});

test('multi-step: shipping info is pushed when the shipping step validates, payment info only later', () => {
    const win = run(stepsJs());
    win.fire('checkout:shipping:method-activate', {method: 'flatrate_flatrate'});
    win.fire('checkout:validation:success', {step: 'shipping', options: {executeAfter: false}});
    assert.deepStrictEqual(events(win).map((e) => e.event), ['add_shipping_info']);
    assert.strictEqual(events(win)[0].ecommerce.shipping_tier, 'flatrate_flatrate');

    // payment step: method gets activated, then the order is placed (validation passes again)
    win.fire('checkout:payment:method-activate', {method: 'checkmo'});
    win.fire('checkout:validation:success', {step: 'payment', options: {executeAfter: false}});
    assert.deepStrictEqual(events(win).map((e) => e.event), ['add_shipping_info', 'add_payment_info']);
    assert.strictEqual(events(win)[1].ecommerce.payment_type, 'checkmo');
    assert.strictEqual(events(win)[1].ecommerce.currency, 'EUR');
});

test('one-page: both events are pushed when the order is placed', () => {
    const win = run(stepsJs());
    win.fire('checkout:shipping:method-activate', {method: 'tablerate_bestway'});
    win.fire('checkout:payment:method-activate', {method: 'banktransfer'});
    win.fire('checkout:validation:success', {step: 'onepage', options: {executeAfter: false}});
    assert.deepStrictEqual(events(win).map((e) => e.event), ['add_shipping_info', 'add_payment_info']);
});

test('validating the same selection again does not push again, a changed method does', () => {
    const win = run(stepsJs());
    win.fire('checkout:shipping:method-activate', {method: 'flatrate_flatrate'});
    win.fire('checkout:validation:success', {step: 'shipping', options: {}});
    win.fire('checkout:validation:success', {step: 'shipping', options: {}});
    assert.strictEqual(events(win).length, 1);

    win.fire('checkout:shipping:method-activate', {method: 'tablerate_bestway'});
    win.fire('checkout:validation:success', {step: 'shipping', options: {}});
    assert.strictEqual(events(win).length, 2);
    assert.strictEqual(events(win)[1].ecommerce.shipping_tier, 'tablerate_bestway');
});

test('the validation that Hyva runs after the order was placed is ignored', () => {
    const win = run(stepsJs());
    win.fire('checkout:shipping:method-activate', {method: 'flatrate_flatrate'});
    win.fire('checkout:validation:success', {step: 'payment', options: {executeAfter: true}});
    assert.strictEqual(win.dataLayer, undefined);
});

test('virtual cart: no shipping method is ever activated, so only payment info is pushed', () => {
    const win = run(stepsJs());
    win.fire('checkout:payment:method-activate', {method: 'checkmo'});
    win.fire('checkout:validation:success', {step: 'payment', options: {}});
    assert.deepStrictEqual(events(win).map((e) => e.event), ['add_payment_info']);
});

test('events without a method code are ignored', () => {
    const win = run(stepsJs());
    win.fire('checkout:shipping:method-activate', {});
    win.fire('checkout:payment:method-activate', undefined);
    win.fire('checkout:validation:success', {step: 'shipping'});
    assert.strictEqual(win.dataLayer, undefined);
});

// body.phtml: delivers queued events from the customer-data "gtm" section to the dataLayer.
// Models Alpine's registration: the constructor must be registered with Alpine.data() on `alpine:init`.
function bodyComponent(storage = new Map(), cookie = '') {
    const src = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/body.phtml'), 'utf8');
    const js = /<script>([\s\S]*?)<\/script>/.exec(src)[1]
        .replaceAll('<?= /* @noEscape */ EventQueue::COOKIE_LOGOUT ?>', 'gtm_logout_event');
    const listeners = {};
    const registered = {};
    const document = {cookie};
    const window = {
        dataLayer: [],
        addEventListener: (name, fn, opts) => { (listeners[name] ||= []).push({fn, opts}); },
    };
    const Alpine = {data: (name, fn) => { registered[name] = fn; }};
    const localStorage = {getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => storage.set(k, v)};
    vm.runInNewContext(js, {window, localStorage, JSON, document, Alpine, console});
    (listeners['alpine:init'] || []).forEach(({fn}) => fn());
    const component = registered.initGtmDatalayer ? registered.initGtmDatalayer() : undefined;
    return {window, document, listeners, registered, component, handler: component?.eventListeners['@private-content-loaded.window']};
}

const ADD_TO_CART = {event: 'add_to_cart', ecommerce: {currency: 'USD', value: 34, items: [{item_id: 'A'}]}};

test('body: queued add_to_cart is pushed once and cleared from the dispatched section data', () => {
    const storage = new Map();
    const {window, handler} = bodyComponent(storage);
    const data = {gtm: {datalayer: JSON.stringify([ADD_TO_CART])}};
    handler({detail: {data}});
    assert.deepStrictEqual(window.dataLayer.map((e) => e.event), ['add_to_cart']);
    // Hyvä stores this same object to localStorage right after dispatching: it must not hold the event any more,
    // otherwise the next page load replays add_to_cart.
    assert.strictEqual(data.gtm.datalayer, '[]');
});

test('body: events already stored in localStorage are cleared there too', () => {
    const storage = new Map([['mage-cache-storage', JSON.stringify({gtm: {datalayer: JSON.stringify([ADD_TO_CART])}})]]);
    const {window, handler} = bodyComponent(storage);
    handler({detail: {data: {gtm: {datalayer: JSON.stringify([ADD_TO_CART])}}}});
    assert.strictEqual(window.dataLayer.length, 1);
    assert.strictEqual(JSON.parse(storage.get('mage-cache-storage')).gtm.datalayer, '[]');
});

test('body: an empty section pushes nothing', () => {
    const {window, handler} = bodyComponent();
    handler({detail: {data: {gtm: {datalayer: '[]'}}}});
    handler({detail: {data: {}}});
    assert.deepStrictEqual(window.dataLayer, []);
});

// head.phtml: consent gate that loads GTM (same cookie rules as ConsentEvaluator on the server).
const HEAD_TAG = '<?= /* @noEscape */ $block->getJsonConsentConfig() ?>';
function head(configOverrides = {}, cookie = '') {
    const config = Object.assign({
        required: true,
        cookieName: 'user_allowed_save_cookie',
        grantedValue: '',
        events: ['user-allowed-save-cookie'],
        websiteId: 1,
        containerId: 'GTM-ABC123',
    }, configOverrides);
    const src = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/head.phtml'), 'utf8');
    const js = /<script>([\s\S]*?)<\/script>/.exec(src)[1].replace(HEAD_TAG, JSON.stringify(config));
    const inserted = [];
    const document = {
        cookie,
        getElementsByTagName: () => [{parentNode: {insertBefore: (el) => inserted.push(el.src)}}],
        createElement: () => ({}),
    };
    const listeners = {};
    const window = {addEventListener: (name, fn) => ((listeners[name] ||= []).push(fn))};
    vm.runInNewContext(js, {window, document, JSON, String, encodeURIComponent, decodeURIComponent});
    return {window, inserted, fire: (name) => (listeners[name] || []).forEach((fn) => fn({})), document};
}
const magentoCookie = (map) => 'user_allowed_save_cookie=' + encodeURIComponent(JSON.stringify(map));

test('head: no consent cookie, no event: GTM is not loaded', () => {
    const h = head();
    assert.deepStrictEqual(h.inserted, []);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(h.window.dataLayer)), []);
});

test('head: Magento consent cookie accepted for this website loads GTM once', () => {
    const h = head({}, 'other=1; ' + magentoCookie({1: 1}));
    assert.deepStrictEqual(h.inserted, ['https://www.googletagmanager.com/gtm.js?id=GTM-ABC123']);
    h.fire('user-allowed-save-cookie');
    assert.strictEqual(h.inserted.length, 1);
});

test('head: cookie accepted only for another website, or declined, does not load GTM', () => {
    assert.deepStrictEqual(head({}, magentoCookie({2: 1})).inserted, []);
    assert.deepStrictEqual(head({}, magentoCookie({1: 0})).inserted, []);
});

test('head: the fallback event loads GTM when no cookie is set yet', () => {
    const h = head();
    h.fire('user-allowed-save-cookie');
    assert.strictEqual(h.inserted.length, 1);
});

test('head: the event does not override a cookie that says declined', () => {
    const h = head({}, magentoCookie({1: 0}));
    h.fire('user-allowed-save-cookie');
    assert.deepStrictEqual(h.inserted, []);
});

test('head: custom cookie name, plain flag value and custom event names', () => {
    assert.strictEqual(head({cookieName: 'my_consent'}, 'my_consent=true').inserted.length, 1);
    assert.strictEqual(head({cookieName: 'my_consent'}, 'my_consent=0').inserted.length, 0);
    const h = head({cookieName: '', events: ['banner:accepted', 'other-event']});
    h.fire('other-event');
    assert.strictEqual(h.inserted.length, 1);
});

test('head: granted value is searched inside the decoded cookie (Cookiebot style)', () => {
    const cookiebot = 'CookieConsent=' + encodeURIComponent("{stamp:'x',necessary:true,statistics:true,marketing:false}");
    assert.strictEqual(head({cookieName: 'CookieConsent', grantedValue: 'statistics:true'}, cookiebot).inserted.length, 1);
    assert.strictEqual(head({cookieName: 'CookieConsent', grantedValue: 'marketing:true'}, cookiebot).inserted.length, 0);
});

test('head: consent not required loads GTM immediately', () => {
    assert.strictEqual(head({required: false}).inserted.length, 1);
});

test('head: no container id, nothing is loaded', () => {
    assert.strictEqual(head({containerId: ''}, magentoCookie({1: 1})).inserted.length, 0);
});

test('body: Alpine component is registered once on alpine:init (Hyvä CSP build needs Alpine.data)', () => {
    const {listeners, registered} = bodyComponent();
    assert.strictEqual(listeners['alpine:init'].length, 1);
    assert.strictEqual(listeners['alpine:init'][0].opts.once, true);
    assert.deepStrictEqual(Object.keys(registered), ['initGtmDatalayer']);
});

test('body: init() consumes the logout cookie once, then expires it', () => {
    const {window, document, component} = bodyComponent(new Map(), 'a=b; gtm_logout_event=' + encodeURIComponent(JSON.stringify({event: 'logout'})));
    component.init();
    assert.deepStrictEqual(window.dataLayer.map((e) => e.event ?? 'reset'), ['reset', 'logout']);
    assert.match(document.cookie, /gtm_logout_event=; expires=/);
});

// Hyvä's Alpine CSP build cannot evaluate expressions: attribute values may only be property or method names.
test('templates: Alpine directives are CSP-safe (no calls, operators or assignments in expressions)', () => {
    const dir = path.join(__dirname, '../../view/frontend/templates');
    const files = [];
    (function walk(d) {
        for (const e of fs.readdirSync(d, {withFileTypes: true})) {
            e.isDirectory() ? walk(path.join(d, e.name)) : e.name.endsWith('.phtml') && files.push(path.join(d, e.name));
        }
    })(dir);
    const directive = /\s(x-[a-z:.-]+|@[a-z.:-]+|:[a-z-]+)="([^"]*)"/g;
    const safe = /^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/;
    let checked = 0;
    for (const file of files) {
        const html = fs.readFileSync(file, 'utf8').replace(/<script[\s\S]*?<\/script>/g, '');
        for (const [, name, value] of html.matchAll(directive)) {
            checked++;
            assert.match(value, safe, `${path.basename(file)}: ${name}="${value}" is not CSP-safe`);
        }
    }
    assert.ok(checked >= 2, 'expected to find the Alpine directives in body.phtml');
});

// head.phtml: Google Consent Mode v2
const consentCommands = (h) => JSON.parse(JSON.stringify(h.window.dataLayer))
    .filter((e) => e['0'] === 'consent')
    .map((e) => [e['1'], e['2']]);
const ALL = (value) => ({ad_storage: value, analytics_storage: value, ad_user_data: value, ad_personalization: value});

test('consent mode off: no consent commands at all', () => {
    const h = head({consentMode: 'off'}, magentoCookie({1: 1}));
    assert.deepStrictEqual(consentCommands(h), []);
    assert.strictEqual(h.inserted.length, 1);
});

test('consent mode basic: denied default, GTM waits, granted update before GTM loads on consent', () => {
    const h = head({consentMode: 'basic'});
    assert.deepStrictEqual(consentCommands(h), [['default', Object.assign(ALL('denied'), {wait_for_update: 500})]]);
    assert.strictEqual(h.inserted.length, 0);
    h.fire('user-allowed-save-cookie');
    const commands = consentCommands(h);
    assert.deepStrictEqual(commands[1], ['update', ALL('granted')]);
    assert.strictEqual(h.inserted.length, 1);
    const layer = JSON.parse(JSON.stringify(h.window.dataLayer));
    assert.ok(layer.findIndex((e) => e['1'] === 'update') < layer.findIndex((e) => e.event === 'gtm.js'), 'update before gtm.js');
});

test('consent mode basic with an accepted cookie: default + update + GTM on page load', () => {
    const h = head({consentMode: 'basic'}, magentoCookie({1: 1}));
    assert.deepStrictEqual(consentCommands(h).map((c) => c[0]), ['default', 'update']);
    assert.strictEqual(h.inserted.length, 1);
});

test('consent mode advanced: GTM loads without consent, everything denied; update after consent', () => {
    const h = head({consentMode: 'advanced'});
    assert.strictEqual(h.inserted.length, 1);
    assert.deepStrictEqual(consentCommands(h).map((c) => c[0]), ['default']);
    h.fire('user-allowed-save-cookie');
    assert.deepStrictEqual(consentCommands(h).map((c) => c[0]), ['default', 'update']);
    assert.strictEqual(h.inserted.length, 1, 'GTM is not loaded twice');
});

test('consent mode advanced with a declined cookie: GTM loaded, no update', () => {
    const h = head({consentMode: 'advanced'}, magentoCookie({1: 0}));
    assert.strictEqual(h.inserted.length, 1);
    assert.deepStrictEqual(consentCommands(h).map((c) => c[0]), ['default']);
});

test('granted signals = analytics only keeps the ad signals denied', () => {
    const h = head({consentMode: 'basic', grantedSignals: 'analytics'}, magentoCookie({1: 1}));
    assert.deepStrictEqual(consentCommands(h)[1], ['update',
        {ad_storage: 'denied', analytics_storage: 'granted', ad_user_data: 'denied', ad_personalization: 'denied'}]);
});

test('banner sends Consent Mode itself: no module commands, advanced still loads GTM at once', () => {
    const h = head({consentMode: 'advanced', cmpManaged: true});
    assert.deepStrictEqual(consentCommands(h), []);
    assert.strictEqual(h.inserted.length, 1);
    const b = head({consentMode: 'basic', cmpManaged: true});
    assert.strictEqual(b.inserted.length, 0);
    b.fire('user-allowed-save-cookie');
    assert.deepStrictEqual(consentCommands(b), []);
    assert.strictEqual(b.inserted.length, 1);
});

// gtm/list-attribution.phtml + the select_item handler in gtm/catalog-events.phtml
function allScripts(file) {
    const src = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates', file), 'utf8');
    return [...src.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
}
const attributionJs = allScripts('gtm/list-attribution.phtml')[0];

function listPage(storage = new Map(), now = Date.now()) {
    const listeners = {};
    const window = {dataLayer: []};
    const localStorage = {getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => storage.set(k, v)};
    const document = {addEventListener: (name, fn) => ((listeners[name] ||= []).push(fn))};
    const DateStub = {now: () => now};
    const context = {window, document, localStorage, JSON, Date: DateStub, String, Array, Object};
    vm.runInNewContext(attributionJs, context);
    const config = {
        item_list_id: 'category_12', item_list_name: 'Tops',
        items: [{item_id: 'MH01', index: 0}, {item_id: 'MH02', index: 1}],
        productIds: ['101', '102'],
    };
    const handlerJs = allScripts('gtm/catalog-events.phtml')[1]
        .replace('<?= /* @noEscape */ $listConfig ?>', JSON.stringify(config));
    vm.runInNewContext(handlerJs, context);
    // DOM stubs: a list item form with its product id; a link or a button inside it
    const element = (productId, kind) => {
        const form = {querySelector: () => ({value: productId})};
        return {closest: (sel) => (sel === 'form' ? form : (sel === 'a[href]' ? (kind === 'link' ? {} : null) : null))};
    };
    return {
        window, storage,
        click: (productId, kind) => (listeners.click || []).forEach((fn) => fn({target: element(productId, kind)})),
        events: () => JSON.parse(JSON.stringify(window.dataLayer)).filter((e) => e.event),
    };
}

test('select_item: a link click in a listing pushes select_item with the list and the item', () => {
    const page = listPage();
    page.click('102', 'link');
    assert.deepStrictEqual(page.events(), [{
        event: 'select_item', ga4_event: 'select_item',
        ecommerce: {item_list_id: 'category_12', item_list_name: 'Tops', items: [{item_id: 'MH02', index: 1}]},
    }]);
});

test('select_item: an add-to-cart button click only remembers the list; unknown products are ignored', () => {
    const page = listPage();
    page.click('101', 'button');
    page.click('999', 'link');
    assert.deepStrictEqual(page.events(), []);
    const stored = JSON.parse(page.storage.get('mageos_gtm_item_lists'));
    assert.strictEqual(stored.MH01.item_list_id, 'category_12');
    assert.strictEqual(stored.MH01.index, 0);
});

test('list attribution is applied to later items, not overriding an existing list, and expires after 7 days', () => {
    const storage = new Map();
    listPage(storage, 1700000000000).click('101', 'link');

    const later = listPage(storage, 1700000000000 + 3600 * 1000);
    const items = [{item_id: 'MH01'}, {item_id: 'OTHER'}, {item_id: 'MH01', item_list_id: 'search_results'}];
    later.window.mageosGtmListAttribution.apply(items);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(items)), [
        {item_id: 'MH01', item_list_id: 'category_12', item_list_name: 'Tops', index: 0},
        {item_id: 'OTHER'},
        {item_id: 'MH01', item_list_id: 'search_results'},
    ]);

    const expired = listPage(storage, 1700000000000 + 8 * 24 * 3600 * 1000);
    const old = [{item_id: 'MH01'}];
    expired.window.mageosGtmListAttribution.apply(old);
    assert.strictEqual(old[0].item_list_id, undefined);
});

test('purchase items get the remembered list', () => {
    const storage = new Map();
    listPage(storage).click('102', 'link');
    const win = run(attributionJs + '\n' + purchaseJs({
        event: 'purchase', ecommerce: {transaction_id: '9', items: [{item_id: 'MH02'}]},
    }), storage);
    const purchase = win.dataLayer.find((e) => e.event === 'purchase');
    assert.strictEqual(purchase.ecommerce.items[0].item_list_name, 'Tops');
});

// gtm/promotions.phtml
function promoPage(elements, {intersection = true} = {}) {
    const listeners = {};
    const window = {dataLayer: []};
    let observerCallback = null;
    const observed = [];
    class IO {
        constructor(cb) { observerCallback = cb; }
        observe(el) { observed.push(el); }
        unobserve() {}
    }
    const document = {
        readyState: 'complete',
        querySelectorAll: () => elements,
        addEventListener: (name, fn) => ((listeners[name] ||= []).push(fn)),
    };
    const context = {window, document, Array, Object};
    if (intersection) {
        context.IntersectionObserver = IO;
    }
    vm.runInNewContext(allScripts('gtm/promotions.phtml')[0], context);
    return {
        observed,
        see: (el) => observerCallback([{isIntersecting: true, target: el}]),
        click: (target) => (listeners.click || []).forEach((fn) => fn({target})),
        events: () => JSON.parse(JSON.stringify(window.dataLayer)).filter((e) => e.event),
    };
}
const promoEl = (attrs) => {
    const el = {getAttribute: (name) => (name in attrs ? attrs[name] : null)};
    el.closest = () => el;
    return el;
};

test('promotions: view_promotion once when visible, select_promotion on click', () => {
    const banner = promoEl({
        'data-gtm-promotion-id': 'SUMMER26', 'data-gtm-promotion-name': 'Summer sale',
        'data-gtm-creative-name': 'hero', 'data-gtm-creative-slot': 'home-top', 'data-gtm-promotion-items': 'MH01, WS12',
    });
    const page = promoPage([banner]);
    assert.strictEqual(page.observed.length, 1);
    assert.deepStrictEqual(page.events(), []);
    page.see(banner);
    page.click(banner);
    const [view, select] = page.events();
    assert.strictEqual(view.event, 'view_promotion');
    assert.deepStrictEqual(view.ecommerce, {
        promotion_id: 'SUMMER26', promotion_name: 'Summer sale', creative_name: 'hero', creative_slot: 'home-top',
        items: [{item_id: 'MH01'}, {item_id: 'WS12'}],
    });
    assert.strictEqual(select.event, 'select_promotion');
});

test('promotions: WeltPixel attributes are understood, empty fields omitted, no IntersectionObserver = on load', () => {
    const banner = promoEl({'data-track-promo-id': 'P1', 'data-track-promo-name': 'Old banner', 'data-track-promo-position': 'slot2'});
    const page = promoPage([banner], {intersection: false});
    assert.deepStrictEqual(page.events()[0].ecommerce, {promotion_id: 'P1', promotion_name: 'Old banner', creative_slot: 'slot2'});
});

test('promotions: clicks outside promotions push nothing', () => {
    const page = promoPage([]);
    page.click({closest: () => null});
    assert.deepStrictEqual(page.events(), []);
});

// gtm/debug.phtml
function debugPage(search, storage = new Map(), dataLayer = []) {
    const logs = [];
    let interval = null;
    const window = {location: {search}, dataLayer};
    const localStorage = {
        getItem: (k) => (storage.has(k) ? storage.get(k) : null),
        setItem: (k, v) => storage.set(k, v),
        removeItem: (k) => storage.delete(k),
    };
    vm.runInNewContext(allScripts('gtm/debug.phtml')[0], {
        window, localStorage, console: {log: (...a) => logs.push(a[0])}, setInterval: (fn) => { interval = fn; },
    });
    return {logs, tick: () => interval && interval(), window, storage};
}

test('debug logger: silent unless switched on with ?gtm_debug=1', () => {
    assert.deepStrictEqual(debugPage('').logs, []);
});

test('debug logger: ?gtm_debug=1 logs existing and later pushes, and remembers the switch', () => {
    const storage = new Map();
    const page = debugPage('?gtm_debug=1', storage, [['consent', 'default', {}], {event: 'view_item'}]);
    assert.strictEqual(page.logs.length, 3);
    assert.match(page.logs[1], /consent default/);
    assert.match(page.logs[2], /view_item/);
    page.window.dataLayer.push({ecommerce: null}, {event: 'add_to_cart'});
    page.tick();
    assert.match(page.logs[3], /ecommerce reset/);
    assert.match(page.logs[4], /add_to_cart/);

    assert.ok(debugPage('', storage).logs.length > 0, 'still on without the parameter');
    assert.deepStrictEqual(debugPage('?a=1&gtm_debug=0', storage).logs, [], 'switched off');
    assert.deepStrictEqual(debugPage('', storage).logs, []);
});
