<?php

namespace App\Services\Escalas;

use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use Illuminate\Support\Carbon;

/**
 * Verificacoes de consistencia da escala antes da publicacao.
 *
 * Complementa o AnalisadorDeEfetivo (que cuida do dimensionamento) checando o
 * que foi efetivamente gerado: intervalo de descanso entre plantoes, aptidao dos
 * motoristas escalados e integridade dos postos.
 */
class ValidadorDeEscala
{
    public function __construct(protected Escala $escala) {}

    public static function para(Escala $escala): self
    {
        return new self($escala);
    }

    /**
     * @return array<int, Alerta>
     */
    public function validar(): array
    {
        return array_merge(
            AnalisadorDeEfetivo::para($this->escala)->alertas(),
            $this->validarPostos(),
            $this->validarDescanso(),
            $this->validarAptidao(),
            $this->validarDiasDescobertos(),
        );
    }

    /** A escala pode ser publicada? */
    public function podePublicar(): bool
    {
        foreach ($this->validar() as $alerta) {
            if ($alerta->ehErro()) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Integridade dos postos
    // -----------------------------------------------------------------

    /** @return array<int, Alerta> */
    protected function validarPostos(): array
    {
        $alertas = [];

        $this->escala->loadMissing('postos.ambulancia', 'postos.unidade');

        foreach ($this->escala->postos as $posto) {
            if ($posto->ambulancia_id === null) {
                $alertas[] = Alerta::erro(
                    'posto_sem_ambulancia',
                    "O posto {$posto->rotuloLotacao()} está sem ambulância definida.",
                    ['posto_id' => $posto->id]
                );

                continue;
            }

            if ($posto->ambulancia && ! $posto->ambulancia->ativo) {
                $alertas[] = Alerta::atencao(
                    'ambulancia_inativa',
                    "A ambulância {$posto->rotuloPlaca()} ({$posto->rotuloLotacao()}) está marcada como inativa no cadastro.",
                    ['posto_id' => $posto->id]
                );
            }

            if ($posto->unidade && ! $posto->unidade->ativo) {
                $alertas[] = Alerta::atencao(
                    'unidade_inativa',
                    "A unidade {$posto->unidade->sigla} está marcada como inativa no cadastro.",
                    ['posto_id' => $posto->id]
                );
            }
        }

        return $alertas;
    }

    // -----------------------------------------------------------------
    // Descanso entre plantoes
    // -----------------------------------------------------------------

    /**
     * Nenhum motorista pode assumir um novo plantao antes de cumprir o descanso
     * do regime. A checagem inclui o ultimo plantao do mes anterior, que e onde
     * o erro costuma passar batido.
     *
     * @return array<int, Alerta>
     */
    protected function validarDescanso(): array
    {
        $alertas = [];

        $plantoes = EscalaPlantao::query()
            ->where('escala_id', $this->escala->id)
            ->with(['motorista', 'posto'])
            ->orderBy('motorista_id')
            ->orderBy('data')
            ->get()
            ->groupBy('motorista_id');

        foreach ($plantoes as $motoristaId => $doMotorista) {
            /** @var Motorista|null $motorista */
            $motorista = $doMotorista->first()->motorista;
            $nome = $motorista?->nome_curto ?? "Motorista #{$motoristaId}";

            // Plantao imediatamente anterior ao mes, para checar a virada.
            $anterior = EscalaPlantao::query()
                ->where('motorista_id', $motoristaId)
                ->where('data', '<', $this->escala->primeiroDia()->toDateString())
                ->orderByDesc('data')
                ->first();

            $sequencia = $anterior ? collect([$anterior])->concat($doMotorista) : $doMotorista;
            $lista = $sequencia->values();

            for ($i = 1; $i < $lista->count(); $i++) {
                /** @var EscalaPlantao $atual */
                $atual = $lista[$i];
                /** @var EscalaPlantao $previo */
                $previo = $lista[$i - 1];

                $horasEntre = $previo->saidaEm()->diffInHours($atual->entradaEm(), false);
                $descansoExigido = $atual->posto?->regime()->horasDescanso ?? 72;

                // Tolerancia de 1 hora para arredondamentos de horario.
                if ($horasEntre < $descansoExigido - 1) {
                    $alertas[] = Alerta::erro(
                        'descanso_insuficiente',
                        sprintf(
                            '%s tem plantão em %s e %s: apenas %dh de intervalo, sendo que o regime %s exige %dh de descanso.',
                            $nome,
                            $previo->data->format('d/m'),
                            $atual->data->format('d/m'),
                            max(0, (int) $horasEntre),
                            $atual->posto?->regimeNotacao() ?? '',
                            $descansoExigido,
                        ),
                        ['motorista_id' => $motoristaId, 'data' => $atual->data->toDateString()]
                    );
                }
            }

            // Um motorista lotado em dois postos no mesmo dia e erro grave.
            $duplicados = $doMotorista->groupBy(fn (EscalaPlantao $p) => $p->data->toDateString())
                ->filter(fn ($grupo) => $grupo->count() > 1);

            foreach ($duplicados as $data => $grupo) {
                $alertas[] = Alerta::erro(
                    'plantao_duplicado',
                    sprintf(
                        '%s está escalado em %d ambulâncias no dia %s.',
                        $nome,
                        $grupo->count(),
                        Carbon::parse($data)->format('d/m/Y'),
                    ),
                    ['motorista_id' => $motoristaId, 'data' => $data]
                );
            }
        }

        return $alertas;
    }

    // -----------------------------------------------------------------
    // Aptidao dos escalados
    // -----------------------------------------------------------------

    /**
     * CNH vencida, contrato encerrado ou vinculo que ainda nao comecou.
     *
     * @return array<int, Alerta>
     */
    protected function validarAptidao(): array
    {
        $alertas = [];
        $inicio = $this->escala->primeiroDia();
        $fim = $this->escala->ultimoDia();

        $this->escala->loadMissing('lotacoes.motorista');

        foreach ($this->escala->lotacoes as $lotacao) {
            if (! $lotacao->escalado() || $lotacao->motorista === null) {
                continue;
            }

            foreach ($lotacao->motorista->impedimentosNoPeriodo($inicio, $fim) as $impedimento) {
                $alertas[] = Alerta::erro(
                    'motorista_inapto',
                    "{$lotacao->motorista->nome_curto}: {$impedimento}",
                    ['motorista_id' => $lotacao->motorista_id]
                );
            }

            if ($lotacao->motorista->cnhVencendo()) {
                $alertas[] = Alerta::atencao(
                    'cnh_vencendo',
                    sprintf(
                        'A CNH de %s vence em %s.',
                        $lotacao->motorista->nome_curto,
                        $lotacao->motorista->cnh_validade->format('d/m/Y'),
                    ),
                    ['motorista_id' => $lotacao->motorista_id]
                );
            }

            if ($lotacao->motorista->contratoVencendo()) {
                $alertas[] = Alerta::atencao(
                    'contrato_vencendo',
                    sprintf(
                        'O contrato de %s encerra em %s.',
                        $lotacao->motorista->nome_curto,
                        $lotacao->motorista->vinculo_fim->format('d/m/Y'),
                    ),
                    ['motorista_id' => $lotacao->motorista_id]
                );
            }
        }

        return $alertas;
    }

    // -----------------------------------------------------------------
    // Cobertura do mes
    // -----------------------------------------------------------------

    /**
     * Dias em que uma ambulancia ficou sem motorista.
     *
     * @return array<int, Alerta>
     */
    protected function validarDiasDescobertos(): array
    {
        $alertas = [];

        $this->escala->loadMissing('postos.plantoes');

        foreach ($this->escala->postos as $posto) {
            $esperados = collect($posto->diasVigentes())->map(fn (Carbon $d) => $d->toDateString());
            $cobertos = $posto->plantoes->map(fn (EscalaPlantao $p) => $p->data->toDateString());
            $descobertos = $esperados->diff($cobertos);

            if ($descobertos->isEmpty()) {
                continue;
            }

            $alertas[] = Alerta::erro(
                'dias_descobertos',
                sprintf(
                    '%s: %d dia(s) sem motorista (%s%s).',
                    $posto->descricao(),
                    $descobertos->count(),
                    $descobertos->take(8)->map(fn ($d) => Carbon::parse($d)->format('d/m'))->implode(', '),
                    $descobertos->count() > 8 ? '...' : '',
                ),
                ['posto_id' => $posto->id, 'datas' => $descobertos->values()->all()]
            );
        }

        return $alertas;
    }
}
