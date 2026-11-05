# Research notes

## jasonmccreary/phpunit-tia (v0.5.0, read 2026-11-04)

Installed in a throwaway project and read the source (4,012 lines in `src/`). Not run:
v0.5.0 requires PHP ^8.4 and PHPUnit ^13.2.6, newer than our PHP 8.3 image.

1. **What it does well.** It piggybacks on PHPUnit's own coverage run. After a full run it
   inverts line coverage into a graph of test file -> source files (`Recorder::invert`).
   Later runs ask git which files changed (`ChangedFiles`) and select only tests linked to
   those files. It also caches pass/fail results, so unaffected tests are skipped entirely.
2. **Storage.** The graph is plain JSON (`graph.json`), by default in
   `~/.phpunit-tia/<project-slug>-<hash-of-git-origin>/`, outside the repo and keyed by git
   remote so CI clones and worktrees share one cache. `local` mode uses `<project>/.phpunit-tia/`.
   Per-branch baselines are kept, with a fallback branch.
3. **Invalidation.** A `Fingerprint` splits changes into structural (for example
   `composer.lock` drift, which forces a full rebuild) and environmental (for example the PHP
   version, which only drops cached results). Content hashes decide whether a file really
   changed since the last run.
4. **Gaps (our opening).**
   - Granularity is **file to file**, not method-level, so any edit to a big class re-runs
     every test touching that file.
   - It only answers "which tests". It says nothing about affected routes, jobs, events or
     commands, and has no call graph.
   - Files with no coverage edges (config, migrations, Blade) are handled by heuristics only.
     Laravel-specific ones were deliberately dropped from core.
   - No confidence levels, and nothing about untested code or dynamic calls.
   - It is PHPUnit-only, at PHP 8.4+ and PHPUnit 13+, which excludes most current Laravel 11/12 apps.
5. **Ideas to borrow.**
   - Git-keyed cache location outside the repo.
   - The structural vs environmental invalidation split.
   - A `Resolver` extension interface: given a changed path with no known edge, return
     affected tests. This is a good model for our Laravel-specific mappings.
   - Merging new coverage into existing edges instead of replacing them, so skipped tests
     don't lose their dependencies.
   - Our difference: we store edges at method level in SQLite and layer the Laravel
     entry points (routes, jobs, events, commands) on top.

## Existing tools found by web search (2026-11-04) — first look from search snippets

The idea is **not unique**. Several tools already cover parts of it:

| Tool | What it claims | Requirements seen |
|---|---|---|
| `sandermuller/richter` | Static Laravel code graph (routes, controllers, jobs, listeners, policies, Blade); `richter:impact` per symbol; `richter:affected-tests` for a diff; reports UNRESOLVED instead of a false "no impact"; falls back to the full suite when selection can't be trusted; MCP tools | PHP ^8.4, Laravel 12/13 |
| `hellpat/watson` | Dev CLI: which routes/commands/jobs/handlers/tests a git diff reaches (Symfony + Laravel); follows imports, `new`, static calls, type hints; depth limit | not checked |
| `hbenabdallah/phpgraph` | Call graph with type inference; `impact_of` for a class/method incl. tests to run; CLI + MCP | not checked |
| `nexvia-solutions/mapin` | Laravel code graph, `mapin:impact`; needs `laravel/mcp` | pre-release dependency |
| Pest 5 `--tia` | Coverage-based test selection with replay of cached results | PHP 8.4+, Laravel plugin needs framework ^13.23 |
| `gosuperscript/phpunit-tia` | Coverage-based test selection for PHPUnit | not checked |

Source-level findings are in the next section.

## Richter, Watson, phpgraph: source read (2026-11-04)

Installed in a throwaway project (platform check ignored; read only, not run). Versions: Richter
0.73, Watson 0.7, phpgraph 2.0.

| | Richter | Watson | phpgraph |
|---|---|---|---|
| Runtime needed | PHP ^8.4, Laravel 12/13 | PHP ^8.4, Symfony + Laravel | PHP >=8.5 (or a standalone binary) |
| Built on | Laravel Brain (`laramint/laravel-brain`) | own introspection of the booted app | own graph on nikic/php-parser |
| Granularity | member (method) level | method level; comment/whitespace-only edits dropped | class and method |
| Confidence | UNRESOLVED flagged per changed file; risk levels and "hazards" (e.g. `$fillable` gained a field) | none seen | EXTRACTED / INFERRED / AMBIGUOUS on every relation |
| Tests | `TestReferenceIndex`: regex scan of `tests/` for URIs, route names, command names, imports. Its docblock: *"Reference-based, not coverage-based, by design ... a reference is a weaker claim than coverage"* | lists tests reached | static: tests using a node are counted |
| Runtime data | none (static only) | none | none |
| Extras | `trace`, `affected-tests`, markdown + HTML report, MCP server for agents | output formats text/md/json/tok (token-optimised for LLM pipes); diff is piped in on stdin | MCP-first for AI agents; DDD/hexagonal layer rules; also Symfony, PrestaShop, **WordPress hooks**, plain PHP |

What this means for BlastRadius:

1. **Not unique:** confidence labels, method-level impact, markdown PR output, test selection and
   MCP/agent integration all exist already. We must not pitch any of these as our difference.
2. **Real gaps we can own (verified in source):**
   - **Real coverage.** None of the three uses per-test coverage; Richter says so explicitly and
     only matches names. "Affected AND actually untested" backed by pcov data is open.
   - **Older runtimes.** All three need PHP 8.4+ (phpgraph 8.5). PHP 8.2/8.3 and Laravel 11 apps
     are not served. This gap closes over time as people upgrade.
   - **Runtime evidence in the graph.** All three are static only. Tying slow routes, failing and
     heavy queries to code symbols is open (roadmap v0.4).
   - **Legacy / WordPress as a developer tool.** phpgraph reads WordPress hooks, but as a graph for
     AI agents. A developer-facing impact tool for plain/legacy PHP and WordPress is not covered.
3. **Ideas worth borrowing:** Richter's hazard idea (flagging risky kinds of change, such as
   guard removed or mass-assignment widened), `trace` between two symbols, an HTML report;
   Watson's diff-on-stdin design (no git dependency) and dropping comment-only edits at the AST
   layer; phpgraph's three-level confidence naming and standalone binary.
4. **Risk to watch:** Richter is actively developed (0.73) and could add coverage later. Our lead
   on coverage is real but not permanent, so ship it early.

## Larastan v3.14: how it boots Laravel (read 2026-11-04)

1. **It does not boot the host app's own `bootstrap/app.php`.** `ApplicationResolver::resolve()`
   builds an application with **Orchestra Testbench** (`Testbench::create`), with package
   discovery enabled, and builds the package manifest if `bootstrap/cache/packages.php` is missing.
   An optional `testbench.yaml` customises it.
2. **Vendor-path hack.** Testbench's skeleton has its own `vendor/`. Larastan deletes it and
   symlinks it to the project's real vendor dir (or uses Testbench's `CreateVendorSymlink` when
   available). It also deletes a stale `bootstrap/cache/packages.php`. This mutates files on disk.
3. **Why it matters for us.** Larastan needs a booted container to learn about facades, model
   properties and so on, but never runs the real app. BlastRadius will run as an artisan command
   inside the already-booted host app (Phase 3), so we need no Testbench trick there.
   Testbench stays right for our own package tests, and a standalone `vendor/bin/blast` run
   would need a Larastan-style bootstrap or must degrade to static-only.

## Deptrac v4.7: AST collector and class references (read 2026-11-04)

1. **Parsing.** `NikicPhpParser` uses nikic/php-parser with a `NameResolver` visitor, then a
   second visitor (`NikicFileReferenceVisitor`) that hands every node to small per-node
   *extractors*: `NewExtractor`, `StaticCallExtractor`, `TraitUseExtractor`, `CatchExtractor`,
   `InstanceofExtractor`, `PropertyExtractor`, `ClassMethodExtractor`, and so on. An optional
   PHPStan-based parser also exists. Parsed results are cached per file.
2. **Edge types.** `DependencyType` has `NEW`, `STATIC_METHOD`, `PARAMETER`, `RETURN_TYPE`,
   `ATTRIBUTE`, `CATCH`, `THROW`, `INSTANCEOF`, `USE`, `CONST`, `VARIABLE` and anonymous-class
   variants. It also records `UNRESOLVED_FUNCTION_CALL`, so unresolved calls are tracked.
3. **Gap.** Edges are class-level ("A depends on B"). There is no instance-call resolution
   (`$this->repo->save()`) and no method-to-method edge, which is exactly the gap our call graph fills.
4. **Ideas to borrow.**
   - One small extractor class per node type, so each resolution rule is testable on its own.
   - A per-file result cache keyed by file, matching our mtime/hash rebuild.
   - Recording unresolved things explicitly, matching our UNRESOLVED level.
