<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe a rota a Administrador e Operador.
 *
 * Usado nas solicitacoes de transporte, que trazem dados de saude de pacientes:
 * o perfil de consulta nao ve.
 */
class GarantirPerfilEditor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->podeEditar()) {
            abort(403, 'Esta área é restrita à coordenação.');
        }

        return $next($request);
    }
}
