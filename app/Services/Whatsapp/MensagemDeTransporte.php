<?php

namespace App\Services\Whatsapp;

use App\Models\Configuracao;
use App\Models\SolicitacaoDeTransporte;
use App\Support\Telefone;

/**
 * Texto que a coordenacao manda ao motorista pelo WhatsApp com o pedido de
 * transporte: onde buscar, quem levar, para onde e como o paciente esta.
 *
 * Traz so o que o motorista precisa para fazer a viagem. Sai pelo WhatsApp de
 * quem coordena, e por isso nao leva nada da origem do envio (IP, navegador).
 */
class MensagemDeTransporte
{
    public function texto(SolicitacaoDeTransporte $solicitacao): string
    {
        $config = Configuracao::atual();

        // O negrito do WhatsApp nao atravessa a quebra de linha: um par de * em cada.
        $linhas = [
            '🚑 *TRANSPORTE DE PACIENTE*',
            '*'.mb_strtoupper($solicitacao->tipo->rotulo()).'*',
            'Protocolo '.$solicitacao->protocolo,
            '',
            '*Buscar em:*',
            $solicitacao->hospital_nome,
            $solicitacao->hospital_bairro.' · '.$solicitacao->hospital_cidade,
            'Contato: '.$solicitacao->solicitante_nome.' | '.$solicitacao->solicitante_funcao,
            $solicitacao->telefoneSolicitante(),
            '',
            '*Paciente:*',
            $solicitacao->paciente_nome.' · '.$solicitacao->idadeTexto(),
        ];

        if (filled($solicitacao->acompanhante_nome)) {
            $linhas[] = 'Acompanhante: '.$solicitacao->acompanhante_nome;
        }

        array_push($linhas, ...$this->telefones($solicitacao->contato_telefone_1, $solicitacao->contato_telefone_2));

        $linhas[] = '';
        $linhas[] = '*Levar para:*';
        $linhas[] = $solicitacao->destino_endereco;
        $linhas[] = $solicitacao->destino_bairro.' · '.$solicitacao->destino_municipio.'-'.SolicitacaoDeTransporte::UF_DESTINO;

        if (filled($solicitacao->destino_referencia)) {
            $linhas[] = 'Referência: '.$solicitacao->destino_referencia;
        }

        $linhas[] = '';
        $linhas[] = '*Situação do paciente:*';
        $linhas[] = implode(' · ', $solicitacao->situacaoEmEtiquetas());

        if (filled($solicitacao->observacoes_paciente)) {
            $linhas[] = $solicitacao->observacoes_paciente;
        }

        $assinatura = [
            ...(filled($config->setor) ? ['_'.$config->setor.'_'] : []),
            ...$this->telefones($config->telefone_1, $config->telefone_2),
        ];

        if ($assinatura !== []) {
            array_push($linhas, '', ...$assinatura);
        }

        return implode("\n", $linhas);
    }

    /**
     * Cada numero fica sozinho na sua linha, sem rotulo nem formatacao: facil de
     * tocar no celular para ligar ou copiar.
     *
     * @return list<string>
     */
    private function telefones(?string ...$numeros): array
    {
        return collect($numeros)
            ->filter()
            ->map(fn (string $numero) => Telefone::formatar($numero))
            ->values()
            ->all();
    }
}
