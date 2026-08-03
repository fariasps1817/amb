<?php

namespace App\Services\Whatsapp\Drivers;

use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Services\Whatsapp\ResultadoEnvio;

/**
 * Envio manual por link wa.me.
 *
 * O sistema monta a mensagem e devolve a URL; o operador clica, o WhatsApp Web
 * ou o aplicativo abre com o texto pronto e ele so aperta enviar.
 *
 * E o modo padrao por tres motivos: nao tem custo, nao exige numero comercial
 * nem aprovacao de template, e nao corre risco de bloqueio do numero por envio
 * automatizado em lote.
 */
class DriverDeLink implements DriverDeWhatsapp
{
    public function enviar(string $telefone, string $texto): ResultadoEnvio
    {
        return ResultadoEnvio::manual(
            $this->nome(),
            'https://wa.me/'.$telefone.'?text='.rawurlencode($texto)
        );
    }

    public function nome(): string
    {
        return 'link';
    }

    public function configurado(): bool
    {
        return true;
    }

    public function pendenciaDeConfiguracao(): ?string
    {
        return null;
    }

    public function requerAcaoManual(): bool
    {
        return true;
    }
}
