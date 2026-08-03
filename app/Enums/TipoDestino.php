<?php

namespace App\Enums;

/**
 * Destino de um motorista ATIVO que nao foi lotado em um posto de escala do mes.
 *
 * Ao fechar a escala mensal todo motorista ativo precisa ter um destino: ou
 * esta escalado em um posto (unidade + ambulancia), ou recebe um destes
 * destinos administrativos. Isso garante que a lista mensal de ocorrencias
 * (segundo documento oficial) contemple 100% do efetivo.
 */
enum TipoDestino: string
{
    case Reserva = 'reserva';
    case Apoio = 'apoio';
    case Ferias = 'ferias';
    case Licenca = 'licenca';
    case Atestado = 'atestado';
    case Cedido = 'cedido';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Reserva => 'Sobreaviso / Reserva',
            self::Apoio => 'Apoio (carro extra)',
            self::Ferias => 'Férias',
            self::Licenca => 'Licença',
            self::Atestado => 'Atestado',
            self::Cedido => 'Cedido a outro setor',
            self::Outro => 'Outro',
        };
    }

    /** Texto impresso na coluna LOTACAO da lista mensal de ocorrencias. */
    public function rotuloLotacao(): string
    {
        return match ($this) {
            self::Reserva => 'SOBREAVISO (RESERVA)',
            self::Apoio => 'APOIO (CARRO EXTRA)',
            self::Ferias => 'FÉRIAS',
            self::Licenca => 'LICENÇA',
            self::Atestado => 'ATESTADO',
            self::Cedido => 'CEDIDO',
            self::Outro => '~',
        };
    }

    /**
     * Destinos que mantem o motorista a disposicao do setor. Usado para
     * dimensionar quantos motoristas ainda podem cobrir faltas.
     */
    public function disponivel(): bool
    {
        return in_array($this, [self::Reserva, self::Apoio], true);
    }

    /** Quantidade de plantoes que costuma ser lancada por padrao. */
    public function plantoesPadrao(): int
    {
        return match ($this) {
            self::Apoio => 8,
            default => 0,
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Reserva => 'bg-sky-100 text-sky-800 ring-sky-200',
            self::Apoio => 'bg-indigo-100 text-indigo-800 ring-indigo-200',
            self::Ferias => 'bg-amber-100 text-amber-800 ring-amber-200',
            self::Licenca => 'bg-rose-100 text-rose-800 ring-rose-200',
            self::Atestado => 'bg-orange-100 text-orange-800 ring-orange-200',
            self::Cedido => 'bg-violet-100 text-violet-800 ring-violet-200',
            self::Outro => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $t) => [$t->value => $t->rotulo()])
            ->all();
    }
}
