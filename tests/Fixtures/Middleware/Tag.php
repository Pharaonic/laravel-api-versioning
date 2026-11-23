<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appends its parameter to the X-Middleware response header, so tests see
 * which middleware ran and in which order.
 */
final class Tag
{
    public function handle(Request $request, Closure $next, string $tag = 'tag'): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $current = $response->headers->get('X-Middleware');
            $response->headers->set('X-Middleware', ($current === null ? '' : $current.',').$tag);
        }

        return $response;
    }
}
