<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StellarSimApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) $request->getUser();
        $password = (string) $request->getPassword();

        $expectedUser = (string) env('APPSETTING_API_USERNAME_STELLAR_SIM_API', '');
        $expectedPass = (string) env('APPSETTING_API_PASSWORD_STELLAR_SIM_API', '');

        $authorized = $expectedUser !== ''
            && $expectedPass !== ''
            && hash_equals($expectedUser, $username)
            && hash_equals($expectedPass, $password);

        if (! $authorized) {
            return response()
                ->json([
                    'response_code' => 401,
                    'response_message' => 'Unauthorized Sim API basic.',
                ], 401, [
                    'WWW-Authenticate' => 'Basic realm="Stellar Sim API"',
                ]);
        }

        return $next($request);
    }
}
