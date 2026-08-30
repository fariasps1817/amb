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
         * O "419 - Page Expired" nem sempre significa pagina expirada.
         *
         * Quando APP_DEBUG esta desligado, o Livewire captura qualquer
         * TypeError vindo de um metodo de componente e responde abort(419)
         * -- ver HandleRequests::handleUpdate(). O usuario le "sua sessao
         * expirou" e o erro de verdade nao deixa rastro nenhum.
         *
         * Foi assim que um parametro tipado como ?int, recebendo a string
         * vazia de um <select>, passou por falha de sessao durante dias.
         *
         * Este gancho separa os dois casos: se o token confere, a sessao esta
         * boa e o 419 esconde um erro de codigo. Do token so guardamos uma
         * assinatura curta -- token e credencial, e log nao e lugar de
         * guardar credencial.
         *
         * Precisa ser gancho de renderizacao e receber HttpException: o
         * Laravel nunca reporta TokenMismatchException, e prepareException()
         * ja a converteu em HttpException(419) antes de consultar os ganchos.
         */
        $exceptions->render(function (HttpException $e, Request $requisicao) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $sessao = $requisicao->hasSession() ? $requisicao->session() : null;
            $recebido = $requisicao->input('_token') ?: $requisicao->header('X-CSRF-TOKEN');
            $daSessao = $sessao?->token();

            $tokenConfere = is_string($recebido)
                && is_string($daSessao)
                && hash_equals($daSessao, $recebido);

            Log::warning($tokenConfere
                ? '419 com token valido: erro de componente mascarado pelo Livewire'
                : '419 por token de sessao invalido', [
                    'rota' => $requisicao->path(),
                    'token_confere' => $tokenConfere ? 'sim' : 'nao',
                    'assinatura_recebido' => is_string($recebido) ? substr(md5($recebido), 0, 8) : '(vazio)',
                    'assinatura_sessao' => is_string($daSessao) ? substr(md5($daSessao), 0, 8) : '(vazio)',
                    'sessao_veio_no_cookie' => $requisicao->hasCookie(config('session.cookie')),
                    'autenticado' => auth()->id() ?? '(nao)',
                ]);

            // Null deixa o Laravel seguir com a resposta 419 de sempre: aqui
            // so anotamos, sem mudar o comportamento.
            return null;
        });

    })->create();
