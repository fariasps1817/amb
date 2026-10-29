<?php

use App\Models\TentativaDeAcesso;
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

// Descarta o historico antigo de tentativas de acesso. Sem isso a tabela
// cresceria sem limite: uma tentativa exaustiva gera milhares de linhas.
Schedule::command('model:prune', ['--model' => [TentativaDeAcesso::class]])
    ->dailyAt('03:20');

// Sessoes vencidas continuam ocupando espaco na tabela ate serem coletadas.
// A coleta padrao do Laravel e por sorteio, entao pode demorar a acontecer.
// O Laravel nao tem comando para isso -- um "session:prune-expired" ficou
// agendado aqui por meses, falhando toda noite. Chamamos a mesma coleta que o
// sorteio faria, no driver de sessao configurado.
Schedule::call(fn () => Session::getHandler()->gc(config('session.lifetime') * 60))
    ->name('limpar-sessoes-vencidas')
    ->dailyAt('03:30');
