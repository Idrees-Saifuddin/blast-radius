# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [0.1.4] - 2026-11-06

### Added
- Live requests: a trace now says how complete it is. The request header shows "all N classes
  timed", or a warning "N classes not timed" that opens a list of the classes that ran without being
  timed and why (the rewriter could not read the file, its timed copy was invalid PHP, or the cache
  folder was not writable). Until now such a class was silently missing from the timeline, so a trace
  with gaps could pass for a complete one. Classes PHP loads while the app boots (service providers)
  cannot be timed in any app; they get a quiet note ("38 timed · 6 loaded at boot") and an optional
  list instead of a warning. Suggested by Alex Shev on DEV.

### Changed
- Files with nothing to time (interfaces, classes without methods) are remembered in the cache
  folder, so they are no longer parsed again on every request.

## [0.1.3] - 2026-11-06

### Fixed
- Live requests: a page that polls one endpoint (a notification counter, say) no longer pushes every
  other request out of the 300 that are remembered. At most `runtime.keep_per_route` (default 30)
  successful calls of one route are kept; failed requests are always kept until they age out.

### Added
- `SECURITY.md`: how to report a vulnerability privately.

## [0.1.2] - 2026-11-05

### Fixed
- Live requests: the right-hand details column now scrolls on its own, and "Slowest queries"
  shows up to 140 characters of each query (wrapped) with the full query on hover.

## [0.1.1] - 2026-11-05

### Fixed
- Live requests: in server-rendered apps (Blade, Livewire), a page opened from a link was listed
  as a call of the page you came from, because its `Referer` is the previous page. A full page
  load now starts its own page; fetch/XHR, Livewire, asset and iframe requests and form posts
  stay with the page that made them. Page loads are labelled "page load".

## [0.1.0] - 2026-11-05

First release.

### Added
- Static code graph for Laravel apps: classes, interfaces, traits, enums, methods and functions,
  with extends / implements / uses-trait edges and method resolution in PHP's real order.
- Call graph with a confidence on every edge: CONFIRMED (the code says so), POSSIBLE (a guess
  from names, capped), UNRESOLVED (dynamic calls, reported instead of dropped). Resolves `new`,
  static, `self`/`parent`/`static`, `$this`, typed properties and parameters, constructor-promoted
  properties, local variables, plain functions, `new static`, dispatched events and jobs, and
  controller methods that take a FormRequest.
- Laravel adapter: routes (closures included), container bindings, facades, events and listeners,
  jobs, commands and the schedule, read from the booted app without running any of it.
- `php artisan blast`: what a git diff can break (affected entry points, signature changes) and the
  tests to run, as text, JSON or markdown, with `--fail-on=confirmed-untested` for CI.
- `php artisan blast:coverage`: runs the test suite once with pcov and records which test executes
  which method; refuses to run unless the tests use a throwaway database.
- `php artisan blast:graph`, `blast:report` (a self-contained HTML report) and the standalone
  `vendor/bin/blast` with `graph:build` and `blast:hierarchy`.
- Browser UI at `/blast-radius` (on once installed; in production only with `BLASTRADIUS_ENABLED=true`): code flow diagrams, and a
  **Live requests** view with pages, API calls, a function timeline, queries with rows, time and
  failures, exceptions and the function that failed. No PHP extension required.
- SQLite graph cache with incremental rebuilds.

### Safety
- On once installed; `BLASTRADIUS_ENABLED=false` switches it off. In production it stays off
  unless `BLASTRADIUS_ENABLED=true` is set.
- The page at `/blast-radius` is open to this machine and private networks by default; public
  addresses are refused unless `UiAccess::using()` says otherwise.

### Known limits
- Dynamic code, untyped calls and Eloquent magic are reported as POSSIBLE or UNRESOLVED.
- Model observers, policies and Eloquent relationships are not analysed yet.
- Pest is not tested yet; rows per query are not counted for `cursor()` and `lazy()`.
- Requires PHP 8.1 to 8.4 and Laravel 8 to 12 (see the README for the tested combinations).
