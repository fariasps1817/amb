<?php

namespace App\Services\Whatsapp;

/**
 * Retorno de uma tentativa de envio.
 */
final readonly class ResultadoEnvio
{
    private function __construct(
        public bool $enviado,
        public string $driver,
        public ?string $retorno = null,
        /** Preenchido pelo driver de link: URL para o operador abrir. */
        public ?string $link = null,
        /** Verdadeiro quando o envio depende de uma acao do operador. */
        public bool $requerAcaoManual = false,
    ) {}

    public static function sucesso(string $driver, ?string $retorno = null): self
    {
        return new self(true, $driver, $retorno);
    }

    public static function falha(string $driver, string $retorno): self
    {
        return new self(false, $driver, $retorno);
    }

    /**
     * O driver de link nao envia sozinho: devolve a URL wa.me para o operador
     * abrir e confirmar o envio.
     */
    public static function manual(string $driver, string $link): self
    {
        return new self(false, $driver, null, $link, true);
    }
}
