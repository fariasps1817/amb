<?php

namespace App\Enums;

/**
 * Origem do veiculo: patrimonio do municipio ou locado de terceiro.
 */
enum VinculoAmbulancia: string
{
    case Propria = 'propria';
    case Alugada = 'alugada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Propria => 'Própria',
            self::Alugada => 'Alugada',
        };
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $v) => [$v->value => $v->rotulo()])
            ->all();
    }
}
