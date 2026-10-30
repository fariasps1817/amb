<?php

namespace Tests\Feature\Auditoria;

use App\Enums\AcaoAuditada;
use App\Enums\CategoriaDeAuditoria;
use App\Models\Escala;
use App\Models\RegistroDeAuditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A tela: so o administrador ve, com filtros, visao por sessao e exportacao.
 */
class TelaDaAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['nome' => 'Farias Sousa']);
    }

    #[Test]
    public function so_o_administrador_entra(): void
    {
        $sessao = '11111111-2222-3333-4444-555555555555';

        foreach ([route('auditoria.index'), route('auditoria.sessoes'), route('auditoria.sessao', $sessao), route('auditoria.exportar')] as $endereco) {
            $this->get($endereco)->assertRedirect(route('login'));

            $this->actingAs(User::factory()->create())->get($endereco)->assertForbidden();
            $this->actingAs(User::factory()->leitor()->create())->get($endereco)->assertForbidden();

            auth()->logout();
        }
    }

    #[Test]
    public function o_menu_mostra_a_auditoria_so_para_o_administrador(): void
    {
        $this->actingAs($this->admin)->get(route('painel'))->assertSee('Auditoria');

        $this->actingAs(User::factory()->create())->get(route('painel'))->assertDontSee('Auditoria');
    }

    #[Test]
    public function a_linha_do_tempo_mostra_quem_o_que_de_onde_e_os_detalhes(): void
    {
        $this->registrar([
            'descricao' => 'Alterou o cadastro de JOÃO',
            'usuario_nome' => 'Carlos Lima',
            'ip' => '177.12.3.4',
            'navegador' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile Safari/537.36',
            'alteracoes' => [['campo' => 'CPF', 'de' => '12345678909', 'para' => '52998224725']],
            'sessao' => '11111111-2222-3333-4444-555555555555',
        ]);

        $this->actingAs($this->admin)
            ->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Alterou o cadastro de JOÃO')
            ->assertSee('Carlos Lima')
            ->assertSee('177.12.3.4')
            ->assertSee('Celular · Android · Chrome')
            ->assertSeeInOrder(['CPF', 'Antes:', '12345678909', 'Depois:', '52998224725'])
            ->assertSee(route('auditoria.sessao', '11111111-2222-3333-4444-555555555555'), false);
    }

    #[Test]
    public function detalhe_sem_valor_anterior_mostra_so_o_valor(): void
    {
        $this->registrar(['alteracoes' => [['campo' => 'Motivo', 'de' => null, 'para' => 'Combinado com o motorista']]]);

        $this->actingAs($this->admin)
            ->get(route('auditoria.index'))
            ->assertSee('Combinado com o motorista')
            ->assertDontSee('Antes:');
    }

    #[Test]
    public function filtra_por_pessoa_tipo_escala_periodo_e_busca(): void
    {
        $this->travelTo('2026-10-30 10:00:00');
        $escala = Escala::query()->create(['ano' => 2026, 'mes' => 8]);

        $this->registrar(['descricao' => 'DO CARLOS', 'user_id' => 7, 'usuario_nome' => 'Carlos', 'created_at' => '2026-10-10 10:00:00']);
        $this->registrar(['descricao' => 'DA MARIA', 'user_id' => 8, 'usuario_nome' => 'Maria', 'created_at' => '2026-10-20 10:00:00']);
        $this->registrar(['descricao' => 'DOCUMENTO', 'categoria' => CategoriaDeAuditoria::Documento, 'acao' => AcaoAuditada::DocumentoEmitido->value]);
        $this->registrar(['descricao' => 'DA ESCALA', 'escala_id' => $escala->id]);

        $this->actingAs($this->admin);
        $tudo = ['DO CARLOS', 'DA MARIA', 'DOCUMENTO', 'DA ESCALA'];

        $this->assertSoAparece(['DO CARLOS'], $tudo, ['usuario' => 7]);
        $this->assertSoAparece(['DOCUMENTO'], $tudo, ['categoria' => 'documento']);
        $this->assertSoAparece(['DA ESCALA'], $tudo, ['escala' => $escala->id]);
        $this->assertSoAparece(['DO CARLOS'], $tudo, ['ate' => '2026-10-15']);
        $this->assertSoAparece(['DA MARIA'], $tudo, ['de' => '2026-10-15', 'ate' => '2026-10-20']);
        $this->assertSoAparece(['DA MARIA'], $tudo, ['busca' => 'Maria']);
    }

    /** O filtro lista quem já apareceu, inclusive quem foi excluído depois. */
    #[Test]
    public function o_filtro_de_pessoa_inclui_usuario_ja_excluido(): void
    {
        $this->registrar(['user_id' => 999, 'usuario_nome' => 'Fulano Excluído']);

        $this->actingAs($this->admin)
            ->get(route('auditoria.index'))
            ->assertSee('<option value="999"', false);
    }

    #[Test]
    public function filtro_com_valor_invalido_nao_derruba_a_tela(): void
    {
        $this->actingAs($this->admin)
            ->get(route('auditoria.index', ['categoria' => 'inventada', 'de' => 'ontem']))
            ->assertRedirect();
    }

    #[Test]
    public function as_sessoes_mostram_inicio_fim_acoes_e_como_terminaram(): void
    {
        $encerrada = '11111111-1111-1111-1111-111111111111';
        $aberta = '22222222-2222-2222-2222-222222222222';

        $this->registrar(['sessao' => $encerrada, 'categoria' => CategoriaDeAuditoria::Acesso, 'acao' => AcaoAuditada::SessaoEntrou->value, 'usuario_nome' => 'Carlos Lima', 'created_at' => '2026-10-30 08:00:00']);
        $this->registrar(['sessao' => $encerrada, 'usuario_nome' => 'Carlos Lima', 'created_at' => '2026-10-30 09:00:00']);
        $this->registrar(['sessao' => $encerrada, 'usuario_nome' => 'Carlos Lima', 'created_at' => '2026-10-30 09:30:00']);
        $this->registrar(['sessao' => $encerrada, 'categoria' => CategoriaDeAuditoria::Acesso, 'acao' => AcaoAuditada::SessaoInatividade->value, 'usuario_nome' => 'Carlos Lima', 'created_at' => '2026-10-30 10:00:00']);
        $this->registrar(['sessao' => $aberta, 'categoria' => CategoriaDeAuditoria::Acesso, 'acao' => AcaoAuditada::SessaoEntrou->value, 'usuario_nome' => 'Maria Souza', 'created_at' => '2026-10-30 11:00:00']);

        $this->actingAs($this->admin)
            ->get(route('auditoria.sessoes'))
            ->assertOk()
            ->assertSeeInOrder(['Maria Souza', 'Aberta', 'Carlos Lima', 'Encerrada por inatividade'])
            ->assertSee('30/10/2026 08:00 até 10:00')
            ->assertSee('2 ações');
    }

    #[Test]
    public function os_filtros_das_sessoes_valem(): void
    {
        $this->registrar(['sessao' => '11111111-1111-1111-1111-111111111111', 'user_id' => 7, 'usuario_nome' => 'Carlos Lima']);
        $this->registrar(['sessao' => '22222222-2222-2222-2222-222222222222', 'user_id' => 8, 'usuario_nome' => 'Maria Souza']);

        $this->actingAs($this->admin)
            ->get(route('auditoria.sessoes', ['usuario' => 7]))
            ->assertViewHas('sessoes', fn ($sessoes) => $sessoes->total() === 1 && $sessoes->first()->sessao === '11111111-1111-1111-1111-111111111111');
    }

    #[Test]
    public function uma_sessao_mostra_tudo_na_ordem_em_que_aconteceu(): void
    {
        $sessao = '11111111-1111-1111-1111-111111111111';

        $this->registrar(['sessao' => $sessao, 'descricao' => 'SEGUNDA ACAO', 'created_at' => '2026-10-30 09:00:00']);
        $this->registrar(['sessao' => $sessao, 'descricao' => 'PRIMEIRA ACAO', 'created_at' => '2026-10-30 08:00:00']);
        $this->registrar(['sessao' => $sessao, 'descricao' => 'Saiu do sistema', 'categoria' => CategoriaDeAuditoria::Acesso, 'acao' => AcaoAuditada::SessaoSaiu->value, 'created_at' => '2026-10-30 10:00:00']);
        $this->registrar(['sessao' => '22222222-2222-2222-2222-222222222222', 'descricao' => 'DE OUTRA SESSAO']);

        $this->actingAs($this->admin)
            ->get(route('auditoria.sessao', $sessao))
            ->assertOk()
            ->assertSeeInOrder(['PRIMEIRA ACAO', 'SEGUNDA ACAO', 'Saiu do sistema'])
            ->assertDontSee('DE OUTRA SESSAO');

        $this->get(route('auditoria.sessao', '33333333-3333-3333-3333-333333333333'))->assertNotFound();
    }

    #[Test]
    public function a_planilha_traz_o_recorte_com_acentos_e_a_exportacao_fica_registrada(): void
    {
        $this->registrar(['descricao' => 'Alterou o cadastro de JOÃO', 'usuario_nome' => 'Carlos Lima', 'alteracoes' => [['campo' => 'CPF', 'de' => '111', 'para' => '222']]]);
        $this->registrar(['descricao' => 'FORA DO FILTRO', 'categoria' => CategoriaDeAuditoria::Documento]);

        $resposta = $this->actingAs($this->admin)->get(route('auditoria.exportar', ['categoria' => 'sistema']));

        $resposta->assertOk();
        $resposta->assertDownload('auditoria-'.now()->format('Y-m-d').'.csv');

        $conteudo = $resposta->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo);
        $this->assertStringContainsString('Alterou o cadastro de JOÃO', $conteudo);
        $this->assertStringContainsString('CPF: 111 → 222', $conteudo);
        $this->assertStringNotContainsString('FORA DO FILTRO', $conteudo);

        $registro = RegistroDeAuditoria::query()->where('acao', AcaoAuditada::AuditoriaExportada->value)->sole();

        $this->assertSame('Farias Sousa', $registro->usuario_nome);
    }

    /** Encontrado olhando a tela: o segundo filtro se perdia no endereço do botão. */
    #[Test]
    public function o_botao_de_exportar_leva_todos_os_filtros(): void
    {
        $this->actingAs($this->admin)
            ->get(route('auditoria.index', ['categoria' => 'cadastro', 'de' => '2026-10-01']))
            ->assertSee('exportar?categoria=cadastro&amp;de=2026-10-01', false);
    }

    /** Parte do que vai na planilha vem da página aberta, que qualquer um preenche. */
    #[Test]
    public function a_planilha_nao_deixa_o_excel_executar_formula_vinda_da_pagina_aberta(): void
    {
        $this->registrar(['descricao' => '=HYPERLINK("http://malicioso")']);

        $conteudo = $this->actingAs($this->admin)->get(route('auditoria.exportar'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $conteudo);
        $this->assertStringNotContainsString(';=HYPERLINK', $conteudo);
    }

    #[Test]
    public function as_abas_ligam_as_tres_telas(): void
    {
        $this->actingAs($this->admin);

        foreach ([route('auditoria.index'), route('auditoria.sessoes'), route('acessos.index')] as $endereco) {
            $this->get($endereco)
                ->assertOk()
                ->assertSee(route('auditoria.index'), false)
                ->assertSee(route('auditoria.sessoes'), false)
                ->assertSee(route('acessos.index'), false);
        }
    }

    /**
     * @param  list<string>  $esperados
     * @param  list<string>  $todos
     * @param  array<string, mixed>  $filtros
     */
    private function assertSoAparece(array $esperados, array $todos, array $filtros): void
    {
        $resposta = $this->get(route('auditoria.index', $filtros))->assertOk();

        foreach ($todos as $descricao) {
            in_array($descricao, $esperados, true)
                ? $resposta->assertSee($descricao)
                : $resposta->assertDontSee($descricao);
        }
    }

    /** @param  array<string, mixed>  $atributos */
    private function registrar(array $atributos = []): RegistroDeAuditoria
    {
        return RegistroDeAuditoria::query()->create([
            'categoria' => CategoriaDeAuditoria::Sistema,
            'acao' => AcaoAuditada::ConfiguracaoAlterada->value,
            'descricao' => 'Alterou a identidade institucional',
            ...$atributos,
        ]);
    }
}
