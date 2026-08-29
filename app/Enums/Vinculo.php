<?php

namespace App\Enums;

/**
 * Vinculo funcional do motorista com o municipio.
 */
enum Vinculo: string
{
    case Efetivo = 'efetivo';
    case Contrato = 'contrato';

    public function rotulo(): string
    {
        return match ($this) {
            self::Efetivo => 'Efetivo',
            self::Contrato => 'Contrato',
        };
    }

    /**
     * Como o servidor e descrito na relacao do cadastro, onde a coluna e
     * estreita e nao cabe a data de termino: fala da pessoa ("Contratado"), e
     * nao do tipo de vinculo ("Contrato").
     */
    public function rotuloDoServidor(): string
    {
        return match ($this) {
            self::Efetivo => 'Efetivo',
            self::Contrato => 'Contratado',
        };
    }

    /** Texto usado nos documentos oficiais (coluna VINCULO da lista mensal). */
    public function rotuloDocumento(): string
    {
        return match ($this) {
            self::Efetivo => 'EFETIVO',
            self::Contrato => 'CONTRATO',
        };
    }

    /** Contratos temporarios exigem data de fim de vinculo. */
    public function exigeDataFim(): bool
    {
        return $this === self::Contrato;
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $v) => [$v->value => $v->rotulo()])
            ->all();
    }
}
