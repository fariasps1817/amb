<?php

namespace Tests\Feature\Auditoria;

use App\Enums\AcaoAuditada;
use App\Enums\CategoriaDeAuditoria;
use App\Enums\MotivoNaoAtendimento;
use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaMensagem;
use App\Models\Motorista;
use App\Models\RegistroDeAuditoria;
use App\Models\SolicitacaoDeTransporte;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria\Auditoria;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Services\Whatsapp\Drivers\DriverCloudApi;
use App\Services\Whatsapp\MontadorDeMensagens;
use App\Support\Dispositivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * O que entra na auditoria: entradas e saidas, cadastros, documentos, mensagens,
 * solicitacoes e os toques nos botoes de envio. As escalas estao em AuditoriaDaEscalaTest.
 */
class RegistroDeAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['nome' => 'Farias Sousa', 'usuario' => 'farias']);
    }

    // -----------------------------------------------------------------
    // Entradas e saidas
    // -----------------------------------------------------------------

    #[Test]
    public function entrar_registra_quem_de_onde_e_em_qual_aparelho(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '177.12.3.4'])
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; SM-A515F) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36')
            ->post(route('login'), ['usuario' => 'farias', 'password' => '1234'])
            ->assertRedirect(route('painel'));

        $registro = $this->registro(AcaoAuditada::SessaoEntrou);

        $this->assertSame('Farias Sousa', $registro->usuario_nome);
        $this->assertSame('farias', $registro->usuario_login);
        $this->assertSame($this->admin->id, $registro->user_id);
        $this->assertSame(CategoriaDeAuditoria::Acesso, $registro->categoria);
        $this->assertSame('177.12.3.4', $registro->ip);
        $this->assertSame('Celular · Android · Chrome', $registro->dispositivo());
        $this->assertNotNull($registro->sessao);
    }

    /** Tentativa errada fica em "Tentativas de entrar": não é uma ação de alguém logado. */
    #[Test]
    public function tentativa_sem_sucesso_nao_entra_na_linha_do_tempo(): void
    {
        $this->post(route('login'), ['usuario' => 'farias', 'password' => 'errada']);

        $this->assertSame(0, RegistroDeAuditoria::query()->count());
    }

    /** A senha está certa, mas o usuário está desativado: nunca chegou a entrar. */
    #[Test]
    public function usuario_desativado_nao_conta_como_entrada(): void
    {
        User::factory()->inativo()->create(['usuario' => 'desativado']);

        $this->post(route('login'), ['usuario' => 'desativado', 'password' => '1234']);

        $this->assertSame(0, RegistroDeAuditoria::query()->count());
    }

    #[Test]
    public function entrada_acoes_e_saida_ficam_na_mesma_sessao(): void
    {
        $this->post(route('login'), ['usuario' => 'farias', 'password' => '1234']);
        $this->post(route('motoristas.store'), $this->dadosDeMotorista());
        $this->post(route('logout'));

        $sessoes = RegistroDeAuditoria::query()->pluck('sessao');

        $this->assertCount(3, $sessoes);
        $this->assertCount(1, $sessoes->unique());
        $this->assertNotNull($sessoes->first());
        $this->assertSame('Saiu do sistema', $this->registro(AcaoAuditada::SessaoSaiu)->descricao);
    }

    #[Test]
    public function a_saida_por_inatividade_e_diferente_da_saida_pedida(): void
    {
        $this->actingAs($this->admin)->post(route('logout'), ['inatividade' => 1]);

        $this->assertSame('Sessão encerrada por inatividade', $this->registro(AcaoAuditada::SessaoInatividade)->descricao);
        $this->assertNull(RegistroDeAuditoria::query()->where('acao', AcaoAuditada::SessaoSaiu->value)->first());
    }

    /** Quem marcou "manter a sessão" volta sem passar pela tela de entrar. */
    #[Test]
    public function voltar_pela_sessao_mantida_tambem_fica_registrado(): void
    {
        $this->admin->forceFill(['remember_token' => 'token-lembrar'])->save();
        $cookie = Auth::guard()->getRecallerName();

        $this->withCookie($cookie, "{$this->admin->id}|token-lembrar|{$this->admin->password}")
            ->get(route('painel'))
            ->assertOk();

        $registro = $this->registro(AcaoAuditada::SessaoRetomada);

        $this->assertSame('farias', $registro->usuario_login);
        $this->assertNotNull($registro->sessao);
    }

    /** Fechou o navegador: a única pista é a sessão vencida, lida antes de a limpeza apagá-la. */
    #[Test]
    public function a_limpeza_noturna_registra_quem_saiu_sem_sair(): void
    {
        config(['session.driver' => 'database']);
        $this->travelTo(Carbon::parse('2026-10-30 03:30:00'));

        $duracao = (int) config('session.lifetime') * 60;
        $ultimaAtividade = Carbon::parse('2026-10-29 14:00:00');
        $sessao = '11111111-2222-3333-4444-555555555555';

        DB::table('sessions')->insert([
            [
                'id' => 'vencida', 'user_id' => $this->admin->id, 'ip_address' => '177.12.3.4',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
                'payload' => base64_encode(json_encode([Auditoria::CHAVE_DA_SESSAO => $sessao])),
                'last_activity' => $ultimaAtividade->timestamp,
            ],
            [
                'id' => 'em-uso', 'user_id' => $this->admin->id, 'ip_address' => null, 'user_agent' => null,
                'payload' => '', 'last_activity' => now()->timestamp - 60,
            ],
            [
                'id' => 'visitante', 'user_id' => null, 'ip_address' => null, 'user_agent' => null,
                'payload' => '', 'last_activity' => now()->timestamp - $duracao - 300,
            ],
        ]);

        $this->artisan('schedule:test', ['--name' => 'limpar-sessoes-vencidas'])->assertSuccessful();

        $this->assertSame(['em-uso'], DB::table('sessions')->pluck('id')->all());

        $registro = RegistroDeAuditoria::query()->sole();

        $this->assertSame(AcaoAuditada::SessaoExpirada->value, $registro->acao);
        $this->assertSame('Farias Sousa', $registro->usuario_nome);
        $this->assertSame($sessao, $registro->sessao);
        $this->assertSame('177.12.3.4', $registro->ip);
        $this->assertStringContainsString('29/10/2026 às 14:00', $registro->descricao);
        // A hora em que a sessão de fato acabou, e não a da limpeza.
        $this->assertSame(
            $ultimaAtividade->copy()->addSeconds($duracao)->toDateTimeString(),
            $registro->created_at->toDateTimeString(),
        );
    }

    /** Sem isso, o sorteio do Laravel apagaria as sessões vencidas antes de serem lidas. */
    #[Test]
    public function a_coleta_de_sessoes_por_sorteio_fica_desligada(): void
    {
        $this->assertSame(0, config('session.lottery')[0]);
    }

    // -----------------------------------------------------------------
    // Regras do registro
    // -----------------------------------------------------------------

    #[Test]
    public function registros_nao_podem_ser_alterados_nem_apagados(): void
    {
        $this->actingAs($this->admin)->post(route('logout'));
        $registro = RegistroDeAuditoria::query()->firstOrFail();

        try {
            $registro->update(['descricao' => 'outra coisa']);
            $this->fail('A alteração deveria ser recusada.');
        } catch (LogicException) {
            $this->assertSame('Saiu do sistema', $registro->fresh()->descricao);
        }

        $this->expectException(LogicException::class);
        $registro->delete();
    }

    /** Guarda decidida pela coordenação: 12 meses para as ações. */
    #[Test]
    public function o_descarte_apaga_o_que_passou_de_doze_meses(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 03:20:00'));

        foreach (['2025-10-29 10:00:00' => 'antigo', '2025-10-31 10:00:00' => 'recente'] as $data => $descricao) {
            RegistroDeAuditoria::query()->create([
                'categoria' => CategoriaDeAuditoria::Sistema,
                'acao' => AcaoAuditada::AuditoriaExportada->value,
                'descricao' => $descricao,
                'created_at' => $data,
            ]);
        }

        $this->artisan('model:prune', ['--model' => [RegistroDeAuditoria::class]])->assertSuccessful();

        $this->assertSame(['recente'], RegistroDeAuditoria::query()->pluck('descricao')->all());
    }

    /** Quem está trabalhando não pode perder o que fez porque a auditoria falhou. */
    #[Test]
    public function falha_ao_registrar_nao_impede_a_acao(): void
    {
        Exceptions::fake();
        RegistroDeAuditoria::creating(fn () => throw new RuntimeException('tabela indisponível'));

        $this->actingAs($this->admin)
            ->post(route('motoristas.store'), $this->dadosDeMotorista())
            ->assertRedirect(route('motoristas.index'));

        $this->assertSame(1, Motorista::query()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    // -----------------------------------------------------------------
    // Cadastros
    // -----------------------------------------------------------------

    #[Test]
    public function cadastrar_motorista_guarda_o_que_foi_preenchido_com_cpf_e_telefone_completos(): void
    {
        $this->actingAs($this->admin)->post(route('motoristas.store'), $this->dadosDeMotorista());

        $registro = $this->registro(AcaoAuditada::MotoristaCriado);

        $this->assertSame('Cadastrou o motorista JOÃO', $registro->descricao);
        $this->assertSame('motorista', $registro->alvo_tipo);
        $this->assertSame(Motorista::query()->sole()->id, $registro->alvo_id);
        $this->assertSame('12345678909', $this->campo($registro, 'CPF')['para']);
        $this->assertSame('(85) 98692-6853', $this->campo($registro, 'Telefone')['para']);
    }

    /** Decisão da coordenação: antes e depois de CPF e telefone completos, sem máscara. */
    #[Test]
    public function alterar_motorista_guarda_antes_e_depois_com_cpf_e_telefone_completos(): void
    {
        $motorista = Motorista::factory()->create([
            'nome_completo' => 'JOÃO DA SILVA', 'nome_curto' => 'JOÃO',
            'cpf' => '12345678909', 'telefone_1' => '85986926853', 'vinculo' => Vinculo::Efetivo,
        ]);

        $this->actingAs($this->admin)->put(route('motoristas.update', $motorista), $this->dadosDeMotorista([
            'nome_completo' => 'JOÃO DA SILVA',
            'cpf' => '529.982.247-25',
            'telefone_1' => '(85) 98888-7777',
        ]));

        $registro = $this->registro(AcaoAuditada::MotoristaAlterado);

        $this->assertEquals(['campo' => 'CPF', 'de' => '12345678909', 'para' => '52998224725'], $this->campo($registro, 'CPF'));
        $this->assertEquals(['campo' => 'Telefone', 'de' => '(85) 98692-6853', 'para' => '(85) 98888-7777'], $this->campo($registro, 'Telefone'));
        // Só o que mudou.
        $this->assertNull($this->campo($registro, 'Nome completo'));
    }

    #[Test]
    public function salvar_sem_mudar_nada_nao_gera_registro(): void
    {
        $this->actingAs($this->admin)->post(route('motoristas.store'), $this->dadosDeMotorista());
        $motorista = Motorista::query()->sole();

        $this->put(route('motoristas.update', $motorista), $this->dadosDeMotorista());

        $this->assertNull(RegistroDeAuditoria::query()->where('acao', AcaoAuditada::MotoristaAlterado->value)->first());
    }

    #[Test]
    public function excluir_motorista_guarda_o_que_ele_tinha(): void
    {
        $motorista = Motorista::factory()->create(['nome_curto' => 'JOÃO', 'cpf' => '12345678909']);

        $this->actingAs($this->admin)->delete(route('motoristas.destroy', $motorista));

        $this->assertSame('12345678909', $this->campo($this->registro(AcaoAuditada::MotoristaExcluido), 'CPF')['de']);
    }

    #[Test]
    public function motorista_com_historico_fica_inativo_e_a_auditoria_diz_isso(): void
    {
        $this->actingAs($this->admin);
        $escala = Escala::query()->create(['ano' => 2026, 'mes' => 8]);
        $motorista = Motorista::factory()->create(['nome_curto' => 'JOÃO']);
        EscalaLotacao::query()->create(['escala_id' => $escala->id, 'motorista_id' => $motorista->id]);

        $this->delete(route('motoristas.destroy', $motorista));

        $registro = $this->registro(AcaoAuditada::MotoristaInativado);

        $this->assertSame(StatusMotorista::Inativo, $motorista->fresh()->status);
        $this->assertEquals(['campo' => 'Situação', 'de' => 'Ativo', 'para' => 'Inativo'], $this->campo($registro, 'Situação'));
    }

    #[Test]
    public function unidade_e_ambulancia_entram_na_auditoria(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('unidades.store'), ['nome' => 'UPA Centro', 'sigla' => 'upa', 'regime' => '24/48', 'ativo' => '1']);
        $unidade = Unidade::query()->sole();
        $this->assertSame('Cadastrou a unidade UPA', $this->registro(AcaoAuditada::UnidadeCriada)->descricao);

        $this->put(route('unidades.update', $unidade), ['nome' => 'UPA do Centro', 'sigla' => 'upa', 'regime' => '24/48', 'ativo' => '1']);
        $this->assertSame('UPA do Centro', $this->campo($this->registro(AcaoAuditada::UnidadeAlterada), 'Nome')['para']);

        $ambulancia = Ambulancia::factory()->create(['unidade_id' => $unidade->id, 'placa' => 'THQ4H34']);
        $outra = Unidade::factory()->create(['sigla' => 'SEDE']);

        $this->put(route('ambulancias.update', $ambulancia), [
            'placa' => 'THQ4H34', 'vinculo' => $ambulancia->vinculo->value, 'unidade_id' => $outra->id, 'ativo' => '1',
        ]);
        // O nome da unidade, e não o número dela no banco.
        $this->assertEquals(
            ['campo' => 'Unidade', 'de' => 'UPA', 'para' => 'SEDE'],
            $this->campo($this->registro(AcaoAuditada::AmbulanciaAlterada), 'Unidade'),
        );

        $this->delete(route('ambulancias.destroy', $ambulancia));
        $this->assertSame('Excluiu a ambulância THQ4H34', $this->registro(AcaoAuditada::AmbulanciaExcluida)->descricao);

        $this->delete(route('unidades.destroy', $outra));
        $this->assertSame('Excluiu a unidade SEDE', $this->registro(AcaoAuditada::UnidadeExcluida)->descricao);
    }

    #[Test]
    public function ambulancia_e_unidade_com_historico_ficam_desativadas_e_a_auditoria_diz_isso(): void
    {
        $this->actingAs($this->admin);
        $unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        $ambulancia = Ambulancia::factory()->create(['unidade_id' => $unidade->id]);
        app(MontadorDeEscala::class)->criar(2026, 8, copiarMesAnterior: false);

        $this->delete(route('ambulancias.destroy', $ambulancia));
        $this->assertSame('Sim', $this->campo($this->registro(AcaoAuditada::AmbulanciaDesativada), 'Ativo')['de']);

        $this->delete(route('unidades.destroy', $unidade));
        $this->assertSame('Não', $this->campo($this->registro(AcaoAuditada::UnidadeDesativada), 'Ativo')['para']);
    }

    /** Da senha só se registra que mudou: nem o texto, nem o hash. */
    #[Test]
    public function a_senha_nunca_entra_na_auditoria(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.store'), [
            'nome' => 'Carlos', 'usuario' => 'carlos', 'password' => 'segredo1', 'password_confirmation' => 'segredo1', 'perfil' => 'operador', 'ativo' => 1,
        ]);

        $criado = $this->registro(AcaoAuditada::UsuarioCriado);
        $this->assertSame('Cadastrou o usuário carlos (Operador)', $criado->descricao);
        $this->assertNull($this->campo($criado, 'Senha'));

        $carlos = User::query()->where('usuario', 'carlos')->sole();

        $this->put(route('usuarios.update', $carlos), [
            'nome' => 'Carlos', 'usuario' => 'carlos', 'password' => 'segredo2', 'password_confirmation' => 'segredo2', 'perfil' => 'admin', 'ativo' => 1,
        ]);

        $alterado = $this->registro(AcaoAuditada::UsuarioAlterado);

        $this->assertEquals(['campo' => 'Senha', 'de' => null, 'para' => '(alterada)'], $this->campo($alterado, 'Senha'));
        $this->assertEquals(['campo' => 'Perfil', 'de' => 'operador', 'para' => 'admin'], $this->campo($alterado, 'Perfil'));

        $this->delete(route('usuarios.destroy', $carlos));

        $tudo = RegistroDeAuditoria::query()->pluck('alteracoes')->toJson();

        $this->assertStringNotContainsString('segredo', $tudo);
        $this->assertStringNotContainsString('$2y$', $tudo);
    }

    /** O registro continua dizendo quem fez, mesmo depois de a pessoa ser excluída. */
    #[Test]
    public function o_registro_sobrevive_a_exclusao_do_usuario(): void
    {
        $carlos = User::factory()->create(['nome' => 'Carlos Lima', 'usuario' => 'carlos']);
        $this->actingAs($carlos)->post(route('motoristas.store'), $this->dadosDeMotorista());

        $this->actingAs($this->admin)->delete(route('usuarios.destroy', $carlos));

        $registro = $this->registro(AcaoAuditada::MotoristaCriado);

        $this->assertSame('Carlos Lima', $registro->autor());
        $this->assertSame($carlos->id, $registro->user_id);
    }

    #[Test]
    public function a_identidade_institucional_entra_na_auditoria(): void
    {
        $this->actingAs($this->admin)->put(route('configuracoes.update'), [
            'prefeitura' => 'Prefeitura de Cascavel', 'secretaria' => 'Saúde', 'setor' => 'Ambulâncias', 'municipio' => 'Cascavel', 'uf' => 'CE',
        ])->assertRedirect();

        $this->assertSame('Ambulâncias', $this->campo($this->registro(AcaoAuditada::ConfiguracaoAlterada), 'Setor')['para']);

        Configuracao::atual()->update(['brasao' => 'institucional/brasao.png']);

        $this->delete(route('configuracoes.remover-imagem', 'brasao'));

        $this->assertStringContainsString('Removeu da identidade institucional', $this->registro(AcaoAuditada::ConfiguracaoImagemRemovida)->descricao);
    }

    // -----------------------------------------------------------------
    // Documentos
    // -----------------------------------------------------------------

    /** O perfil de consulta também imprime: é onde mais interessa saber quem tirou o quê. */
    #[Test]
    public function emitir_documentos_fica_registrado_tambem_para_o_perfil_de_consulta(): void
    {
        $escala = $this->escalaPublicada();
        $motorista = $escala->lotacoes()->first()->motorista;
        $this->actingAs(User::factory()->leitor()->create(['nome' => 'Quem Consulta']));

        $this->get(route('documentos.planilha', $escala))->assertOk();
        $this->get(route('documentos.ocorrencias', [$escala, 'download' => 1]))->assertOk();
        $this->get(route('documentos.frequencias', $escala))->assertOk();
        $this->get(route('documentos.frequencia', [$escala, $motorista]))->assertOk();

        $descricoes = RegistroDeAuditoria::query()->where('acao', AcaoAuditada::DocumentoEmitido->value)->orderBy('id')->pluck('descricao');

        $this->assertSame([
            'Abriu a planilha mensal de plantões — Agosto de 2026',
            'Baixou a lista mensal de ocorrências — Agosto de 2026',
            'Abriu as folhas de frequência de todos os motoristas — Agosto de 2026',
            "Abriu a folha de frequência de {$motorista->nome_curto} — Agosto de 2026",
        ], $descricoes->all());

        $this->assertSame('Quem Consulta', $this->registro(AcaoAuditada::DocumentoEmitido)->usuario_nome);
    }

    /** O leitor de PDF do navegador pede o arquivo mais de uma vez: é uma emissão só. */
    #[Test]
    public function o_mesmo_documento_em_seguida_nao_gera_duas_linhas(): void
    {
        $escala = $this->escalaPublicada();
        $this->actingAs($this->admin);

        $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));
        $this->get(route('documentos.ocorrencias', $escala));
        $this->get(route('documentos.ocorrencias', $escala));
        $this->assertSame(1, RegistroDeAuditoria::query()->where('acao', AcaoAuditada::DocumentoEmitido->value)->count());

        $this->travelTo(Carbon::parse('2026-10-30 10:05:00'));
        $this->get(route('documentos.ocorrencias', $escala));
        $this->assertSame(2, RegistroDeAuditoria::query()->where('acao', AcaoAuditada::DocumentoEmitido->value)->count());
    }

    #[Test]
    public function exportar_a_lista_de_motoristas_fica_registrado_com_os_filtros(): void
    {
        Motorista::factory()->create();

        $this->actingAs($this->admin)->get(route('motoristas.exportar', ['status' => 'ativo']))->assertOk();

        $this->assertSame('Abriu a lista de motoristas em PDF (Situação: Ativo)', $this->registro(AcaoAuditada::DocumentoEmitido)->descricao);
    }

    /** Decisão da coordenação: visitas a telas não entram. */
    #[Test]
    public function olhar_uma_tela_nao_gera_registro(): void
    {
        $motorista = Motorista::factory()->create();
        $this->actingAs($this->admin);

        $this->get(route('painel'))->assertOk();
        $this->get(route('motoristas.index'))->assertOk();
        $this->get(route('motoristas.show', $motorista))->assertOk();
        $this->get(route('plantao'))->assertOk();
        $this->get(route('auditoria.index'))->assertOk();

        $this->assertSame(0, RegistroDeAuditoria::query()->count());
    }

    // -----------------------------------------------------------------
    // Mensagens
    // -----------------------------------------------------------------

    #[Test]
    public function preparar_e_confirmar_mensagens_entram_na_auditoria(): void
    {
        $escala = $this->escalaPublicada();
        $this->actingAs($this->admin);

        $this->post(route('mensagens.preparar', $escala));
        $this->assertStringContainsString('mensagem(ns) aos motoristas', $this->registro(AcaoAuditada::MensagensPreparadas)->descricao);

        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->firstOrFail();

        $this->post(route('mensagens.enviada', [$escala, $mensagem]));

        $registro = $this->registro(AcaoAuditada::MensagemConfirmada);

        $this->assertSame("Confirmou o envio da mensagem a {$mensagem->motorista->nome_curto} — Agosto de 2026", $registro->descricao);
        $this->assertSame($escala->id, $registro->escala_id);
    }

    #[Test]
    public function o_envio_pela_api_registra_sucesso_e_falha(): void
    {
        config(['whatsapp.driver' => 'cloud', 'whatsapp.cloud.token' => 'token', 'whatsapp.cloud.phone_id' => '1']);
        $this->app->bind(DriverDeWhatsapp::class, fn () => new DriverCloudApi);

        $escala = $this->escalaPublicada();
        $this->actingAs($this->admin);
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);
        $mensagens = EscalaMensagem::query()->where('escala_id', $escala->id)->get();

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'x']]], 200)
            ->push(['error' => ['message' => 'Número inválido']], 400)]);

        $this->post(route('mensagens.enviar', [$escala, $mensagens[0]]));
        $this->assertStringStartsWith('Enviou a mensagem a', $this->registro(AcaoAuditada::MensagemEnviada)->descricao);

        $this->post(route('mensagens.enviar', [$escala, $mensagens[1]]));

        $falhou = $this->registro(AcaoAuditada::MensagemFalhou);
        $this->assertStringStartsWith('Falhou o envio da mensagem a', $falhou->descricao);
        $this->assertStringContainsString('Número inválido', $this->campo($falhou, 'Retorno')['para']);
    }

    #[Test]
    public function o_envio_em_lote_entra_na_auditoria(): void
    {
        config(['whatsapp.driver' => 'cloud', 'whatsapp.cloud.token' => 'token', 'whatsapp.cloud.phone_id' => '1']);
        $this->app->bind(DriverDeWhatsapp::class, fn () => new DriverCloudApi);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $escala = $this->escalaPublicada();
        $this->actingAs($this->admin);
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $this->post(route('mensagens.enviar-todas', $escala));

        $this->assertStringContainsString('4 enviada(s) e 0 com falha', $this->registro(AcaoAuditada::MensagensEmLote)->descricao);
    }

    // -----------------------------------------------------------------
    // Solicitacoes
    // -----------------------------------------------------------------

    #[Test]
    public function o_pedido_pela_pagina_aberta_e_registrado_sem_dados_do_paciente(): void
    {
        $this->post(route('solicitacao.enviar'), $this->dadosDeSolicitacao());

        $registro = $this->registro(AcaoAuditada::SolicitacaoRecebida);
        $protocolo = SolicitacaoDeTransporte::query()->sole()->protocolo;

        $this->assertSame("Recebida pela página aberta a solicitação {$protocolo}, de Hospital Geral (Fortaleza)", $registro->descricao);
        $this->assertSame('Página aberta (sem login)', $registro->autor());
        $this->assertNull($registro->user_id);
        $this->assertNull($registro->sessao);
        $this->assertStringNotContainsString('JOSÉ', $registro->descricao.json_encode($registro->alteracoes));
    }

    #[Test]
    public function reenviar_o_mesmo_formulario_nao_registra_de_novo(): void
    {
        $dados = $this->dadosDeSolicitacao(['chave_envio' => '11111111-2222-3333-4444-555555555555']);

        $this->post(route('solicitacao.enviar'), $dados);
        $this->post(route('solicitacao.enviar'), $dados);

        $this->assertSame(1, RegistroDeAuditoria::query()->where('acao', AcaoAuditada::SolicitacaoRecebida->value)->count());
    }

    #[Test]
    public function o_desfecho_entra_na_auditoria_com_o_nome_do_motorista(): void
    {
        $solicitacao = $this->solicitacao();
        $motorista = Motorista::factory()->create(['nome_curto' => 'JOÃO']);
        $this->actingAs($this->admin);

        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $motorista->id, 'atendida_em' => now()->format('Y-m-d\TH:i')]);

        $registro = $this->registro(AcaoAuditada::SolicitacaoAtendida);

        $this->assertSame("Registrou como atendida a solicitação {$solicitacao->protocolo}", $registro->descricao);
        $this->assertSame('JOÃO', $this->campo($registro, 'Motorista')['para']);
        $this->assertEquals(['campo' => 'Situação', 'de' => 'Aguardando', 'para' => 'Atendida'], $this->campo($registro, 'Situação'));
    }

    #[Test]
    public function registrar_como_nao_atendida_entra_na_auditoria(): void
    {
        $solicitacao = $this->solicitacao();
        $this->actingAs($this->admin);

        $this->post(route('solicitacoes.nao-atendida', $solicitacao), ['motivo_nao_atendimento' => 'indevida']);

        $this->assertSame(MotivoNaoAtendimento::Indevida->rotulo(), $this->campo($this->registro(AcaoAuditada::SolicitacaoNaoAtendida), 'Motivo')['para']);
    }

    /** Reabrir apaga o desfecho da solicitação: sem a auditoria, ele se perderia. */
    #[Test]
    public function reabrir_preserva_na_auditoria_o_desfecho_que_foi_apagado(): void
    {
        $solicitacao = $this->solicitacao();
        $motorista = Motorista::factory()->create(['nome_curto' => 'JOÃO']);
        $this->actingAs($this->admin);

        $this->post(route('solicitacoes.atendida', $solicitacao), [
            'motorista_id' => $motorista->id,
            'atendida_em' => now()->format('Y-m-d\TH:i'),
            'observacoes_atendimento' => 'Entregue à família.',
        ]);
        $this->post(route('solicitacoes.reabrir', $solicitacao));

        $registro = $this->registro(AcaoAuditada::SolicitacaoReaberta);

        $this->assertNull($solicitacao->fresh()->motorista_id);
        $this->assertSame('JOÃO', $this->campo($registro, 'Motorista')['de']);
        $this->assertSame('Entregue à família.', $this->campo($registro, 'Observações do atendimento')['de']);
        $this->assertSame('Farias Sousa', $this->campo($registro, 'Registrada por')['de']);
    }

    /** Apagar existe para o dado do paciente sumir: na auditoria fica só o protocolo. */
    #[Test]
    public function apagar_registra_o_protocolo_e_nenhum_dado_do_paciente(): void
    {
        $solicitacao = $this->solicitacao();
        $protocolo = $solicitacao->protocolo;

        $this->actingAs($this->admin)->delete(route('solicitacoes.destroy', $solicitacao));

        $registro = $this->registro(AcaoAuditada::SolicitacaoExcluida);

        $this->assertSame("Apagou a solicitação {$protocolo} (Aguardando)", $registro->descricao);
        $this->assertNull($registro->alteracoes);

        $tudo = RegistroDeAuditoria::query()->get()->toJson();

        $this->assertStringNotContainsString('JOSÉ', $tudo);
        $this->assertStringNotContainsString('98888', $tudo);
    }

    // -----------------------------------------------------------------
    // Botoes de envio pelo WhatsApp
    // -----------------------------------------------------------------

    #[Test]
    public function tocar_no_envio_do_plantao_fica_registrado_para_qualquer_perfil(): void
    {
        $this->actingAs(User::factory()->leitor()->create());

        $this->postJson(route('auditoria.envio'), ['evento' => 'plantao', 'forma' => 'whatsapp', 'dia' => '2026-10-30'])->assertNoContent();
        $this->assertSame('Abriu o WhatsApp para enviar o plantão de 30/10/2026', $this->registro(AcaoAuditada::PlantaoCompartilhado)->descricao);

        $this->postJson(route('auditoria.envio'), ['evento' => 'plantao', 'forma' => 'copia', 'dia' => '2026-10-30'])->assertNoContent();
        $this->assertSame('Copiou o texto do plantão de 30/10/2026', $this->registro(AcaoAuditada::PlantaoCompartilhado)->descricao);

        $this->postJson(route('auditoria.envio'), ['evento' => 'plantao', 'forma' => 'compartilhar', 'dia' => '2026-10-30'])->assertNoContent();
        $this->assertSame('Abriu o compartilhamento do plantão de 30/10/2026', $this->registro(AcaoAuditada::PlantaoCompartilhado)->descricao);
    }

    #[Test]
    public function tocar_no_envio_da_solicitacao_registra_para_quem_foi(): void
    {
        $solicitacao = $this->solicitacao();
        $motorista = Motorista::factory()->create(['nome_curto' => 'JOÃO']);
        $this->actingAs($this->admin);

        $this->postJson(route('auditoria.envio'), ['evento' => 'solicitacao', 'forma' => 'whatsapp', 'solicitacao' => $solicitacao->id, 'motorista' => $motorista->id])->assertNoContent();

        $registro = $this->registro(AcaoAuditada::SolicitacaoCompartilhada);

        // "Abriu" e não "enviou": a pessoa ainda pode desistir no WhatsApp.
        $this->assertSame("Abriu o WhatsApp para enviar a solicitação {$solicitacao->protocolo} a JOÃO", $registro->descricao);

        $this->postJson(route('auditoria.envio'), ['evento' => 'solicitacao', 'forma' => 'whatsapp', 'solicitacao' => $solicitacao->id]);
        $this->assertStringEndsWith('a outra pessoa dos contatos', $this->registro(AcaoAuditada::SolicitacaoCompartilhada)->descricao);

        $this->postJson(route('auditoria.envio'), ['evento' => 'solicitacao', 'forma' => 'copia', 'solicitacao' => $solicitacao->id]);
        $this->assertSame("Copiou o texto da solicitação {$solicitacao->protocolo}", $this->registro(AcaoAuditada::SolicitacaoCompartilhada)->descricao);
    }

    #[Test]
    public function o_toque_duplo_no_envio_nao_vira_duas_linhas(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('auditoria.envio'), ['evento' => 'plantao', 'forma' => 'whatsapp', 'dia' => '2026-10-30']);
        $this->postJson(route('auditoria.envio'), ['evento' => 'plantao', 'forma' => 'whatsapp', 'dia' => '2026-10-30']);

        $this->assertSame(1, RegistroDeAuditoria::query()->count());
    }

    /** Dados de saúde: quem não vê a solicitação também não anota nada sobre ela. */
    #[Test]
    public function o_envio_da_solicitacao_e_restrito_a_quem_ve_a_solicitacao(): void
    {
        $solicitacao = $this->solicitacao();
        $dados = ['evento' => 'solicitacao', 'forma' => 'whatsapp', 'solicitacao' => $solicitacao->id];

        $this->postJson(route('auditoria.envio'), $dados)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->leitor()->create());
        $this->postJson(route('auditoria.envio'), $dados)->assertForbidden();

        $this->assertNull(RegistroDeAuditoria::query()->where('acao', AcaoAuditada::SolicitacaoCompartilhada->value)->first());
    }

    #[Test]
    public function o_aviso_de_envio_recusa_o_que_nao_conhece(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('auditoria.envio'), ['evento' => 'outra-coisa', 'forma' => 'whatsapp'])->assertSessionHasErrors('evento');
        $this->postJson(route('auditoria.envio'), ['evento' => 'plantao', 'forma' => 'whatsapp', 'dia' => 'ontem'])->assertSessionHasErrors('dia');
        $this->postJson(route('auditoria.envio'), ['evento' => 'solicitacao', 'forma' => 'whatsapp', 'solicitacao' => 999999])->assertNotFound();

        $this->assertSame(0, RegistroDeAuditoria::query()->count());
    }

    #[Test]
    public function as_telas_avisam_a_auditoria_ao_tocar_nos_botoes_de_envio(): void
    {
        $solicitacao = $this->solicitacao();
        $motorista = Motorista::factory()->create(['telefone_1' => '85986926853']);
        $this->escalaPublicada(today()->year, today()->month);
        $this->actingAs($this->admin);

        $this->get(route('solicitacoes.show', $solicitacao))
            ->assertOk()
            ->assertSee("solicitacao: {$solicitacao->id}, motorista: {$motorista->id}", false);

        $this->get(route('plantao'))->assertOk()->assertSee("evento: 'plantao'", false);

        $painel = $this->get(route('painel'))->assertOk()->getContent();

        $this->assertStringContainsString('registrarEnvio', $painel);
        $this->assertStringContainsString('name="auditoria-envio"', $painel);
    }

    // -----------------------------------------------------------------
    // Dispositivo
    // -----------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function navegadores(): array
    {
        return [
            'Android' => ['Mozilla/5.0 (Linux; Android 14; SM-A515F) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', 'Celular · Android · Chrome'],
            'Samsung' => ['Mozilla/5.0 (Linux; Android 13; SM-A515F) AppleWebKit/537.36 SamsungBrowser/23.0 Chrome/115.0 Mobile Safari/537.36', 'Celular · Android · Samsung Internet'],
            'Tablet Android' => ['Mozilla/5.0 (Linux; Android 13; SM-T220) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'Tablet · Android · Chrome'],
            'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Celular · iPhone · Safari'],
            'iPad' => ['Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Version/16.0 Mobile/15E148 Safari/604.1', 'Tablet · iPad · Safari'],
            'Chrome no iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 CriOS/120.0 Mobile/15E148 Safari/604.1', 'Celular · iPhone · Chrome'],
            'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0', 'Computador · Windows · Edge'],
            'Windows Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'Computador · Windows · Chrome'],
            'Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Computador · Mac · Safari'],
            'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'Computador · Linux · Firefox'],
            'vazio' => [null, 'Dispositivo desconhecido'],
        ];
    }

    #[Test]
    #[DataProvider('navegadores')]
    public function reconhece_o_aparelho_pelo_navegador(?string $navegador, string $esperado): void
    {
        $this->assertSame($esperado, Dispositivo::descrever($navegador));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function registro(AcaoAuditada $acao): RegistroDeAuditoria
    {
        return RegistroDeAuditoria::query()->where('acao', $acao->value)->latest('id')->firstOrFail();
    }

    /** @return array{campo: string, de: ?string, para: ?string}|null */
    private function campo(RegistroDeAuditoria $registro, string $rotulo): ?array
    {
        return collect($registro->alteracoes ?? [])->firstWhere('campo', $rotulo);
    }

    /** @param  array<string, mixed>  $extras */
    private function dadosDeMotorista(array $extras = []): array
    {
        return [
            'nome_completo' => 'JOÃO DA SILVA',
            'nome_curto' => 'JOÃO',
            'cpf' => '123.456.789-09',
            'vinculo' => Vinculo::Efetivo->value,
            'telefone_1' => '(85) 98692-6853',
            'status' => StatusMotorista::Ativo->value,
            ...$extras,
        ];
    }

    private function solicitacao(): SolicitacaoDeTransporte
    {
        $this->post(route('solicitacao.enviar'), $this->dadosDeSolicitacao());

        return SolicitacaoDeTransporte::query()->latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $extras */
    private function dadosDeSolicitacao(array $extras = []): array
    {
        return [
            'hospital_nome' => 'Hospital Geral',
            'hospital_cidade' => 'Fortaleza',
            'hospital_bairro' => 'Papicu',
            'solicitante_nome' => 'Maria Clara',
            'solicitante_funcao' => 'Assistente Social',
            'solicitante_telefone' => '(85) 98692-6853',
            'paciente_nome' => 'JOSÉ DA SILVA',
            'paciente_idade' => 72,
            'paciente_idade_unidade' => 'anos',
            'contato_telefone_1' => '(85) 98888-7777',
            'destino_endereco' => 'Rua das Flores, 10',
            'destino_bairro' => 'Centro',
            'locomocao' => 'acamado',
            'declaracao' => 1,
            ...$extras,
        ];
    }

    private function escalaPublicada(int $ano = 2026, int $mes = 8): Escala
    {
        $unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $unidade->id, 'identificacao' => 'SEDE 1']);

        $this->actingAs($this->admin);

        $montador = app(MontadorDeEscala::class);
        $escala = $montador->criar($ano, $mes, copiarMesAnterior: false);
        $posto = $escala->postos()->first();

        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            $montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());
        $montador->publicar($escala->fresh());

        return $escala->fresh();
    }
}
