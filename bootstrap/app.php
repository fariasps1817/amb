<?php

use App\Http\Middleware\GarantirPerfilAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => GarantirPerfilAdmin::class,
        ]);

        // Visitante sem sessao vai para a tela de login do sistema.
        $middleware->redirectGuestsTo('/entrar');
        $middleware->redirectUsersTo('/painel');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * O "419 - Page Expired" aparece de forma intermitente na montagem da
         * escala, em cerca de uma a cada dez chamadas do Livewire, sem que a
         * sessao tenha expirado. O Laravel nao registra essa excecao, entao a
         * falha nao deixava rastro nenhum para investigar.
         *
         * Dois detalhes atrapalham quem tenta anotar isso:
         *
         *   - TokenMismatchException esta numa lista interna de excecoes que o
         *     Laravel nunca reporta, entao um report() jamais seria chamado;
         *   - prepareException() ja a converteu em HttpException(419) antes de
         *     consultar os ganchos de renderizacao.
         *
         * Por isso o gancho e de renderizacao e recebe HttpException.
         *
         * Aqui anotamos o suficiente para comparar o token recebido com o da
         * sessao. Sao gravados apenas os primeiros caracteres de cada um: o
         * token e credencial, e o log nao e lugar para guardar credencial
         * inteira.
         */
        $exceptions->render(function (HttpException $e, Request $requisicao) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $sessao = $requisicao->hasSession() ? $requisicao->session() : null;

            $inicio = fn (?string $valor) => $valor === null || $valor === ''
                ? '(vazio)'
                : substr($valor, 0, 8).'…';

            Log::warning('CSRF recusado', [
                'rota' => $requisicao->path(),
                'token_recebido' => $inicio($requisicao->input('_token')),
                'token_no_cabecalho' => $inicio($requisicao->header('X-CSRF-TOKEN')),
                'token_da_sessao' => $inicio($sessao?->token()),
                'sessao_id' => $inicio($sessao?->getId()),
                'sessao_veio_no_cookie' => $requisicao->hasCookie(config('session.cookie')),
                'sessao_recem_criada' => $sessao !== null && ! $sessao->has('_previous'),
                'autenticado' => auth()->id() ?? '(nao)',
                'corpo_recebido' => strlen((string) $requisicao->getContent()),
                'content_type' => $requisicao->header('Content-Type'),
            ]);

            // Devolver null deixa o Laravel seguir com a resposta 419 de
            // sempre: aqui so queremos anotar, nao mudar o comportamento.
            return null;
        });
    })->create();
