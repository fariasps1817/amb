<?php

use App\Http\Controllers\AcessoController;
use App\Http\Controllers\AmbulanciaController;
use App\Http\Controllers\AniversarianteController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ComparacaoDePropostasController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\EnvioPeloWhatsappController;
use App\Http\Controllers\EscalaController;
use App\Http\Controllers\MensagemController;
use App\Http\Controllers\MotoristaController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PlantaoController;
use App\Http\Controllers\SolicitacaoController;
use App\Http\Controllers\SolicitacaoPublicaController;
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
| Solicitacao de ambulancia pelo hospital (pagina aberta, sem login)
|--------------------------------------------------------------------------
*/

Route::controller(SolicitacaoPublicaController::class)->prefix('solicitar-ambulancia')->name('solicitacao.')->group(function () {
    Route::get('/', 'formulario')->name('formulario');
    Route::post('/', 'enviar')->middleware('throttle:solicitacoes')->name('enviar');
    Route::get('recebida/{protocolo}', 'recebida')->middleware('signed')->name('recebida');
});

/*
|--------------------------------------------------------------------------
| Area autenticada
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::redirect('/', '/painel');
    Route::get('painel', PainelController::class)->name('painel');

    // Consultas do dia a dia, feitas sobretudo pelo celular.
    Route::get('plantao', PlantaoController::class)->name('plantao');
    Route::get('aniversariantes', AniversarianteController::class)->name('aniversariantes');

    // -----------------------------------------------------------------
    // Solicitacoes de transporte recebidas (dados de saude: so a coordenacao)
    // -----------------------------------------------------------------

    Route::controller(SolicitacaoController::class)->prefix('solicitacoes')->name('solicitacoes.')->middleware('editor')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('contagem', 'contagem')->name('contagem');
        Route::get('{solicitacao}', 'show')->name('show');
        Route::post('{solicitacao}/atendida', 'atendida')->name('atendida');
        Route::post('{solicitacao}/nao-atendida', 'naoAtendida')->name('nao-atendida');
        Route::post('{solicitacao}/reabrir', 'reabrir')->name('reabrir');
        Route::delete('{solicitacao}', 'destroy')->middleware('admin')->name('destroy');
    });

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

        // Propostas alternativas do mes, enquanto aguardam aprovacao
        Route::post('{escala}/duplicar', 'duplicar')->name('duplicar');
        Route::put('{escala}/proposta', 'renomear')->name('renomear');
        Route::post('{escala}/restaurar', 'restaurar')->name('restaurar');
    });

    Route::get('escalas/{escala}/comparar', [ComparacaoDePropostasController::class, '__invoke'])->name('escalas.comparar');

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

    Route::controller(AuditoriaController::class)->prefix('auditoria')->name('auditoria.')->middleware('admin')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('exportar', 'exportar')->name('exportar');
        Route::get('sessoes', 'sessoes')->name('sessoes');
        Route::get('sessoes/{sessao}', 'sessao')->whereUuid('sessao')->name('sessao');
    });

    // Fora do grupo acima: quem toca nos botoes de envio nao e so o administrador.
    Route::post('auditoria/envio', EnvioPeloWhatsappController::class)
        ->middleware('throttle:60,1')
        ->name('auditoria.envio');

    // Chamada pelo aviso de inatividade quando o usuario pede para continuar
    // conectado. Qualquer requisicao autenticada ja renova a sessao; esta
    // existe apenas para fazer isso sem recarregar a tela.
    Route::post('sessao/renovar', fn () => response()->noContent())->name('sessao.renovar');
});
