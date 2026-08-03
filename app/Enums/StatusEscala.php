<?php

namespace App\Enums;

/**
 * Ciclo de vida da escala mensal.
 *
 * Rascunho  -> em montagem, pode ser regerada livremente.
 * Publicada -> distribuida as unidades; serve de ancora para a rotacao do mes
 *              seguinte e libera a emissao dos documentos e mensagens.
 * Arquivada -> mes encerrado, mantido apenas para historico.
 */
enum StatusEscala: string
{
    case Rascunho = 'rascunho';
    case Publicada = 'publicada';
    case Arquivada = 'arquivada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Publicada => 'Publicada',
            self::Arquivada => 'Arquivada',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Rascunho => 'bg-amber-100 text-amber-800 ring-amber-200',
            self::Publicada => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::Arquivada => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }

    public function editavel(): bool
    {
        return $this !== self::Arquivada;
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->rotulo()])
            ->all();
    }
}
