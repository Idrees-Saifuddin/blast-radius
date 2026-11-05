<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Core\ClosureName;
use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Static\Hierarchy;
use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * Reads what the booted Laravel app knows and the source code cannot say: routes, container
 * bindings, facades, event listeners, commands and the schedule. Nothing is executed beyond
 * what booting does: no route, job, listener or command runs, and no binding is resolved.
 */
final class AppIntrospector
{
    private const SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';

    private const DISPATCHABLE = 'Illuminate\\Foundation\\Bus\\Dispatchable';

    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, Edge> */
    private array $edges = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Application $app,
        private readonly Hierarchy $hierarchy,
        private readonly string $root,
    ) {}

    public function introspect(): AppGraph
    {
        $bindings = $this->guard('container', fn () => $this->bindings()) ?? [];
        $facades = $this->guard('facades', fn () => $this->facades($bindings)) ?? [];

        $this->guard('routes', fn () => $this->routes());
        $listenerClasses = $this->guard('events', fn () => $this->events()) ?? [];
        $this->guard('jobs', fn () => $this->jobs($listenerClasses));
        $commands = $this->guard('commands', fn () => $this->commands()) ?? [];
        $this->guard('schedule', fn () => $this->schedule($commands));

        return new AppGraph(array_values($this->nodes), array_values($this->edges), $bindings, $facades, $this->warnings);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function guard(string $part, callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            $this->warnings[] = "{$part}: {$e->getMessage()}";

            return null;
        }
    }

    private function routes(): void
    {
        $router = $this->app->make(Router::class);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $handler = $this->handlerName($route->getAction('uses'), '__invoke');

            if ($handler === null) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD'])) ?: $route->methods();
            $name = 'route '.implode('|', $methods).' /'.ltrim($route->uri(), '/');

            $this->node(NodeKind::Route, $name);
            $this->edge($name, $handler, EdgeKind::RoutesTo);
        }
    }

    /**
     * @return array<string, string> abstract => concrete class, for class-to-class bindings
     */
    private function bindings(): array
    {
        $this->app->loadDeferredProviders();

        $bound = [];

        foreach ($this->app->getBindings() as $abstract => $binding) {
            $concrete = $binding['concrete'] ?? null;

            if (! $concrete instanceof Closure) {
                continue;
            }

            // Container::getClosure() wraps `bind($abstract, $class)` in a closure that captures both.
            $captured = (new ReflectionFunction($concrete))->getStaticVariables();

            if (is_string($captured['concrete'] ?? null) && $captured['concrete'] !== $abstract) {
                $bound[$abstract] = $captured['concrete'];
            }
        }

        $resolved = [];

        foreach ($bound as $abstract => $concrete) {
            for ($hops = 0; $hops < 5 && isset($bound[$concrete]); $hops++) {
                $concrete = $bound[$concrete];
            }

            $resolved[$abstract] = $concrete;

            if ($this->hierarchy->knows($abstract) || $this->hierarchy->knows($concrete)) {
                $this->edge($abstract, $concrete, EdgeKind::ResolvesTo);
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $bindings
     * @return array<string, string> facade class => concrete class
     */
    private function facades(array $bindings): array
    {
        $facades = [];

        foreach ($this->hierarchy->typeNames() as $type) {
            if (! $this->hierarchy->isFacade($type) || ! class_exists($type)) {
                continue;
            }

            $accessor = (new ReflectionMethod($type, 'getFacadeAccessor'))->invoke(null);

            if (! is_string($accessor)) {
                continue;
            }

            $concrete = $bindings[$accessor] ?? (class_exists($accessor) ? $accessor : null);

            if ($concrete !== null) {
                $facades[$type] = $concrete;
                $this->edge($type, $concrete, EdgeKind::ResolvesTo);
            }
        }

        return $facades;
    }

    /**
     * @return array<string, true> classes that are registered as listeners
     */
    private function events(): array
    {
        $classes = [];

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');

        // getRawListeners() exists from Laravel 9. Laravel 8 keeps each listener wrapped in a closure
        // in a protected property, so read it from there and unwrap it.
        if (method_exists($dispatcher, 'getRawListeners')) {
            /** @var array<string, array<int, mixed>> $raw */
            $raw = $dispatcher->getRawListeners();
        } else {
            $raw = [];

            /** @var array<string, array<int, mixed>> $wrapped */
            $wrapped = (fn () => $this->listeners)->call($dispatcher);

            foreach ($wrapped as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $raw[$event][] = self::unwrapLegacyListener($listener);
                }
            }
        }

        foreach ($raw as $event => $listeners) {
            foreach ($listeners as $listener) {
                $handler = $this->handlerName($listener, 'handle');

                if ($handler === null) {
                    continue;
                }

                if (! $this->hierarchy->declares($event)) {
                    $this->node(NodeKind::Event, $event);
                }

                $entry = 'listener '.$handler;
                $this->node(NodeKind::Listener, $entry);
                $this->edge($event, $handler, EdgeKind::Listens);
                $this->edge($entry, $handler, EdgeKind::Handles);

                $classes[explode('::', $handler)[0]] = true;
            }
        }

        return $classes;
    }

    /**
     * @param  array<string, true>  $listenerClasses
     */
    private function jobs(array $listenerClasses): void
    {
        foreach ($this->hierarchy->typeNames() as $type) {
            $ancestors = $this->hierarchy->ancestors($type);

            if (isset($listenerClasses[$type]) || ! (in_array(self::SHOULD_QUEUE, $ancestors, true) || in_array(self::DISPATCHABLE, $ancestors, true))) {
                continue;
            }

            $handler = $this->hierarchy->resolveMethod($type, 'handle');

            if ($handler === null) {
                continue;
            }

            $entry = 'job '.$type;
            $this->node(NodeKind::Job, $entry);
            $this->edge($entry, $handler, EdgeKind::Handles);
            // Dispatching the class reaches its handler: callers -> class -> handle().
            $this->edge($type, $handler, EdgeKind::Handles);
        }
    }

    /**
     * @return array<string, string> command name => handler
     */
    private function commands(): array
    {
        $handlers = [];

        foreach ($this->app->make(Kernel::class)->all() as $name => $command) {
            $callback = $command instanceof ClosureCommand ? (fn () => $this->callback)->call($command) : null;
            $handler = $callback instanceof Closure
                ? $this->handlerName($callback, '')
                : $this->handlerName([$command::class, 'handle'], 'handle');

            if ($handler === null) {
                continue;
            }

            $entry = 'command '.$name;
            $this->node(NodeKind::Command, $entry);
            $this->edge($entry, $handler, EdgeKind::Handles);
            $handlers[$name] = $handler;
        }

        return $handlers;
    }

    /**
     * @param  array<string, string>  $commands
     */
    private function schedule(array $commands): void
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $handler = null;

            if ($event instanceof CallbackEvent) {
                $handler = $this->handlerName((fn () => $this->callback)->call($event), '__invoke');
            } elseif (preg_match('/artisan[\'"]?\s+([^\s\'"]+)/', (string) $event->command, $match) === 1) {
                $handler = $commands[$match[1]] ?? null;
            }

            if ($handler === null) {
                continue;
            }

            $entry = 'schedule '.$event->expression.' '.$handler;
            $this->node(NodeKind::Schedule, $entry);
            $this->edge($entry, $handler, EdgeKind::Handles);
        }
    }

    /**
     * Name of the graph node that runs when `$callable` is invoked, or null when it is not
     * code from this project (framework and vendor handlers are ignored).
     */
    private function handlerName(mixed $callable, string $defaultMethod): ?string
    {
        if ($callable instanceof Closure) {
            return $this->closureNode(new ReflectionFunction($callable));
        }

        if (is_string($callable)) {
            [$class, $method] = Str::parseCallback($callable, $defaultMethod);
        } elseif (is_array($callable) && count($callable) === 2) {
            [$class, $method] = [is_object($callable[0]) ? $callable[0]::class : (string) $callable[0], (string) $callable[1]];
        } elseif (is_object($callable)) {
            [$class, $method] = [$callable::class, $defaultMethod];
        } else {
            return null;
        }

        $class = ltrim((string) $class, '\\');

        return ($method !== null && $method !== '' ? $this->hierarchy->resolveMethod($class, $method) : null)
            ?? ($defaultMethod === 'handle' ? $this->hierarchy->resolveMethod($class, '__invoke') : null);
    }

    /**
     * Laravel 8 stores `listen(Event, Listener)` as a closure that captures `$listener` and
     * `$wildcard`; the first is what was registered.
     */
    private static function unwrapLegacyListener(mixed $listener): mixed
    {
        if (! $listener instanceof Closure) {
            return $listener;
        }

        $captured = (new ReflectionFunction($listener))->getStaticVariables();

        return array_key_exists('listener', $captured) && array_key_exists('wildcard', $captured) ? $captured['listener'] : $listener;
    }

    private function closureNode(ReflectionFunction $closure): ?string
    {
        $file = $closure->getFileName();

        if ($file === false || ! str_starts_with($file, rtrim($this->root, '/').'/') || str_contains($file, '/vendor/')) {
            return null;
        }

        $name = ClosureName::for($this->root, $file, (int) $closure->getStartLine());
        $this->nodes[NodeKind::Closure->value.' '.$name] ??= new Node(NodeKind::Closure, $name, $file, (int) $closure->getStartLine(), (int) $closure->getEndLine());

        return $name;
    }

    private function node(NodeKind $kind, string $name): void
    {
        $this->nodes[$kind->value.' '.$name] ??= new Node($kind, $name, '', 0, 0);
    }

    private function edge(string $from, string $to, EdgeKind $kind): void
    {
        $this->edges[$from.'|'.$to.'|'.$kind->value] ??= new Edge($from, $to, $kind, Confidence::Confirmed, '', 0);
    }
}
