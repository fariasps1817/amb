<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tarefas noturnas de routes/console.php.
 *
 * O cron do servidor descarta a saida do schedule:run, entao uma tarefa que
 * falha nao avisa ninguem: o erro so aparece no log, e so quando o log aceita
 * a escrita.
 */
class AgendadorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "session:prune-expired" ficou agendado por meses sem nunca ter existido
     * no Laravel. Este teste barra qualquer agendamento de comando inexistente.
     */
    #[Test]
    public function todo_comando_agendado_existe(): void
    {
        $existentes = array_keys(Artisan::all());

        $agendados = collect(app(Schedule::class)->events())
            ->filter(fn (Event $evento) => $evento->command !== null)
            ->map(function (Event $evento) {
                preg_match('/artisan["\']?\s+([\w:\-]+)/', $evento->command, $partes);

                return $partes[1] ?? $evento->command;
            });

        $this->assertNotEmpty($agendados, 'Nenhum comando agendado encontrado: o teste não estaria verificando nada.');

        foreach ($agendados as $comando) {
            $this->assertContains($comando, $existentes, "O comando agendado \"{$comando}\" não existe.");
        }
    }

    #[Test]
    public function a_limpeza_noturna_apaga_so_as_sessoes_vencidas(): void
    {
        config(['session.driver' => 'database']);

        $limite = (int) config('session.lifetime') * 60;

        DB::table('sessions')->insert([
            ['id' => 'vencida', 'payload' => '', 'last_activity' => now()->timestamp - $limite - 300],
            ['id' => 'em-uso', 'payload' => '', 'last_activity' => now()->timestamp - 60],
        ]);

        $this->artisan('schedule:test', ['--name' => 'limpar-sessoes-vencidas'])->assertSuccessful();

        $this->assertSame(['em-uso'], DB::table('sessions')->pluck('id')->all());
    }
}
