<?php

namespace Phnxdgtl\CtrlClient;

use Closure;
use Illuminate\Http\Request;

class CtrlToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {

        $key   = env('CTRL_KEY');
        $token = $request->bearerToken();
        if (!$key || $token != $key) {
            return response()->json('Unauthorized', 401);
        }

        return $next($request);
    }
}
