<?php

namespace App\Http\Controllers;

use App\Enums\AcaoAuditada;
use App\Http\Requests\SolicitacaoDeAltaRequest;
use App\Models\Configuracao;
use App\Models\SolicitacaoDeTransporte;
use App\Services\Auditoria\Auditoria;
use App\Services\Solicitacoes\RegistroDeSolicitacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Pagina aberta, sem login, em que o hospital pede a ambulancia para o paciente
 * em alta. Usada quase sempre do celular pessoal da assistente social.
 */
class SolicitacaoPublicaController extends Controller
{
    /** Campo-armadilha: invisivel para pessoas, preenchido por robos. */
    private const CAMPO_ARMADILHA = 'pagina_pessoal';

    public function formulario(): View
    {
        return view('solicitacoes.publico.formulario', [
            'config' => Configuracao::atual(),
            'campoArmadilha' => self::CAMPO_ARMADILHA,
        ]);
    }

    public function enviar(SolicitacaoDeAltaRequest $request, RegistroDeSolicitacao $registro): RedirectResponse
    {
        // Nunca finge que deu certo: se um preenchimento automatico cair na
        // armadilha, quem esta do outro lado precisa saber que o pedido nao
        // entrou. Um paciente nao pode ficar esperando por engano.
        if ($request->filled(self::CAMPO_ARMADILHA)) {
            return back()
                ->withInput($request->except(self::CAMPO_ARMADILHA))
                ->with('erro', 'Não foi possível enviar. Tente de novo; se continuar, ligue para a coordenação.');
        }

        $dados = collect($request->validated())->except('declaracao')->all();

        $solicitacao = $registro->registrar($dados, $request->ip(), $request->userAgent());

        // Reenvio do mesmo formulario devolve o pedido ja gravado: nao e pedido novo.
        // Sem dados do paciente: se a solicitacao for apagada, eles nao ficam aqui.
        if ($solicitacao->wasRecentlyCreated) {
            Auditoria::registrar(
                AcaoAuditada::SolicitacaoRecebida,
                "Recebida pela página aberta a solicitação {$solicitacao->protocolo}, de {$solicitacao->hospital_nome} ({$solicitacao->hospital_cidade})",
                $solicitacao,
            );
        }

        // Assinado: a confirmacao mostra data e protocolo, e ninguem chega a
        // ela trocando o numero no endereco.
        return redirect()->to(URL::signedRoute('solicitacao.recebida', ['protocolo' => $solicitacao->protocolo]));
    }

    /** Confirmacao do envio. De proposito, sem nenhum dado do paciente. */
    public function recebida(string $protocolo): View
    {
        return view('solicitacoes.publico.recebida', [
            'solicitacao' => SolicitacaoDeTransporte::query()->where('protocolo', $protocolo)->firstOrFail(),
            'config' => Configuracao::atual(),
        ]);
    }
}
