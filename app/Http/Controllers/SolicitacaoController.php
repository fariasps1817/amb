<?php

namespace App\Http\Controllers;

use App\Enums\MotivoNaoAtendimento;
use App\Enums\StatusMotorista;
use App\Enums\StatusSolicitacao;
use App\Models\Motorista;
use App\Models\SolicitacaoDeTransporte;
use App\Services\Whatsapp\PlantaoDoDia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pedidos de transporte recebidos: lista, detalhes e o registro do desfecho.
 * Restrito a Administrador e Operador (middleware "editor"): sao dados de saude.
 */
class SolicitacaoController extends Controller
{
    public function index(Request $request): View
    {
        $situacao = $request->string('situacao')->toString();
        $situacao = in_array($situacao, ['aguardando', 'atendida', 'nao_atendida', 'todas'], true) ? $situacao : 'aguardando';

        $solicitacoes = SolicitacaoDeTransporte::query()
            ->busca($request->string('busca')->toString())
            ->when($situacao !== 'todas', fn ($q) => $q->where('status', $situacao))
            // Quem espera ha mais tempo vem primeiro; no historico, o mais recente.
            ->orderBy('solicitada_em', $situacao === 'aguardando' ? 'asc' : 'desc')
            ->with('motorista')
            ->paginate(20)
            ->withQueryString();

        $totais = SolicitacaoDeTransporte::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('solicitacoes.index', [
            'solicitacoes' => $solicitacoes,
            'situacao' => $situacao,
            'totais' => $totais,
        ]);
    }

    /** Numero do contador vermelho, consultado pela tela a cada minuto. */
    public function contagem(): JsonResponse
    {
        return response()->json(['aguardando' => SolicitacaoDeTransporte::query()->aguardando()->count()]);
    }

    public function show(SolicitacaoDeTransporte $solicitacao, PlantaoDoDia $plantao): View
    {
        $solicitacao->load(['motorista', 'registradaPor', 'duplicadaDe']);

        $deServico = $plantao->deServicoAgora();
        $idsDeServico = $deServico->pluck('motorista_id')->all();

        return view('solicitacoes.show', [
            'solicitacao' => $solicitacao,
            // Quem esta de plantao vem primeiro: quase sempre e um deles que vai.
            'deServico' => $deServico->filter(fn ($p) => $p->motorista !== null)->values(),
            'demaisMotoristas' => Motorista::query()
                ->where('status', StatusMotorista::Ativo)
                ->whereNotIn('id', $idsDeServico ?: [0])
                ->ordenadoPorNome()
                ->get(),
            'motivos' => MotivoNaoAtendimento::opcoes(),
        ]);
    }

    /** Registra que o paciente foi levado: motorista, data e hora e observacoes. */
    public function atendida(Request $request, SolicitacaoDeTransporte $solicitacao): RedirectResponse
    {
        if ($bloqueio = $this->exigirAguardando($solicitacao)) {
            return $bloqueio;
        }

        $dados = $request->validate([
            'motorista_id' => [
                'required',
                Rule::exists('motoristas', 'id')->where('status', StatusMotorista::Ativo->value)->whereNull('deleted_at'),
            ],
            'atendida_em' => [
                'required',
                'date',
                // O campo so vai ate o minuto: um pedido das 10:00:40 pode ter
                // sido atendido as 10:00 na tela.
                'after_or_equal:'.$solicitacao->solicitada_em->copy()->startOfMinute()->toDateTimeString(),
                'before_or_equal:'.now()->addMinutes(5)->toDateTimeString(),
            ],
            'observacoes_atendimento' => ['nullable', 'string', 'max:1000'],
        ], [
            'atendida_em.after_or_equal' => 'O atendimento não pode ser anterior à solicitação ('.$solicitacao->solicitada_em->format('d/m/Y H:i').').',
            'atendida_em.before_or_equal' => 'A data do atendimento não pode estar no futuro.',
        ], [
            'motorista_id' => 'motorista',
            'atendida_em' => 'data e hora do atendimento',
            'observacoes_atendimento' => 'observações',
        ]);

        $solicitacao->update([
            ...$dados,
            'status' => StatusSolicitacao::Atendida,
            'motivo_nao_atendimento' => null,
            'registrada_por' => $request->user()->id,
            'registrada_em' => now(),
        ]);

        return redirect()
            ->route('solicitacoes.index')
            ->with('sucesso', "Solicitação {$solicitacao->protocolo} registrada como atendida.");
    }

    public function naoAtendida(Request $request, SolicitacaoDeTransporte $solicitacao): RedirectResponse
    {
        if ($bloqueio = $this->exigirAguardando($solicitacao)) {
            return $bloqueio;
        }

        $dados = $request->validate([
            'motivo_nao_atendimento' => ['required', Rule::enum(MotivoNaoAtendimento::class)],
            'observacoes_atendimento' => [
                // "Outro motivo" sem explicacao nao diz nada a quem consultar depois.
                Rule::requiredIf($request->input('motivo_nao_atendimento') === MotivoNaoAtendimento::Outro->value),
                'nullable',
                'string',
                'max:1000',
            ],
        ], [
            'observacoes_atendimento.required' => 'Explique o motivo nas observações.',
        ], [
            'motivo_nao_atendimento' => 'motivo',
            'observacoes_atendimento' => 'observações',
        ]);

        $solicitacao->update([
            ...$dados,
            'status' => StatusSolicitacao::NaoAtendida,
            'motorista_id' => null,
            'atendida_em' => null,
            'registrada_por' => $request->user()->id,
            'registrada_em' => now(),
        ]);

        return redirect()
            ->route('solicitacoes.index')
            ->with('sucesso', "Solicitação {$solicitacao->protocolo} registrada como não atendida.");
    }

    /** Desfaz o desfecho registrado por engano: volta a contar como pendente. */
    public function reabrir(SolicitacaoDeTransporte $solicitacao): RedirectResponse
    {
        $solicitacao->update([
            'status' => StatusSolicitacao::Aguardando,
            'motorista_id' => null,
            'atendida_em' => null,
            'motivo_nao_atendimento' => null,
            'observacoes_atendimento' => null,
            'registrada_por' => null,
            'registrada_em' => null,
        ]);

        return redirect()
            ->route('solicitacoes.show', $solicitacao)
            ->with('atencao', 'Solicitação reaberta: ela voltou a aguardar atendimento.');
    }

    /** Duas pessoas abrindo o mesmo pedido: a segunda nao sobrescreve a primeira. */
    private function exigirAguardando(SolicitacaoDeTransporte $solicitacao): ?RedirectResponse
    {
        if ($solicitacao->estaAguardando()) {
            return null;
        }

        return redirect()
            ->route('solicitacoes.show', $solicitacao)
            ->with('erro', 'Esta solicitação já teve o desfecho registrado. Para alterar, reabra-a primeiro.');
    }
}
