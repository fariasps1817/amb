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
use Illuminate\Support\Collection;

#[Fillable([
    'ano', 'mes', 'status', 'nome', 'origem_id', 'observacao', 'criada_por', 'gerada_em',
    'publicada_em', 'publicada_por', 'aprovada_por', 'aprovada_em',
    'pendencias_aceitas', 'justificativa_publicacao',
])]
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
            'aprovada_em' => 'date',
            'pendencias_aceitas' => 'array',
        ];
    }

    // -----------------------------------------------------------------
    // Qual escala vale para o mes
    // -----------------------------------------------------------------

    /**
     * A escala que vale para o mes: a oficial (publicada ou arquivada) ou,
     * enquanto nao ha oficial, a proposta em rascunho quando ela e a unica.
     *
     * Com duas ou mais propostas e nenhuma publicada nao ha escala vigente:
     * nenhuma delas pode aparecer no painel, no plantao do dia, nas mensagens
     * ou na rotacao do mes seguinte antes da decisao.
     */
    public static function vigenteDoMes(int $ano, int $mes): ?self
    {
        return self::escolherVigente(static::query()->doMes($ano, $mes)->get());
    }

    /**
     * Propostas que aguardam decisao: as do mes, quando sao mais de uma e
     * nenhuma foi publicada. Vazia quando o mes esta resolvido.
     *
     * @return Collection<int, self>
     */
    public static function propostasSemDecisao(int $ano, int $mes): Collection
    {
        $doMes = static::query()->doMes($ano, $mes)->orderBy('id')->get();

        if ($doMes->contains(fn (self $e) => $e->oficial())) {
            return collect();
        }

        $rascunhos = $doMes->filter(fn (self $e) => $e->ehRascunho())->values();

        return $rascunhos->count() > 1 ? $rascunhos : collect();
    }

    /**
     * Ids das escalas vigentes de todos os meses. E o filtro de tudo que olha
     * plantoes de varios meses ao mesmo tempo -- a ancora da rotacao, o
     * descanso na virada, os proximos plantoes do motorista.
     *
     * @return list<int>
     */
    public static function idsVigentes(): array
    {
        return static::query()
            ->get(['id', 'ano', 'mes', 'status'])
            ->groupBy(fn (self $e) => $e->ano * 100 + $e->mes)
            ->map(fn (Collection $doMes) => self::escolherVigente($doMes)?->id)
            ->filter()
            ->values()
            ->all();
    }

    /** @param  Collection<int, self>  $doMes */
    private static function escolherVigente(Collection $doMes): ?self
    {
        $oficial = $doMes->first(fn (self $e) => $e->oficial());

        if ($oficial !== null) {
            return $oficial;
        }

        $rascunhos = $doMes->filter(fn (self $e) => $e->ehRascunho());

        return $rascunhos->count() === 1 ? $rascunhos->first() : null;
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

    public function publicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicada_por');
    }

    /** Escala da qual esta proposta foi duplicada. */
    public function origem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origem_id');
    }

    /**
     * Todas as escalas do mesmo mes, esta inclusive, na ordem de criacao: as
     * propostas alternativas e as descartadas.
     *
     * @return Collection<int, self>
     */
    public function doMesmoMes(): Collection
    {
        return static::query()->doMes($this->ano, $this->mes)->orderBy('id')->get();
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

    /** "Agosto de 2026 · Proposta B" — o mes e, quando houver, o nome da proposta. */
    public function titulo(): string
    {
        return $this->referenciaLonga().($this->nome ? ' · '.$this->nome : '');
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

    /** Publicada ou arquivada: a que valeu para o mes. */
    public function oficial(): bool
    {
        return $this->status->oficial();
    }

    public function descartada(): bool
    {
        return $this->status === StatusEscala::Descartada;
    }

    /** Publicada com pendências aceitas por quem publicou, com motivo registrado. */
    public function publicadaComPendencias(): bool
    {
        return $this->oficial() && filled($this->pendencias_aceitas);
    }

    public function gerada(): bool
    {
        return $this->gerada_em !== null;
    }

    /**
     * A escala vigente do mes imediatamente anterior, ancora da rotacao
     * continua. Nula quando aquele mes ainda tem propostas sem decisao.
     */
    public function anterior(): ?self
    {
        $mesAnterior = $this->primeiroDia()->subMonth();

        return static::vigenteDoMes($mesAnterior->year, $mesAnterior->month);
    }

    /** Propostas do mes anterior ainda sem decisao (vazia no caso normal). */
    public function anteriorSemDecisao(): Collection
    {
        $mesAnterior = $this->primeiroDia()->subMonth();

        return static::propostasSemDecisao($mesAnterior->year, $mesAnterior->month);
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
