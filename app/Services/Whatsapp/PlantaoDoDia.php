<?php

namespace App\Services\Whatsapp;

use App\Enums\TipoDestino;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Quem esta de plantao num dia, e o texto para avisar as unidades.
 *
 * Todo dia o coordenador manda para a UPA, o hospital e a secretaria a lista
 * de quem esta em cada ambulancia, com o telefone. Era digitada a mao no
 * WhatsApp; aqui sai pronta, na ordem da planilha, para ele so escolher o
 * grupo e enviar.
 */
class PlantaoDoDia
{
    /**
     * Plantoes do dia, na ordem das ambulancias definida na montagem.
     *
     * @return Collection<int, EscalaPlantao>
     */
    public function plantoes(Carbon $dia): Collection
    {
        return EscalaPlantao::query()
            ->whereDate('data', $dia->toDateString())
            ->with(['motorista', 'posto.unidade', 'posto.ambulancia'])
            ->get()
            // Chave em texto: ordem do posto e, no empate, o proprio posto.
            ->sortBy(fn (EscalaPlantao $p) => sprintf('%06d|%09d', $p->posto?->ordem ?? 0, $p->escala_posto_id))
            ->values();
    }

    /** Escala do mes do dia, para a tela avisar quando ainda e rascunho. */
    public function escala(Carbon $dia): ?Escala
    {
        return Escala::query()->doMes($dia->year, $dia->month)->first();
    }

    /**
     * Ambulancias que operam no dia e estao sem ninguem: vaga aberta na
     * montagem. Aparecem na tela, para o coordenador resolver antes de avisar
     * as unidades, e nao no texto.
     *
     * @return Collection<int, EscalaPosto>
     */
    public function semPlantonista(Carbon $dia): Collection
    {
        $escala = $this->escala($dia);

        if ($escala === null) {
            return collect();
        }

        $cobertos = EscalaPlantao::query()
            ->where('escala_id', $escala->id)
            ->whereDate('data', $dia->toDateString())
            ->pluck('escala_posto_id')
            ->all();

        return $escala->postos()
            ->with(['unidade', 'ambulancia', 'escala'])
            ->whereNotIn('id', $cobertos ?: [0])
            ->get()
            ->filter(fn (EscalaPosto $posto) => $dia->copy()->startOfDay()
                ->between($posto->inicioVigencia(), $posto->fimVigencia()))
            ->values();
    }

    /**
     * Quem esta de sobreaviso no mes -- a quem a coordenacao recorre quando
     * alguem falta. Respeita o periodo, quando o destino tem um.
     *
     * @return Collection<int, EscalaLotacao>
     */
    public function sobreaviso(Carbon $dia): Collection
    {
        $escala = $this->escala($dia);

        if ($escala === null) {
            return collect();
        }

        $data = $dia->copy()->startOfDay();

        return EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNull('escala_posto_id')
            ->where('tipo_destino', TipoDestino::Reserva)
            ->with('motorista')
            ->get()
            ->filter(fn (EscalaLotacao $l) => $l->motorista?->ativo()
                && ($l->periodo_inicio === null || $data->gte($l->periodo_inicio))
                && ($l->periodo_fim === null || $data->lte($l->periodo_fim)))
            ->sortBy(fn (EscalaLotacao $l) => $l->motorista->nome_completo)
            ->values();
    }

    /**
     * Texto pronto para o WhatsApp: uma ambulancia por bloco, com o motorista
     * e o telefone, e o horario do plantao no topo.
     */
    public function texto(Carbon $dia, bool $comSobreaviso = false): string
    {
        $plantoes = $this->plantoes($dia);
        $config = Configuracao::atual();

        $linhas = [];
        $linhas[] = '🚑 *PLANTÃO DAS AMBULÂNCIAS*';
        $linhas[] = '*'.ucfirst($dia->translatedFormat('l')).', '.$dia->format('d/m/Y').'*';

        if ($plantoes->isEmpty()) {
            $linhas[] = '';
            $linhas[] = 'Nenhum plantão registrado para este dia.';

            return implode("\n", $linhas);
        }

        // O horario vai no topo quando e o mesmo para todos -- o caso comum,
        // das 7h as 7h. Se alguma ambulancia tiver outro, ele vai na linha dela.
        $horarios = $plantoes->map(fn (EscalaPlantao $p) => $p->horaEntradaTexto().'|'.$p->horaSaidaTexto())->unique();
        $horarioUnico = $horarios->count() === 1;

        if ($horarioUnico) {
            $primeiro = $plantoes->first();
            $linhas[] = 'Das '.$primeiro->horaEntradaTexto().' de '.$dia->format('d/m')
                .' às '.$primeiro->horaSaidaTexto().' de '.$dia->copy()->addDay()->format('d/m');
        }

        foreach ($plantoes as $plantao) {
            $posto = $plantao->posto;
            $motorista = $plantao->motorista;

            $linhas[] = '';
            $linhas[] = '*'.($posto?->rotuloLotacao() ?? '—').'* · '.($posto?->rotuloPlaca() ?? '—')
                .($horarioUnico ? '' : ' · '.$plantao->horaEntradaTexto().' às '.$plantao->horaSaidaTexto());
            $linhas[] = $this->pessoa($motorista);
        }

        if ($comSobreaviso && ($reservas = $this->sobreaviso($dia))->isNotEmpty()) {
            $linhas[] = '';
            $linhas[] = '*Sobreaviso*';

            foreach ($reservas as $lotacao) {
                $linhas[] = $this->pessoa($lotacao->motorista);
            }
        }

        $assinatura = collect([$config->setor, $config->telefonesFormatados()])->filter()->implode(' · ');

        if ($assinatura !== '') {
            $linhas[] = '';
            $linhas[] = '_'.$assinatura.'_';
        }

        return implode("\n", $linhas);
    }

    /**
     * Link que abre o WhatsApp com o texto pronto e deixa escolher a conversa
     * ou o grupo -- sem numero, de proposito: o destino muda a cada envio.
     */
    public function linkWhatsapp(string $texto): string
    {
        return 'https://wa.me/?text='.rawurlencode($texto);
    }

    private function pessoa(?Motorista $motorista): string
    {
        if ($motorista === null) {
            return 'sem motorista';
        }

        $telefone = $motorista->telefoneFormatado();

        return $motorista->nomePlanilha().($telefone !== '' ? ' · '.$telefone : '');
    }
}
