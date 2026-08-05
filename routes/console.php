<?php

use App\Models\TentativaDeAcesso;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
Schedule::command('session:prune-expired')
    ->dailyAt('03:30');
