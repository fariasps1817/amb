<?php

namespace App\Support;

use App\Models\Motorista;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filtro de aniversariantes da listagem de motoristas.
 *
 * Reune num lugar so as opcoes do select, a consulta e o texto impresso no
 * PDF. Sao tres leituras do mesmo criterio, e separa-las e o caminho curto
 * para a tela dizer uma coisa e o documento dizer outra.
 */
final class Aniversario
{
    public const HOJE = 'hoje';

    public const AMANHA = 'amanha';

    public const SEMANA = 'semana';

    public const MES_CORRENTE = 'mes';

    /**
     * Opcoes do select, na ordem em que aparecem.
     *
     * Os meses nomeados existem porque a lista costuma ser preparada antes de
     * o mes virar -- em 28 de agosto o coordenador quer os de setembro.
     *
     * @return array<string, string>
     */
    public static function opcoes(): array
    {
        $opcoes = [
            self::HOJE => 'Aniversariantes de hoje',
            self::AMANHA => 'Aniversariantes de amanhã',
            self::SEMANA => 'Aniversariantes dos próximos 7 dias',
            self::MES_CORRENTE => 'Aniversariantes deste mês',
        ];

        foreach (range(1, 12) as $mes) {
            $opcoes[(string) $mes] = 'Aniversariantes de '.self::nomeDoMes($mes);
        }

        return $opcoes;
    }

    public static function valido(?string $valor): bool
    {
        return $valor !== null && array_key_exists($valor, self::opcoes());
    }

    /** Texto do filtro, impresso abaixo do titulo do PDF. */
    public static function descricao(string $valor): ?string
    {
        return self::opcoes()[$valor] ?? null;
    }

    public static function aplicar(Builder $query, string $valor): void
    {
        // Quem nao tem data de nascimento no cadastro nao pode entrar numa
        // lista de aniversariantes -- nem como "sem data".
        $query->whereNotNull('data_nascimento');

        $hoje = today();

        $dias = match ($valor) {
            self::HOJE => [$hoje],
            self::AMANHA => [$hoje->copy()->addDay()],
            // Dia a dia, e nao "mes entre X e Y": a semana pode virar o ano.
            self::SEMANA => array_map(fn (int $n) => $hoje->copy()->addDays($n), range(0, 6)),
            default => null,
        };

        if ($dias !== null) {
            self::nosDias($query, $dias);

            return;
        }

        $query->whereMonth(
            'data_nascimento',
            $valor === self::MES_CORRENTE ? $hoje->month : (int) $valor
        );
    }

    /** Aniversariantes de uma data escolhida no calendario. */
    public static function aplicarDia(Builder $query, Carbon $dia): void
    {
        $query->whereNotNull('data_nascimento');

        self::nosDias($query, [$dia]);
    }

    /**
     * Data em que o motorista comemora dentro do filtro: e por ela que a lista
     * se ordena e e dela que sai a idade que ele completa.
     */
    public static function dataNoFiltro(Motorista $motorista, string $valor, ?Carbon $dia = null): Carbon
    {
        $hoje = today();

        if ($dia !== null) {
            return $dia->copy()->startOfDay();
        }

        return match ($valor) {
            self::HOJE => $hoje,
            self::AMANHA => $hoje->copy()->addDay(),
            self::SEMANA => $motorista->aniversarioEm($hoje->year)->lt($hoje)
                ? $motorista->aniversarioEm($hoje->year + 1)
                : $motorista->aniversarioEm($hoje->year),
            default => $motorista->aniversarioEm($hoje->year),
        };
    }

    /**
     * @param  array<int, Carbon>  $dias
     */
    private static function nosDias(Builder $query, array $dias): void
    {
        $query->where(function (Builder $q) use ($dias) {
            foreach ($dias as $dia) {
                $q->orWhere(fn (Builder $d) => $d
                    ->whereMonth('data_nascimento', $dia->month)
                    ->whereDay('data_nascimento', $dia->day));

                // Quem nasceu em 29/02 comemora em 28/02 nos anos sem o dia 29.
                if ($dia->month === 2 && $dia->day === 28 && ! $dia->isLeapYear()) {
                    $q->orWhere(fn (Builder $d) => $d
                        ->whereMonth('data_nascimento', 2)
                        ->whereDay('data_nascimento', 29));
                }
            }
        });
    }

    private static function nomeDoMes(int $mes): string
    {
        return ucfirst(Carbon::create(null, $mes, 1)->translatedFormat('F'));
    }
}
