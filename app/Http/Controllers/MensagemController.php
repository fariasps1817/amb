<?php

namespace App\Http\Controllers;

use App\Models\Escala;
use App\Models\EscalaMensagem;
use App\Services\Whatsapp\EnviadorDeMensagens;
use App\Services\Whatsapp\MontadorDeMensagens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Comunicacao da escala aos motoristas pelo WhatsApp.
 */
class MensagemController extends Controller
{
    public function __construct(
        private readonly MontadorDeMensagens $montador,
        private readonly EnviadorDeMensagens $enviador,
    ) {}

    public function index(Escala $escala): View
    {
        $escala->load(['lotacoes.motorista', 'lotacoes.posto.unidade', 'lotacoes.posto.ambulancia']);

        $mensagens = $this->montador->mensagensDaEscala($escala);
        $driver = $this->enviador->driver();

        return view('mensagens.index', [
            'escala' => $escala,
            'mensagens' => $mensagens,
            'semTelefone' => $this->montador->semTelefone($escala),
            'driver' => $driver,
            'driverManual' => $driver->requerAcaoManual(),
            'pendenciaDriver' => $driver->pendenciaDeConfiguracao(),
            'totais' => [
                'total' => $mensagens->count(),
                'enviadas' => $mensagens->filter(fn ($m) => $m->foiEnviada())->count(),
                'pendentes' => $mensagens->filter(fn ($m) => ! $m->foiEnviada() && ! $m->comErro())->count(),
                'erros' => $mensagens->filter(fn ($m) => $m->comErro())->count(),
                'escalados' => $escala->lotacoes->filter(fn ($l) => $l->escalado())->count(),
            ],

            // Quem recebeu a mensagem e depois saiu da escala precisa ser
            // avisado de que o plantao nao e mais dele.
            'avisadosQueSairam' => $this->montador->avisadosQueSairam($escala),
        ]);
    }

    /**
     * Monta o texto de cada motorista a partir dos plantoes gerados.
     */
    public function preparar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        if ($escala->plantoes()->doesntExist()) {
            return back()->with('erro', 'Gere os plantões da escala antes de preparar as mensagens.');
        }

        $preparadas = $this->montador->prepararParaEscala($escala, $request->boolean('recriar_enviadas'));

        if ($preparadas === 0) {
            return back()->with('atencao', 'Nenhuma mensagem nova para preparar. As já enviadas foram mantidas.');
        }

        return back()->with('sucesso', "{$preparadas} mensagem(ns) preparada(s). Confira o texto e envie.");
    }

    /**
     * Registra o envio manual feito pelo operador no driver de link.
     */
    public function marcarEnviada(Request $request, Escala $escala, EscalaMensagem $mensagem): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless($mensagem->escala_id === $escala->id, 404);

        $this->enviador->marcarComoEnviada($mensagem);

        return back()->with('sucesso', "Envio registrado para {$mensagem->motorista?->nome_curto}.");
    }

    /**
     * Envia uma mensagem pelo driver de API configurado.
     */
    public function enviar(Request $request, Escala $escala, EscalaMensagem $mensagem): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless($mensagem->escala_id === $escala->id, 404);

        $resultado = $this->enviador->enviar($mensagem);

        if ($resultado->requerAcaoManual) {
            return back()->with(
                'atencao',
                'O driver configurado é o de link: abra o WhatsApp pelo botão e depois confirme o envio.'
            );
        }

        return $resultado->enviado
            ? back()->with('sucesso', "Mensagem enviada para {$mensagem->motorista?->nome_curto}.")
            : back()->with('erro', "Falha no envio para {$mensagem->motorista?->nome_curto}: {$resultado->retorno}");
    }

    /**
     * Dispara todas as pendentes de uma vez, apenas em drivers automaticos.
     */
    public function enviarTodas(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $driver = $this->enviador->driver();

        if ($driver->requerAcaoManual()) {
            return back()->with(
                'atencao',
                'O envio em lote exige um driver de API configurado. Com o driver de link, o envio é feito motorista por motorista.'
            );
        }

        if (! $driver->configurado()) {
            return back()->with('erro', (string) $driver->pendenciaDeConfiguracao());
        }

        $resultado = $this->enviador->enviarPendentes($escala);

        if ($resultado['falhas'] === 0) {
            return back()->with('sucesso', "{$resultado['enviadas']} mensagem(ns) enviada(s).");
        }

        return back()->with(
            'atencao',
            "{$resultado['enviadas']} enviada(s) e {$resultado['falhas']} com falha. "
                .implode(' · ', array_slice($resultado['mensagens'], 0, 3))
        );
    }
}
