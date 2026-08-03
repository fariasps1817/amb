<?php

namespace App\Models;

use App\Support\Regime;
use App\Support\Telefone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'nome', 'sigla', 'tipo',
    'endereco', 'bairro', 'cidade', 'uf', 'cep',
    'responsavel', 'cargo_responsavel', 'telefone_1', 'telefone_2', 'email',
    'horas_trabalho', 'horas_descanso',
    'ordem', 'ativo', 'observacao',
])]
class Unidade extends Model
{
    use HasFactory;

    protected $table = 'unidades';

    public const TIPOS = [
        'UPA' => 'UPA',
        'Hospital' => 'Hospital',
        'Posto de Saúde' => 'Posto de Saúde',
        'Sede' => 'Sede / Secretaria',
        'Distrito' => 'Distrito / Interior',
        'Outro' => 'Outro',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'horas_trabalho' => 'integer',
            'horas_descanso' => 'integer',
            'ordem' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    public function ambulancias(): HasMany
    {
        return $this->hasMany(Ambulancia::class);
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
    protected function ordenada(Builder $query): void
    {
        $query->orderBy('ordem')->orderBy('nome');
    }

    #[Scope]
    protected function busca(Builder $query, ?string $termo): void
    {
        $termo = trim((string) $termo);

        if ($termo === '') {
            return;
        }

        $query->where(function (Builder $q) use ($termo) {
            $q->where('nome', 'like', "%{$termo}%")
                ->orWhere('sigla', 'like', "%{$termo}%")
                ->orWhere('bairro', 'like', "%{$termo}%")
                ->orWhere('responsavel', 'like', "%{$termo}%");
        });
    }

    // -----------------------------------------------------------------
    // Regime de plantao
    // -----------------------------------------------------------------

    /**
     * Regime de plantao praticado pelas ambulancias desta unidade. E daqui que
     * o gerador de escalas descobre quantos motoristas cada ambulancia precisa.
     */
    public function regime(): Regime
    {
        return new Regime(
            $this->horas_trabalho ?: 24,
            $this->horas_descanso ?? 72,
        );
    }

    public function regimeNotacao(): string
    {
        return $this->regime()->notacao();
    }

    /** Motoristas necessarios por ambulancia desta unidade. */
    public function motoristasPorAmbulancia(): int
    {
        return $this->regime()->motoristasNecessarios();
    }

    /**
     * Total de motoristas que a unidade demanda considerando toda a sua frota
     * ativa. Usado no painel de dimensionamento do efetivo.
     */
    public function motoristasNecessarios(): int
    {
        return $this->ambulancias()->where('ativo', true)->count() * $this->motoristasPorAmbulancia();
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    public function nomeCompleto(): string
    {
        return $this->sigla ? "{$this->sigla} — {$this->nome}" : (string) $this->nome;
    }

    public function enderecoCompleto(): string
    {
        return collect([
            $this->endereco,
            $this->bairro,
            trim(collect([$this->cidade, $this->uf])->filter()->implode('/')),
            $this->cep ? 'CEP '.$this->cep : null,
        ])->filter()->implode(', ');
    }

    public function telefoneFormatado(): string
    {
        return Telefone::formatar($this->telefone_1);
    }

    public function telefonesFormatados(): string
    {
        return collect([$this->telefone_1, $this->telefone_2])
            ->filter()
            ->map(fn ($t) => Telefone::formatar($t))
            ->implode(' / ');
    }
}
