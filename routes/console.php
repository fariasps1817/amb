<?php

use App\Models\RegistroDeAuditoria;
use App\Models\TentativaDeAcesso;
use App\Services\Auditoria\SessoesExpiradas;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Session;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tarefas agendadas
|--------------------------------------------------------------------------
|
| Dependem de uma unica linha no cron do servidor chamando
| "php artisan schedule:run" a cada minuto. Assim o agendamento de cada tarefa
| fica aqui, versionado, e nao espalhado em arquivos soltos do servidor.
|
*/

// Descarta o que passou do prazo de guarda: 6 meses para as tentativas de acesso e
// 12 para as acoes na auditoria, por decisao da coordenacao. Sem isso as tabelas
// cresceriam sem limite: uma tentativa exaustiva gera milhares de linhas. As
// solicitacoes de ambulancia ficam de fora: a guarda delas e indefinida, por enquanto.
Schedule::command('model:prune', ['--model' => [TentativaDeAcesso::class, RegistroDeAuditoria::class]])
    ->dailyAt('03:20');

// Sessoes vencidas continuam ocupando espaco na tabela ate serem coletadas.
// O Laravel nao tem comando para isso -- um "session:prune-expired" ficou
// agendado aqui por meses, falhando toda noite. Chamamos a mesma coleta que o
// sorteio faria, no driver de sessao configurado. Antes de apagar, a auditoria
// anota quem saiu sem sair: e a unica pista de que aquela sessao terminou.
Schedule::call(function () {
    app(SessoesExpiradas::class)->registrar();

    Session::getHandler()->gc(config('session.lifetime') * 60);
})
    ->name('limpar-sessoes-vencidas')
    ->dailyAt('03:30');
