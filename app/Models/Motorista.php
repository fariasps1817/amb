<?php

namespace App\Models;

use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Support\Telefone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'nome_completo', 'nome_curto', 'cpf', 'data_nascimento',
    'vinculo', 'vinculo_inicio', 'vinculo_fim',
    'cnh_numero', 'cnh_categoria', 'cnh_validade',
    'telefone_1', 'telefone_2', 'matricula', 'status', 'observacao',
])]
class Motorista extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'motoristas';

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'vinculo_inicio' => 'date',
            'vinculo_fim' => 'date',
            'cnh_validade' => 'date',
            'vinculo' => Vinculo::class,
            'status' => StatusMotorista::class,
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function ativos(Builder $query): void
    {
        $query->where('status', StatusMotorista::Ativo);
    }

    #[Scope]
    protected function ordenadoPorNome(Builder $query): void
    {
        $query->orderBy('nome_completo');
    }

    /** Busca por nome, apelido, CPF ou telefone. */
    #[Scope]
    protected function busca(Builder $query, ?string $termo): void
    {
        $termo = trim((string) $termo);

        if ($termo === '') {
            return;
        }

        $digitos = Telefone::digitos($termo);

        $query->where(function (Builder $q) use ($termo, $digitos) {
            $q->where('nome_completo', 'like', "%{$termo}%")
                ->orWhere('nome_curto', 'like', "%{$termo}%")
                ->orWhere('matricula', 'like', "%{$termo}%");

            if ($digitos !== '') {
                $q->orWhere('cpf', 'like', "%{$digitos}%")
                    ->orWhere('telefone_1', 'like', "%{$digitos}%")
                    ->orWhere('telefone_2', 'like', "%{$digitos}%");
            }
        });
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    /**
     * Nome como aparece na planilha da escala: as duas primeiras palavras em
     * caixa alta, com a segunda em negrito no documento impresso.
     */
    public function nomePlanilha(): string
    {
        return mb_strtoupper($this->nome_curto ?: $this->nome_completo);
    }

    public function nomeDocumento(): string
    {
        return mb_strtoupper($this->nome_completo);
    }

    public function telefoneFormatado(): string
    {
        return Telefone::formatar($this->telefone_1);
    }

    public function telefone2Formatado(): string
    {
        return Telefone::formatar($this->telefone_2);
    }

    public function cpfFormatado(): string
    {
        $d = Telefone::digitos($this->cpf);

        if (strlen($d) !== 11) {
            return (string) $this->cpf;
        }

        return sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9));
    }

    public function ativo(): bool
    {
        return $this->status === StatusMotorista::Ativo;
    }

    public function idade(): ?int
    {
        return $this->data_nascimento?->age;
    }

    // -----------------------------------------------------------------
    // Alertas de regularidade
    // -----------------------------------------------------------------

    /** CNH vencida impede o motorista de assumir plantao. */
    public function cnhVencida(): bool
    {
        return $this->cnh_validade !== null && $this->cnh_validade->isPast();
    }

    /** CNH que vence nos proximos 60 dias merece aviso ao coordenador. */
    public function cnhVencendo(int $dias = 60): bool
    {
        if ($this->cnh_validade === null || $this->cnhVencida()) {
            return false;
        }

        return now()->startOfDay()->diffInDays($this->cnh_validade, false) <= $dias;
    }

    public function contratoEncerrado(): bool
    {
        return $this->vinculo === Vinculo::Contrato
            && $this->vinculo_fim !== null
            && $this->vinculo_fim->isPast();
    }

    /** Contrato que termina nos proximos 30 dias. */
    public function contratoVencendo(int $dias = 30): bool
    {
        if ($this->vinculo !== Vinculo::Contrato || $this->vinculo_fim === null || $this->contratoEncerrado()) {
            return false;
        }

        return now()->startOfDay()->diffInDays($this->vinculo_fim, false) <= $dias;
    }

    /**
     * O motorista esta apto a ser escalado em determinado periodo?
     *
     * Retorna a lista de impedimentos encontrados (vazia = apto). Usado pelo
     * gerador de escalas para avisar o coordenador antes de fechar o mes.
     */
    public function impedimentosNoPeriodo(\DateTimeInterface $inicio, \DateTimeInterface $fim): array
    {
        $impedimentos = [];

        if (! $this->ativo()) {
            $impedimentos[] = 'Motorista com cadastro inativo.';
        }

        if ($this->cnh_validade !== null && $this->cnh_validade < $inicio) {
            $impedimentos[] = 'CNH vencida em '.$this->cnh_validade->format('d/m/Y').'.';
        }

        if ($this->vinculo === Vinculo::Contrato && $this->vinculo_fim !== null && $this->vinculo_fim < $inicio) {
            $impedimentos[] = 'Contrato encerrado em '.$this->vinculo_fim->format('d/m/Y').'.';
        }

        if ($this->vinculo_inicio !== null && $this->vinculo_inicio > $fim) {
            $impedimentos[] = 'Vínculo começa em '.$this->vinculo_inicio->format('d/m/Y').'.';
        }

        return $impedimentos;
    }
}
