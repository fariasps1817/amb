<?php

namespace App\Models;

use App\Enums\TipoDestino;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destino de um motorista na escala do mes.
 *
 * Ou o motorista esta escalado em um posto (escala_posto_id + posicao), ou tem
 * um destino administrativo (tipo_destino). Ao publicar a escala o sistema
 * exige que todo motorista ativo tenha uma destas duas situacoes definidas.
 */
#[Fillable([
    'escala_id', 'motorista_id', 'escala_posto_id', 'posicao',
    'tipo_destino', 'unidade_apoio_id', 'periodo_inicio', 'periodo_fim',
    'plantoes_previstos', 'plantoes_ajustados', 'observacao',
])]
class EscalaLotacao extends Model
{
    protected $table = 'escala_lotacoes';

    protected function casts(): array
    {
        return [
            'posicao' => 'integer',
            'tipo_destino' => TipoDestino::class,
            'periodo_inicio' => 'date',
            'periodo_fim' => 'date',
            'plantoes_previstos' => 'integer',
            'plantoes_ajustados' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    public function posto(): BelongsTo
    {
        return $this->belongsTo(EscalaPosto::class, 'escala_posto_id');
    }

    /** Unidade de referencia quando o destino e apoio / carro extra. */
    public function unidadeApoio(): BelongsTo
    {
        return $this->belongsTo(Unidade::class, 'unidade_apoio_id');
    }

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function escalados(Builder $query): void
    {
        $query->whereNotNull('escala_posto_id');
    }

    #[Scope]
    protected function comDestino(Builder $query): void
    {
        $query->whereNull('escala_posto_id')->whereNotNull('tipo_destino');
    }

    #[Scope]
    protected function semDefinicao(Builder $query): void
    {
        $query->whereNull('escala_posto_id')->whereNull('tipo_destino');
    }

    // -----------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------

    public function escalado(): bool
    {
        return $this->escala_posto_id !== null;
    }

    public function definido(): bool
    {
        return $this->escalado() || $this->tipo_destino !== null;
    }

    /** Motorista a disposicao do setor (reserva ou apoio). */
    public function disponivel(): bool
    {
        return $this->tipo_destino?->disponivel() ?? false;
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    /**
     * Texto da coluna LOTACAO da lista mensal de ocorrencias.
     *
     * Escalado -> rotulo do posto ("SEDE 1", "GUANACES").
     * Destino  -> rotulo do destino ("SOBREAVISO (RESERVA)", "FÉRIAS").
     */
    public function rotuloLotacao(): string
    {
        if ($this->escalado()) {
            return $this->posto?->rotuloLotacao() ?? '~';
        }

        if ($this->tipo_destino === null) {
            return '~';
        }

        $rotulo = $this->tipo_destino->rotuloLotacao();

        if ($this->tipo_destino === TipoDestino::Apoio && $this->unidadeApoio) {
            return $rotulo.' — '.mb_strtoupper($this->unidadeApoio->sigla);
        }

        return $rotulo;
    }

    /**
     * Texto da coluna OCORRENCIA. Quando o operador nao escreveu nada e o
     * destino tem periodo definido, monta a frase automaticamente
     * ("Férias de 01 a 30/08/26").
     */
    public function textoOcorrencia(): string
    {
        if ($this->observacao) {
            return $this->observacao;
        }

        if ($this->periodo_inicio === null || $this->tipo_destino === null) {
            return '';
        }

        $rotulo = $this->tipo_destino->rotulo();

        if ($this->periodo_fim === null) {
            return "{$rotulo} a partir de ".$this->periodo_inicio->format('d/m/Y');
        }

        // Mesmo mes: "Férias de 01 a 30/08/26".
        if ($this->periodo_inicio->isSameMonth($this->periodo_fim)) {
            return sprintf(
                '%s de %s a %s',
                $rotulo,
                $this->periodo_inicio->format('d'),
                $this->periodo_fim->format('d/m/y')
            );
        }

        return sprintf(
            '%s de %s a %s',
            $rotulo,
            $this->periodo_inicio->format('d/m/y'),
            $this->periodo_fim->format('d/m/y')
        );
    }

    public function situacaoRotulo(): string
    {
        if ($this->escalado()) {
            return 'Escalado';
        }

        return $this->tipo_destino?->rotulo() ?? 'Sem definição';
    }

    // -----------------------------------------------------------------
    // Plantoes
    // -----------------------------------------------------------------

    /**
     * Quantidade que vai para a coluna PLANTOES do documento.
     *
     * Por padrao e a contagem da escala; quando o operador informa um ajuste —
     * o caso de quem faltou a um plantao — vale o numero informado.
     */
    public function plantoesEfetivos(): int
    {
        return $this->plantoes_ajustados ?? (int) $this->plantoes_previstos;
    }

    public function plantoesForamAjustados(): bool
    {
        return $this->plantoes_ajustados !== null
            && $this->plantoes_ajustados !== (int) $this->plantoes_previstos;
    }

    /**
     * Diferenca entre o informado e o calculado: -1 para quem faltou um plantao.
     * Zero quando nao ha ajuste.
     */
    public function diferencaDePlantoes(): int
    {
        return $this->plantoes_ajustados === null
            ? 0
            : $this->plantoes_ajustados - (int) $this->plantoes_previstos;
    }
}
