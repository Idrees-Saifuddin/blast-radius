# How BlastRadius compares

A feature-by-feature comparison with similar tools, so you can judge what fits. It is a snapshot:
these tools change, so check them before deciding.

**Legend.** BlastRadius column: ✅ built · 🟡 partly · 📅 planned · 💡 idea. Other columns: ✅ has it ·
🟡 partly · ❌ not seen or not supported · ❓ not verified.

**How the other columns were checked (November 2026).**
- Richter 0.73, Watson 0.7 and phpgraph 2.0: their README, `composer.json` and source were read.
- Pest 5 TIA: web search results only.
- Telescope, Debugbar, Clockwork, profilers (Xdebug, XHProf, SPX, Blackfire, Tideways), Deptrac and
  Larastan: general knowledge, not re-checked; cells I am unsure of are ❓.

Tools in the columns: **Richter, Watson, phpgraph** are static change-impact tools; **Pest 5 TIA** selects
tests from coverage; **Monitors** are Telescope, Debugbar and Clockwork; **Profilers** are
Xdebug, XHProf, SPX, Blackfire and Tideways.

## 1. Code relationships

| Feature | BlastRadius | Richter | Watson | phpgraph | Pest 5 TIA | Telescope / Debugbar / Clockwork | Profilers | Deptrac / Larastan |
|---|---|---|---|---|---|---|---|---|
| Class hierarchy view (extends / implements / traits) | ✅ `blast:hierarchy` | ❓ | ❓ | ✅ | ❌ | ❌ | ❌ | 🟡 Deptrac: layer rules, not a tree |
| Method resolution through parents and traits (PHP order) | ✅ | ❓ | ❓ | ✅ type inference | ❌ | ❌ | ❌ | 🟡 Larastan (types) |
| Method-level call graph | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ at runtime | ❌ class level |
| Call path between two symbols | 💡 not yet (the flow diagram shows the paths) | ✅ `trace` | ❌ | ✅ `shortest_path` | ❌ | ❌ | 🟡 call stack | ❌ |
| Circular dependencies, coupling hotspots | 💡 later | ❌ | ❌ | ✅ `god_nodes`, layer checks | ❌ | ❌ | ❌ | ✅ Deptrac |
| Unused code candidates | 💡 later | ❌ | ❌ | ❓ | ❌ | ❌ | ❌ | 🟡 |
| Web graph explorer | ✅ code flow and live requests, in the browser | 🟡 HTML report | ❌ | ❌ | ❌ | ❌ | ✅ (paid UIs) | ❌ |

## 2. Change impact

| Feature | BlastRadius | Richter | Watson | phpgraph | Pest 5 TIA | Telescope / Debugbar / Clockwork | Profilers | Deptrac / Larastan |
|---|---|---|---|---|---|---|---|---|
| Affected routes, jobs, events, commands | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Confidence on every result | ✅ CONFIRMED / POSSIBLE / UNRESOLVED | 🟡 UNRESOLVED + risk levels | ❌ | ✅ EXTRACTED / INFERRED / AMBIGUOUS | ❌ | ❌ | ❌ | ❌ |
| Unresolved dynamic calls reported, never dropped | ✅ | ✅ | ❓ | ✅ | ❌ | ❌ | ❌ | 🟡 Deptrac records them |
| Tests to run | ✅ from **real per-test coverage** (an optional step) | 🟡 name scan ("weaker than coverage", by its own docs) | ✅ static | 🟡 static | ✅ coverage | ❌ | ❌ | ❌ |
| "Affected but untested" check with CI exit code | ✅ coverage-backed | 🟡 heuristic tag only | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Works from a git diff | ✅ | ✅ | ✅ (diff on stdin) | 🟡 | ✅ | ❌ | ❌ | ❌ |
| PR comment (one sticky comment) | 📅 planned (markdown output works today) | 🟡 markdown to paste | 🟡 markdown to paste | ❌ | ❌ | ❌ | ❌ | ❌ |
| Risky-change "hazards" (e.g. `$fillable` widened) | 💡 borrow from Richter | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Confidence is the weakest edge on the path | ✅ | ❓ | ❌ | ❓ | ❌ | ❌ | ❌ | ❌ |

## 3. Live requests: what the running app did

All three impact tools are **static only**: they never run your app, so none of these rows applies to them.

| Feature | BlastRadius | Richter / Watson / phpgraph | Pest 5 TIA | Telescope | Debugbar / Clockwork | Profilers | Deptrac / Larastan |
|---|---|---|---|---|---|---|---|
| Which URL is slow | ✅ | ❌ | ❌ | 🟡 | ✅ | ✅ | ❌ |
| Function-level timeline ("A took X ms, called B and C") | ✅ with no PHP extension (an instrumented copy of your classes) | ❌ | ❌ | ❌ | 🟡 | ✅ (needs a PHP extension) | ❌ |
| Queries with duration | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ❌ |
| Failed queries | ✅ (through a replaced connection class) | ❌ | ❌ | ❓ | ❓ | ❓ | ❌ |
| Queries that return a lot of data | ✅ rows returned or changed per query (not for `cursor()` / `lazy()`) | ❌ | ❌ | ❌ | ❓ | ❓ | ❌ |
| N+1 query detection | 💡 later | ❌ | ❌ | ❓ | ❓ | ❓ | ❌ |
| **Slow route / query linked to code symbols and to your diff** | 🟡 linked to the code and to the route's code flow; not yet combined with the diff | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| "This change touches a route that is slow or untested" | 🟡 untested: yes (coverage); slow: not yet | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

## 4. Where it runs: PHP versions, legacy code, other frameworks

| Feature | BlastRadius | Richter | Watson | phpgraph | Pest 5 TIA | Monitors | Profilers | Deptrac / Larastan |
|---|---|---|---|---|---|---|---|---|
| Runs on PHP 8.1 to 8.4 | ✅ | ❌ 8.4+ | ❌ 8.4+ | ❌ 8.5+ (or standalone binary) | ❌ 8.4+ ❓ | ✅ | ✅ | ✅ |
| Laravel 8 to 12 | ✅ (CI on Laravel 8 to 12) | ❌ 12, 13 only | ❓ (needs PHP 8.4 to run) | ✅ | 🟡 plugin needs 13.x ❓ | ✅ | ✅ | ✅ |
| Legacy PHP 5-style code (old constructors, globals, `mysql_*`) | 💡 planned as a separate project | ❌ | ❌ | ❓ | ❌ | ❌ | ✅ at runtime | 🟡 |
| WordPress | 💡 planned as a separate project | ❌ | ❌ | 🟡 hooks, for AI agents | ❌ | 🟡 Query Monitor (separate tool) | ✅ | ❌ |
| Symfony / plain PHP | 💡 later | ❌ | ✅ Symfony | ✅ | ❌ | ❌ | ✅ | ✅ |

## 5. Safety, speed and reach

| Feature | BlastRadius | Richter | Watson | phpgraph | Pest 5 TIA | Monitors | Profilers | Deptrac / Larastan |
|---|---|---|---|---|---|---|---|---|
| Light on live sites: no work on web requests | ✅ (nothing is registered when switched off; tested) | ✅ dev tool | ✅ dev tool | ✅ dev tool | ✅ dev tool | ❌ runs on requests | ❌ overhead | ✅ dev tool |
| Switch off with an env variable; off in production by default | ✅ `BLASTRADIUS_ENABLED` | ❓ | ❓ | ❓ | ❓ | 🟡 Telescope can be disabled | 🟡 | n/a |
| Recording limited to development | 🟡 records every request, in development only; keeps the last 300 | n/a | n/a | n/a | n/a | 🟡 | ✅ | n/a |
| Standalone CLI plus Laravel command | ✅ `vendor/bin/blast`, artisan command in P3 | 🟡 artisan | ✅ standalone | ✅ standalone binary | 🟡 | ❌ | ❌ | ✅ |
| AI agent / MCP server | ❌ not planned for v1 | ✅ | 🟡 token-optimised output | ✅ MCP-first | ❌ | ❌ | ❌ | ❌ |
| IDE integration | 💡 later | ❌ | ❌ | 🟡 via MCP | ❌ | ❌ | ✅ (paid) | ✅ (IDE plugins) |
| Security findings engine | 💡 maybe never (crowded: Enlightn, Psalm taint, Snyk) | 🟡 "hazards" only | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ Larastan / Enlightn ❓ |

## Where BlastRadius is different

- **Coverage-backed "affected but untested".** Richter's own source says its test mapping is
  reference-based, not coverage-based, by design. BlastRadius can use real per-test coverage.
- **Older PHP and Laravel.** The impact tools above need PHP 8.4 or newer (phpgraph 8.5). BlastRadius
  runs on PHP 8.1 to 8.4 and Laravel 8 to 12.
- **Live requests tied to the code graph,** with no PHP extension: pages, API calls, the functions and
  queries each one ran, rows returned, failures and the function that failed, next to the same
  confidence-labelled diagram of your routes.
- **One tool** instead of an impact analyser plus a separate monitor.

## Where others are ahead

- **Richter** has risk "hazards" (for example a mass-assignment list widened), a command that traces
  the path between two symbols, and an MCP server for AI agents. phpgraph is built MCP-first.
- **Profilers** see everything, including vendor and framework code, with exact CPU time; BlastRadius
  times only your own code and records a development session, not production.
- **Telescope and Pulse** cover more of Laravel's moving parts (mail, notifications, cache, queues,
  logs) and are mature and widely used.
- **Deptrac** enforces architecture rules in CI; BlastRadius does not.

## Not a difference

Method-level impact, confidence labels (phpgraph has three levels, Richter reports UNRESOLVED),
markdown output and test selection exist in other tools too.
