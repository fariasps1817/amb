<?php

namespace App\Enums;

/**
 * Por que um pedido nao foi atendido. Sem este desfecho, os pedidos que nao se
 * realizam ficariam contando como pendentes no painel para sempre.
 */
enum MotivoNaoAtendimento: string
{
    case PacienteDesistiu = 'paciente_desistiu';
    case HospitalCancelou = 'hospital_cancelou';
    case SemAmbulancia = 'sem_ambulancia';
    case NaoConfirmada = 'nao_confirmada';
    case Indevida = 'indevida';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::PacienteDesistiu => 'Paciente ou família desistiu',
            self::HospitalCancelou => 'Hospital cancelou',
            self::SemAmbulancia => 'Sem ambulância disponível',
            self::NaoConfirmada => 'Não foi possível confirmar por telefone',
            self::Indevida => 'Solicitação indevida (não veio do hospital)',
            self::Outro => 'Outro motivo',
        };
    }

    public static function opcoes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $m) => [$m->value => $m->rotulo()])
            ->all();
    }
}
