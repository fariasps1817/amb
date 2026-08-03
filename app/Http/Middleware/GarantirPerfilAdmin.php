<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe a rota ao perfil de administrador.
 *
 * Usado na gestao de usuarios do sistema.
 */
class GarantirPerfilAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->ehAdmin()) {
            abort(403, 'Esta área é restrita ao administrador do sistema.');
        }

        return $next($request);
    }
}
