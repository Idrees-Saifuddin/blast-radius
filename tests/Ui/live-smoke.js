// Runs the live requests view in a simulated browser (jsdom) against a mock of the JSON endpoints.
// Usage: php tests/Ui/make-samples.php /tmp/samples && (cd tests/Ui && npm i --no-save jsdom@24 && node live-smoke.js /tmp/samples)
const { JSDOM, VirtualConsole } = require('jsdom');
const fs = require('fs');
const dir = process.argv[2] || '.';
let failures = 0;
function check(cond, msg) { if (!cond) { failures++; console.log('FAIL: ' + msg); } else { console.log('ok:   ' + msg); } }
const document_has_no_legend_on_live = (doc) => doc.body.classList.contains('live-tab') && !!doc.querySelector('.legend.flow-only') && !!doc.querySelector('#live.flow-only');
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

const loc = {
  'App\\Services\\DashboardService::totals': { file: 'app/Services/DashboardService.php', line: 15, end: 30 },
  'App\\Http\\DashboardController::index': { file: 'app/Http/DashboardController.php', line: 10, end: 24 },
  'App\\Services\\ReportBuilder::build': { file: 'app/Reports/ReportBuilder.php', line: 8, end: 40 },
};
const O = 'http://localhost:3000';
const row = (id, method, path, status, ms, page, extra) => Object.assign({ id, at: '2026-11-05T10:00:0' + id + '+00:00', method, path, route: path, status, ms, page, pageOrigin: O, queries: 0, queryMs: 0, failedQueries: 0, errors: 0, bytes: 100, items: null }, extra || {});
// newest first, as the server returns them
const requests = [
  row(9, 'GET', '/api/products', 200, 56, '/dashboard/products', { queries: 13, queryMs: 21, items: 10, bytes: 11264 }),
  row(8, 'GET', '/api/notifications/unread-count', 200, 36, '/dashboard/products', { queries: 7, queryMs: 29, bytes: 18 }),
  row(7, 'GET', '/api/notifications/unread-count', 200, 39, '/dashboard/products', { queries: 7, queryMs: 32, bytes: 18 }),
  row(6, 'GET', '/api/notifications/unread-count', 200, 35, '/dashboard/products', { queries: 7, queryMs: 28, bytes: 18 }),
  row(5, 'GET', '/api/orders', 500, 640, '/orders', { queries: 1, queryMs: 600, failedQueries: 1, errors: 1, bytes: 120 }),
  row(4, 'GET', '/api/dashboard', 200, 184, '/dashboard', { queries: 2, queryMs: 41, items: 12, bytes: 2150 }),
  row(3, 'GET', '/api/notifications/unread-count', 200, 30, '/dashboard', { queries: 7, queryMs: 20, bytes: 18 }),
  row(2, 'POST', '/api/purchases', 201, 1200, '/dashboard'),
  row(1, 'GET', '/api/user', 200, 9, '/dashboard'),
];
const details = {
  9: {
    id: 9, at: '2026-11-05T10:00:09+00:00', method: 'GET', path: '/api/products', uri: '/api/products?page=1', route: '/api/products', action: 'App\\Http\\ProductController@index',
    status: 200, ms: 56, memory: 22, page: '/dashboard/products', pageOrigin: O, bytes: 11264, items: 10, dropped: 0,
    spans: [{ i: 0, n: 'App\\Http\\ProductController::index', p: -1, s: 1, ms: 50, f: null }],
    queries: [
      { sql: 'select * from products limit 15', kind: 'select', ms: 1.2, at: 5, ok: true, rows: 10, rowsKind: 'returned', error: null, bindings: 0, connection: 'mysql', span: 0, caller: 'app/Http/ProductController.php:20' },
      { sql: 'select * from personal_access_tokens where id = ?', kind: 'select', ms: 5.9, at: 0.2, ok: true, rows: 1, rowsKind: 'returned', error: null, bindings: 1, connection: 'mysql', span: -1, caller: null },
    ],
    models: { 'App\\Models\\Product': 10 }, exceptions: [], files: [], locations: {},
  },
  7: {
    id: 7, at: '2026-11-05T10:00:07+00:00', method: 'GET', path: '/api/notifications/unread-count', uri: '/api/notifications/unread-count', route: '/api/notifications/unread-count', action: null,
    status: 200, ms: 39, memory: 20, page: '/dashboard/products', pageOrigin: O, bytes: 18, items: null, dropped: 0, spans: [], queries: [], models: {}, exceptions: [], files: [], locations: {},
  },
  4: {
    id: 4, at: '2026-11-05T10:00:02+00:00', method: 'GET', path: '/api/dashboard', uri: '/api/dashboard?range=7', route: '/api/dashboard', action: 'App\\Http\\DashboardController@index',
    status: 200, ms: 184, memory: 22, page: '/dashboard', pageOrigin: O, bytes: 2150, items: 12, dropped: 0,
    spans: [
      { i: 0, n: 'App\\Http\\DashboardController::index', p: -1, s: 1, ms: 180, f: null },
      { i: 1, n: 'App\\Services\\DashboardService::totals', p: 0, s: 5, ms: 120, f: null },
      { i: 2, n: 'App\\Services\\ReportBuilder::build', p: 1, s: 60, ms: 60, f: null },
    ],
    queries: [
      { sql: 'select * from sales where day > ?', kind: 'select', ms: 35, at: 12, ok: true, rows: 120, rowsKind: 'returned', error: null, bindings: 1, connection: 'mysql', span: 1, caller: 'app/Services/DashboardService.php:20' },
      { sql: 'select count(*) from sales', kind: 'select', ms: 6, at: 130, ok: true, rows: 1, rowsKind: 'returned', error: null, bindings: 0, connection: 'mysql', span: 0, caller: 'app/Http/DashboardController.php:15' },
    ],
    models: { 'App\\Models\\Sale': 120 }, exceptions: [], files: ['app/Http/DashboardController.php', 'app/Services/DashboardService.php'], locations: loc,
  },
  5: {
    id: 5, at: '2026-11-05T10:00:05+00:00', method: 'GET', path: '/api/orders', uri: '/api/orders', route: '/api/orders', action: 'App\\Http\\OrderController@index',
    status: 500, ms: 640, memory: 20, page: '/orders', pageOrigin: O, bytes: 120, items: null, dropped: 0,
    spans: [
      { i: 0, n: 'App\\Http\\OrderController::index', p: -1, s: 1, ms: 630, f: { class: 'Illuminate\\Database\\QueryException', message: 'no such table: orders', file: 'vendor/laravel/framework/Connection.php', line: 10, x: 5 } },
      { i: 1, n: 'App\\Services\\OrderService::list', p: 0, s: 10, ms: 610, f: { class: 'Illuminate\\Database\\QueryException', message: 'no such table: orders', file: 'vendor/laravel/framework/Connection.php', line: 10, x: 5 } },
    ],
    queries: [{ sql: 'select * from orders', kind: 'select', ms: 600, at: 15, ok: false, rows: null, rowsKind: null, error: 'no such table: orders', bindings: 0, connection: 'mysql', span: 1, caller: 'app/Services/OrderService.php:33' }],
    models: {}, exceptions: [{ class: 'Illuminate\\Database\\QueryException', message: 'no such table: orders', file: 'vendor/laravel/framework/Connection.php', line: 10, x: 5, code: 0, appFrame: 'app/Services/OrderService.php:33' }],
    files: [], locations: { 'App\\Services\\OrderService::list': { file: 'app/Services/OrderService.php', line: 28, end: 40 } },
  },
  2: {
    id: 2, at: '2026-11-05T10:00:02+00:00', method: 'POST', path: '/api/purchases', uri: '/api/purchases', route: '/api/purchases', action: null,
    status: 201, ms: 1200, memory: 20, page: '/dashboard', bytes: 90, items: null, dropped: 0, spans: [], queries: [], models: {}, exceptions: [], files: [], locations: {},
  },
};

function load(list) {
  const calls = [];
  const errors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => errors.push(String(e.message || e)));
  const dom = new JSDOM(fs.readFileSync(dir + '/live.html', 'utf8'), {
    url: 'http://localhost/blast-radius',
    runScripts: 'dangerously',
    virtualConsole: vc,
    pretendToBeVisual: true,
    beforeParse(window) {
      window.fetch = (url) => {
        calls.push(url);
        const m = String(url).match(/\/requests(?:\/(\d+))?(?:\?after=(\d+))?$/);
        let body;
        if (m && m[1]) { body = details[m[1]]; } else { body = { requests: (list || requests).filter((r) => r.id > Number((m && m[2]) || 0)) }; }
        return Promise.resolve({ ok: body !== undefined, status: body ? 200 : 404, json: () => Promise.resolve(body) });
      };
    },
  });
  return { dom, doc: dom.window.document, w: dom.window, calls, errors };
}
const click = (w, node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
const text = (node) => (node ? node.textContent : '');

(async () => {
  const { doc, w, calls, errors } = load();
  await wait(150);

  check(errors.length === 0, 'live: no script errors ' + errors.join(' | '));
  check(doc.getElementById('tabs').style.display === 'flex', 'live: the tab bar is shown');
  check(doc.getElementById('live-main').hidden === false && doc.getElementById('flow-main').hidden === true, 'live: opens on the live requests tab');
  check(calls[0] === '/blast-radius/requests?after=0', 'live: first call goes to the page\'s own /requests endpoint (' + calls[0] + ')');

  // ---- sidebar: only pages, newest first
  const heads = () => [...doc.querySelectorAll('.grp-head')];
  const titles = () => heads().map((h) => text(h.querySelector('.ttl')).trim());
  check(titles().length === 3, 'sidebar: one entry per page visited, not one per request (' + titles().join(' | ') + ')');
  check(/^\/dashboard\/products/.test(titles()[0]) && /^\/orders/.test(titles()[1]) && /^\/dashboard/.test(titles()[2]), 'sidebar: the page hit most recently is on top');
  check(/localhost:3000/.test(text(heads()[0])), 'sidebar: the address the page was served from is shown');
  check(doc.querySelectorAll('.req').length === 1, 'sidebar: only the newest page is expanded, showing its main call');
  check(/Background · 1 route · 3 calls/.test(text(doc.querySelector('.bg-toggle'))), 'sidebar: background polling is folded into one collapsed row (' + text(doc.querySelector('.bg-toggle')) + ')');
  check(document_has_no_legend_on_live(doc), 'header: the flow legend and the compare box are hidden on the live tab');
  check(heads()[0].getAttribute('aria-expanded') === 'true' && heads()[1].getAttribute('aria-expanded') === 'false', 'sidebar: other pages start collapsed');
  check(/4 API calls/.test(text(heads()[0])) && /98 ms|0\.1 s|166 ms|166/.test(text(heads()[0])), 'sidebar: a page shows its calls and total time on one compact header (' + text(heads()[0]).replace(/\s+/g, ' ') + ')');
  check(/1 failed/.test(text(heads()[1])), 'sidebar: a page with a failing call says so');

  const routes = () => [...doc.querySelectorAll('.route')];
  const products = routes().find((r) => /\/api\/products/.test(text(r)));
  check(!!products && /^GET\/api\/products200/.test(text(products.querySelector('.req')).replace(/\s+/g, '')) && /56 ms/.test(text(products)), 'submenu: one line per call: method, path, status, time (' + text(products).replace(/\s+/g, ' ') + ')');
  const tip = products.querySelector('.req').getAttribute('title');
  check(/13 queries \(21 ms\)/.test(tip) && /10 items/.test(tip) && /11\.0 KB/.test(tip), 'submenu: the other details are in the hover tooltip (' + tip.replace(/\n/g, ' | ') + ')');
  check(!/unread-count/.test(text(doc.querySelector('.grp'))), 'submenu: background polling is not listed until you open it');
  click(w, doc.querySelector('.bg-toggle'));
  const polling = routes().find((r) => /unread-count/.test(text(r)));
  check(!!polling && /×3/.test(text(polling.querySelector('.times-toggle'))) && /×3 · avg 37 ms · max 39 ms/.test(polling.querySelector('.req').getAttribute('title')), 'submenu: opening Background shows each polled route once with its count (' + text(polling).replace(/\s+/g, ' ') + ')');
  check(doc.querySelectorAll('.route .call').length === 0, 'submenu: the individual calls start hidden');

  // the page's main call is opened, not the background polling
  const stage = doc.getElementById('live-stage');
  check(/GET \/api\/products/.test(text(stage.querySelector('h2'))), 'auto-open: the main call of the newest page is opened, not the polling');
  check(/from page localhost:3000\/dashboard\/products/.test(text(stage.querySelector('.sub'))), 'header: shows the page the call came from');

  // a query that ran before the first timed function sits at its real position, not at the bottom
  const firstRows = [...stage.querySelectorAll('.wf-row .wf-label')].map((l) => text(l));
  check(/personal_access_tokens/.test(firstRows[0]) && /ProductController::index/.test(firstRows[1]), 'timeline: a query that ran before any timed function is placed first, in time order (' + firstRows.slice(0, 2).map((r) => r.replace(/\s+/g, ' ').slice(0, 40)).join(' | ') + ')');

  // expand the individual calls of the polling route
  click(w, polling.querySelector('.times-toggle'));
  check(doc.querySelectorAll('.route .call').length === 3, 'submenu: the calls toggle lists each call');
  // the mock API has the details of call 7 (the second one listed, newest first)
  click(w, doc.querySelectorAll('.route .call')[1]);
  await wait(60);
  check(/unread-count/.test(text(doc.getElementById('live-stage').querySelector('h2'))), 'submenu: clicking a call opens that call');

  // open another page and its failing call
  click(w, heads()[1]);
  check(heads()[1].getAttribute('aria-expanded') === 'true' && doc.querySelectorAll('.grp')[1].querySelectorAll('.req').length === 1, 'sidebar: clicking a page opens its submenu');
  click(w, doc.querySelectorAll('.grp')[1].querySelector('.req'));
  await wait(60);
  const stage1 = doc.getElementById('live-stage');
  check(/GET \/api\/orders/.test(text(stage1.querySelector('h2'))) && /500/.test(text(stage1.querySelector('.st'))), 'failing call: opens with its status');
  const failedRow = doc.querySelectorAll('.grp')[1].querySelector('.req');
  check(failedRow.classList.contains('bad') && /1 query failed/.test(failedRow.getAttribute('title')) && /1 error/.test(failedRow.getAttribute('title')), 'failing call: its row is marked and the tooltip says what failed');
  check(stage1.querySelectorAll('.wf-row').length >= 3, 'timeline: rows for the functions and the query');
  check([...stage1.querySelectorAll('.wf-row .tag.bad')].some((t) => /failed/.test(text(t))), 'timeline: failed functions and the failed query are tagged');
  const detail = doc.getElementById('live-detail');
  check(/What failed/.test(text(detail)) && /QueryException/.test(text(detail)) && /no such table: orders/.test(text(detail)), 'overview: says what failed');
  check(/app\/Services\/OrderService\.php:33/.test(text(detail)), 'overview: shows the line of your own code');
  const failing = [...detail.querySelectorAll('a')].find((a) => /OrderService::list/.test(text(a)));
  check(!!failing, 'overview: names the failing function as a link');
  click(w, failing);
  check(/Failure/.test(text(doc.getElementById('live-detail'))) && /thrown at/.test(text(doc.getElementById('live-detail'))), 'function: clicking the failing function shows the failure');

  // a healthy call on the third page: timeline with a query row
  click(w, heads()[2]);
  click(w, [...doc.querySelectorAll('.grp')[2].querySelectorAll('.req')].find((r) => /\/api\/dashboard/.test(text(r))));
  await wait(60);
  const s2 = doc.getElementById('live-stage');
  check(/184 ms total/.test(text(s2.querySelector('.sub'))), 'timeline: header shows total time');
  const cards = [...s2.querySelectorAll('.card')].map((c) => text(c).replace(/\s+/g, ' '));
  check(cards.length === 4 && cards.some((c) => /2 · 41 ms/.test(c)) && cards.some((c) => /121/.test(c)), 'timeline: four simple cards: time, queries, rows returned, response (' + cards.length + ')');
  check(/functions timed/.test(text(s2.querySelector('.sub'))), 'timeline: the header says how many functions were timed');
  check(/your functions/.test(text(s2.querySelector('.wf-legend'))) && /database queries/.test(text(s2.querySelector('.wf-legend'))), 'timeline: a legend explains the colours');
  const labels = [...s2.querySelectorAll('.wf-row .wf-label')].map((l) => text(l));
  check(labels.some((l) => /DashboardController::index/.test(l)) && labels.some((l) => /DashboardService::totals/.test(l)) && labels.some((l) => /ReportBuilder::build/.test(l)), 'timeline: functions appear');
  const q = labels.find((l) => /select \* from sales/.test(l));
  check(!!q && /120 returned/.test(q) && /35 ms/.test(q), 'timeline: the query row shows rows returned and time (' + (q || '').replace(/\s+/g, ' ') + ')');
  check(!labels.some((l) => /SELECT select/i.test(l)), 'timeline: query labels do not repeat the SQL verb');
  const bars = [...s2.querySelectorAll('.wf-row .wf-bar')];
  check(bars.length >= 4 && bars.every((b) => /%$/.test(b.style.left) && /%$/.test(b.style.width)), 'timeline: every row has a positioned bar');
  check(doc.querySelectorAll('#live-stage .wf-row .tag.bad').length === 0, 'timeline: nothing is flagged as failed on a healthy request');

  const qRow = [...s2.querySelectorAll('.wf-row')].find((r) => /select \* from sales/.test(text(r)));
  click(w, qRow);
  const d2 = text(doc.getElementById('live-detail'));
  check(/select \* from sales where day > \?/.test(d2) && /120 rows returned/.test(d2) && /succeeded/.test(d2), 'query: details show SQL, rows and success');
  check(/Issued by function/.test(d2) && /DashboardService::totals/.test(d2) && /app\/Services\/DashboardService\.php:20/.test(d2), 'query: names the function and the line that issued it');
  check(/values are not recorded/.test(d2), 'query: bound values are not shown');
  const fRow = [...doc.querySelectorAll('#live-stage .wf-row')].find((r) => /DashboardService::totals/.test(text(r)));
  click(w, fRow);
  const d3 = text(doc.getElementById('live-detail'));
  check(/app\/Services\/DashboardService\.php:15–30/.test(d3), 'function: details show file and lines');
  check(/itself/.test(d3) && /Queries it ran \(1\)/.test(d3) && /Calls \(1\)/.test(d3), 'function: details show own time, its queries and what it calls');
  click(w, doc.querySelector('#live-detail a'));
  const d4 = text(doc.getElementById('live-detail'));
  check(/Directories hit/.test(d4) && /app\/Services/.test(d4) && /Slowest functions/.test(d4) && /Slowest queries/.test(d4) && /Models loaded/.test(d4) && /Sale/.test(d4), 'overview: lists slowest queries and functions, directories and models');
  check(doc.querySelectorAll('#live-detail details').length >= 2 && [...doc.querySelectorAll('#live-detail details')].every((d) => !d.open), 'overview: the extras are collapsed by default');
  check(/Own time/.test(text(doc.getElementById('live-detail'))) && /Rows/.test(text(doc.getElementById('live-detail'))), 'overview: tables have column headings');

  // filters
  const failuresOnly = () => [...doc.querySelectorAll('#req-chips button')].find((c) => /Failures only/.test(text(c)));
  click(w, failuresOnly());
  check(titles().length === 1 && /^\/orders/.test(titles()[0]), 'filter: "Failures only" keeps only the page with the failing call');
  click(w, failuresOnly());
  check(titles().length === 3, 'filter: toggling it again shows everything');
  const search = doc.getElementById('req-search');
  search.value = 'purchases'; search.dispatchEvent(new w.Event('input', { bubbles: true }));
  check(titles().length === 1 && /^\/dashboard$/.test(titles()[0]), 'filter: the search box filters by URL');
  search.value = ''; search.dispatchEvent(new w.Event('input', { bubbles: true }));

  // help
  const help = doc.getElementById('live-help');
  check(doc.getElementById('help-box').hidden === true, 'help: hidden until asked for');
  click(w, help);
  check(doc.getElementById('help-box').hidden === false && /Background/.test(text(doc.getElementById('help-box'))) && /pages you opened/.test(text(doc.getElementById('help-box'))), 'help: "How to read this" explains the three panels');
  click(w, help);
  check(doc.getElementById('help-box').hidden === true, 'help: can be closed again');

  // code flow link and tabs
  click(w, [...doc.querySelectorAll('.req')].find((r) => /purchases/.test(text(r))));
  await wait(60);
  const flowButton = [...doc.querySelectorAll('#live-stage .chip')].find((b) => /code flow/.test(text(b)));
  check(!!flowButton, 'live: a request whose route is known links to its code flow');
  click(w, flowButton);
  check(doc.getElementById('flow-main').hidden === false && doc.querySelectorAll('#viewport g.node').length > 0, 'live: that link opens the code flow of the route');
  check(!doc.body.classList.contains('live-tab'), 'header: the flow legend and compare box come back on the Code flow tab');
  click(w, doc.getElementById('tab-live'));
  check(doc.getElementById('live-main').hidden === false && doc.body.classList.contains('live-tab'), 'live: the tabs switch back');

  // pause and polling
  const pause = doc.getElementById('live-pause');
  click(w, pause);
  check(/Resume/.test(text(pause)), 'live: pause changes to resume');
  const before = calls.length;
  await wait(2300);
  check(calls.length === before, 'live: nothing is fetched while paused');
  click(w, pause);
  await wait(100);
  check(calls.slice(before).some((u) => /requests\?after=9$/.test(u)), 'live: polling asks only for requests newer than the last one it has');

  // pages the browser did not tell us about
  const unknownList = [
    row(12, 'GET', '/api/products', 200, 50, null),
    row(11, 'GET', '/api/user', 200, 8, null),
    Object.assign(row(10, 'GET', '/api/health', 200, 3, null), { pageOrigin: null }),
  ];
  unknownList.forEach((r) => { if (r.id !== 10) { r.page = null; } });
  const b = load(unknownList);
  await wait(150);
  const bdoc = b.doc;
  const bTitles = [...bdoc.querySelectorAll('.grp-head .ttl')].map((t) => text(t).trim());
  check(bTitles.some((t) => /^\(page unknown\)/.test(t)) && bTitles.some((t) => /^\(direct requests\)/.test(t)), 'unknown page: calls without a page are grouped honestly (' + bTitles.join(' | ') + ')');
  check(/no-referrer-when-downgrade/.test(text(bdoc.querySelector('.hint-box'))), 'unknown page: the sidebar tells you the one-line fix');
  check(doc.querySelector('.hint-box') === null, 'unknown page: no hint when every call has its page');
  check(!![...bdoc.querySelectorAll('.hint-box .chip')].find((c) => /Copy line/.test(text(c))), 'unknown page: the hint has a copy button');
  click(b.w, bdoc.querySelector('.hint-box .x'));
  check(bdoc.querySelector('.hint-box') === null, 'unknown page: the hint can be dismissed');

  console.log(failures === 0 ? '\nALL LIVE UI CHECKS PASSED' : '\n' + failures + ' LIVE UI CHECK(S) FAILED');
  process.exit(failures === 0 ? 0 : 1);
})();
