<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PropagateRequestId
{
    public const HEADER = 'X-Request-Id';

    public const CONTEXT_KEY = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get(self::HEADER) ?: (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);
        $request->attributes->set(self::CONTEXT_KEY, $requestId);

        Log::shareContext([self::CONTEXT_KEY => $requestId]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
