<?php

namespace Tests\Feature;

use App\Enums\Locomocao;
use App\Enums\MotivoNaoAtendimento;
use App\Enums\StatusSolicitacao;
use App\Models\Ambulancia;
use App\Models\Motorista;
use App\Models\SolicitacaoDeTransporte;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Whatsapp\MensagemDeTransporte;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Solicitacao de ambulancia para paciente em alta: a pagina aberta usada pelos
 * hospitais e o tratamento pela coordenacao.
 */
class SolicitacoesDeAmbulanciaTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Pagina aberta
    // -----------------------------------------------------------------

    #[Test]
    public function o_formulario_abre_sem_login(): void
    {
        $this->get(route('solicitacao.formulario'))
            ->assertOk()
            ->assertSee('profissionais do hospital')
            ->assertSee('Cascavel - CE')
            ->assertSee('Sou profissional deste hospital')
            ->assertSee('noindex', false);
    }

    #[Test]
    public function registra_o_pedido_com_data_e_municipio_definidos_pelo_servidor(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 14:32:00'));

        $resposta = $this->post(route('solicitacao.enviar'), $this->dados([
            // Nada disto pode ser decidido por quem envia.
            'solicitada_em' => '2020-01-01 00:00:00',
            'destino_municipio' => 'Fortaleza',
            'status' => 'atendida',
            'motorista_id' => 999,
        ]));

        $solicitacao = SolicitacaoDeTransporte::query()->sole();

        $resposta->assertRedirectContains('/solicitar-ambulancia/recebida/'.$solicitacao->protocolo);

        $this->assertSame('2026-10-30 14:32:00', $solicitacao->solicitada_em->toDateTimeString());
        $this->assertSame('America/Fortaleza', config('app.timezone'));
        $this->assertSame('Cascavel', $solicitacao->destino_municipio);
        $this->assertSame(StatusSolicitacao::Aguardando, $solicitacao->status);
        $this->assertNull($solicitacao->motorista_id);
        $this->assertTrue($solicitacao->declaracao_profissional);
        $this->assertSame('85986926853', $solicitacao->solicitante_telefone);
        $this->assertSame(Locomocao::Acamado, $solicitacao->locomocao);
        $this->assertTrue($solicitacao->necessita_oxigenio);
        $this->assertFalse($solicitacao->necessita_aspirador);
        $this->assertSame(sprintf('AH-2026-%05d', $solicitacao->id), $solicitacao->protocolo);
    }

    /** A confirmação só mostra o protocolo: nada do paciente fica exposto. */
    #[Test]
    public function a_confirmacao_mostra_o_protocolo_e_nenhum_dado_do_paciente(): void
    {
        $resposta = $this->post(route('solicitacao.enviar'), $this->dados());
        $solicitacao = SolicitacaoDeTransporte::query()->sole();

        $this->followRedirects($resposta)
            ->assertOk()
            ->assertSee($solicitacao->protocolo)
            ->assertSee('A coordenação vai ligar para confirmar')
            ->assertDontSee('JOSÉ DA SILVA');
    }

    #[Test]
    public function a_confirmacao_nao_abre_trocando_o_numero_no_endereco(): void
    {
        $this->post(route('solicitacao.enviar'), $this->dados());
        $solicitacao = SolicitacaoDeTransporte::query()->sole();

        $this->get(route('solicitacao.recebida', ['protocolo' => $solicitacao->protocolo]))->assertForbidden();
    }

    #[Test]
    public function exige_a_declaracao_e_os_campos_obrigatorios(): void
    {
        $this->post(route('solicitacao.enviar'), [])
            ->assertSessionHasErrors([
                'hospital_nome', 'hospital_cidade', 'hospital_bairro',
                'solicitante_nome', 'solicitante_funcao', 'solicitante_telefone',
                'paciente_nome', 'paciente_idade', 'contato_telefone_1',
                'destino_endereco', 'destino_bairro', 'locomocao', 'declaracao',
            ]);

        $this->assertSame(0, SolicitacaoDeTransporte::query()->count());
    }

    /** Os hospitais ficam em vários municípios: sem DDD não dá para ligar. */
    #[Test]
    public function exige_telefone_com_ddd(): void
    {
        $this->post(route('solicitacao.enviar'), $this->dados(['solicitante_telefone' => '98692-6853']))
            ->assertSessionHasErrors('solicitante_telefone');
    }

    /** Encontrado testando de verdade: bytes fora de UTF-8 causavam erro 500 ao gravar. */
    #[Test]
    public function texto_com_codificacao_errada_nao_derruba_a_pagina(): void
    {
        $this->post(route('solicitacao.enviar'), $this->dados(['hospital_nome' => "Hospital Dr. C\xE9sar Cals"]))
            ->assertRedirect();

        $this->assertSame(1, SolicitacaoDeTransporte::query()->count());
    }

    /** Robô cai na armadilha -- e nunca se finge sucesso, pois pode ser uma pessoa. */
    #[Test]
    public function campo_armadilha_impede_o_registro_e_avisa(): void
    {
        $this->post(route('solicitacao.enviar'), $this->dados(['pagina_pessoal' => 'http://spam.example']))
            ->assertSessionHas('erro');

        $this->assertSame(0, SolicitacaoDeTransporte::query()->count());
    }

    /** Toque duplo, ou internet que caiu antes da resposta: não vira dois pedidos. */
    #[Test]
    public function reenviar_o_mesmo_formulario_nao_duplica(): void
    {
        $chave = (string) Str::uuid();

        $this->post(route('solicitacao.enviar'), $this->dados(['chave_envio' => $chave]));
        $this->post(route('solicitacao.enviar'), $this->dados(['chave_envio' => $chave]))
            ->assertRedirectContains(SolicitacaoDeTransporte::query()->sole()->protocolo);

        $this->assertSame(1, SolicitacaoDeTransporte::query()->count());
    }

    /** Voltou a página e preencheu outro paciente: é outro pedido, não pode se perder. */
    #[Test]
    public function mesma_chave_com_outro_paciente_e_outro_pedido(): void
    {
        $chave = (string) Str::uuid();

        $this->post(route('solicitacao.enviar'), $this->dados(['chave_envio' => $chave]));
        $this->post(route('solicitacao.enviar'), $this->dados([
            'chave_envio' => $chave,
            'paciente_nome' => 'MARIA DE SOUSA',
            'contato_telefone_1' => '85999990000',
        ]));

        $this->assertSame(2, SolicitacaoDeTransporte::query()->count());
    }

    #[Test]
    public function mesmo_paciente_e_telefone_em_poucas_horas_entra_como_possivel_duplicada(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));
        $this->post(route('solicitacao.enviar'), $this->dados());
        $primeira = SolicitacaoDeTransporte::query()->sole();

        $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
        $this->post(route('solicitacao.enviar'), $this->dados(['paciente_nome' => 'josé da silva']));

        $this->assertSame($primeira->id, SolicitacaoDeTransporte::query()->latest('id')->first()->possivel_duplicada_de);

        // Dias depois, é outra internação: não é duplicidade.
        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
        $this->post(route('solicitacao.enviar'), $this->dados());

        $this->assertNull(SolicitacaoDeTransporte::query()->latest('id')->first()->possivel_duplicada_de);
    }

    #[Test]
    public function limita_os_envios_por_origem_sem_perder_o_que_foi_digitado(): void
    {
        foreach (range(1, 30) as $i) {
            RateLimiter::hit(md5('solicitacoes'.'127.0.0.1'), 3600);
        }

        $this->post(route('solicitacao.enviar'), $this->dados())
            ->assertSessionHas('erro', fn ($texto) => str_contains($texto, 'Muitos envios'))
            ->assertSessionHasInput('paciente_nome', 'JOSÉ DA SILVA');

        $this->assertSame(0, SolicitacaoDeTransporte::query()->count());
    }

    /**
     * Sem login não há sessão a proteger. Com o token, quem deixasse o formulário
     * aberto além do prazo da sessão perderia tudo o que digitou.
     */
    #[Test]
    public function a_pagina_aberta_nao_depende_do_token_de_sessao(): void
    {
        $middleware = app(PreventRequestForgery::class);

        $this->assertContains('solicitar-ambulancia', $middleware->getExcludedPaths());
    }

    // -----------------------------------------------------------------
    // Quem ve
    // -----------------------------------------------------------------

    #[Test]
    public function so_administrador_e_operador_veem_as_solicitacoes(): void
    {
        $solicitacao = $this->solicitacao();

        $this->get(route('solicitacoes.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->leitor()->create());
        $this->get(route('solicitacoes.index'))->assertForbidden();
        $this->get(route('solicitacoes.show', $solicitacao))->assertForbidden();
        $this->get(route('solicitacoes.contagem'))->assertForbidden();
        $this->post(route('solicitacoes.reabrir', $solicitacao))->assertForbidden();

        $this->actingAs(User::factory()->create());
        $this->get(route('solicitacoes.index'))->assertOk()->assertSee('JOSÉ DA SILVA');

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('solicitacoes.show', $solicitacao))->assertOk()->assertSee('JOSÉ DA SILVA');
    }

    // -----------------------------------------------------------------
    // Painel e contador
    // -----------------------------------------------------------------

    #[Test]
    public function o_painel_mostra_o_cartao_com_o_numero_vermelho(): void
    {
        $this->solicitacao();
        $this->solicitacao(['paciente_nome' => 'MARIA', 'contato_telefone_1' => '85999990000']);

        $this->actingAs(User::factory()->create());

        $this->get(route('painel'))
            ->assertOk()
            ->assertSee('Solicitações de ambulância')
            ->assertSee('2 aguardando atendimento')
            ->assertSee(route('solicitacoes.index'), false)
            ->assertViewHas('solicitacoesAguardando', fn ($s) => $s['total'] === 2);

        $this->getJson(route('solicitacoes.contagem'))->assertExactJson(['aguardando' => 2]);
    }

    #[Test]
    public function o_perfil_de_consulta_nao_ve_o_cartao_nem_o_menu(): void
    {
        $this->solicitacao();
        $this->actingAs(User::factory()->leitor()->create());

        $this->get(route('painel'))
            ->assertOk()
            ->assertDontSee('Solicitações de ambulância')
            ->assertDontSee(route('solicitacoes.index'), false)
            ->assertDontSee('contagem-solicitacoes', false);
    }

    // -----------------------------------------------------------------
    // Lista e detalhes
    // -----------------------------------------------------------------

    #[Test]
    public function a_lista_traz_quem_espera_ha_mais_tempo_primeiro_e_filtra(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 08:00:00'));
        $antiga = $this->solicitacao(['paciente_nome' => 'PACIENTE ANTIGO', 'contato_telefone_1' => '85911110000']);
        $this->travelTo(Carbon::parse('2026-10-30 09:00:00'));
        $this->solicitacao(['paciente_nome' => 'PACIENTE NOVO', 'contato_telefone_1' => '85922220000']);
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));
        $atendida = $this->solicitacao(['paciente_nome' => 'PACIENTE ATENDIDO', 'contato_telefone_1' => '85933330000']);
        $atendida->update(['status' => StatusSolicitacao::Atendida]);

        $this->actingAs(User::factory()->create());

        $this->get(route('solicitacoes.index'))
            ->assertOk()
            ->assertSeeInOrder(['PACIENTE ANTIGO', 'PACIENTE NOVO'])
            ->assertDontSee('PACIENTE ATENDIDO');

        $this->get(route('solicitacoes.index', ['situacao' => 'atendida']))
            ->assertSee('PACIENTE ATENDIDO')
            ->assertDontSee('PACIENTE ANTIGO');

        $this->get(route('solicitacoes.index', ['situacao' => 'todas', 'busca' => $antiga->protocolo]))
            ->assertSee('PACIENTE ANTIGO')
            ->assertDontSee('PACIENTE NOVO');
    }

    #[Test]
    public function os_detalhes_mostram_o_contato_a_situacao_e_o_motorista_de_plantao_primeiro(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $unidade->id, 'identificacao' => 'SEDE 1']);

        $this->actingAs(User::factory()->create());

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();
        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $forasteiro = Motorista::factory()->create(['nome_curto' => 'FORA DO PLANTAO']);
        $dePlantao = $escala->plantoes()->whereDate('data', '2026-08-01')->first()->motorista;

        $solicitacao = $this->solicitacao();

        $this->get(route('solicitacoes.show', $solicitacao))
            ->assertOk()
            ->assertSee('tel:85986926853', false)
            ->assertSee('Maria Clara | Assistente Social')
            ->assertSee('Acamado (maca)')
            ->assertSee('Oxigênio')
            ->assertSeeInOrder(['De plantão agora', $dePlantao->nome_curto, 'Demais motoristas', $forasteiro->nome_curto]);
    }

    // -----------------------------------------------------------------
    // Enviar para o motorista
    // -----------------------------------------------------------------

    #[Test]
    public function a_mensagem_traz_o_que_o_motorista_precisa_e_nada_da_origem_do_envio(): void
    {
        $solicitacao = $this->solicitacao(['contato_telefone_2' => '(85) 97777-6666']);

        $texto = app(MensagemDeTransporte::class)->texto($solicitacao);

        $this->assertStringContainsString('*TRANSPORTE DE PACIENTE — ALTA HOSPITALAR*', $texto);
        $this->assertStringContainsString('Protocolo '.$solicitacao->protocolo, $texto);
        $this->assertStringContainsString("*Buscar em:*\nHospital Geral de Fortaleza\nPapicu · Fortaleza", $texto);
        $this->assertStringContainsString('Contato: Maria Clara | Assistente Social · (85) 98692-6853', $texto);
        $this->assertStringContainsString('JOSÉ DA SILVA · 72 anos', $texto);
        $this->assertStringContainsString('Acompanhante: Ana da Silva', $texto);
        $this->assertStringContainsString('Telefone: (85) 98888-7777 / (85) 97777-6666', $texto);
        $this->assertStringContainsString("*Levar para:*\nRua das Flores, 10\nCentro · Cascavel-CE\nReferência: Perto da praça", $texto);
        $this->assertStringContainsString('Acamado (maca) · Oxigênio · Consciente', $texto);
        $this->assertStringNotContainsString($solicitacao->ip, $texto);
    }

    /** Campos opcionais em branco não deixam linhas soltas na mensagem. */
    #[Test]
    public function a_mensagem_omite_o_que_nao_foi_informado(): void
    {
        $solicitacao = $this->solicitacao(['acompanhante_nome' => null, 'destino_referencia' => null]);

        $texto = app(MensagemDeTransporte::class)->texto($solicitacao);

        $this->assertStringNotContainsString('Acompanhante:', $texto);
        $this->assertStringNotContainsString('Referência:', $texto);
    }

    #[Test]
    public function os_detalhes_oferecem_enviar_ao_motorista_pelo_whatsapp(): void
    {
        $solicitacao = $this->solicitacao();
        $comTelefone = Motorista::factory()->create(['nome_curto' => 'COM TELEFONE', 'telefone_1' => '85986926853']);
        Motorista::factory()->create(['nome_curto' => 'SEM TELEFONE', 'telefone_1' => null]);

        $this->actingAs(User::factory()->create());

        $this->get(route('solicitacoes.show', $solicitacao))
            ->assertOk()
            ->assertSee('Enviar para o motorista')
            ->assertSee('Enviar para este motorista')
            ->assertSee('value="5585986926853"', false)
            ->assertSee('SEM TELEFONE (sem telefone)')
            ->assertSee('https://wa.me/?text=', false)
            ->assertSee('TRANSPORTE DE PACIENTE');
    }

    /** Depois do desfecho não há mais viagem a combinar. */
    #[Test]
    public function solicitacao_com_desfecho_nao_oferece_o_envio(): void
    {
        $solicitacao = $this->solicitacao();
        $solicitacao->update(['status' => StatusSolicitacao::NaoAtendida, 'motivo_nao_atendimento' => MotivoNaoAtendimento::HospitalCancelou]);

        $this->actingAs(User::factory()->create());

        $this->get(route('solicitacoes.show', $solicitacao))
            ->assertOk()
            ->assertDontSee('Enviar para o motorista');
    }

    // -----------------------------------------------------------------
    // Desfecho
    // -----------------------------------------------------------------

    #[Test]
    public function registra_o_atendimento_com_motorista_data_e_observacoes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));
        $solicitacao = $this->solicitacao();
        $motorista = Motorista::factory()->create();
        $usuario = User::factory()->create();

        $this->travelTo(Carbon::parse('2026-10-30 15:00:00'));

        $this->actingAs($usuario)
            ->post(route('solicitacoes.atendida', $solicitacao), [
                'motorista_id' => $motorista->id,
                'atendida_em' => '2026-10-30T14:30',
                'observacoes_atendimento' => 'Paciente entregue à família.',
            ])
            ->assertRedirect(route('solicitacoes.index'))
            ->assertSessionHas('sucesso');

        $solicitacao->refresh();
        $this->assertSame(StatusSolicitacao::Atendida, $solicitacao->status);
        $this->assertSame($motorista->id, $solicitacao->motorista_id);
        $this->assertSame('2026-10-30 14:30:00', $solicitacao->atendida_em->toDateTimeString());
        $this->assertSame('Paciente entregue à família.', $solicitacao->observacoes_atendimento);
        $this->assertSame($usuario->id, $solicitacao->registrada_por);
        $this->assertNotNull($solicitacao->registrada_em);

        // Saiu do número vermelho.
        $this->getJson(route('solicitacoes.contagem'))->assertExactJson(['aguardando' => 0]);
    }

    #[Test]
    public function o_atendimento_nao_pode_ser_antes_do_pedido_nem_no_futuro_nem_com_motorista_inativo(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:40'));
        $solicitacao = $this->solicitacao();
        $motorista = Motorista::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $motorista->id, 'atendida_em' => '2026-10-30T09:59'])
            ->assertSessionHasErrors('atendida_em');

        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $motorista->id, 'atendida_em' => '2026-10-31T09:00'])
            ->assertSessionHasErrors('atendida_em');

        $inativo = Motorista::factory()->inativo()->create();
        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $inativo->id, 'atendida_em' => '2026-10-30T10:00'])
            ->assertSessionHasErrors('motorista_id');

        // No mesmo minuto do pedido vale: o campo só vai até o minuto.
        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $motorista->id, 'atendida_em' => '2026-10-30T10:00'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function registra_nao_atendida_com_motivo(): void
    {
        $solicitacao = $this->solicitacao();
        $this->actingAs(User::factory()->create());

        $this->post(route('solicitacoes.nao-atendida', $solicitacao), [])->assertSessionHasErrors('motivo_nao_atendimento');

        // "Outro motivo" sem explicação não diz nada a quem consultar depois.
        $this->post(route('solicitacoes.nao-atendida', $solicitacao), ['motivo_nao_atendimento' => MotivoNaoAtendimento::Outro->value])
            ->assertSessionHasErrors('observacoes_atendimento');

        $this->post(route('solicitacoes.nao-atendida', $solicitacao), ['motivo_nao_atendimento' => MotivoNaoAtendimento::Indevida->value])
            ->assertSessionHas('sucesso');

        $this->assertSame(StatusSolicitacao::NaoAtendida, $solicitacao->fresh()->status);
        $this->assertSame(MotivoNaoAtendimento::Indevida, $solicitacao->fresh()->motivo_nao_atendimento);
        $this->get(route('solicitacoes.show', $solicitacao))->assertOk()->assertSee('Solicitação indevida');
    }

    /** Duas pessoas abrindo o mesmo pedido: a segunda não sobrescreve a primeira. */
    #[Test]
    public function nao_sobrescreve_o_desfecho_ja_registrado(): void
    {
        $solicitacao = $this->solicitacao();
        $primeiro = Motorista::factory()->create();
        $segundo = Motorista::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $primeiro->id, 'atendida_em' => now()->format('Y-m-d\TH:i')]);
        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => $segundo->id, 'atendida_em' => now()->format('Y-m-d\TH:i')])
            ->assertSessionHas('erro');

        $this->assertSame($primeiro->id, $solicitacao->fresh()->motorista_id);
    }

    #[Test]
    public function reabrir_apaga_o_desfecho_e_volta_a_contar(): void
    {
        $solicitacao = $this->solicitacao();
        $this->actingAs(User::factory()->create());
        $this->post(route('solicitacoes.atendida', $solicitacao), ['motorista_id' => Motorista::factory()->create()->id, 'atendida_em' => now()->format('Y-m-d\TH:i')]);

        $this->post(route('solicitacoes.reabrir', $solicitacao))->assertSessionHas('atencao');

        $solicitacao->refresh();
        $this->assertSame(StatusSolicitacao::Aguardando, $solicitacao->status);
        $this->assertNull($solicitacao->motorista_id);
        $this->assertNull($solicitacao->atendida_em);
        $this->assertNull($solicitacao->registrada_por);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** @param  array<string, mixed>  $extras */
    private function solicitacao(array $extras = []): SolicitacaoDeTransporte
    {
        $this->post(route('solicitacao.enviar'), $this->dados($extras));

        return SolicitacaoDeTransporte::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function dados(array $extras = []): array
    {
        return [
            'hospital_nome' => 'Hospital Geral de Fortaleza',
            'hospital_cidade' => 'Fortaleza',
            'hospital_bairro' => 'Papicu',
            'solicitante_nome' => 'Maria Clara',
            'solicitante_funcao' => 'Assistente Social',
            'solicitante_telefone' => '(85) 98692-6853',
            'paciente_nome' => 'JOSÉ DA SILVA',
            'paciente_idade' => 72,
            'paciente_idade_unidade' => 'anos',
            'acompanhante_nome' => 'Ana da Silva',
            'contato_telefone_1' => '(85) 98888-7777',
            'destino_endereco' => 'Rua das Flores, 10',
            'destino_bairro' => 'Centro',
            'destino_referencia' => 'Perto da praça',
            'locomocao' => Locomocao::Acamado->value,
            'consciente' => 1,
            'necessita_oxigenio' => 1,
            'declaracao' => 1,
            ...$extras,
        ];
    }
}
