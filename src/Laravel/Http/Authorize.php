<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Http;

use BlastRadius\Laravel\UiAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(UiAccess::allows($request), 403, 'The BlastRadius UI is only open to this machine and private networks. To allow other addresses, see UiAccess in config/blastradius.php.');

        return $next($request);
    }
}
