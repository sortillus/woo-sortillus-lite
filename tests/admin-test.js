const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = {};
const timers = [];
let payload = { state: {}, category_state: {} };
const context = {
  document: { getElementById(id) {
    return elements[id] ||= { style: {}, addEventListener(event, callback) { this[event] = callback; } };
  } },
  window: {
    wooSortillusLiteAdmin: { i18n: {} },
    setTimeout(callback) { timers.push(callback); }
  },
  FormData: class { append() {} },
  fetch: async () => ({ json: async () => ({ success: true, data: payload }) })
};
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/admin.js', 'utf8'), context);
  await flush();
  const products = elements['woo-sortillus-lite-import'];
  const categories = elements['woo-sortillus-lite-import-categories'];
  assert.equal(products.hidden, true, 'Products hidden before category import');
  payload.category_state = { status: 'queued', total: 2, processed: 0 };
  categories.click();
  await flush();
  assert.equal(products.hidden, true, 'Products hidden while categories are queued');
  assert.equal(categories.disabled, true, 'No second category import while running');
  payload.category_state = { status: 'running', total: 2, processed: 1 };
  timers.shift()();
  await flush();
  assert.equal(products.hidden, true, 'Partial progress must not unlock products');
  payload.category_state = { status: 'failed', last_error: 'Category rejected' };
  timers.shift()();
  await flush();
  assert.equal(products.hidden, true, 'Failed import must not unlock products');
  assert.equal(categories.disabled, false, 'Failed import can be retried');
  assert.equal(elements['woo-sortillus-lite-category-error'].textContent, 'Category rejected');
  payload.category_state = { status: 'succeeded', total: 2, processed: 2 };
  categories.click();
  await flush();
  assert.equal(products.hidden, false, 'Products appear immediately on confirmed completion');
  assert.equal(products.disabled, false);
  console.log('Sortillus Lite admin category flow test passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
