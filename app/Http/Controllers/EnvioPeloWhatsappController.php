<?php

namespace App\Http\Controllers;

use App\Enums\AcaoAuditada;
use App\Models\Motorista;
use App\Models\SolicitacaoDeTransporte;
use App\Services\Auditoria\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Anota na auditoria o toque nos botoes que levam texto para fora do sistema.
 *
 * O envio pelo WhatsApp acontece no aparelho, em um link: o servidor nao fica sabendo.
 * A tela avisa aqui no momento do toque. Por isso o registro diz "abriu o WhatsApp", e
 * nao "enviou": a pessoa ainda pode desistir la.
 *
 * A tela informa so os numeros: nomes e protocolos sao lidos aqui, para o registro nao
 * depender do que foi enviado pelo navegador.
 */
class EnvioPeloWhatsappController extends Controller
{
    /** O toque duplo nao vira duas linhas. */
    private const NAO_REPETIR_POR = 5;

    public function __invoke(Request $request): Response
    {
        $dados = $request->validate([
            'evento' => ['required', Rule::in(['plantao', 'solicitacao'])],
            'forma' => ['required', Rule::in(['whatsapp', 'copia', 'compartilhar'])],
            'dia' => ['required_if:evento,plantao', 'date_format:Y-m-d'],
            'solicitacao' => ['required_if:evento,solicitacao', 'integer'],
            'motorista' => ['nullable', 'integer'],
        ]);

        $dados['evento'] === 'plantao'
            ? $this->plantao($dados)
            : $this->solicitacao($request, $dados);

        return response()->noContent();
    }

    /** @param  array<string, mixed>  $dados */
    private function plantao(array $dados): void
    {
        $dia = Carbon::createFromFormat('!Y-m-d', $dados['dia'])->format('d/m/Y');

        Auditoria::registrar(
            AcaoAuditada::PlantaoCompartilhado,
            match ($dados['forma']) {
                'copia' => "Copiou o texto do plantão de {$dia}",
                'compartilhar' => "Abriu o compartilhamento do plantão de {$dia}",
                default => "Abriu o WhatsApp para enviar o plantão de {$dia}",
            },
            naoRepetirPor: self::NAO_REPETIR_POR,
        );
    }

    /** @param  array<string, mixed>  $dados */
    private function solicitacao(Request $request, array $dados): void
    {
        // Dados de saude: a mesma restricao da tela de onde o texto saiu.
        abort_unless($request->user()->podeEditar(), 403);

        $solicitacao = SolicitacaoDeTransporte::query()->findOrFail($dados['solicitacao']);
        $motorista = isset($dados['motorista']) ? Motorista::query()->find($dados['motorista']) : null;

        Auditoria::registrar(
            AcaoAuditada::SolicitacaoCompartilhada,
            match (true) {
                $dados['forma'] === 'copia' => "Copiou o texto da solicitação {$solicitacao->protocolo}",
                $motorista !== null => "Abriu o WhatsApp para enviar a solicitação {$solicitacao->protocolo} a {$motorista->nome_curto}",
                default => "Abriu o WhatsApp para enviar a solicitação {$solicitacao->protocolo} a outra pessoa dos contatos",
            },
            $solicitacao,
            naoRepetirPor: self::NAO_REPETIR_POR,
        );
    }
}
