<?php

namespace Tests\Feature\Auditoria;

use App\Enums\AcaoAuditada;
use App\Livewire\DefinirDestinos;
use App\Livewire\MontarEscala;
use App\Livewire\OrdenarPostos;
use Illuminate\Routing\Route as Rota;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A trava contra esquecer a auditoria nas proximas funcionalidades.
 *
 * Boa parte do sistema grava direto no banco, sem disparar evento de model: nada
 * registra sozinho, e uma rota nova ficaria de fora em silencio. Estes testes falham
 * quando surge rota que grava, ou acao de tela, que ninguem classificou.
 *
 * Falhou aqui? Registre a acao com Auditoria::registrar() e inclua o nome na lista,
 * com um teste que confirme o registro. Se de fato nao for algo a auditar, inclua em
 * SEM_AUDITORIA, dizendo o motivo.
 */
class CoberturaDaAuditoriaTest extends TestCase
{
    /** Rotas que gravam dados ou tiram documento do sistema, e por isso entram na auditoria. */
    private const ROTAS_AUDITADAS = [
        'logout',
        'solicitacao.enviar',
        'solicitacoes.atendida', 'solicitacoes.nao-atendida', 'solicitacoes.reabrir', 'solicitacoes.destroy',
        'motoristas.store', 'motoristas.update', 'motoristas.destroy', 'motoristas.exportar',
        'unidades.store', 'unidades.update', 'unidades.destroy',
        'ambulancias.store', 'ambulancias.update', 'ambulancias.destroy',
        'escalas.store', 'escalas.destroy', 'escalas.gerar', 'escalas.publicar', 'escalas.reabrir',
        'escalas.arquivar', 'escalas.duplicar', 'escalas.renomear', 'escalas.restaurar',
        'documentos.planilha', 'documentos.ocorrencias', 'documentos.frequencias', 'documentos.frequencia',
        'mensagens.preparar', 'mensagens.enviada', 'mensagens.enviar', 'mensagens.enviar-todas',
        'configuracoes.update', 'configuracoes.remover-imagem',
        'usuarios.store', 'usuarios.update', 'usuarios.destroy',
        'auditoria.envio', 'auditoria.exportar',
    ];

    /** Rotas que gravam mas nao sao acao de alguem, com o motivo. */
    private const SEM_AUDITORIA = [
        // A entrada e registrada dentro dele, na linha de sucesso; a tentativa errada
        // fica em tentativas_de_acesso. Sem nome: e a mesma url da tela de entrar.
        'POST entrar',
        // So renova a sessao, para o aviso de inatividade. Nao muda nada.
        'sessao.renovar',
        // A raiz so redireciona para o painel, com qualquer metodo.
        'GET /',
    ];

    /** Acoes das telas Livewire que gravam dados. */
    private const ACOES_DE_TELA = [
        MontarEscala::class => [
            'adicionarPosto', 'removerPosto', 'alterarUnidadeDoPosto', 'alterarVigencia', 'alternarRotacao',
            'lotar', 'liberarPosicao', 'mover', 'preencherAutomaticamente', 'gerarPlantoes',
        ],
        DefinirDestinos::class => ['definir', 'limparOcorrencia', 'salvarDetalhes', 'todosParaReserva'],
        OrdenarPostos::class => ['mover'],
    ];

    /** Metodos publicos que so mexem na tela: abrir painel, filtrar, carregar dados. */
    private const ACOES_SO_DE_TELA = [
        MontarEscala::class => [
            'mount', 'render', 'postosReordenados', 'postos', 'analisador', 'resumo', 'alertas', 'candidatos',
            'escaladosEmOutrosPostos', 'unidades', 'ordensDeEntrada', 'ambulanciasLivres', 'abrirNovoPosto',
            'cancelarNovoPosto', 'updatedNovaAmbulanciaId', 'abrirPosto',
        ],
        DefinirDestinos::class => [
            'mount', 'render', 'analisador', 'resumo', 'alertas', 'lotacoes', 'contagens', 'unidades',
            'editar', 'fecharEdicao', 'filtrar',
        ],
        OrdenarPostos::class => ['mount', 'render', 'postos', 'abrir', 'concluir'],
    ];

    #[Test]
    public function toda_rota_que_grava_foi_classificada(): void
    {
        $classificadas = [...self::ROTAS_AUDITADAS, ...self::SEM_AUDITORIA];

        $novas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (Rota $rota) => $this->grava($rota) || $this->emiteDocumento($rota))
            // Do framework e dos pacotes: nao sao telas do sistema.
            ->reject(fn (Rota $rota) => str_starts_with($rota->uri(), 'livewire') || str_starts_with($rota->uri(), 'storage/') || str_starts_with($rota->uri(), '_'))
            ->map(fn (Rota $rota) => $rota->getName() ?? ($rota->methods()[0].' '.$rota->uri()))
            ->reject(fn (string $nome) => in_array($nome, $classificadas, true))
            ->values()
            ->all();

        $this->assertSame([], $novas, 'Rota nova sem classificação na auditoria (ver o comentário no topo deste teste).');
    }

    #[Test]
    public function a_lista_de_rotas_nao_tem_nome_que_nao_existe_mais(): void
    {
        foreach (self::ROTAS_AUDITADAS as $nome) {
            $this->assertTrue(Route::has($nome), "A rota \"{$nome}\" não existe mais: tire da lista.");
        }
    }

    #[Test]
    public function toda_acao_das_telas_de_montagem_foi_classificada(): void
    {
        foreach (self::ACOES_DE_TELA as $tela => $auditadas) {
            $publicos = collect((new ReflectionClass($tela))->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $metodo) => $metodo->getDeclaringClass()->getName() === $tela)
                ->map(fn (ReflectionMethod $metodo) => $metodo->getName());

            $this->assertSame(
                [],
                $publicos->diff([...$auditadas, ...self::ACOES_SO_DE_TELA[$tela]])->values()->all(),
                "Ação nova em {$tela} sem classificação na auditoria (ver o comentário no topo deste teste).",
            );
        }
    }

    #[Test]
    public function toda_acao_tem_categoria_definida_de_proposito(): void
    {
        foreach (AcaoAuditada::cases() as $acao) {
            $this->assertSame($acao, AcaoAuditada::from($acao->value));
            // Prefixo novo cairia em "Sistema" em silêncio.
            $this->assertTrue(
                str_starts_with($acao->value, 'configuracao.') || str_starts_with($acao->value, 'auditoria.') || $acao->categoria()->value !== 'sistema',
                "A ação \"{$acao->value}\" não tem categoria.",
            );
        }
    }

    private function grava(Rota $rota): bool
    {
        return array_intersect($rota->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
    }

    /** Documento nao grava nada, mas decidiu-se registrar a emissao. */
    private function emiteDocumento(Rota $rota): bool
    {
        return str_starts_with((string) $rota->getName(), 'documentos.') && $rota->getName() !== 'documentos.index';
    }
}
