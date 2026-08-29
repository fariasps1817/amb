<?php

namespace Tests\Feature;

use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Documentos\ColunasDeMotoristas;
use App\Services\Escalas\MontadorDeEscala;
use App\Support\Aniversario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Percurso completo dos CRUDs: listagem, criacao, edicao e exclusao, incluindo
 * as regras que protegem o historico das escalas.
 */
class CadastrosTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operador = User::factory()->admin()->create();
    }

    // -----------------------------------------------------------------
    // Acesso
    // -----------------------------------------------------------------

    #[Test]
    public function visitante_e_levado_para_o_login(): void
    {
        $this->get('/painel')->assertRedirect('/entrar');
        $this->get('/motoristas')->assertRedirect('/entrar');
    }

    #[Test]
    public function usuario_entra_com_usuario_e_senha(): void
    {
        User::factory()->create(['usuario' => 'coordenacao', 'password' => bcrypt('1234')]);

        $this->post('/entrar', ['usuario' => 'coordenacao', 'password' => '1234'])
            ->assertRedirect('/painel');

        $this->assertAuthenticated();
    }

    #[Test]
    public function senha_errada_nao_autentica(): void
    {
        User::factory()->create(['usuario' => 'coordenacao', 'password' => bcrypt('1234')]);

        $this->post('/entrar', ['usuario' => 'coordenacao', 'password' => '9999'])
            ->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    /** Usuario desativado nao entra, mesmo com a senha correta. */
    #[Test]
    public function usuario_inativo_nao_entra(): void
    {
        User::factory()->inativo()->create(['usuario' => 'afastado', 'password' => bcrypt('1234')]);

        $this->post('/entrar', ['usuario' => 'afastado', 'password' => '1234'])
            ->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    #[Test]
    public function painel_abre_para_usuario_autenticado(): void
    {
        $this->actingAs($this->operador)->get('/painel')->assertOk();
    }

    // -----------------------------------------------------------------
    // Motoristas
    // -----------------------------------------------------------------

    #[Test]
    public function lista_cadastra_e_edita_motorista(): void
    {
        $this->actingAs($this->operador)->get('/motoristas')->assertOk();
        $this->actingAs($this->operador)->get('/motoristas/create')->assertOk();

        $this->actingAs($this->operador)->post('/motoristas', [
            'nome_completo' => 'joão bernardo de oliveira',
            'nome_curto' => 'joão bernardo',
            'cpf' => '123.456.789-09',
            'vinculo' => Vinculo::Efetivo->value,
            'cnh_categoria' => 'd',
            'cnh_validade' => now()->addYear()->toDateString(),
            'telefone_1' => '(85) 98692-6853',
            'status' => StatusMotorista::Ativo->value,
        ])->assertRedirect('/motoristas');

        $motorista = Motorista::query()->firstOrFail();

        // Normalizacao: nome em caixa alta, telefone e CPF apenas com digitos.
        $this->assertSame('JOÃO BERNARDO DE OLIVEIRA', $motorista->nome_completo);
        $this->assertSame('JOÃO BERNARDO', $motorista->nome_curto);
        $this->assertSame('12345678909', $motorista->cpf);
        $this->assertSame('85986926853', $motorista->telefone_1);
        $this->assertSame('D', $motorista->cnh_categoria);

        $this->actingAs($this->operador)->get("/motoristas/{$motorista->id}")->assertOk();
        $this->actingAs($this->operador)->get("/motoristas/{$motorista->id}/edit")->assertOk();

        $this->actingAs($this->operador)->put("/motoristas/{$motorista->id}", [
            'nome_completo' => 'JOÃO BERNARDO DE OLIVEIRA',
            'nome_curto' => 'JOÃO B.',
            'vinculo' => Vinculo::Efetivo->value,
            'telefone_1' => '85986926853',
            'status' => StatusMotorista::Ativo->value,
        ])->assertRedirect('/motoristas');

        $this->assertSame('JOÃO B.', $motorista->fresh()->nome_curto);
    }

    /**
     * CPF e telefone so aceitam digitos, pontuados pelo navegador.
     *
     * O inputmode numerico e o que abre o teclado de numeros no celular; sem
     * ele o campo volta ao teclado alfabetico e a digitacao no plantao fica
     * penosa. O maxlength conta a pontuacao ja aplicada.
     */
    #[Test]
    public function os_campos_de_cpf_e_telefone_saem_com_mascara(): void
    {
        $motorista = Motorista::factory()->create([
            'cpf' => '12345678909',
            'telefone_1' => '85986926853',
        ]);

        $html = $this->actingAs($this->operador)
            ->get("/motoristas/{$motorista->id}/edit")
            ->assertOk()
            ->getContent();

        // Valor ja pontuado ao abrir a tela, e nao os digitos crus do banco.
        $this->assertStringContainsString('value="123.456.789-09"', $html);
        $this->assertStringContainsString('value="(85) 98692-6853"', $html);

        $this->assertStringContainsString('data-mascara="cpf"', $html);
        $this->assertStringContainsString('data-mascara="telefone"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('maxlength="14"', $html);
        $this->assertStringContainsString('maxlength="15"', $html);
    }

    /**
     * A relacao em PDF sai da mesma consulta da tela, com os filtros aplicados.
     *
     * O texto e conferido de dentro do PDF: afirmar so o content-type deixaria
     * passar um documento gerado com a lista errada.
     */
    #[Test]
    public function exporta_em_pdf_a_relacao_de_motoristas_filtrada(): void
    {
        Motorista::factory()->create(['nome_completo' => 'ANTONIO DA SILVA', 'nome_curto' => 'ANTONIO']);
        Motorista::factory()->inativo()->create(['nome_completo' => 'BENEDITO SOUZA', 'nome_curto' => 'BENEDITO']);

        $resposta = $this->actingAs($this->operador)->get('/motoristas/exportar');

        $resposta->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resposta->getContent());

        $completa = $this->textoDoPdf($resposta->getContent());
        $this->assertStringContainsString('ANTONIO', $completa);
        $this->assertStringContainsString('BENEDITO', $completa);

        // Com filtro, o inativo fica de fora — e o PDF diz por que.
        $filtrada = $this->textoDoPdf(
            $this->actingAs($this->operador)
                ->get('/motoristas/exportar?status='.StatusMotorista::Ativo->value)
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString('ANTONIO', $filtrada);
        $this->assertStringNotContainsString('BENEDITO', $filtrada);
        $this->assertStringContainsString('Filtros aplicados', $filtrada);
    }

    /**
     * O filtro de aniversariantes muda tambem a ordem: uma relacao de
     * aniversarios em ordem alfabetica nao responde a pergunta que se faz a
     * ela, que e quem faz anos primeiro.
     */
    #[Test]
    public function filtra_e_ordena_os_aniversariantes_do_mes(): void
    {
        $this->travelTo(Carbon::create(2026, 5, 10));

        $dia20 = Motorista::factory()->create(['nome_completo' => 'ZULMIRA', 'data_nascimento' => '1980-05-20']);
        $dia03 = Motorista::factory()->create(['nome_completo' => 'ADRIANO', 'data_nascimento' => '1975-05-03']);
        $outroMes = Motorista::factory()->create(['nome_completo' => 'BRUNO', 'data_nascimento' => '1990-06-15']);
        $semData = Motorista::factory()->create(['nome_completo' => 'CARLOS', 'data_nascimento' => null]);

        $ids = $this->actingAs($this->operador)
            ->get('/motoristas?aniversario='.Aniversario::MES_CORRENTE)
            ->assertOk()
            ->viewData('motoristas')
            ->getCollection()
            ->pluck('id')
            ->all();

        // Dia 3 antes do dia 20, embora ADRIANO venha depois de ZULMIRA no alfabeto.
        $this->assertSame([$dia03->id, $dia20->id], $ids);

        // Sem data de nascimento ninguem entra, nem como "sem data".
        $this->assertNotContains($outroMes->id, $ids);
        $this->assertNotContains($semData->id, $ids);
    }

    #[Test]
    public function filtra_os_aniversariantes_do_dia(): void
    {
        $this->travelTo(Carbon::create(2026, 5, 10));

        $hoje = Motorista::factory()->create(['data_nascimento' => '1980-05-10']);
        Motorista::factory()->create(['data_nascimento' => '1980-05-11']);

        $ids = $this->actingAs($this->operador)
            ->get('/motoristas?aniversario='.Aniversario::HOJE)
            ->assertOk()
            ->viewData('motoristas')
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertSame([$hoje->id], $ids);
    }

    /**
     * As colunas do PDF vem da tela. A do nome nao pode faltar: uma relacao sem
     * nome nao serve para nada, entao ela e imposta mesmo sem ser pedida.
     *
     * As asserçoes evitam texto acentuado de proposito: o dompdf grava a fonte
     * em dois bytes por caractere e os acentos nao voltam em UTF-8.
     */
    #[Test]
    public function o_pdf_traz_apenas_as_colunas_escolhidas(): void
    {
        Motorista::factory()->create([
            'nome_completo' => 'ANTONIO DA SILVA',
            'cpf' => '12345678909',
            'vinculo' => Vinculo::Efetivo,
            'telefone_1' => '85986926853',
        ]);

        $texto = $this->textoDoPdf(
            $this->actingAs($this->operador)
                ->get('/motoristas/exportar?colunas[]=cpf')
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString('SERVIDOR', $texto, 'a coluna do nome e obrigatoria');
        $this->assertStringContainsString('CPF', $texto);
        $this->assertStringContainsString('123.456.789-09', $texto);

        // Nao foram pedidas, mesmo sendo o padrao quando nada e escolhido.
        $this->assertStringNotContainsString('TELEFONE', $texto);
        $this->assertStringNotContainsString('Efetivo', $texto);
    }

    /** Sem escolha nenhuma, valem as colunas padrao. */
    #[Test]
    public function o_pdf_sem_escolha_traz_as_colunas_padrao(): void
    {
        Motorista::factory()->create(['vinculo' => Vinculo::Efetivo, 'telefone_1' => '85986926853']);

        $texto = $this->textoDoPdf(
            $this->actingAs($this->operador)->get('/motoristas/exportar')->assertOk()->getContent()
        );

        $this->assertStringContainsString('SERVIDOR', $texto);
        $this->assertStringContainsString('TELEFONE', $texto);
        $this->assertStringContainsString('Efetivo', $texto);

        $this->assertStringNotContainsString('CPF', $texto);
    }

    /**
     * Com muitas colunas nao ha largura em pe, e espremer o conteudo o faria
     * quebrar linha. A folha vira sozinha.
     */
    #[Test]
    public function a_folha_vira_para_paisagem_quando_as_colunas_nao_cabem(): void
    {
        $emPe = ColunasDeMotoristas::de(ColunasDeMotoristas::PADRAO);
        $this->assertFalse($emPe->paisagem());
        $this->assertSame('portrait', $emPe->orientacao());

        $deitada = ColunasDeMotoristas::de(array_keys(ColunasDeMotoristas::opcoes()));
        $this->assertTrue($deitada->paisagem());
        $this->assertSame('landscape', $deitada->orientacao());

        // As larguras precisam fechar a folha, sem sobra nem estouro.
        foreach ([$emPe, $deitada] as $colunas) {
            $this->assertEqualsWithDelta(100, array_sum($colunas->larguras()), 0.1);
        }
    }

    /**
     * Invariante do layout: combinacao nenhuma pode exigir mais largura do que
     * a folha oferece. Quando exige, a celula quebra em duas linhas e a relacao
     * inteira perde o alinhamento -- foi assim que a primeira calibragem
     * falhou, com o tratamento em 24mm. Sao 256 combinacoes, e conferi-las
     * todas nao custa nada porque nao gera PDF nenhum.
     */
    #[Test]
    public function nenhuma_combinacao_de_colunas_estoura_a_largura_da_folha(): void
    {
        $opcionais = array_values(array_diff(
            array_keys(ColunasDeMotoristas::opcoes()),
            [ColunasDeMotoristas::OBRIGATORIA]
        ));

        for ($mascara = 0; $mascara < 2 ** count($opcionais); $mascara++) {
            $escolha = [ColunasDeMotoristas::OBRIGATORIA];

            foreach ($opcionais as $posicao => $chave) {
                if ($mascara & (1 << $posicao)) {
                    $escolha[] = $chave;
                }
            }

            $colunas = ColunasDeMotoristas::de($escolha);

            $this->assertLessThanOrEqual(
                $colunas->larguraDaFolha(),
                $colunas->larguraExigida(),
                'Estourou a folha com: '.implode(', ', $escolha)
            );
        }
    }

    /**
     * A rota de exportacao e declarada antes do resource de proposito. Depois
     * dele, "exportar" cairia em motoristas/{motorista} e viraria 404.
     */
    #[Test]
    public function a_rota_de_exportacao_nao_e_confundida_com_um_motorista(): void
    {
        $this->actingAs($this->operador)
            ->get('/motoristas/exportar')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Texto legivel de dentro de um PDF do dompdf.
     *
     * O conteudo das paginas vem comprimido; depois de descomprimir, o texto
     * esta nos operadores Tj e TJ, entre parenteses.
     */
    private function textoDoPdf(string $pdf): string
    {
        $conteudo = '';

        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $blocos);

        foreach ($blocos[1] as $bloco) {
            $descomprimido = @gzuncompress($bloco);

            if ($descomprimido !== false) {
                $conteudo .= $descomprimido;
            }
        }

        // O texto sai em blocos [( ... )] TJ. A fonte e escrita com dois bytes
        // por caractere, entao cada letra vem precedida de um byte zero.
        preg_match_all('/\[\((.*?)\)\]/s', $conteudo, $trechos);

        return str_replace("\0", '', implode(' ', $trechos[1]));
    }

    /** Contrato temporario sem prazo impede o sistema de avisar o vencimento. */
    #[Test]
    public function contrato_temporario_exige_data_de_termino(): void
    {
        $this->actingAs($this->operador)->post('/motoristas', [
            'nome_completo' => 'MARIA DIVANIR',
            'nome_curto' => 'MARIA DIVANIR',
            'vinculo' => Vinculo::Contrato->value,
            'telefone_1' => '85986926853',
            'status' => StatusMotorista::Ativo->value,
        ])->assertSessionHasErrors('vinculo_fim');
    }

    /** Sem telefone nao ha como enviar a escala pelo WhatsApp. */
    #[Test]
    public function motorista_ativo_exige_telefone(): void
    {
        $this->actingAs($this->operador)->post('/motoristas', [
            'nome_completo' => 'JOSÉ LUIS',
            'nome_curto' => 'JOSÉ LUIS',
            'vinculo' => Vinculo::Efetivo->value,
            'status' => StatusMotorista::Ativo->value,
        ])->assertSessionHasErrors('telefone_1');
    }

    #[Test]
    public function cpf_nao_pode_repetir(): void
    {
        Motorista::factory()->create(['cpf' => '12345678909']);

        $this->actingAs($this->operador)->post('/motoristas', [
            'nome_completo' => 'OUTRO MOTORISTA',
            'nome_curto' => 'OUTRO',
            'cpf' => '123.456.789-09',
            'vinculo' => Vinculo::Efetivo->value,
            'telefone_1' => '85986926853',
            'status' => StatusMotorista::Ativo->value,
        ])->assertSessionHasErrors('cpf');
    }

    #[Test]
    public function motorista_sem_escala_pode_ser_excluido(): void
    {
        $motorista = Motorista::factory()->create();

        $this->actingAs($this->operador)
            ->delete("/motoristas/{$motorista->id}")
            ->assertRedirect('/motoristas');

        $this->assertSoftDeleted($motorista);
    }

    /**
     * Excluir um motorista que consta em escalas apagaria as lotacoes por
     * cascata e desfiguraria documentos ja emitidos; nesse caso ele e inativado.
     */
    #[Test]
    public function motorista_com_escala_e_apenas_inativado(): void
    {
        $unidade = Unidade::factory()->regime2448()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $motorista = Motorista::factory()->create();

        app(MontadorDeEscala::class)
            ->lotarMotorista($escala->postos()->first(), $motorista->id, 1);

        $this->actingAs($this->operador)
            ->delete("/motoristas/{$motorista->id}")
            ->assertRedirect('/motoristas')
            ->assertSessionHas('atencao');

        $this->assertNotSoftDeleted($motorista);
        $this->assertSame(StatusMotorista::Inativo, $motorista->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Unidades
    // -----------------------------------------------------------------

    #[Test]
    public function cadastra_unidade_convertendo_o_regime(): void
    {
        $this->actingAs($this->operador)->get('/unidades')->assertOk();
        $this->actingAs($this->operador)->get('/unidades/create')->assertOk();

        $this->actingAs($this->operador)->post('/unidades', [
            'nome' => 'UPA Centro',
            'sigla' => 'upa',
            'regime' => '24/48',
            'ativo' => '1',
        ])->assertRedirect('/unidades');

        $unidade = Unidade::query()->firstOrFail();

        $this->assertSame('UPA', $unidade->sigla);
        $this->assertSame(24, $unidade->horas_trabalho);
        $this->assertSame(48, $unidade->horas_descanso);
        // 24/48 exige tres motoristas por ambulancia.
        $this->assertSame(3, $unidade->motoristasPorAmbulancia());

        $this->actingAs($this->operador)->get("/unidades/{$unidade->id}")->assertOk();
        $this->actingAs($this->operador)->get("/unidades/{$unidade->id}/edit")->assertOk();
    }

    /** Regime que nao fecha ciclo inteiro geraria escala inconsistente. */
    #[Test]
    public function recusa_regime_que_nao_fecha_ciclo(): void
    {
        $this->actingAs($this->operador)->post('/unidades', [
            'nome' => 'Posto Praia',
            'sigla' => 'PRAIA',
            'regime' => '24/50',
            'ativo' => '1',
        ])->assertSessionHasErrors('regime');
    }

    #[Test]
    public function sigla_de_unidade_nao_pode_repetir(): void
    {
        Unidade::factory()->create(['sigla' => 'UPA']);

        $this->actingAs($this->operador)->post('/unidades', [
            'nome' => 'Outra UPA',
            'sigla' => 'UPA',
            'regime' => '24/72',
            'ativo' => '1',
        ])->assertSessionHasErrors('sigla');
    }

    // -----------------------------------------------------------------
    // Ambulâncias
    // -----------------------------------------------------------------

    #[Test]
    public function cadastra_ambulancia_normalizando_a_placa(): void
    {
        $unidade = Unidade::factory()->create();

        $this->actingAs($this->operador)->get('/ambulancias')->assertOk();
        $this->actingAs($this->operador)->get('/ambulancias/create')->assertOk();

        $this->actingAs($this->operador)->post('/ambulancias', [
            'placa' => 'thq-4h34',
            'renavam' => '123456789',
            'vinculo' => 'propria',
            'marca' => 'Fiat',
            'modelo' => 'Ducato',
            'ano_fabricacao' => 2023,
            'ano_modelo' => 2024,
            'identificacao' => 'sede 1',
            'unidade_id' => $unidade->id,
            'ativo' => '1',
        ])->assertRedirect('/ambulancias');

        $ambulancia = Ambulancia::query()->firstOrFail();

        $this->assertSame('THQ4H34', $ambulancia->placa);
        $this->assertSame('SEDE 1', $ambulancia->identificacao);

        $this->actingAs($this->operador)->get("/ambulancias/{$ambulancia->id}")->assertOk();
        $this->actingAs($this->operador)->get("/ambulancias/{$ambulancia->id}/edit")->assertOk();
    }

    #[Test]
    public function recusa_placa_invalida(): void
    {
        $this->actingAs($this->operador)->post('/ambulancias', [
            'placa' => '1234',
            'vinculo' => 'propria',
            'ativo' => '1',
        ])->assertSessionHasErrors('placa');
    }

    #[Test]
    public function recusa_ano_do_modelo_anterior_ao_de_fabricacao(): void
    {
        $this->actingAs($this->operador)->post('/ambulancias', [
            'placa' => 'ABC1D23',
            'vinculo' => 'propria',
            'ano_fabricacao' => 2024,
            'ano_modelo' => 2022,
            'ativo' => '1',
        ])->assertSessionHasErrors('ano_modelo');
    }

    // -----------------------------------------------------------------
    // Identidade institucional e usuários
    // -----------------------------------------------------------------

    #[Test]
    public function salva_a_identidade_institucional(): void
    {
        $this->actingAs($this->operador)->get('/configuracoes')->assertOk();

        $this->actingAs($this->operador)->put('/configuracoes', [
            'municipio' => 'Cascavel',
            'prefeitura' => 'Prefeitura Municipal de Cascavel',
            'secretaria' => 'Secretaria Municipal de Saúde',
            'setor' => 'Coordenação de Ambulâncias',
            'slogan' => 'Agora cuidando de você.',
            'uf' => 'ce',
            'telefone_1' => '(85) 3334-2244',
        ])->assertRedirect('/configuracoes');

        $configuracao = Configuracao::atual();

        $this->assertSame('CE', $configuracao->uf);
        $this->assertSame('8533342244', $configuracao->telefone_1);
    }

    #[Test]
    public function somente_administrador_gerencia_usuarios(): void
    {
        $this->actingAs($this->operador)->get('/usuarios')->assertOk();

        $comum = User::factory()->create(['perfil' => 'operador']);
        $this->actingAs($comum)->get('/usuarios')->assertForbidden();
    }

    #[Test]
    public function cadastra_usuario_com_senha_numerica(): void
    {
        $this->actingAs($this->operador)->post('/usuarios', [
            'nome' => 'Coordenação',
            'usuario' => 'coord',
            'password' => '1234',
            'password_confirmation' => '1234',
            'perfil' => 'operador',
            'ativo' => '1',
        ])->assertRedirect('/usuarios');

        $this->assertTrue(User::query()->where('usuario', 'coord')->exists());
    }

    /** O sistema nao pode ficar sem nenhum administrador ativo. */
    #[Test]
    public function nao_exclui_o_ultimo_administrador(): void
    {
        $outro = User::factory()->admin()->create();

        // Sobra apenas um admin ativo (o autenticado): excluir o outro e permitido.
        $this->actingAs($this->operador)->delete("/usuarios/{$outro->id}")->assertRedirect('/usuarios');

        // Mas o admin nao pode excluir a si mesmo.
        $this->actingAs($this->operador)
            ->delete("/usuarios/{$this->operador->id}")
            ->assertSessionHas('erro');
    }

    #[Test]
    public function perfil_de_consulta_nao_cadastra(): void
    {
        $leitor = User::factory()->leitor()->create();

        $this->actingAs($leitor)->get('/motoristas')->assertOk();

        $this->actingAs($leitor)->post('/motoristas', [
            'nome_completo' => 'TESTE',
            'nome_curto' => 'TESTE',
            'vinculo' => Vinculo::Efetivo->value,
            'telefone_1' => '85986926853',
            'status' => StatusMotorista::Ativo->value,
        ])->assertForbidden();
    }
}
