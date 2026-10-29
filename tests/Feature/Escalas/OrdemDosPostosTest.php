<?php

namespace Tests\Feature\Escalas;

use App\Livewire\DefinirDestinos;
use App\Livewire\MontarEscala;
use App\Livewire\OrdenarPostos;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sequencia das ambulancias no mes: a ordem dos blocos na montagem, na
 * planilha impressa e na lista de quem esta de plantao.
 */
class OrdemDosPostosTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $sede;

    private Unidade $guanaces;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->sede = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE', 'ordem' => 1]);
        $this->guanaces = Unidade::factory()->regime2472()->create(['sigla' => 'GUANACES', 'ordem' => 2]);

        foreach (['SEDE 1', 'SEDE 2', 'SEDE 3'] as $identificacao) {
            Ambulancia::factory()->create(['unidade_id' => $this->sede->id, 'identificacao' => $identificacao]);
        }
        Ambulancia::factory()->create(['unidade_id' => $this->guanaces->id, 'identificacao' => 'GUANACES']);
    }

    /** O caso que motivou a ordenacao: a SEDE 4 ia parar no fim da planilha. */
    #[Test]
    public function nova_ambulancia_entra_depois_da_ultima_da_mesma_unidade(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $nova = Ambulancia::factory()->create(['unidade_id' => $this->sede->id, 'identificacao' => 'SEDE 4']);

        app(MontadorDeEscala::class)->adicionarPosto($escala, $this->sede->id, $nova->id);

        $this->assertSame(['SEDE 1', 'SEDE 2', 'SEDE 3', 'SEDE 4', 'GUANACES'], $this->sequencia($escala));
    }

    #[Test]
    public function unidade_ainda_sem_ambulancia_no_mes_vai_para_o_fim(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $brito = Unidade::factory()->regime2472()->create(['sigla' => 'BRITO', 'ordem' => 0]);
        $nova = Ambulancia::factory()->create(['unidade_id' => $brito->id, 'identificacao' => 'BRITO']);

        app(MontadorDeEscala::class)->adicionarPosto($escala, $brito->id, $nova->id);

        $this->assertSame(['SEDE 1', 'SEDE 2', 'SEDE 3', 'GUANACES', 'BRITO'], $this->sequencia($escala));
    }

    #[Test]
    public function sobe_e_desce_uma_casa_e_para_nas_pontas(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $montador = app(MontadorDeEscala::class);
        $postos = $escala->postos()->get()->keyBy(fn ($p) => $p->rotuloLotacao());

        $montador->moverPosto($postos['GUANACES'], -1);
        $this->assertSame(['SEDE 1', 'SEDE 2', 'GUANACES', 'SEDE 3'], $this->sequencia($escala));

        // Nas pontas nao ha para onde ir: nada muda.
        $montador->moverPosto($postos['SEDE 1'], -1);
        $montador->moverPosto($postos['SEDE 3']->fresh(), 1);
        $this->assertSame(['SEDE 1', 'SEDE 2', 'GUANACES', 'SEDE 3'], $this->sequencia($escala));
    }

    /** Quem acerta a ordem uma vez nao precisa acertar de novo no mes seguinte. */
    #[Test]
    public function a_ordem_ajustada_segue_para_o_mes_seguinte(): void
    {
        $agosto = app(MontadorDeEscala::class)->criar(2026, 8);
        $guanaces = $agosto->postos()->get()->first(fn ($p) => $p->rotuloLotacao() === 'GUANACES');
        app(MontadorDeEscala::class)->moverPosto($guanaces, -1);
        app(MontadorDeEscala::class)->moverPosto($guanaces->fresh(), -1);
        app(MontadorDeEscala::class)->moverPosto($guanaces->fresh(), -1);

        $setembro = app(MontadorDeEscala::class)->criar(2026, 9);

        $this->assertSame(['GUANACES', 'SEDE 1', 'SEDE 2', 'SEDE 3'], $this->sequencia($setembro));
    }

    #[Test]
    public function o_painel_de_ordenacao_move_e_avisa_a_montagem(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $guanaces = $escala->postos()->get()->first(fn ($p) => $p->rotuloLotacao() === 'GUANACES');

        Livewire::test(OrdenarPostos::class, ['escala' => $escala])
            ->dispatch('abrir-ordenacao')
            ->assertSee('Ordem das ambulâncias')
            ->call('mover', $guanaces->id, -1)
            ->assertDispatched('postos-reordenados');

        $this->assertSame(['SEDE 1', 'SEDE 2', 'GUANACES', 'SEDE 3'], $this->sequencia($escala));
    }

    /** Na escala pronta a planilha nao e Livewire: concluir recarrega a pagina. */
    #[Test]
    public function na_escala_pronta_concluir_recarrega_a_pagina(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $guanaces = $escala->postos()->get()->first(fn ($p) => $p->rotuloLotacao() === 'GUANACES');
        $endereco = route('escalas.show', $escala);

        Livewire::test(OrdenarPostos::class, ['escala' => $escala, 'recarregar' => $endereco])
            ->call('abrir')
            ->call('mover', $guanaces->id, -1)
            ->call('concluir')
            ->assertRedirect($endereco);
    }

    #[Test]
    public function a_escala_pronta_oferece_a_ordenacao_a_quem_edita(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);

        $this->get(route('escalas.show', $escala))
            ->assertOk()
            ->assertSee('Ordenar ambulâncias');

        $this->actingAs(User::factory()->leitor()->create())
            ->get(route('escalas.show', $escala))
            ->assertOk()
            ->assertDontSee('Ordenar ambulâncias');
    }

    /**
     * O perfil de consulta so visualiza e imprime. As telas de montagem e de
     * destinos nao conferiam o perfil: bastava digitar o endereco.
     */
    #[Test]
    public function consulta_nao_abre_montagem_destinos_nem_ordenacao(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        Motorista::factory()->create();

        $leitor = User::factory()->leitor()->create();
        $this->actingAs($leitor);

        $this->get(route('escalas.montar', $escala))->assertForbidden();
        $this->get(route('escalas.destinos', $escala))->assertForbidden();

        // A tela de destinos grava ao abrir; barrada, nao pode ter gravado.
        $this->assertSame(0, EscalaLotacao::query()->where('escala_id', $escala->id)->count());

        Livewire::test(MontarEscala::class, ['escala' => $escala])->assertForbidden();
        Livewire::test(DefinirDestinos::class, ['escala' => $escala])->assertForbidden();
        Livewire::test(OrdenarPostos::class, ['escala' => $escala])->assertForbidden();
    }

    /** @return list<string> */
    private function sequencia(Escala $escala): array
    {
        return $escala->fresh()->postos()->get()->map(fn ($p) => $p->rotuloLotacao())->all();
    }
}
