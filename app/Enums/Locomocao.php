<?php

namespace App\Enums;

/**
 * Como o paciente se locomove: e o que decide o preparo da ambulancia.
 */
enum Locomocao: string
{
    case AndaSozinho = 'anda_sozinho';
    case AndaComAjuda = 'anda_com_ajuda';
    case CadeiraDeRodas = 'cadeira_de_rodas';
    case Acamado = 'acamado';

    public function rotulo(): string
    {
        return match ($this) {
            self::AndaSozinho => 'Anda sozinho',
            self::AndaComAjuda => 'Anda com ajuda',
            self::CadeiraDeRodas => 'Cadeira de rodas',
            self::Acamado => 'Acamado (maca)',
        };
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $l) => [$l->value => $l->rotulo()])
            ->all();
    }
}
