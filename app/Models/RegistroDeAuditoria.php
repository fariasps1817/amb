<?php

namespace App\Models;

use App\Enums\CategoriaDeAuditoria;
use App\Support\Dispositivo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Uma acao feita no sistema: quem, quando, o que, de onde.
 *
 * Traz CPF e telefone completos no antes e depois, por decisao da coordenacao, e
 * por isso so o Administrador ve. Nunca guarda senha.
 *
 * Registros nao mudam: uma auditoria que se pode editar nao prova nada. Saem
 * apenas pelo descarte do prazo de guarda, que apaga direto no banco.
 */
#[Fillable([
    'user_id', 'usuario_nome', 'usuario_login',
    'categoria', 'acao', 'descricao',
    'alvo_tipo', 'alvo_id', 'escala_id', 'alteracoes',
    'ip', 'navegador', 'sessao', 'created_at',
])]
class RegistroDeAuditoria extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Meses de historico mantidos, por decisao da coordenacao. */
    public const MESES_DE_HISTORICO = 12;

    protected $table = 'registros_de_auditoria';

    protected static function booted(): void
    {
        $recusar = fn () => throw new LogicException('Registros de auditoria não podem ser alterados.');

        static::updating($recusar);
        static::deleting($recusar);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(self::MESES_DE_HISTORICO));
    }

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaDeAuditoria::class,
            'alteracoes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @param  array<string, mixed>  $filtros  usuario, categoria, escala, de, ate, busca */
    #[Scope]
    protected function filtrado(Builder $consulta, array $filtros): void
    {
        $consulta
            ->when($filtros['usuario'] ?? null, fn ($c, $id) => $c->where('user_id', $id))
            ->when($filtros['categoria'] ?? null, fn ($c, $categoria) => $c->where('categoria', $categoria))
            ->when($filtros['escala'] ?? null, fn ($c, $id) => $c->where('escala_id', $id))
            ->when($filtros['de'] ?? null, fn ($c, $data) => $c->where('created_at', '>=', Carbon::parse($data)->startOfDay()))
            ->when($filtros['ate'] ?? null, fn ($c, $data) => $c->where('created_at', '<=', Carbon::parse($data)->endOfDay()))
            ->when($filtros['busca'] ?? null, fn ($c, $termo) => $c->where(fn (Builder $q) => $q
                ->where('descricao', 'like', "%{$termo}%")
                ->orWhere('usuario_nome', 'like', "%{$termo}%")
                ->orWhere('ip', 'like', "%{$termo}%")));
    }

    #[Scope]
    protected function maisRecentesPrimeiro(Builder $consulta): void
    {
        $consulta->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Quem fez. O unico registro sem pessoa e o pedido que chega pela pagina aberta. */
    public function autor(): string
    {
        return $this->usuario_nome ?? 'Página aberta (sem login)';
    }

    public function dispositivo(): string
    {
        return Dispositivo::descrever($this->navegador);
    }
}
