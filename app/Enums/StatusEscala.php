<?php

namespace App\Enums;

/**
 * Ciclo de vida da escala mensal.
 *
 * Rascunho   -> em montagem, pode ser regerada livremente. Um mes pode ter
 *               varias, como propostas alternativas aguardando aprovacao.
 * Publicada  -> distribuida as unidades; serve de ancora para a rotacao do mes
 *               seguinte e libera a emissao dos documentos e mensagens.
 * Arquivada  -> mes encerrado, mantido apenas para historico.
 * Descartada -> proposta que nao foi a escolhida, guardada so para consulta.
 */
enum StatusEscala: string
{
    case Rascunho = 'rascunho';
    case Publicada = 'publicada';
    case Arquivada = 'arquivada';
    case Descartada = 'descartada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Publicada => 'Publicada',
            self::Arquivada => 'Arquivada',
            self::Descartada => 'Descartada',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Rascunho => 'bg-amber-100 text-amber-800 ring-amber-200',
            self::Publicada => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::Arquivada => 'bg-slate-100 text-slate-600 ring-slate-200',
            self::Descartada => 'bg-slate-100 text-slate-500 ring-slate-200',
        };
    }

    public function editavel(): bool
    {
        return $this === self::Rascunho || $this === self::Publicada;
    }

    /** A escala que vale para o mes: a que foi publicada (e depois arquivada). */
    public function oficial(): bool
    {
        return $this === self::Publicada || $this === self::Arquivada;
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->rotulo()])
            ->all();
    }
}
