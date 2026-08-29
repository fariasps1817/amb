<?php

use App\Http\Controllers\AcessoController;
use App\Http\Controllers\AmbulanciaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\EscalaController;
use App\Http\Controllers\MensagemController;
use App\Http\Controllers\MotoristaController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\UnidadeController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autenticacao
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('entrar', [LoginController::class, 'mostrar'])->name('login');
    Route::post('entrar', [LoginController::class, 'entrar']);
});

Route::post('sair', [LoginController::class, 'sair'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Area autenticada
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::redirect('/', '/painel');
    Route::get('painel', PainelController::class)->name('painel');

    // -----------------------------------------------------------------
    // Cadastros
    // -----------------------------------------------------------------

    // Antes do resource de proposito: declarada depois, "exportar" cairia em
    // motoristas/{motorista} e o Laravel procuraria um motorista com esse id.
    Route::get('motoristas/exportar', [MotoristaController::class, 'exportar'])
        ->name('motoristas.exportar');

    Route::resource('motoristas', MotoristaController::class)
        ->parameters(['motoristas' => 'motorista']);

    Route::resource('unidades', UnidadeController::class)
        ->parameters(['unidades' => 'unidade']);

    Route::resource('ambulancias', AmbulanciaController::class)
        ->parameters(['ambulancias' => 'ambulancia']);

    // -----------------------------------------------------------------
    // Escalas mensais
    // -----------------------------------------------------------------

    Route::controller(EscalaController::class)->prefix('escalas')->name('escalas.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('nova', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{escala}', 'show')->name('show');
        Route::delete('{escala}', 'destroy')->name('destroy');

        // Montagem
        Route::get('{escala}/montar', 'montar')->name('montar');
        Route::get('{escala}/destinos', 'destinos')->name('destinos');

        // Acoes
        Route::post('{escala}/gerar', 'gerar')->name('gerar');
        Route::post('{escala}/publicar', 'publicar')->name('publicar');
        Route::post('{escala}/reabrir', 'reabrir')->name('reabrir');
        Route::post('{escala}/arquivar', 'arquivar')->name('arquivar');
    });

    // -----------------------------------------------------------------
    // Documentos em PDF
    // -----------------------------------------------------------------

    Route::controller(DocumentoController::class)->prefix('escalas/{escala}/documentos')->name('documentos.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('planilha', 'planilha')->name('planilha');
        Route::get('ocorrencias', 'ocorrencias')->name('ocorrencias');
        Route::get('frequencias', 'frequencias')->name('frequencias');
        Route::get('frequencias/{motorista}', 'frequencia')->name('frequencia');
    });

    // -----------------------------------------------------------------
    // Mensagens de WhatsApp
    // -----------------------------------------------------------------

    Route::controller(MensagemController::class)->prefix('escalas/{escala}/mensagens')->name('mensagens.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('preparar', 'preparar')->name('preparar');
        Route::post('{mensagem}/enviada', 'marcarEnviada')->name('enviada');
        Route::post('{mensagem}/enviar', 'enviar')->name('enviar');
        Route::post('enviar-todas', 'enviarTodas')->name('enviar-todas');
    });

    // -----------------------------------------------------------------
    // Sistema
    // -----------------------------------------------------------------

    Route::get('configuracoes', [ConfiguracaoController::class, 'edit'])->name('configuracoes.edit');
    Route::put('configuracoes', [ConfiguracaoController::class, 'update'])->name('configuracoes.update');
    Route::delete('configuracoes/imagem/{campo}', [ConfiguracaoController::class, 'removerImagem'])
        ->name('configuracoes.remover-imagem');

    Route::resource('usuarios', UsuarioController::class)
        ->parameters(['usuarios' => 'usuario'])
        ->middleware('admin');

    Route::get('acessos', AcessoController::class)
        ->middleware('admin')
        ->name('acessos.index');

    // Chamada pelo aviso de inatividade quando o usuario pede para continuar
    // conectado. Qualquer requisicao autenticada ja renova a sessao; esta
    // existe apenas para fazer isso sem recarregar a tela.
    Route::post('sessao/renovar', fn () => response()->noContent())->name('sessao.renovar');
});
