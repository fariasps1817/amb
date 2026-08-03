<?php

namespace App\Models;

use App\Enums\VinculoAmbulancia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'placa', 'renavam', 'vinculo', 'marca', 'modelo',
    'ano_fabricacao', 'ano_modelo', 'tipo', 'identificacao',
    'unidade_id', 'ativo', 'observacao',
])]
class Ambulancia extends Model
{
    protected $table = 'ambulancias';

    public const TIPOS = [
        'Básica' => 'Suporte Básico',
        'UTI' => 'Suporte Avançado / UTI',
        'Resgate' => 'Resgate',
        'Transporte' => 'Transporte Sanitário',
    ];

    protected function casts(): array
    {
        return [
            'vinculo' => VinculoAmbulancia::class,
            'ativo' => 'boolean',
            'ano_fabricacao' => 'integer',
            'ano_modelo' => 'integer',
            'unidade_id' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    /** Lotacao padrao, apenas sugerida ao montar a escala do mes. */
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function postos(): HasMany
    {
        return $this->hasMany(EscalaPosto::class);
    }

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function ativas(Builder $query): void
    {
        $query->where('ativo', true);
    }

    #[Scope]
    protected function busca(Builder $query, ?string $termo): void
    {
        $termo = trim((string) $termo);

        if ($termo === '') {
            return;
        }

        $query->where(function (Builder $q) use ($termo) {
            $q->where('placa', 'like', '%'.str_replace('-', '', $termo).'%')
                ->orWhere('renavam', 'like', "%{$termo}%")
                ->orWhere('modelo', 'like', "%{$termo}%")
                ->orWhere('marca', 'like', "%{$termo}%")
                ->orWhere('identificacao', 'like', "%{$termo}%");
        });
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    /** Placa em caixa alta, como impressa na planilha (coluna P). */
    public function placaFormatada(): string
    {
        return mb_strtoupper(trim((string) $this->placa));
    }

    /** Rotulo curto para listas e seletores: "THQ4H34 · Sprinter (SEDE 1)". */
    public function rotulo(): string
    {
        $partes = [$this->placaFormatada()];

        if ($this->modelo) {
            $partes[] = $this->modelo;
        }

        if ($this->identificacao) {
            $partes[] = "({$this->identificacao})";
        }

        return implode(' · ', $partes);
    }

    public function anos(): string
    {
        if ($this->ano_fabricacao && $this->ano_modelo) {
            return "{$this->ano_fabricacao}/{$this->ano_modelo}";
        }

        return (string) ($this->ano_fabricacao ?: $this->ano_modelo ?: '');
    }

    public function marcaModelo(): string
    {
        return trim(collect([$this->marca, $this->modelo])->filter()->implode(' '));
    }

    /** Idade do veiculo em anos, quando o ano de fabricacao esta preenchido. */
    public function idade(): ?int
    {
        return $this->ano_fabricacao ? max(0, (int) now()->year - (int) $this->ano_fabricacao) : null;
    }
}
