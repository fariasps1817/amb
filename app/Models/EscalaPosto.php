<?php

namespace App\Models;

use App\Support\Regime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Posto de escala: unidade + ambulancia + regime dentro de um mes.
 *
 * Cada posto corresponde a um bloco da planilha impressa (grupo de linhas que
 * compartilham a mesma placa e a mesma lotacao).
 */
#[Fillable([
    'escala_id', 'unidade_id', 'ambulancia_id',
    'horas_trabalho', 'horas_descanso', 'rotulo',
    'data_inicio', 'data_fim', 'continuar_rotacao', 'ordem', 'observacao',
])]
class EscalaPosto extends Model
{
    protected $table = 'escala_postos';

    protected function casts(): array
    {
        return [
            'horas_trabalho' => 'integer',
            'horas_descanso' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'continuar_rotacao' => 'boolean',
            'ordem' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function ambulancia(): BelongsTo
    {
        return $this->belongsTo(Ambulancia::class);
    }

    /** Motoristas lotados no posto, na ordem do ciclo de rotacao. */
    public function lotacoes(): HasMany
    {
        return $this->hasMany(EscalaLotacao::class)->orderBy('posicao');
    }

    public function plantoes(): HasMany
    {
        return $this->hasMany(EscalaPlantao::class)->orderBy('data');
    }

    // -----------------------------------------------------------------
    // Regime
    // -----------------------------------------------------------------

    public function regime(): Regime
    {
        return new Regime($this->horas_trabalho ?: 24, $this->horas_descanso ?? 72);
    }

    public function regimeNotacao(): string
    {
        return $this->regime()->notacao();
    }

    /** Quantos motoristas o posto precisa para fechar o ciclo. */
    public function vagas(): int
    {
        return $this->regime()->motoristasNecessarios();
    }

    /** Usa a relacao ja carregada quando disponivel, evitando consulta extra. */
    public function vagasOcupadas(): int
    {
        return $this->lotacoes->count();
    }

    public function vagasLivres(): int
    {
        return max(0, $this->vagas() - $this->vagasOcupadas());
    }

    public function completo(): bool
    {
        return $this->vagasLivres() === 0;
    }

    // -----------------------------------------------------------------
    // Periodo de vigencia dentro do mes
    // -----------------------------------------------------------------

    /**
     * Primeiro dia em que o posto gera plantao. Por padrao o dia 1 do mes, mas
     * pode comecar depois (ex.: ambulancia entregue em 04/08).
     */
    public function inicioVigencia(): Carbon
    {
        $primeiro = $this->escala->primeiroDia();

        if ($this->data_inicio === null) {
            return $primeiro;
        }

        return $this->data_inicio->greaterThan($primeiro)
            ? $this->data_inicio->copy()->startOfDay()
            : $primeiro;
    }

    public function fimVigencia(): Carbon
    {
        $ultimo = $this->escala->ultimoDia();

        if ($this->data_fim === null) {
            return $ultimo;
        }

        return $this->data_fim->lessThan($ultimo)
            ? $this->data_fim->copy()->startOfDay()
            : $ultimo;
    }

    /** Dias do mes em que este posto opera. @return array<int, Carbon> */
    public function diasVigentes(): array
    {
        $dias = [];
        $cursor = $this->inicioVigencia();
        $fim = $this->fimVigencia();

        while ($cursor->lessThanOrEqualTo($fim)) {
            $dias[] = $cursor->copy();
            $cursor->addDay();
        }

        return $dias;
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    /** Texto da coluna LOT da planilha. */
    public function rotuloLotacao(): string
    {
        return mb_strtoupper($this->rotulo ?: ($this->unidade?->sigla ?? ''));
    }

    /** Texto da coluna P da planilha (placa da ambulancia). */
    public function rotuloPlaca(): string
    {
        return $this->ambulancia?->placaFormatada() ?? '—';
    }

    public function descricao(): string
    {
        return sprintf(
            '%s · %s · %s',
            $this->rotuloLotacao(),
            $this->rotuloPlaca(),
            $this->regimeNotacao()
        );
    }

    /**
     * Motorista escalado em determinada data, se houver.
     */
    public function motoristaEm(Carbon $data): ?Motorista
    {
        /** @var EscalaPlantao|null $plantao */
        $plantao = $this->plantoes->firstWhere(
            fn (EscalaPlantao $p) => $p->data->isSameDay($data)
        );

        return $plantao?->motorista;
    }

    /**
     * Lotacoes indexadas pela posicao do ciclo (1..N).
     *
     * @return Collection<int, EscalaLotacao>
     */
    public function lotacoesPorPosicao(): Collection
    {
        return $this->lotacoes->keyBy('posicao');
    }
}
