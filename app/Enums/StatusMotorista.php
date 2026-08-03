<?php

namespace App\Enums;

/**
 * Situacao cadastral do motorista. Somente motoristas ATIVOS entram na
 * montagem da escala mensal.
 */
enum StatusMotorista: string
{
    case Ativo = 'ativo';
    case Inativo = 'inativo';

    public function rotulo(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::Inativo => 'Inativo',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Ativo => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::Inativo => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->rotulo()])
            ->all();
    }
}
