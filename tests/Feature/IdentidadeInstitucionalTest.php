<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Upload das imagens institucionais que vao no cabecalho dos documentos.
 */
class IdentidadeInstitucionalTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    /** Dados minimos exigidos pelo formulario, para isolar o teste no upload. */
    private array $base = [
        'municipio' => 'Cascavel',
        'prefeitura' => 'Prefeitura Municipal de Cascavel',
        'secretaria' => 'Secretaria Municipal de Saúde',
        'setor' => 'Coordenação de Ambulâncias',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->operador = User::factory()->admin()->create();
        $this->actingAs($this->operador);
    }

    #[Test]
    public function envia_a_logo_da_prefeitura_em_png(): void
    {
        $resposta = $this->put('/configuracoes', [
            ...$this->base,
            'logo_prefeitura' => UploadedFile::fake()->image('logo.png', 400, 160),
        ]);

        $resposta->assertRedirect('/configuracoes')->assertSessionHasNoErrors();

        $caminho = Configuracao::atual()->logo_prefeitura;

        $this->assertNotNull($caminho, 'O caminho da imagem deveria ter sido gravado.');
        Storage::disk('public')->assertExists($caminho);
        $this->assertStringStartsWith('institucional/', $caminho);
    }

    /** As quatro imagens podem ser enviadas de uma vez. */
    #[Test]
    public function envia_as_quatro_imagens_de_uma_vez(): void
    {
        $this->put('/configuracoes', [
            ...$this->base,
            'logo_prefeitura' => UploadedFile::fake()->image('a.png'),
            'logo_secretaria' => UploadedFile::fake()->image('b.jpg'),
            'brasao' => UploadedFile::fake()->image('c.png'),
            'imagem_ambulancia' => UploadedFile::fake()->image('d.webp'),
        ])->assertSessionHasNoErrors();

        $config = Configuracao::atual();

        foreach (['logo_prefeitura', 'logo_secretaria', 'brasao', 'imagem_ambulancia'] as $campo) {
            $this->assertNotNull($config->{$campo}, "{$campo} não foi gravado.");
            Storage::disk('public')->assertExists($config->{$campo});
        }
    }

    /**
     * O formulario oferece SVG na descricao; o upload precisa aceitar.
     */
    #[Test]
    public function aceita_brasao_em_svg(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="40" fill="#2b736e"/>
        </svg>
        SVG;

        $resposta = $this->put('/configuracoes', [
            ...$this->base,
            'brasao' => UploadedFile::fake()->createWithContent('brasao.svg', $svg),
        ]);

        $resposta->assertSessionHasNoErrors();

        $caminho = Configuracao::atual()->brasao;

        $this->assertNotNull($caminho, 'O SVG deveria ter sido aceito e gravado.');
        Storage::disk('public')->assertExists($caminho);
    }

    /** Salvar os textos sem enviar imagem nao apaga as imagens existentes. */
    #[Test]
    public function salvar_sem_imagem_preserva_a_existente(): void
    {
        $this->put('/configuracoes', [
            ...$this->base,
            'logo_prefeitura' => UploadedFile::fake()->image('logo.png'),
        ]);

        $caminhoOriginal = Configuracao::atual()->logo_prefeitura;
        $this->assertNotNull($caminhoOriginal);

        // Segundo envio apenas com textos.
        $this->put('/configuracoes', [...$this->base, 'slogan' => 'Agora cuidando de você.']);

        $config = Configuracao::atual();

        $this->assertSame($caminhoOriginal, $config->logo_prefeitura);
        $this->assertSame('Agora cuidando de você.', $config->slogan);
        Storage::disk('public')->assertExists($caminhoOriginal);
    }

    /** Substituir a imagem apaga o arquivo anterior, sem deixar orfaos. */
    #[Test]
    public function substituir_a_imagem_apaga_a_anterior(): void
    {
        $this->put('/configuracoes', [...$this->base, 'brasao' => UploadedFile::fake()->image('antigo.png')]);
        $antigo = Configuracao::atual()->brasao;

        $this->put('/configuracoes', [...$this->base, 'brasao' => UploadedFile::fake()->image('novo.png')]);
        $novo = Configuracao::atual()->brasao;

        $this->assertNotSame($antigo, $novo);
        Storage::disk('public')->assertMissing($antigo);
        Storage::disk('public')->assertExists($novo);
    }

    #[Test]
    public function remove_a_imagem_pelo_botao(): void
    {
        $this->put('/configuracoes', [...$this->base, 'brasao' => UploadedFile::fake()->image('b.png')]);
        $caminho = Configuracao::atual()->brasao;

        $this->delete('/configuracoes/imagem/brasao')
            ->assertRedirect('/configuracoes')
            ->assertSessionHas('sucesso');

        $this->assertNull(Configuracao::atual()->brasao);
        Storage::disk('public')->assertMissing($caminho);
    }

    #[Test]
    public function recusa_arquivo_que_nao_e_imagem(): void
    {
        $this->put('/configuracoes', [
            ...$this->base,
            'brasao' => UploadedFile::fake()->create('planilha.xlsx', 100),
        ])->assertSessionHasErrors('brasao');

        $this->assertNull(Configuracao::atual()->brasao);
    }

    /**
     * O motivo da recusa precisa aparecer na tela.
     *
     * Sem isso o formulário recarrega sem imagem e sem explicação, e o operador
     * conclui que o upload simplesmente não funciona.
     */
    #[Test]
    public function o_erro_do_upload_aparece_na_tela(): void
    {
        // followingRedirects reproduz o navegador: envia, segue o redirecionamento
        // e entrega a tela que o operador realmente vê.
        $this->followingRedirects()
            ->put('/configuracoes', [
                ...$this->base,
                'brasao' => UploadedFile::fake()->create('planilha.xlsx', 100),
            ])
            ->assertOk()
            // Volta para a tela de identidade, e não para o painel.
            ->assertSee('Identidade institucional')
            ->assertSee('Há um campo para corrigir')
            // Uma única mensagem por arquivo, explicando o motivo.
            ->assertSee('O arquivo enviado em brasão não é uma imagem válida.');
    }

    #[Test]
    public function recusa_imagem_acima_de_2mb(): void
    {
        $this->put('/configuracoes', [
            ...$this->base,
            'brasao' => UploadedFile::fake()->image('grande.png')->size(3000),
        ])->assertSessionHasErrors('brasao');
    }

    #[Test]
    public function campo_de_imagem_desconhecido_nao_e_removivel(): void
    {
        $this->delete('/configuracoes/imagem/password')->assertNotFound();
    }

    /**
     * O PDF embute a imagem em base64; sem isso o dompdf nao a renderiza.
     */
    #[Test]
    public function imagem_enviada_fica_disponivel_em_base64_para_o_pdf(): void
    {
        $this->put('/configuracoes', [...$this->base, 'brasao' => UploadedFile::fake()->image('b.png', 120, 120)]);

        $config = Configuracao::atual();

        $this->assertNotNull($config->caminhoImagem('brasao'), 'O caminho absoluto deveria existir no disco.');
        $this->assertStringStartsWith('data:image/png;base64,', (string) $config->imagemBase64('brasao'));
        $this->assertNotNull($config->urlImagem('brasao'));
    }

    #[Test]
    public function perfil_de_consulta_nao_altera_a_identidade(): void
    {
        $leitor = User::factory()->leitor()->create();

        $this->actingAs($leitor)
            ->put('/configuracoes', [...$this->base, 'brasao' => UploadedFile::fake()->image('b.png')])
            ->assertForbidden();
    }
}
