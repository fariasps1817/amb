<?php

namespace App\Http\Controllers;

use App\Enums\StatusMotorista;
use App\Models\Escala;
use App\Models\Motorista;
use App\Services\Escalas\ValidadorDeEscala;
use App\Services\Whatsapp\PlantaoDoDia;
use App\Support\Aniversario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Tela inicial, pensada para quem abre o sistema no celular para o dia a dia:
 * quem esta de plantao (com o envio para as unidades), os aniversariantes e a
 * situacao da escala. Numeros de cadastro ficam nas telas de cadastro.
 */
class PainelController extends Controller
{
    public function __invoke(PlantaoDoDia $plantao): View
    {
        $hoje = today();

        $escalaAtual = Escala::query()->doMes($hoje->year, $hoje->month)->first();
        $proxima = $hoje->copy()->addMonthNoOverflow();
        $proximaEscala = Escala::query()->doMes($proxima->year, $proxima->month)->first();

        $plantoes = $plantao->plantoes($hoje);
        $texto = $plantao->texto($hoje);

        return view('painel.index', [
            'hoje' => $hoje,
            'plantoes' => $plantoes,
            'semPlantonista' => $plantao->semPlantonista($hoje),
            'textoDoPlantao' => $texto,
            'linkDoPlantao' => $plantao->linkWhatsapp($texto),
            'antesDaTroca' => $this->antesDaTroca($plantoes),

            'aniversariantesHoje' => $this->aniversariantesDeHoje(),
            'proximoAniversariante' => $this->proximoAniversariante(),

            'escalaAtual' => $escalaAtual,
            'errosDaEscalaAtual' => $this->erros($escalaAtual),
            'proximoMes' => $proxima,
            'proximaEscala' => $proximaEscala,
            'errosDaProximaEscala' => $this->erros($proximaEscala),

            'pendencias' => $this->pendenciasDeCadastro(),
        ]);
    }

    /**
     * Antes da hora de troca (7h, em geral), quem esta na ambulancia ainda e o
     * plantonista de ontem. Sem o aviso, quem consulta de madrugada ve a
     * equipe que so entra mais tarde.
     */
    private function antesDaTroca(Collection $plantoes): bool
    {
        $entrada = $plantoes->first()?->horaEntradaTexto();

        return $entrada !== null && now()->format('H:i') < $entrada;
    }

    /** @return Collection<int, Motorista> */
    private function aniversariantesDeHoje(): Collection
    {
        $consulta = Motorista::query()->ativos();
        Aniversario::aplicar($consulta, Aniversario::HOJE);

        return $consulta->orderBy('nome_completo')->get();
    }

    /**
     * O proximo a fazer anos depois de hoje, para o cartao nao ficar vazio nos
     * dias sem aniversario.
     *
     * @return array{motorista: Motorista, data: Carbon}|null
     */
    private function proximoAniversariante(): ?array
    {
        $amanha = today()->addDay();

        return Motorista::query()
            ->ativos()
            ->whereNotNull('data_nascimento')
            ->get()
            ->map(function (Motorista $m) use ($amanha) {
                $data = $m->aniversarioEm($amanha->year);

                return ['motorista' => $m, 'data' => $data->lt($amanha) ? $m->aniversarioEm($amanha->year + 1) : $data];
            })
            ->sortBy(fn (array $a) => $a['data']->format('Ymd').'|'.$a['motorista']->nome_completo)
            ->first();
    }

    /**
     * Pendencias que impedem publicar. So para rascunho: a escala publicada ja
     * passou por esta validacao, e repeti-la a cada abertura do painel custaria
     * dezenas de consultas no servidor pequeno.
     */
    private function erros(?Escala $escala): int
    {
        if ($escala === null || ! $escala->ehRascunho()) {
            return 0;
        }

        return collect(ValidadorDeEscala::para($escala)->validar())->filter(fn ($a) => $a->ehErro())->count();
    }

    /**
     * Cadastros que precisam de atencao: CNH vencida ou vencendo e contrato
     * proximo do fim. Evita descobrir o problema so na hora de fechar a escala.
     *
     * @return array<string, Collection>
     */
    private function pendenciasDeCadastro(): array
    {
        $ativos = Motorista::query()
            ->where('status', StatusMotorista::Ativo)
            ->ordenadoPorNome()
            ->get();

        return [
            'cnh_vencida' => $ativos->filter(fn (Motorista $m) => $m->cnhVencida())->values(),
            'cnh_vencendo' => $ativos->filter(fn (Motorista $m) => $m->cnhVencendo())->values(),
            'contrato_encerrado' => $ativos->filter(fn (Motorista $m) => $m->contratoEncerrado())->values(),
            'contrato_vencendo' => $ativos->filter(fn (Motorista $m) => $m->contratoVencendo())->values(),
            'sem_telefone' => $ativos->filter(fn (Motorista $m) => blank($m->telefone_1))->values(),
        ];
    }
}
