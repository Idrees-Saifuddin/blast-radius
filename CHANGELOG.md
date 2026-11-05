# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

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
