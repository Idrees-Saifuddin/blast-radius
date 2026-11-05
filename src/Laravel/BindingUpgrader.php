<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Static\Hierarchy;

/**
 * Uses what the container knows to sharpen edges the source alone could only guess:
 *
 * - a facade call (`Purchases::recalc()`) becomes a CONFIRMED call to the bound class;
 * - a call on an interface type has one POSSIBLE edge per implementation; the one the
 *   container binds the interface to is upgraded to CONFIRMED.
 *
 * Applied on read, so the stored edges never go stale when bindings change.
 */
final class BindingUpgrader
{
    /**
     * @param  list<Edge>  $edges
     * @param  array<string, string>  $bindings  abstract => concrete
     * @param  array<string, string>  $facades  facade class => concrete
     * @return list<Edge>
     */
    public static function apply(array $edges, array $bindings, array $facades, Hierarchy $hierarchy): array
    {
        $confirmedTargets = [];

        foreach ($edges as $edge) {
            if ($edge->confidence === Confidence::Confirmed) {
                $confirmedTargets[self::site($edge)][] = $edge->to;
            }
        }

        $result = [];

        foreach ($edges as $edge) {
            $target = self::splitTarget($edge->to);

            if ($target !== null && $edge->kind === EdgeKind::StaticCalls && isset($facades[$target[0]])) {
                $resolved = $hierarchy->resolveMethod($facades[$target[0]], $target[1]);

                if ($resolved !== null) {
                    $result[] = new Edge($edge->from, $resolved, $edge->kind, Confidence::Confirmed, $edge->file, $edge->line);
                }

                continue;
            }

            if ($edge->confidence === Confidence::Possible && $target !== null && self::boundImplementation($edge, $target, $bindings, $hierarchy, $confirmedTargets)) {
                $edge = new Edge($edge->from, $edge->to, $edge->kind, Confidence::Confirmed, $edge->file, $edge->line);
            }

            $result[] = $edge;
        }

        return $result;
    }

    /**
     * @return array{string, string}|null `Type::method` split into its two parts
     */
    private static function splitTarget(string $to): ?array
    {
        $split = strrpos($to, '::');

        return $split === false ? null : [substr($to, 0, $split), substr($to, $split + 2)];
    }

    /**
     * @param  array{string, string}  $target
     * @param  array<string, string>  $bindings
     * @param  array<string, list<string>>  $confirmedTargets
     */
    private static function boundImplementation(Edge $edge, array $target, array $bindings, Hierarchy $hierarchy, array $confirmedTargets): bool
    {
        [$type, $method] = $target;

        // The same call site must also have a CONFIRMED edge to a bound abstract's method:
        // that is how we know this POSSIBLE edge is an implementation of it.
        foreach ($confirmedTargets[self::site($edge)] ?? [] as $confirmed) {
            $abstractTarget = self::splitTarget($confirmed);

            if ($abstractTarget === null || strtolower($abstractTarget[1]) !== strtolower($method)) {
                continue;
            }

            $concrete = $bindings[$abstractTarget[0]] ?? null;

            if ($concrete !== null && $type !== $abstractTarget[0] && $hierarchy->resolveMethod($concrete, $method) === $edge->to) {
                return true;
            }
        }

        return false;
    }

    private static function site(Edge $edge): string
    {
        return $edge->from.'|'.$edge->file.'|'.$edge->line.'|'.$edge->kind->value;
    }
}
