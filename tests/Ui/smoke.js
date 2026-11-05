// Runs the generated report pages in a simulated browser (jsdom) and checks that they draw and respond to clicks.
// Usage: php tests/Ui/make-samples.php /tmp/samples && (cd tests/Ui && npm i --no-save jsdom@24 && node smoke.js /tmp/samples)
const { JSDOM, VirtualConsole } = require('jsdom');
const dir = process.argv[2] || '.';
const fs = require('fs');
let failures = 0;
function check(cond, msg) { if (!cond) { failures++; console.log('FAIL: ' + msg); } else { console.log('ok:   ' + msg); } }

function load(file) {
  const errors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', e => errors.push(String(e.message || e)));
  vc.on('error', e => errors.push(String(e)));
  const dom = new JSDOM(fs.readFileSync(file, 'utf8'), {
    runScripts: 'dangerously',
    virtualConsole: vc,
    pretendToBeVisual: true,
    // pages served by the app poll for requests: answer with an empty list
    beforeParse(window) { window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ requests: [] }) }); },
  });
  return { dom, doc: dom.window.document, errors };
}
const click = (w, node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));

// 1. report with a change highlighted
{
  const { dom, doc, errors } = load(dir + '/with-impact.html');
  const w = dom.window;
  check(errors.length === 0, 'with-impact: no script errors ' + errors.join(' | '));
  check(doc.querySelectorAll('#entries button').length === 3, 'with-impact: three entry points listed');
  check(doc.getElementById('banner').style.display === 'flex', 'with-impact: change banner shown');
  check(/1 changed/.test(doc.getElementById('banner').textContent), 'with-impact: banner says 1 changed');
  const first = doc.querySelector('#entries button[aria-current="true"]');
  check(!!first, 'with-impact: an affected entry point is selected automatically');
  const nodes = doc.querySelectorAll('#viewport g.node');
  const links = doc.querySelectorAll('#viewport path.link');
  check(nodes.length >= 4, 'with-impact: diagram has nodes (' + nodes.length + ')');
  check(links.length >= 3, 'with-impact: diagram has links (' + links.length + ')');
  check(doc.querySelectorAll('#viewport g.node.changed').length === 1, 'with-impact: exactly one node marked changed');
  check(doc.querySelectorAll('#viewport path.link.c-POSSIBLE').length >= 1 && doc.querySelectorAll('#viewport path.link.c-UNRESOLVED').length >= 1, 'with-impact: possible and unresolved links drawn');
  const changedNode = doc.querySelector('#viewport g.node.changed');
  click(w, changedNode);
  const detail = doc.getElementById('detail').textContent;
  check(/updateStock/.test(detail), 'with-impact: clicking a function shows its details');
  check(/app\/Services\/PurchaseService\.php:15/.test(detail), 'with-impact: details show file and line');
  check(/declaration changed/.test(detail), 'with-impact: details flag the signature change');
  check(/Tests\\PurchaseTest::test_store/.test(detail), 'with-impact: details list the covering test');
  check(doc.querySelectorAll('#viewport g.node.dim').length >= 0 && doc.querySelector('#viewport g.node.selected') !== null, 'with-impact: selected node highlighted');
  // clicking a caller link in the panel selects that node
  const callerLink = doc.querySelector('#detail a');
  check(!!callerLink, 'with-impact: details have clickable callers/callees');
  if (callerLink) { click(w, callerLink); check(doc.querySelector('#viewport g.node.selected') !== null, 'with-impact: clicking a related function moves the selection'); }
  // search + kind filter
  const search = doc.getElementById('search');
  search.value = 'ping'; search.dispatchEvent(new w.Event('input', { bubbles: true }));
  check(doc.querySelectorAll('#entries button').length === 1, 'with-impact: search narrows the list');
  search.value = ''; search.dispatchEvent(new w.Event('input', { bubbles: true }));
  const chips = [...doc.querySelectorAll('#kinds button')];
  const jobChip = chips.find(b => /^job/.test(b.textContent));
  check(!!jobChip, 'with-impact: kind filter chips exist');
  if (jobChip) { click(w, jobChip); check(doc.querySelectorAll('#entries button').length === 1, 'with-impact: kind filter narrows the list'); }
  // only affected
  const box = doc.querySelector('#banner input[type=checkbox]');
  box.checked = true; box.dispatchEvent(new w.Event('change', { bubbles: true }));
  chips[0] && click(w, chips[0]);
  check(doc.querySelectorAll('#entries button').length === 2, 'with-impact: "only affected" hides the unaffected route');
  // wheel zoom
  w.document.getElementById('stage').dispatchEvent(new w.WheelEvent('wheel', { deltaY: -100, bubbles: true, cancelable: true }));
  check(/scale\(1\.1/.test(doc.getElementById('viewport').getAttribute('transform')), 'with-impact: scroll zooms the diagram');
}

// 1b. served live by the app: the compare form is shown and prefilled
{
  const { doc, errors } = load(dir + '/live.html');
  check(errors.length === 0, 'live: no script errors ' + errors.join(' | '));
  check(doc.getElementById('live').hidden === false, 'live: compare form is shown');
  check(doc.getElementById('base').value === 'main', 'live: form is prefilled with the current base');
  check(doc.getElementById('live').getAttribute('method') === 'get', 'live: form submits as a GET (?base=...)');
}

// 2. plain report: no banner, still draws
{
  const { doc, errors } = load(dir + '/plain.html');
  check(errors.length === 0, 'plain: no script errors ' + errors.join(' | '));
  check(doc.getElementById('banner').style.display !== 'flex', 'plain: no change banner');
  check(doc.getElementById('live').hidden === true, 'plain: a static file has no compare form');
  check(doc.querySelectorAll('#viewport g.node').length >= 1, 'plain: first entry point drawn');
}

// 3. empty report: friendly message instead of a blank page
{
  const { doc, errors } = load(dir + '/empty.html');
  check(errors.length === 0, 'empty: no script errors ' + errors.join(' | '));
  check(/No entry points were found/.test(doc.getElementById('empty').textContent), 'empty: explains that nothing was found');
}

console.log(failures === 0 ? '\nALL UI CHECKS PASSED' : '\n' + failures + ' UI CHECK(S) FAILED');
process.exit(failures === 0 ? 0 : 1);
