<?php

namespace Tests\Feature;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Documentos\DadosDaPlanilha;
use App\Services\Documentos\GeradorDeDocumentos;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Documentos de uma escala que ainda nao e a oficial do mes.
 *
 * A proposta impressa para a Secretaria circula em papel como a escala de
 * verdade. Tudo o que a distingue -- a marca no cabecalho, as vagas "a
 * contratar", a ambulancia "a definir" -- tem de estar nela e so nela: a
 * publicada continua saindo exatamente como antes.
 */
class DocumentosDePropostaTest extends TestCase
{
    use RefreshDatabase;

    private MontadorDeEscala $montador;

    private Unidade $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->montador = app(MontadorDeEscala::class);

        // Com telefones e sem logos: o contato ocupa a coluna em que a marca
        // entra, e sem logos o centro do cabecalho e o mais baixo possivel --
        // o pior caso para a marca nao alterar a altura do cabecalho.
        Configuracao::atual()->update([
            'prefeitura' => 'Prefeitura Municipal de Cascavel',
            'secretaria' => 'Secretaria Municipal de Saúde',
            'setor' => 'Coordenação de Ambulâncias',
            'telefone_1' => '8533342244',
            'responsavel_setor' => 'Fulano de Tal',
            'logo_prefeitura' => null,
            'logo_secretaria' => null,
            'brasao' => null,
        ]);

        $this->unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE', 'nome' => 'Sede']);
        Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'placa' => 'THQ4H34', 'identificacao' => 'SEDE 1']);
    }

    // -----------------------------------------------------------------
    // Marca de proposta
    // -----------------------------------------------------------------

    #[Test]
    public function a_proposta_traz_a_marca_com_o_nome_em_todas_as_paginas(): void
    {
        [, $propostaB] = $this->duasPropostas();

        // Uma folha da grade e a folha final de fora de escala: as duas marcadas.
        foreach ([GeradorDeDocumentos::LAYOUT_CLASSICO, GeradorDeDocumentos::LAYOUT_AGRUPADO] as $layout) {
            $pdf = $this->get(route('documentos.planilha', [$propostaB, 'layout' => $layout]))->assertOk()->getContent();

            $this->assertSame(2, $this->paginas($pdf));
            $this->assertSame(
                2,
                substr_count($this->textoDoPdf($pdf), 'PROPOSTA B — SUJEITA A APROVAÇÃO'),
                "No layout {$layout} alguma página saiu sem a marca."
            );
        }

        // Uma folha de frequencia por motorista, cada uma com a marca.
        $frequencias = $this->get(route('documentos.frequencias', $propostaB))->assertOk()->getContent();
        $this->assertSame(4, $this->paginas($frequencias));
        $this->assertSame(4, substr_count($this->textoDoPdf($frequencias), 'PROPOSTA B — SUJEITA A APROVAÇÃO'));

        $ocorrencias = $this->get(route('documentos.ocorrencias', $propostaB))->assertOk()->getContent();
        $this->assertStringContainsString('PROPOSTA B — SUJEITA A APROVAÇÃO', $this->textoDoPdf($ocorrencias));
    }

    #[Test]
    public function a_escala_publicada_sai_sem_a_marca(): void
    {
        [$propostaA] = $this->duasPropostas();
        $this->montador->publicar($propostaA, excluirOutras: false);

        $documentos = [
            route('documentos.planilha', $propostaA),
            route('documentos.planilha', [$propostaA, 'layout' => GeradorDeDocumentos::LAYOUT_AGRUPADO]),
            route('documentos.ocorrencias', $propostaA),
            route('documentos.frequencias', $propostaA),
        ];

        foreach ($documentos as $endereco) {
            $texto = $this->textoDoPdf($this->get($endereco)->assertOk()->getContent());

            $this->assertStringNotContainsString('PROPOSTA', $texto, $endereco);
            $this->assertStringNotContainsString('APROVAÇÃO', $texto, $endereco);
        }

        $this->assertNull(GeradorDeDocumentos::marcaDeProposta($propostaA->fresh()));
    }

    #[Test]
    public function a_proposta_descartada_diz_que_foi_descartada(): void
    {
        [$propostaA, $propostaB] = $this->duasPropostas();
        $this->montador->publicar($propostaA, excluirOutras: false);

        $texto = $this->textoDoPdf($this->get(route('documentos.planilha', $propostaB->fresh()))->assertOk()->getContent());

        $this->assertStringContainsString('PROPOSTA DESCARTADA — PROPOSTA B', $texto);
        $this->assertStringNotContainsString('SUJEITA A APROVAÇÃO', $texto);
    }

    /** O rascunho unico do mes tambem nao e a oficial: sai marcado, sem nome. */
    #[Test]
    public function o_rascunho_unico_do_mes_sai_marcado_sem_nome(): void
    {
        $escala = $this->escalaCompleta();

        $this->assertNull($escala->nome);
        $this->assertSame('PROPOSTA — SUJEITA A APROVAÇÃO', GeradorDeDocumentos::marcaDeProposta($escala));
    }

    /** Um nome livre ganha a palavra "proposta" na frente; o de letra, nao. */
    #[Test]
    public function nome_livre_ganha_a_palavra_proposta(): void
    {
        $escala = $this->escalaCompleta();

        $escala->update(['nome' => 'Com as 2 contratações']);
        $this->assertSame('PROPOSTA COM AS 2 CONTRATAÇÕES — SUJEITA A APROVAÇÃO', GeradorDeDocumentos::marcaDeProposta($escala));

        $escala->update(['nome' => 'Proposta C']);
        $this->assertSame('PROPOSTA C — SUJEITA A APROVAÇÃO', GeradorDeDocumentos::marcaDeProposta($escala));
    }

    /**
     * A lista de ocorrencias corre para uma segunda folha, que nao tem o
     * cabecalho. Separada da primeira, ela tambem precisa dizer que e proposta.
     */
    #[Test]
    public function a_continuacao_da_lista_de_ocorrencias_tambem_e_marcada(): void
    {
        $escala = $this->escalaCompleta();

        foreach (Motorista::factory()->count(70)->create() as $motorista) {
            $this->montador->definirDestino($escala, $motorista->id, TipoDestino::Reserva);
        }

        $pdf = app(GeradorDeDocumentos::class)->ocorrencias($escala->fresh())->output();
        $paginas = $this->paginas($pdf);

        $this->assertGreaterThan(1, $paginas, 'O efetivo deveria ocupar mais de uma folha.');
        $this->assertSame($paginas, substr_count($this->textoDoPdf($pdf), 'PROPOSTA — SUJEITA A APROVAÇÃO'));
    }

    /**
     * A planilha e a folha de frequencia estao calibradas no limite do papel.
     * A marca vai na coluna lateral do cabecalho, no lugar da imagem da
     * ambulancia, onde cabe sem aumentar a altura: nenhuma celula desenhada
     * pode mudar de lugar entre a proposta e a publicada -- nem com o nome
     * livre mais comprido, que quebra a marca em mais linhas, nem sem logos,
     * quando o centro do cabecalho e o mais baixo.
     */
    #[Test]
    #[DataProvider('documentosCalibrados')]
    public function a_marca_nao_desloca_nada_na_folha(string $documento, ?string $nome, bool $comImagens = false): void
    {
        if ($comImagens) {
            Storage::fake('public');

            Configuracao::atual()->update([
                'brasao' => UploadedFile::fake()->image('brasao.png', 200, 200)->store('identidade', 'public'),
                'imagem_ambulancia' => UploadedFile::fake()->image('ambulancia.png', 400, 160)->store('identidade', 'public'),
            ]);
        }

        $escala = $this->escalaCompleta();
        $escala->update(['nome' => $nome]);
        $gerador = app(GeradorDeDocumentos::class);

        $gerar = fn (Escala $e) => match ($documento) {
            'frequencia' => $gerador->frequencia($e, $e->lotacoes->first()->motorista)->output(),
            default => $gerador->planilha($e, $documento, false)->output(),
        };

        $proposta = $gerar($escala->fresh());
        $this->montador->publicar($escala);
        $publicada = $gerar($escala->fresh());

        $this->assertStringContainsString('SUJEITA A APROVAÇÃO', $this->textoDoPdf($proposta));
        $this->assertSame($this->paginas($publicada), $this->paginas($proposta));

        if ($comImagens) {
            // A publicada leva brasao e ambulancia; a proposta, so o brasao.
            $this->assertSame(2, substr_count($publicada, '/Subtype /Image'));
            $this->assertSame(1, substr_count($proposta, '/Subtype /Image'));
        }

        // Toda celula preenchida da publicada esta, no mesmo lugar, na proposta.
        $this->assertNotEmpty($this->retangulos($publicada));
        $foraDoLugar = array_diff($this->retangulos($publicada), $this->retangulos($proposta));

        $this->assertSame([], array_values($foraDoLugar), 'A marca empurrou a tabela.');
    }

    public static function documentosCalibrados(): array
    {
        $longo = 'Com as duas contratações da UPA Norte e da Sede';

        return [
            'planilha clássica' => [GeradorDeDocumentos::LAYOUT_CLASSICO, null],
            'planilha agrupada' => [GeradorDeDocumentos::LAYOUT_AGRUPADO, null],
            'folha de frequência' => ['frequencia', null],
            'planilha clássica, proposta B' => [GeradorDeDocumentos::LAYOUT_CLASSICO, 'Proposta B'],
            'planilha clássica, nome longo' => [GeradorDeDocumentos::LAYOUT_CLASSICO, $longo],
            'planilha agrupada, nome longo' => [GeradorDeDocumentos::LAYOUT_AGRUPADO, $longo],
            'folha de frequência, nome longo' => ['frequencia', $longo],
            'planilha clássica, com logos e imagem' => [GeradorDeDocumentos::LAYOUT_CLASSICO, $longo, true],
            'folha de frequência, com logos e imagem' => ['frequencia', $longo, true],
        ];
    }

    // -----------------------------------------------------------------
    // Vagas a contratar e ambulancia a definir
    // -----------------------------------------------------------------

    #[Test]
    public function vagas_a_contratar_e_ambulancia_a_definir_saem_na_proposta(): void
    {
        $escala = $this->escalaComPostoNovo();

        $bloco = DadosDaPlanilha::para($escala)->blocos->last();
        $this->assertSame('AMBULÂNCIA A DEFINIR', $bloco['placa']);

        foreach ([GeradorDeDocumentos::LAYOUT_CLASSICO, GeradorDeDocumentos::LAYOUT_AGRUPADO] as $layout) {
            $texto = $this->textoDoPdf($this->get(route('documentos.planilha', [$escala, 'layout' => $layout]))->getContent());

            $this->assertStringContainsString('A CONTRATAR: 4 vaga(s)', $texto, $layout);
            $this->assertStringContainsString('AMBULÂNCIA A DEFINIR', $texto, $layout);
            $this->assertStringNotContainsString('sem motorista designado', $texto, $layout);
        }

        // No layout classico, sem motorista o bloco nao imprime as colunas P e
        // LOT: a linha da vaga e que identifica a ambulancia nova.
        $classico = $this->textoDoPdf($this->get(route('documentos.planilha', $escala))->getContent());
        $this->assertStringContainsString('SEDE 4 · AMBULÂNCIA A DEFINIR · 24/72', $classico);
    }

    #[Test]
    public function a_publicada_continua_com_vaga_sem_motorista_e_placa_em_branco(): void
    {
        $escala = $this->escalaComPostoNovo();

        // Sem passar pela validacao da tela: o que se confere aqui e so o papel.
        $this->montador->publicar($escala);
        $escala = $escala->fresh();

        $this->assertSame('—', DadosDaPlanilha::para($escala)->blocos->last()['placa']);

        foreach ([GeradorDeDocumentos::LAYOUT_CLASSICO, GeradorDeDocumentos::LAYOUT_AGRUPADO] as $layout) {
            $texto = $this->textoDoPdf($this->get(route('documentos.planilha', [$escala, 'layout' => $layout]))->getContent());

            $this->assertStringContainsString('4 vaga(s) sem motorista designado', $texto, $layout);
            $this->assertStringNotContainsString('A CONTRATAR', $texto, $layout);
            $this->assertStringNotContainsString('A DEFINIR', $texto, $layout);
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Agosto/2026: uma ambulancia 24/72 com as quatro vagas e plantoes gerados. */
    private function escalaCompleta(): Escala
    {
        $escala = $this->montador->criar(2026, 8);
        $posto = $escala->postos()->first();

        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            $this->montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh();
    }

    /** Proposta A e sua copia, a Proposta B. @return array{Escala, Escala} */
    private function duasPropostas(): array
    {
        $propostaA = $this->escalaCompleta();
        $propostaB = $this->montador->duplicar($propostaA);

        return [$propostaA->fresh(), $propostaB->fresh()];
    }

    /** A escala completa mais um posto sem ambulancia e sem ninguem: a SEDE 4. */
    private function escalaComPostoNovo(): Escala
    {
        $escala = $this->escalaCompleta();
        $this->montador->adicionarPosto($escala, $this->unidade->id, null, 'SEDE 4');

        return $escala->fresh();
    }

    private function paginas(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    /** Conteudo descomprimido das paginas. */
    private function conteudo(string $pdf): string
    {
        $conteudo = '';

        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $blocos);

        foreach ($blocos[1] as $bloco) {
            $descomprimido = @gzuncompress($bloco);

            if ($descomprimido !== false) {
                $conteudo .= $descomprimido;
            }
        }

        return $conteudo;
    }

    /**
     * Texto do PDF em UTF-8.
     *
     * O dompdf grava cada trecho como [( ... )] TJ em UTF-16BE, com parenteses
     * e barras escapados. Tirar so os bytes nulos, como basta para texto ASCII,
     * estraga acentos e o travessao -- justamente o que a marca tem.
     */
    private function textoDoPdf(string $pdf): string
    {
        preg_match_all('/\[\((.*?)\)\] TJ/s', $this->conteudo($pdf), $trechos);

        $texto = array_map(function (string $trecho) {
            $bytes = preg_replace_callback(
                '/\\\\(.)/s',
                fn (array $m) => ['r' => "\r", 'n' => "\n"][$m[1]] ?? $m[1],
                $trecho
            );

            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }, $trechos[1]);

        // Linhas quebradas viram trechos separados: junta e normaliza espacos.
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $texto)));
    }

    /**
     * Retangulos preenchidos (fundos de celula) desenhados no PDF, como
     * "x y largura altura". Mesma tabela no mesmo lugar, mesmos retangulos.
     *
     * @return list<string>
     */
    private function retangulos(string $pdf): array
    {
        preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re f/', $this->conteudo($pdf), $m, PREG_SET_ORDER);

        return array_map(fn (array $r) => "{$r[1]} {$r[2]} {$r[3]} {$r[4]}", $m);
    }
}
