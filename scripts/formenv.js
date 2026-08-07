// Loads AppJs.html (raw form JS) into a minimal fake browser so the real functions
// can be driven from node. No jsdom needed: the form only ever touches a handful of
// DOM entry points, and every render path is exercised through renderCurrentPage().
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function makeEl(id) {
  const el = {
    id, innerHTML: '', value: '', disabled: false, className: '', style: { setProperty() {} },
    classList: { toggle() {}, add() {}, remove() {} },
    addEventListener() {}, querySelectorAll: () => [], querySelector: () => null,
    contains: () => false, click() {}, focus() {},
  };
  return el;
}

function loadForm(appJsPath) {
  const src = fs.readFileSync(appJsPath, 'utf8');
  const els = new Map();
  const document = {
    getElementById(id) { if (!els.has(id)) els.set(id, makeEl(id)); return els.get(id); },
    querySelector() { return makeEl('q'); },
    querySelectorAll() { return []; },
    addEventListener() {},
    createElement: makeEl,
    body: makeEl('body'),
  };
  const store = {};
  const sandbox = {
    console,
    document,
    window: {
      PMS_ENGINEERS: ['Dada', 'Nagraj'],
      addEventListener() {}, removeEventListener() {}, postMessage() {}, open: () => null,
    },
    localStorage: {
      getItem: k => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v); },
      removeItem: k => { delete store[k]; },
    },
    alert() {},
    setTimeout, clearTimeout, fetch: () => Promise.resolve({ json: () => ({}) }),
  };
  sandbox.globalThis = sandbox;
  sandbox.window.localStorage = sandbox.localStorage;
  // `let`/`const` at the top of AppJs.html are lexical — they never become sandbox
  // globals, so state like `answers` is invisible (and un-settable) from outside.
  // Appending this tail runs IN THE SAME SCOPE and hands out live accessors.
  const bridge = `
    ;globalThis.__ctx = {
      get answers() { return answers; },           set answers(v) { answers = v; },
      get currentPage() { return currentPage; },   set currentPage(v) { currentPage = v; },
      get projectList() { return projectList; },   set projectList(v) { projectList = v; },
      get projectListLoading() { return projectListLoading; }, set projectListLoading(v) { projectListLoading = v; },
      STATUS_STEPS, OTHER_ACTIVITY, WORK_DONE_OPTIONS, TECH_TYPES, HOLD_REASONS, SITE_TYPES,
    };`;
  vm.createContext(sandbox);
  vm.runInContext(src + bridge, sandbox, { filename: path.basename(appJsPath) });

  const ctx = sandbox.__ctx;
  // One facade: live lexical state first, plain globals (the functions) second.
  return new Proxy({}, {
    get: (_, k) => (k in ctx ? ctx[k] : sandbox[k]),
    set: (_, k, v) => { if (k in ctx) { ctx[k] = v; } else { sandbox[k] = v; } return true; },
    has: (_, k) => (k in ctx) || (k in sandbox),
  });
}

module.exports = { loadForm };
