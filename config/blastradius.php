<?php

return [

    /*
    | BLASTRADIUS_ENABLED decides:
    |   unset  -> on once installed, except when the environment is "production"
    |   true   -> on, in any environment (this is how you check production on purpose)
    |   false  -> off, in any environment: nothing is registered
    */
    'enabled' => env('BLASTRADIUS_ENABLED'),

    // Folders to analyse, relative to the project root.
    'paths' => ['app', 'routes', 'database', 'config'],

    // Relative paths or fnmatch patterns to skip.
    'excludes' => [],

    // An untyped `$x->m()` links to every class declaring m() as POSSIBLE, up to this many.
    'possible_fan_out' => 10,

    // Project root to analyse; null means the application base path.
    'root' => null,

    // Graph cache file; null means storage/framework/cache/blastradius/graph.sqlite.
    'cache' => null,

    /*
    | The browser UI at /blast-radius, served by your app (like Telescope). Left unset (null) it
    | follows the switch above. Who may open it is decided by UiAccess::using(fn ($request) => ...);
    | by default this machine and private networks (Docker, LAN) may, public addresses may not.
    */
    'ui' => [
        'enabled' => env('BLASTRADIUS_UI'),
        'path' => env('BLASTRADIUS_PATH', 'blast-radius'),
        'middleware' => ['web'],
        'depth' => 6,
    ],

    /*
    | Live request tracing: every request is recorded (URL, the page that made it, time, queries
    | with rows and failures, errors, response size) and the functions in `paths` are timed, by
    | loading an instrumented copy of those classes. Left unset (null) it follows the UI.
    */
    'runtime' => [
        'enabled' => env('BLASTRADIUS_TRACE'),
        'paths' => ['app'],
        'except' => [],          // request paths to ignore, e.g. ['up', 'horizon/*']
        'keep' => 300,           // how many requests to remember
        'store' => null,         // null: storage/framework/cache/blastradius/requests.sqlite
    ],

];
