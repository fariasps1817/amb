<?php

namespace App\Services\Whatsapp;

use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaMensagem;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Support\Telefone;
use Illuminate\Support\Collection;

/**
 * Monta a mensagem individual que cada motorista recebe no WhatsApp com os dias
 * em que esta de plantao no mes.
 *
 * Exemplo do texto gerado para um motorista de 24/48:
 *
 *   *ESCALA DE PLANTÃO — AGOSTO/2026*
 *   Coordenação de Ambulâncias · Secretaria Municipal de Saúde
 *
 *   Olá, JOSÉ LUIS!
 *   Você está escalado na *UPA CENTRO*, ambulância *HUH1020*, em regime de
 *   *24/48* (plantão de 24h).
 *
 *   *Seus 10 plantões no mês:*
 *   • 01/08 (sáb)
 *   • 04/08 (ter)
 *   ...
 *
 *   Entrada às 07:00, saída às 07:00 do dia seguinte.
 *   Em caso de impedimento, avise a coordenação com antecedência.
 */
class MontadorDeMensagens
{
    /**
     * Cria ou atualiza as mensagens de todos os motoristas com plantao no mes.
     *
     * Mensagens ja enviadas nao sao sobrescritas, para nao perder o registro do
     * que o motorista recebeu; se a escala mudou, o operador reabre e prepara de
     * novo com $recriarEnviadas.
     *
     * @return int Quantidade de mensagens preparadas.
     */
    public function prepararParaEscala(Escala $escala, bool $recriarEnviadas = false): int
    {
        $escala->loadMissing([
            'lotacoes.motorista',
            'lotacoes.posto.unidade',
            'lotacoes.posto.ambulancia',
        ]);

        $preparadas = 0;

        foreach ($this->lotacoesComPlantao($escala) as $lotacao) {
            $mensagem = EscalaMensagem::query()
                ->where('escala_id', $escala->id)
                ->where('motorista_id', $lotacao->motorista_id)
                ->first();

            if ($mensagem?->foiEnviada() && ! $recriarEnviadas) {
                continue;
            }

            EscalaMensagem::query()->updateOrCreate(
                ['escala_id' => $escala->id, 'motorista_id' => $lotacao->motorista_id],
                [
                    'telefone' => $lotacao->motorista->telefone_1,
                    'texto' => $this->textoPara($escala, $lotacao),
                    'status' => EscalaMensagem::PENDENTE,
                    'driver' => null,
                    'enviada_em' => null,
                    'enviada_por' => null,
                    'retorno' => null,
                ]
            );

            $preparadas++;
        }

        return $preparadas;
    }

    /**
     * Texto da mensagem de um motorista.
     */
    public function textoPara(Escala $escala, EscalaLotacao $lotacao): string
    {
        $config = Configuracao::atual();
        $motorista = $lotacao->motorista;

        $plantoes = EscalaPlantao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $lotacao->motorista_id)
            ->orderBy('data')
            ->get();

        $linhas = [];

        // Cabecalho
        $linhas[] = '*ESCALA DE PLANTÃO — '.$escala->referencia().'*';

        $orgao = collect([$config->setor, $config->secretaria])->filter()->implode(' · ');

        if ($orgao !== '') {
            $linhas[] = $orgao;
        }

        $linhas[] = '';

        // Saudacao e lotacao
        $linhas[] = 'Olá, '.$this->tratamento($motorista).'!';

        $posto = $lotacao->posto;

        if ($posto !== null) {
            $partes = ['Você está escalado na *'.mb_strtoupper($posto->unidade?->nome ?? $posto->rotuloLotacao()).'*'];

            if ($posto->ambulancia !== null) {
                $partes[] = 'ambulância *'.$posto->rotuloPlaca().'*';
            }

            $partes[] = 'em regime de *'.$posto->regimeNotacao().'* (plantão de '.$posto->regime()->horasTrabalho.'h)';

            $linhas[] = implode(', ', $partes).'.';
        }

        $linhas[] = '';

        // Dias de plantao
        if ($plantoes->isEmpty()) {
            $linhas[] = 'Você não tem plantões previstos neste mês.';
        } else {
            $total = $plantoes->count();
            $linhas[] = "*Seus {$total} ".($total === 1 ? 'plantão' : 'plantões').' no mês:*';

            foreach ($plantoes as $plantao) {
                $linha = '• '.$plantao->data->format('d/m').' ('.$this->diaDaSemana($plantao).')';

                if ($plantao->ajuste_manual) {
                    $linha .= ' — troca combinada';
                }

                $linhas[] = $linha;
            }

            $linhas[] = '';

            $primeiro = $plantoes->first();
            $linhas[] = 'Entrada às '.$primeiro->horaEntradaTexto()
                .', saída às '.$primeiro->horaSaidaTexto().' do dia seguinte.';
        }

        // Contato da coordenacao
        $linhas[] = '';

        $telefone = $config->telefonesFormatados();

        $linhas[] = $telefone !== ''
            ? 'Em caso de impedimento, avise a coordenação: '.$telefone
            : 'Em caso de impedimento, avise a coordenação com antecedência.';

        return implode("\n", $linhas);
    }

    /**
     * Mensagens da escala com os dados do motorista, para a tela de envio.
     *
     * @return Collection<int, EscalaMensagem>
     */
    public function mensagensDaEscala(Escala $escala): Collection
    {
        return EscalaMensagem::query()
            ->where('escala_id', $escala->id)
            ->with('motorista', 'operador')
            ->get()
            ->sortBy(fn (EscalaMensagem $m) => $m->motorista?->nome_completo ?? '')
            ->values();
    }

    /**
     * Motoristas escalados que nao tem telefone cadastrado — nao ha como
     * comunicar a escala a eles.
     *
     * @return Collection<int, Motorista>
     */
    public function semTelefone(Escala $escala): Collection
    {
        return $this->lotacoesComPlantao($escala)
            ->filter(fn (EscalaLotacao $l) => Telefone::paraWhatsapp($l->motorista->telefone_1) === null)
            ->map(fn (EscalaLotacao $l) => $l->motorista)
            ->sortBy('nome_completo')
            ->values();
    }

    /**
     * Lotacoes que devem receber mensagem: motoristas escalados em um posto e com
     * telefone cadastrado.
     *
     * @return Collection<int, EscalaLotacao>
     */
    private function lotacoesComPlantao(Escala $escala): Collection
    {
        return $escala->lotacoes
            ->filter(fn (EscalaLotacao $l) => $l->escalado() && $l->motorista !== null)
            ->sortBy(fn (EscalaLotacao $l) => $l->motorista->nome_completo)
            ->values();
    }

    /** "sáb", "dom" — abreviacao do dia da semana em portugues. */
    private function diaDaSemana(EscalaPlantao $plantao): string
    {
        return mb_strtolower($plantao->data->translatedFormat('D'));
    }

    /**
     * Como o motorista e chamado na saudacao.
     *
     * E o nome curto do cadastro, inteiro: e justamente o campo em que o setor
     * registra por qual nome cada um e conhecido. Cortar so a primeira palavra
     * dele desfazia essa escolha e confundia quem divide o primeiro nome com
     * outro motorista -- e ha varios Franciscos e Carlos na equipe.
     */
    private function tratamento(Motorista $motorista): string
    {
        return trim($motorista->nome_curto ?: $motorista->nome_completo);
    }
}
