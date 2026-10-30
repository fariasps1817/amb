<?php

namespace App\Enums;

/**
 * Situacao de um pedido de transporte. So "aguardando" conta no numero vermelho
 * do painel: os outros dois sao o desfecho registrado pela coordenacao.
 */
enum StatusSolicitacao: string
{
    case Aguardando = 'aguardando';
    case Atendida = 'atendida';
    case NaoAtendida = 'nao_atendida';

    public function rotulo(): string
    {
        return match ($this) {
            self::Aguardando => 'Aguardando',
            self::Atendida => 'Atendida',
            self::NaoAtendida => 'Não atendida',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Aguardando => 'bg-rose-100 text-rose-800 ring-rose-200',
            self::Atendida => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::NaoAtendida => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }
}
