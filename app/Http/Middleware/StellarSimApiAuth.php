<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StellarSimApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $username = $request->getUser();
        $password = $request->getPassword();

        $expectedUser = env('APPSETTING_API_USERNAME_STELLAR_SIM_API');
        $expectedPass = env('APPSETTING_API_PASSWORD_STELLAR_SIM_API');

        if (
            empty($expectedUser) ||
            empty($expectedPass) ||
            $username !== $expectedUser ||
            $password !== $expectedPass
        ) {
            return response()
                ->json([
                    'response_code'    => 401,
                    'response_message' => 'Unauthorized Sim API basic.',
                ], 401, [
                    'WWW-Authenticate' => 'Basic realm="Stellar Sim API"',
                ]);
        }

        return $next($request);
    }
}
