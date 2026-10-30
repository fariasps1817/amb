<?php

namespace App\Enums;

/**
 * Por que o transporte foi pedido. A sigla inicia o protocolo. A consulta
 * eletiva, pedida pelo paciente ou pela familia, entra aqui quando for definida.
 */
enum TipoSolicitacao: string
{
    case AltaHospitalar = 'alta_hospitalar';

    public function rotulo(): string
    {
        return match ($this) {
            self::AltaHospitalar => 'Alta hospitalar',
        };
    }

    public function sigla(): string
    {
        return match ($this) {
            self::AltaHospitalar => 'AH',
        };
    }
}
