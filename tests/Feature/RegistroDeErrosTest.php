<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O log em storage/logs: quem consegue escrever nele e o que vai para ele.
 */
class RegistroDeErrosTest extends TestCase
{
    /**
     * O site (www-data) e o agendador (ubuntu) escrevem no mesmo arquivo do
     * dia. Nos dias em que o agendador criava o arquivo, o site nao conseguia
     * registrar nada -- e o 419, que registra o motivo, virava 500.
     */
    #[Test]
    public function o_log_diario_nasce_gravavel_pelo_grupo(): void
    {
        $this->assertSame(0664, config('logging.channels.daily.permission'));

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('O Windows não tem permissão de grupo no estilo Unix; o CI confere no Linux.');
        }

        $pasta = sys_get_temp_dir().'/amb-log-'.uniqid();
        config(['logging.channels.teste_permissao' => [
            ...config('logging.channels.daily'),
            'path' => $pasta.'/laravel.log',
            'level' => 'debug',
        ]]);

        try {
            Log::channel('teste_permissao')->error('teste de permissão');

            $arquivo = File::glob($pasta.'/laravel-*.log')[0] ?? null;

            $this->assertNotNull($arquivo, 'O arquivo de log do dia não foi criado.');
            $this->assertSame('0664', substr(sprintf('%o', fileperms($arquivo)), -4));
        } finally {
            File::deleteDirectory($pasta);
        }
    }

    /**
     * Robo que faz POST sem nunca ter recebido cookie gera 419 a toda hora. Isso
     * nao diz nada sobre o sistema e soterrava os 419 de usuarios reais.
     */
    #[Test]
    public function o_419_sem_cookie_de_sessao_nao_vai_para_o_log(): void
    {
        $this->rotaQueExpira();
        Log::spy();

        $this->post('/_teste/expira')->assertStatus(419);

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function o_419_de_quem_tem_sessao_continua_registrado(): void
    {
        $this->rotaQueExpira();
        Log::spy();

        $this->withCookie(config('session.cookie'), 'sessao-antiga')
            ->post('/_teste/expira')
            ->assertStatus(419);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $mensagem, array $contexto) => str_starts_with($mensagem, '419')
                && $contexto['sessao_veio_no_cookie'] === true);
    }

    private function rotaQueExpira(): void
    {
        Route::post('/_teste/expira', fn () => abort(419))->middleware('web');
    }
}
