<?php

namespace App\Support;

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

        if ($valor === self::HOJE) {
            $query
                ->whereMonth('data_nascimento', now()->month)
                ->whereDay('data_nascimento', now()->day);

            return;
        }

        $query->whereMonth(
            'data_nascimento',
            $valor === self::MES_CORRENTE ? now()->month : (int) $valor
        );
    }

    private static function nomeDoMes(int $mes): string
    {
        return ucfirst(Carbon::create(null, $mes, 1)->translatedFormat('F'));
    }
}
