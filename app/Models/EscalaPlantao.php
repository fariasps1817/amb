<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um plantao de 24 horas: o "X" da planilha mensal.
 */
#[Fillable([
    'escala_id', 'escala_posto_id', 'motorista_id', 'data', 'posicao',
    'hora_entrada', 'hora_saida', 'ajuste_manual', 'observacao',
])]
class EscalaPlantao extends Model
{
    protected $table = 'escala_plantoes';

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'posicao' => 'integer',
            'ajuste_manual' => 'boolean',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    public function posto(): BelongsTo
    {
        return $this->belongsTo(EscalaPosto::class, 'escala_posto_id');
    }

    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function doMotorista(Builder $query, int $motoristaId): void
    {
        $query->where('motorista_id', $motoristaId);
    }

    #[Scope]
    protected function noPeriodo(Builder $query, Carbon $inicio, Carbon $fim): void
    {
        $query->whereBetween('data', [$inicio->toDateString(), $fim->toDateString()]);
    }

    #[Scope]
    protected function porData(Builder $query): void
    {
        $query->orderBy('data');
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    /** "01/08" — como aparece na folha de frequencia e nas mensagens. */
    public function diaMes(): string
    {
        return $this->data->format('d/m');
    }

    public function dataFormatada(): string
    {
        return $this->data->format('d/m/Y');
    }

    /** "sáb", "dom" — cabecalho de dia da semana da planilha. */
    public function diaSemanaCurto(): string
    {
        return mb_strtolower($this->data->translatedFormat('D'));
    }

    /**
     * Momento de entrada no plantao (data do plantao + hora de entrada).
     */
    public function entradaEm(): Carbon
    {
        return $this->data->copy()->setTimeFromTimeString($this->horaEntradaTexto().':00');
    }

    /**
     * Momento de saida. O plantao de 24 horas comeca as 07:00 e termina as
     * 07:00 do dia seguinte, por isso a saida cai no dia posterior sempre que a
     * hora de saida nao for maior que a de entrada.
     */
    public function saidaEm(): Carbon
    {
        $entrada = $this->entradaEm();
        $saida = $this->data->copy()->setTimeFromTimeString($this->horaSaidaTexto().':00');

        return $saida->greaterThan($entrada) ? $saida : $saida->addDay();
    }

    public function horaEntradaTexto(): string
    {
        return substr((string) $this->hora_entrada, 0, 5) ?: '07:00';
    }

    public function horaSaidaTexto(): string
    {
        return substr((string) $this->hora_saida, 0, 5) ?: '07:00';
    }
}
