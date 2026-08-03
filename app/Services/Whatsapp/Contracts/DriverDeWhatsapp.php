<?php

namespace App\Services\Whatsapp\Contracts;

use App\Services\Whatsapp\ResultadoEnvio;

/**
 * Forma de entrega de uma mensagem de WhatsApp.
 *
 * Implementacoes: envio manual por link wa.me, API oficial da Meta e Evolution
 * API self-hosted. O driver ativo vem de config('whatsapp.driver').
 */
interface DriverDeWhatsapp
{
    /**
     * @param  string  $telefone  Numero em E.164 sem simbolos (5585986926853).
     */
    public function enviar(string $telefone, string $texto): ResultadoEnvio;

    /** Identificador usado nos registros de envio. */
    public function nome(): string;

    /** O driver esta configurado e pronto para uso? */
    public function configurado(): bool;

    /** Mensagem explicando o que falta configurar, quando for o caso. */
    public function pendenciaDeConfiguracao(): ?string;

    /** O envio depende de uma acao do operador na tela? */
    public function requerAcaoManual(): bool;
}
