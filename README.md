# BlastRadius

[![CI](https://github.com/Idrees-Saifuddin/blast-radius/actions/workflows/ci.yml/badge.svg)](https://github.com/Idrees-Saifuddin/blast-radius/actions/workflows/ci.yml)

**See what your Laravel code does, and what a change can break, in your browser.**

BlastRadius reads your application and shows it as a picture: which routes, jobs, listeners and
commands reach which functions. It tells you what a git diff can affect, which tests to run, and,
while you use your app locally, what every request actually did: which functions ran, how long they
took, which database queries they made, and what failed.

It is a development tool in the spirit of Telescope and Debugbar, with one difference: everything it
shows is connected to your code, so "this route is slow" and "this change affects that route" are
answers from the same place.

> **Status: v0.1, pre-release.** Everything below works and is tested. It is not on Packagist yet; see
> [Installing before the release](#installing-before-the-release).

## What you get

| | What it does | How you use it |
|---|---|---|
| **Live requests** | Shows each request your app handles, grouped by the page that made it, with a timeline of functions and queries, rows returned, errors and the function that failed. | Open `/blast-radius` and use your app |
| **Code flow** | Draws every route, job, listener, command and scheduled task as a diagram of the functions it reaches, with how sure BlastRadius is about each link. | The other tab of `/blast-radius` |
| **Change impact** | For a git diff: the changed methods, the entry points that can reach them, and the tests to run. | `php artisan blast --base=main` |

## Quick start

```bash
composer require --dev idrees-saifuddin/blast-radius
```

Then open **`http://your-app.test/blast-radius`** in a browser. That is all: no other setup, no PHP
extension, no config file.

- It is **on once installed**. Put `BLASTRADIUS_ENABLED=false` in `.env` to switch it off completely.
- In **production it stays off** unless you set `BLASTRADIUS_ENABLED=true`, so you can look at
  production on purpose and never by accident.
- Install it with `--dev` so a normal production deploy (`composer install --no-dev`) never ships it.

### Installing before the release

Until the package is on Packagist, point Composer at the repository:

```bash
composer config repositories.blastradius vcs https://github.com/Idrees-Saifuddin/blast-radius
composer require --dev idrees-saifuddin/blast-radius:dev-main
```

## Requirements

PHP 8.1 to 8.4, Laravel 8 to 12 and `ext-pdo_sqlite` (used for its cache; it is enabled by default in
the official PHP builds). The test suite runs in CI on all of these combinations:

| PHP | Laravel |
|---|---|
| 8.1 | 8, 9, 10 |
| 8.2 | 10, 11, 12 |
| 8.3 | 10, 11, 12 |
| 8.4 | 11, 12 |

PHP 8.0 and older are not supported. Laravel 8, 9 and 10 no longer receive security fixes from
Laravel itself; they are supported here because many projects still run them.

## Live requests

Open `/blast-radius` and use your application in another tab. Every request appears within a couple
of seconds.

- **Left: the pages you opened**, newest first (`/dashboard/products`, then `/dashboard`). Click a page
  to see the API calls it made, one line each: method, path, status, time. Hover a line for the
  queries, items and size.
- **Background traffic** (your frontend polling `GET /api/notifications/unread-count` every few
  seconds, say) is folded into a collapsed "Background" row inside each page, so it never buries the
  page you are looking at. Open it to see each polled route once with its count (`×40`), and click the
  count to list the individual calls.
- **Middle: the timeline of the call you clicked.** Each bar is a function of yours (blue) or a database
  query (green), placed by when it ran. Red means it failed.
- **Right: details of whatever you click.** For a function: its file and lines, its own time against the
  time of what it calls, and what it called. For a query: the SQL, how many rows came back or were
  changed, the time, whether it succeeded, and the line of your code that ran it. With nothing
  selected: what failed (the exception, the line of your code involved and the failing function), the
  slowest queries and functions, the directories your time went to, the models loaded and the files
  loaded.

```
▾ /dashboard/products                                  166 ms
    GET /api/products                    200    56 ms
    GET /api/accounts                    200    23 ms
    ▸ Background · 1 route · 8 calls
▸ /dashboard                                           1.4 s

DashboardController::index                                       180 ms
  DashboardService::totals                                       120 ms
    query  SELECT * FROM sales WHERE day > ?   120 returned       35 ms
```

A "How to read this" button in the sidebar explains the three panels.

**If your frontend and API are on different addresses** (page on `localhost:3000`, API on
`localhost`), browsers send only the site address in the `Referer` header, not the page. BlastRadius
then shows "(page unknown)" and a hint. Add this line inside `<head>` of your frontend's `index.html`
and the full page path is sent:

```html
<meta name="referrer" content="no-referrer-when-downgrade">
```

Alternatively your frontend can send an `X-BlastRadius-Page: /dashboard/products` header on API calls
(your CORS settings must allow that header).

## Code flow

The **Code flow** tab lists every route, job, listener, command and scheduled task. Pick one to see a
diagram of the functions it reaches, linked to each other. Click a function for its file and line,
who calls it, what it calls, and which tests executed it. Add a branch name next to "Highlight changes
since" (or `?base=main` to the URL) to highlight what a branch changed and flag the entry points it
affects.

Prefer a file you can send to someone? `php artisan blast:report --out=blast-report.html` writes the
same page as one self-contained HTML file (no server, nothing fetched from the internet).

## What a change can break

```bash
php artisan blast --base=main
```

compares your branch with `main` (the merge-base, like a pull request), finds the methods you changed,
and follows callers back to the routes, jobs, listeners, commands and scheduled tasks that can reach
them.

```
Changed: App\Services\SupplierService::update   (modified)

CONFIRMED
  route PUT|PATCH /api/suppliers/{supplier}  (depth 2)  [no test executes this path]

TESTS TO RUN (coverage map): 0
```

| Option | Meaning |
|---|---|
| `--base=main` | the branch or commit to compare with (default `main`) |
| `--format=text\|json\|markdown` | `markdown` is ready to paste into a pull request |
| `--fail-on=confirmed-untested` | exit with code 1 when a CONFIRMED entry point has no covering test (for CI) |
| `--working-tree` | include uncommitted changes |
| `--depth=8` | how many calls deep to look for entry points |

A change to a method's declaration (parameters, return type, visibility) is flagged separately,
because it can break callers.

### Which tests to run

`blast` can list the exact tests that execute the changed code once it knows which test runs which
method. That needs one run of your test suite with a coverage driver (pcov or Xdebug):

```bash
php artisan blast:coverage                  # whole suite
php artisan blast:coverage --filter=Order   # only tests with "Order" in the name
```

This step is optional; everything else works without it. It runs your real tests, so it first checks
that they are configured for a throwaway database (`:memory:` or a name containing "test" in
`phpunit.xml` or `.env.testing`) and refuses otherwise; `--force` overrides that. It can be slow on a
big suite: run it in CI on your main branch. PHPUnit is supported; Pest is not tested yet.

## All commands

| Command | What it does |
|---|---|
| `php artisan blast` | What a diff can break and the tests to run |
| `php artisan blast:coverage` | Records which test executes which method (optional) |
| `php artisan blast:graph` | Lists every entry point and what it reaches |
| `php artisan blast:report` | Writes the code flow page as a standalone HTML file |
| `vendor/bin/blast graph:build` | Builds the graph cache without booting the app (code structure only) |
| `vendor/bin/blast blast:hierarchy [Class]` | Prints class hierarchies (extends, implements, traits) |

The Artisan commands accept `--path`, `--paths=app,routes` and `--db` to change what is analysed and
where the cache lives.

## Understanding the results

Every link BlastRadius draws has a confidence:

| Label | Meaning | Example |
|---|---|---|
| **CONFIRMED** | The code says so directly. | `$this->service->update()` where `$service` is typed `SupplierService` |
| **POSSIBLE** | A guess. Several things could be meant. | `$x->save()` with an unknown `$x`: every class with a `save()` method is listed |
| **UNRESOLVED** | Too dynamic to follow, but reported instead of hidden. | `$this->$method()`, `call_user_func`, `new $class` |

A path is only as certain as its weakest link. `depth` is the number of calls from the entry point
(`depth 2` means the route calls a controller that calls this function).

## Configuration

Almost nothing needs configuring. These environment variables exist:

| Variable | Effect |
|---|---|
| `BLASTRADIUS_ENABLED` | `false` switches everything off. Unset: on, except in production. `true`: on everywhere, production included. |
| `BLASTRADIUS_UI` | `false` removes the page but keeps the commands. |
| `BLASTRADIUS_TRACE` | `false` keeps the page but stops recording live requests. |
| `BLASTRADIUS_PATH` | URL of the page (default `blast-radius`). |

For more, publish the config file: `php artisan vendor:publish --tag=blastradius-config`. It lets you
change the folders analysed (`paths`, `excludes`), the folders whose functions are timed
(`runtime.paths`, default `app`), request paths to ignore (`runtime.except`), how many requests to
keep (`runtime.keep`, default 300), the page's middleware, and where the cache is stored.

## Safe on live sites

- **Off with one variable.** `BLASTRADIUS_ENABLED=false` and it registers nothing: no commands, no page,
  no middleware, no listeners.
- **Production needs a yes.** With the variable unset it is on everywhere except production.
- **Who can open `/blast-radius`.** By default this machine and private networks (127.x, 10.x,
  172.16-31.x, 192.168.x; that covers Docker and a LAN). Public internet addresses get a 403, so a
  staging server is not exposed by accident. To change that, in a service provider:

  ```php
  use BlastRadius\Laravel\UiAccess;

  UiAccess::using(fn ($request) => $request->user()?->isAdmin() === true);
  ```
- The page can show your code structure and the SQL your app runs. Treat it like Telescope: it is for
  development and staging machines.
- **Query values are never stored**, only how many were bound. Error messages are stored as the
  database reports them. Requests are kept in `storage/framework/cache/blastradius/` (the last 300).

## How it works

1. **It reads your code** (with a PHP parser, never executing it) and builds a graph: classes, methods,
   and who calls whom, resolved through typed properties, parameters, constructor injection, traits
   and parent classes.
2. **It asks your booted app** for what source code cannot say: routes, container bindings, facades,
   event listeners, jobs, commands and the schedule. Nothing is run beyond what booting does.
3. **While you use the app locally,** it loads an instrumented copy of the classes in `app/`: every
   method is wrapped with timing code on the same lines, so errors still point at the right line (if a
   file cannot be rewritten, the original is used). It swaps the database connection class to count
   rows and catch failures, and a middleware records each request after the response has been sent.
   No PHP extension is needed.
4. The graph is cached in SQLite and rebuilt incrementally: a 349-file app takes under a second the
   first time and about 0.02 s when nothing changed.

## Troubleshooting

| You see | Why and what to do |
|---|---|
| `404` at `/blast-radius` | It is switched off or you are in production: check `BLASTRADIUS_ENABLED` and `APP_ENV`; run `php artisan route:clear` if you cache routes. |
| `403` at `/blast-radius` | Your address is not a private one (see "Safe on live sites"). Add a `UiAccess::using()` rule. |
| "(page unknown)" in the sidebar | See "If your frontend and API are on different addresses" above. |
| No requests appear | Tracing runs only for web requests, in the folders under `runtime.paths`, and not for requests to `/blast-radius` itself. Check `BLASTRADIUS_TRACE`. |
| "0 tests recorded" | You have not run `php artisan blast:coverage`. It is optional. |
| `blast:coverage` says no coverage file | Install pcov or enable Xdebug for the PHP that runs your tests, and check that `vendor/bin/phpunit` runs on its own. |
| Composer conflict on `nikic/php-parser` | An old dev tool pins version 4; BlastRadius needs 5. Upgrade or remove the old tool. |
| "could not find driver" on the page | PHP needs `pdo_sqlite` (the official PHP images include it). |
| Using Docker | Run the commands inside your PHP container. The page is reachable from your host because Docker's network addresses are private. |

## Limitations

BlastRadius is static analysis plus what it observes. It tells you where to look; it is not a proof
that nothing else breaks.

- **Dynamic code is reported, not followed:** `$this->$method()`, `call_user_func`, `new $class`,
  string callables appear as UNRESOLVED.
- **Untyped calls are guesses** (POSSIBLE), up to a limit. Methods the framework calls itself (a cast's
  `get()`, a resource's `toArray()`) are not guessed from names.
- **Not covered yet:** model observers, policies, Eloquent relationships, contextual and closure
  container bindings, and edits to the routes files themselves. Controller methods that take a
  FormRequest are linked to its `rules()` and `authorize()`.
- **Change detection:** a method renamed in place shows as one deleted and one added method; a
  signature change is detected by a heuristic (the declaration line was edited).
- **Run `blast` on a clean checkout of the branch** (or pass `--working-tree`): the graph is built from
  the files on disk.
- **Live tracing:** only your own code is timed (not Laravel or vendor code); constructors and other
  magic methods are not timed; rows per query are counted for `select`, `update` and `delete` through
  Laravel's connection but not for `cursor()` or `lazy()`; it assumes one request at a time per
  process (PHP-FPM, `php artisan serve`), not Octane.
- **Tested on** a ~350-file Laravel app; very large codebases are untested.

How it compares to similar tools: [docs/comparison.md](docs/comparison.md).

## Roadmap

A GitHub Action that comments the impact report on pull requests; Pest support; observers and
policies. Support for WordPress, older PHP and other languages is planned as separate projects that
share the same ideas.

## Development

The project is developed and tested inside Docker; nothing needs to be installed locally.

```bash
make build      # build the PHP image
make install    # composer install
make test       # PHPUnit
make stan       # PHPStan (level 8)
make fix        # Pint (code style)
```

The browser pages are also tested in a simulated browser: `php tests/Ui/make-samples.php /tmp/s`, then
`node tests/Ui/smoke.js /tmp/s` and `node tests/Ui/live-smoke.js /tmp/s` (see the comments at the top
of those files). See [CHANGELOG.md](CHANGELOG.md) for what changed in each version.

## License

MIT, see [LICENSE](LICENSE).
