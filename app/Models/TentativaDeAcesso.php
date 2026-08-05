<?php

namespace App\Models;

use App\Enums\MotivoDeAcesso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Uma tentativa de entrada no sistema, bem ou mal sucedida.
 *
 * Serve a dois propositos: dar visibilidade ao coordenador sobre quem anda
 * tentando entrar, e permitir reconstituir o que aconteceu caso alguem
 * questione um acesso.
 *
 * Nunca guardamos a senha digitada, nem em caso de erro.
 */
#[Fillable(['usuario', 'ip', 'navegador', 'sucesso', 'motivo'])]
class TentativaDeAcesso extends Model
{
    use Prunable;

    /** Nao ha updated_at: uma tentativa acontece uma vez e nao muda. */
    public const UPDATED_AT = null;

    /** Meses de historico mantidos. Depois disso o registro perde utilidade. */
    private const MESES_DE_HISTORICO = 6;

    protected $table = 'tentativas_de_acesso';

    /**
     * Registros descartados pelo comando model:prune, agendado diariamente.
     *
     * Uma tentativa exaustiva gera milhares de linhas em minutos; sem descarte
     * a tabela cresceria sem limite num servidor de 1 GB.
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(self::MESES_DE_HISTORICO));
    }

    protected function casts(): array
    {
        return [
            'sucesso' => 'boolean',
            'motivo' => MotivoDeAcesso::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Registra a tentativa a partir da requisicao.
     *
     * O nome do navegador vem cortado em 255 caracteres porque alguns
     * enviam cadeias absurdamente longas, e o excesso nao acrescenta nada.
     */
    public static function registrar(Request $request, string $usuario, MotivoDeAcesso $motivo): self
    {
        return static::query()->create([
            'usuario' => Str::limit($usuario, 250, ''),
            'ip' => $request->ip() ?? 'desconhecido',
            'navegador' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
            'sucesso' => ! $motivo->ehFalha(),
            'motivo' => $motivo,
        ]);
    }

    #[Scope]
    protected function falhas(Builder $consulta): void
    {
        $consulta->where('sucesso', false);
    }

    #[Scope]
    protected function desde(Builder $consulta, \DateTimeInterface $momento): void
    {
        $consulta->where('created_at', '>=', $momento);
    }

    #[Scope]
    protected function maisRecentesPrimeiro(Builder $consulta): void
    {
        $consulta->orderByDesc('created_at')->orderByDesc('id');
    }
}
