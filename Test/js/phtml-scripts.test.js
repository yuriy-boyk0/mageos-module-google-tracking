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
    window.addEventListener = (name, fn) => { (window.listeners ||= {})[name] = fn; };
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

test('checkout step listeners are not attached until the Hyva event names are filled in', () => {
    const win = run(stepsJs());
    assert.strictEqual(win.listeners, undefined);
    assert.strictEqual(win.dataLayer, undefined);
});
