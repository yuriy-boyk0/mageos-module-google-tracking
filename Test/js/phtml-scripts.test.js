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
function bodyComponent(storage = new Map()) {
    const src = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/body.phtml'), 'utf8');
    const js = /<script>([\s\S]*?)<\/script>/.exec(src)[1]
        .replace('<?= /* @noEscape */ EventQueue::COOKIE_LOGOUT ?>', 'gtm_logout_event');
    const window = {dataLayer: []};
    const localStorage = {getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => storage.set(k, v)};
    const context = {window, localStorage, JSON, document: {cookie: ''}, console};
    vm.runInNewContext(js + '\nthis.component = initGtmDatalayer();', context);
    return {window, handler: context.component.eventListeners['@private-content-loaded.window']};
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
