<?php

namespace App\Models;

use App\Enums\StatusEscala;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['ano', 'mes', 'status', 'observacao', 'criada_por', 'gerada_em', 'publicada_em'])]
class Escala extends Model
{
    protected $table = 'escalas';

    protected function casts(): array
    {
        return [
            'ano' => 'integer',
            'mes' => 'integer',
            'status' => StatusEscala::class,
            'gerada_em' => 'datetime',
            'publicada_em' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    public function postos(): HasMany
    {
        return $this->hasMany(EscalaPosto::class)->orderBy('ordem')->orderBy('id');
    }

    public function lotacoes(): HasMany
    {
        return $this->hasMany(EscalaLotacao::class);
    }

    public function plantoes(): HasMany
    {
        return $this->hasMany(EscalaPlantao::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(EscalaMensagem::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function maisRecentes(Builder $query): void
    {
        $query->orderByDesc('ano')->orderByDesc('mes');
    }

    #[Scope]
    protected function doMes(Builder $query, int $ano, int $mes): void
    {
        $query->where('ano', $ano)->where('mes', $mes);
    }

    // -----------------------------------------------------------------
    // Periodo
    // -----------------------------------------------------------------

    public function primeiroDia(): Carbon
    {
        return Carbon::create($this->ano, $this->mes, 1)->startOfDay();
    }

    public function ultimoDia(): Carbon
    {
        return $this->primeiroDia()->endOfMonth()->startOfDay();
    }

    public function diasNoMes(): int
    {
        return $this->primeiroDia()->daysInMonth;
    }

    /**
     * @return array<int, Carbon> Todos os dias do mes, do dia 1 ao ultimo.
     */
    public function dias(): array
    {
        $inicio = $this->primeiroDia();

        return array_map(
            fn (int $d) => $inicio->copy()->addDays($d - 1),
            range(1, $this->diasNoMes())
        );
    }

    /** "AGOSTO/2026" — como aparece no titulo dos documentos. */
    public function referencia(): string
    {
        return mb_strtoupper($this->primeiroDia()->translatedFormat('F/Y'));
    }

    /** "Agosto de 2026" — para as telas. */
    public function referenciaLonga(): string
    {
        return ucfirst($this->primeiroDia()->translatedFormat('F \d\e Y'));
    }

    /** "AGOSTO/26" — formato curto usado na folha de frequencia. */
    public function referenciaCurta(): string
    {
        return mb_strtoupper($this->primeiroDia()->translatedFormat('F/y'));
    }

    // -----------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------

    public function ehRascunho(): bool
    {
        return $this->status === StatusEscala::Rascunho;
    }

    public function publicada(): bool
    {
        return $this->status === StatusEscala::Publicada;
    }

    public function editavel(): bool
    {
        return $this->status->editavel();
    }

    public function gerada(): bool
    {
        return $this->gerada_em !== null;
    }

    /** A escala do mes imediatamente anterior, ancora da rotacao continua. */
    public function anterior(): ?self
    {
        $mesAnterior = $this->primeiroDia()->subMonth();

        return static::query()
            ->doMes($mesAnterior->year, $mesAnterior->month)
            ->first();
    }

    // -----------------------------------------------------------------
    // Numeros do painel
    // -----------------------------------------------------------------

    public function totalPlantoes(): int
    {
        return $this->plantoes()->count();
    }

    public function totalEscalados(): int
    {
        return $this->lotacoes()->whereNotNull('escala_posto_id')->count();
    }

    public function totalComDestino(): int
    {
        return $this->lotacoes()->whereNull('escala_posto_id')->whereNotNull('tipo_destino')->count();
    }
}
