<?php

namespace Tests\Feature;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\ComparadorDePropostas;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Comparacao das propostas de escala de um mes -- a tela que o coordenador
 * leva a Secretaria de Saude para a escolha.
 */
class ComparacaoDePropostasTest extends TestCase
{
    use RefreshDatabase;

    private MontadorDeEscala $montador;

    private Unidade $sede;

    /** Os quatro motoristas da SEDE 1, na ordem do ciclo. */
    private array $equipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->montador = app(MontadorDeEscala::class);

        $this->sede = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $this->sede->id, 'placa' => 'THQ4H34', 'identificacao' => 'SEDE 1']);

        $this->equipe = collect(['ANA LIMA', 'BRUNO DIAS', 'CARLA REIS', 'DIEGO MOTA'])
            ->map(fn (string $nome) => Motorista::factory()->create(['nome_completo' => $nome, 'nome_curto' => $nome]))
            ->all();
    }

    #[Test]
    public function mostra_cada_proposta_com_numeros_descricao_e_link(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();

        $propostaA->update(['observacao' => 'Mantém a frota atual.']);
        $propostaB->update(['observacao' => 'Pede uma ambulância a mais na Sede, com quatro contratações.']);
        $this->montador->adicionarPosto($propostaB, $this->sede->id, null, 'SEDE 4');

        $resposta = $this->get(route('escalas.comparar', $propostaA))
            ->assertOk()
            ->assertSeeText('Proposta A')
            ->assertSeeText('Proposta B')
            ->assertSeeText('Mantém a frota atual.')
            ->assertSeeText('Pede uma ambulância a mais na Sede, com quatro contratações.')
            ->assertSeeText('Vagas a contratar')
            ->assertSeeText('Ambulâncias a definir')
            ->assertSee(route('escalas.show', $propostaA), false)
            ->assertSee(route('escalas.show', $propostaB), false);

        $numeros = $resposta->viewData('quadros')->pluck('numeros', 'nome');

        $this->assertSame(['postos' => 1, 'vagas' => '4/4', 'a_contratar' => 0, 'a_definir' => 0], array_intersect_key(
            $numeros['Proposta A'],
            array_flip(['postos', 'vagas', 'a_contratar', 'a_definir'])
        ));
        $this->assertSame(['postos' => 2, 'vagas' => '4/8', 'a_contratar' => 4, 'a_definir' => 1], array_intersect_key(
            $numeros['Proposta B'],
            array_flip(['postos', 'vagas', 'a_contratar', 'a_definir'])
        ));
        $this->assertSame(4, $numeros['Proposta A']['escalados']);
        $this->assertSame(31, $numeros['Proposta A']['plantoes']);

        // O que muda entre as duas fica em destaque; o que nao muda, nao.
        $diferentes = $resposta->viewData('diferentes');
        $this->assertContains('a_contratar', $diferentes);
        $this->assertContains('a_definir', $diferentes);
        $this->assertNotContains('escalados', $diferentes);
    }

    /** A pendencia que impede a publicacao aparece com a mensagem do validador. */
    #[Test]
    public function mostra_as_pendencias_que_impedem_a_publicacao(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();
        $this->montador->adicionarPosto($propostaB, $this->sede->id, null, 'SEDE 4');

        $quadros = $this->get(route('escalas.comparar', $propostaB))
            ->assertOk()
            ->assertSeeText('Nenhuma pendência para publicar.')
            ->assertSeeText('impedem a publicação')
            ->assertSeeText('O posto SEDE 4 está sem ambulância definida.')
            ->viewData('quadros')
            ->keyBy('nome');

        $this->assertSame([], $quadros['Proposta A']['erros']);
        $this->assertContains('O posto SEDE 4 está sem ambulância definida.', $quadros['Proposta B']['erros']);
    }

    #[Test]
    public function aponta_a_ambulancia_que_so_uma_proposta_tem(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();

        $nova = Ambulancia::factory()->create(['unidade_id' => $this->sede->id, 'placa' => 'ABC1D23', 'identificacao' => 'SEDE 2']);
        $this->montador->adicionarPosto($propostaB, $this->sede->id, $nova->id);
        $this->montador->adicionarPosto($propostaB, $this->sede->id, null, 'SEDE 4');

        $diferencas = ComparadorDePropostas::doMesDe($propostaA)->diferencasDeAmbulancias();

        // A SEDE 1, que esta nas duas do mesmo jeito, nao aparece.
        $this->assertSame(['ABC1D23 · SEDE 2', 'SEDE 4 · ambulância a definir'], $diferencas->pluck('rotulo')->all());
        $this->assertSame(
            ['Proposta A' => 'Não entra', 'Proposta B' => 'SEDE 2 (24/72)'],
            array_column($diferencas->first()['situacoes'], 'valor', 'nome')
        );

        $this->get(route('escalas.comparar', $propostaA))
            ->assertOk()
            ->assertSeeText('ABC1D23 · SEDE 2:')
            ->assertSeeText('Proposta A → Não entra')
            ->assertSeeText('Proposta B → SEDE 2 (24/72)')
            ->assertSeeText('SEDE 4 · ambulância a definir:');
    }

    /** Remanejar uma ambulancia para outra unidade tambem e diferenca. */
    #[Test]
    public function aponta_a_ambulancia_que_muda_de_lotacao(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();
        $guanaces = Unidade::factory()->regime2448()->create(['sigla' => 'GUANACES']);

        $propostaB->postos()->first()->update([
            'unidade_id' => $guanaces->id,
            'rotulo' => 'GUANACES',
            'horas_descanso' => 48,
        ]);

        $diferencas = ComparadorDePropostas::doMesDe($propostaA)->diferencasDeAmbulancias();

        $this->assertCount(1, $diferencas);
        $this->assertSame(
            ['Proposta A' => 'SEDE 1 (24/72)', 'Proposta B' => 'GUANACES (24/48)'],
            array_column($diferencas->first()['situacoes'], 'valor', 'nome')
        );
    }

    #[Test]
    public function aponta_o_motorista_que_muda_de_lugar(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();
        [$ana, $bruno] = $this->equipe;

        // Na B, o Bruno sai da ambulancia para a reserva.
        $this->montador->definirDestino($propostaB, $bruno->id, TipoDestino::Reserva);

        // Contratado so para a B: na A ele nem esta na folha.
        $novo = Motorista::factory()->create(['nome_completo' => 'ZECA NOVO', 'nome_curto' => 'ZECA NOVO']);
        $this->montador->lotarMotorista($propostaB->postos()->first(), $novo->id, 2);

        $diferencas = ComparadorDePropostas::doMesDe($propostaB)->diferencasDeMotoristas();

        // Ana, Carla e Diego ficam na SEDE 1 nas duas: nao sao listados.
        $this->assertSame(['BRUNO DIAS', 'ZECA NOVO'], $diferencas->pluck('rotulo')->all());
        $this->assertSame(
            ['Proposta A' => 'SEDE 1', 'Proposta B' => 'Sobreaviso / Reserva'],
            array_column($diferencas->first()['situacoes'], 'valor', 'nome')
        );
        $this->assertSame(
            ['Proposta A' => 'Sem definição', 'Proposta B' => 'SEDE 1'],
            array_column($diferencas->last()['situacoes'], 'valor', 'nome')
        );

        $this->get(route('escalas.comparar', $propostaA))
            ->assertOk()
            ->assertSeeText('BRUNO DIAS:')
            ->assertSeeText('Proposta A → SEDE 1')
            ->assertSeeText('Proposta B → Sobreaviso / Reserva')
            ->assertDontSeeText($ana->nome_curto.':');
    }

    #[Test]
    public function propostas_identicas_dizem_que_nao_diferem(): void
    {
        [$propostaA] = $this->duasPropostas();

        $conteudo = $this->get(route('escalas.comparar', $propostaA))->assertOk()->getContent();

        // Uma vez para as ambulancias, outra para os motoristas.
        $this->assertSame(2, substr_count($conteudo, 'As propostas não diferem nisso.'));
    }

    /**
     * Depois da escolha, a comparacao continua servindo de registro: a
     * publicada e as descartadas aparecem, cada uma com a sua situacao.
     */
    #[Test]
    public function inclui_a_publicada_e_as_descartadas(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();
        $propostaC = $this->montador->duplicar($propostaB);

        $this->montador->publicar($propostaB, excluirOutras: false, aprovadaPor: 'Secretária Maria');

        $resposta = $this->get(route('escalas.comparar', $propostaA))
            ->assertOk()
            ->assertSeeText('A escala que vale para Agosto de 2026 é a Proposta B')
            ->assertSeeText('Aprovada por Secretária Maria')
            ->assertSeeText('Publicada')
            ->assertSeeText('Descartada');

        $this->assertSame(
            [$propostaA->id, $propostaB->id, $propostaC->id],
            $resposta->viewData('quadros')->pluck('escala.id')->all()
        );
    }

    /** O mes de uma escala so: a tela abre e diz que nao ha o que comparar. */
    #[Test]
    public function escala_unica_do_mes_nao_tem_o_que_comparar(): void
    {
        $escala = $this->escalaCompleta();

        $this->get(route('escalas.comparar', $escala))
            ->assertOk()
            ->assertSeeText('Escala do mês')
            ->assertSeeText('não há outra proposta para comparar')
            ->assertDontSeeText('As propostas não diferem nisso.');
    }

    /** Somente consulta: o perfil de leitura tambem abre. */
    #[Test]
    public function qualquer_usuario_logado_ve_a_comparacao(): void
    {
        [$propostaA] = $this->duasPropostas();

        $this->actingAs(User::factory()->leitor()->create())
            ->get(route('escalas.comparar', $propostaA))
            ->assertOk()
            ->assertSeeText('Proposta B');

        auth()->logout();

        $this->get(route('escalas.comparar', $propostaA))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Agosto/2026: a SEDE 1 com a equipe completa e os plantoes gerados. */
    private function escalaCompleta(): Escala
    {
        $escala = $this->montador->criar(2026, 8);
        $posto = $escala->postos()->first();

        foreach ($this->equipe as $i => $motorista) {
            $this->montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh();
    }

    /** A escala completa e uma copia dela: Proposta A e Proposta B. @return array{Escala, Escala} */
    private function duasPropostas(): array
    {
        $propostaA = $this->escalaCompleta();
        $propostaB = $this->montador->duplicar($propostaA);

        return [$propostaA->fresh(), $propostaB->fresh()];
    }
}
