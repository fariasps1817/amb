<?php

namespace Tests\Feature;

use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
