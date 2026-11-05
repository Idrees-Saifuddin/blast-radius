<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use Closure;
use Illuminate\Http\Request;

/**
 * Decides who may open the browser UI. By default this machine and private networks (127.x,
 * 10.x, 172.16-31.x, 192.168.x, and their IPv6 equivalents, which covers Docker and a LAN) may,
 * and public internet addresses may not, so a staging server is not exposed by accident.
 * To allow others, call this from a service provider:
 *
 *     UiAccess::using(fn (Request $request) => $request->user()?->isAdmin() === true);
 */
final class UiAccess
{
    /** @var (Closure(Request): bool)|null */
    private static ?Closure $callback = null;

    /**
     * @param  Closure(Request): bool  $callback
     */
    public static function using(Closure $callback): void
    {
        self::$callback = $callback;
    }

    public static function reset(): void
    {
        self::$callback = null;
    }

    public static function allows(Request $request): bool
    {
        return self::$callback !== null ? (bool) (self::$callback)($request) : self::isPrivate($request->ip());
    }

    private static function isPrivate(?string $ip): bool
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Valid, but not a public address: loopback, private or reserved ranges.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
