<?php

namespace App\Services\Whatsapp;

use App\Models\Escala;
use App\Models\EscalaMensagem;
use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Support\Telefone;

/**
 * Entrega das mensagens pelo driver configurado.
 */
class EnviadorDeMensagens
{
    public function __construct(private readonly DriverDeWhatsapp $driver) {}

    public function driver(): DriverDeWhatsapp
    {
        return $this->driver;
    }

    /**
     * Envia uma mensagem e registra o resultado.
     *
     * No driver de link o envio nao acontece aqui: o retorno traz a URL wa.me
     * para o operador abrir, e a confirmacao e feita depois com
     * marcarComoEnviada().
     */
    public function enviar(EscalaMensagem $mensagem): ResultadoEnvio
    {
        $destino = Telefone::paraWhatsapp($mensagem->telefone);

        if ($destino === null) {
            $resultado = ResultadoEnvio::falha(
                $this->driver->nome(),
                'Telefone inválido ou não cadastrado.'
            );

            $mensagem->marcarErro((string) $resultado->retorno);

            return $resultado;
        }

        $resultado = $this->driver->enviar($destino, $mensagem->texto);

        if ($resultado->enviado) {
            $mensagem->marcarEnviada($resultado->driver, $resultado->retorno);
        } elseif (! $resultado->requerAcaoManual) {
            $mensagem->marcarErro((string) $resultado->retorno);
        }

        return $resultado;
    }

    /**
     * Envia todas as mensagens pendentes da escala.
     *
     * Disponivel apenas para drivers automaticos; com o driver de link o operador
     * envia uma a uma pela tela.
     *
     * @return array{enviadas: int, falhas: int, mensagens: array<int, string>}
     */
    public function enviarPendentes(Escala $escala): array
    {
        $enviadas = 0;
        $falhas = 0;
        $avisos = [];

        $pendentes = EscalaMensagem::query()
            ->where('escala_id', $escala->id)
            ->where('status', '!=', EscalaMensagem::ENVIADA)
            ->with('motorista')
            ->get();

        foreach ($pendentes as $mensagem) {
            $resultado = $this->enviar($mensagem);

            if ($resultado->enviado) {
                $enviadas++;

                continue;
            }

            $falhas++;

            $nome = $mensagem->motorista?->nome_curto ?? "#{$mensagem->motorista_id}";
            $avisos[] = "{$nome}: ".($resultado->retorno ?? 'falha no envio');
        }

        return ['enviadas' => $enviadas, 'falhas' => $falhas, 'mensagens' => $avisos];
    }

    /**
     * Registra o envio manual feito pelo operador no driver de link.
     */
    public function marcarComoEnviada(EscalaMensagem $mensagem): void
    {
        $mensagem->marcarEnviada($this->driver->nome(), 'Enviada manualmente pelo operador.');
    }
}
