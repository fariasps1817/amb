<?php

namespace App\Services\Whatsapp\Drivers;

use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Services\Whatsapp\ResultadoEnvio;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Evolution API — servidor proprio que conecta um numero comum de WhatsApp por
 * leitura de QR Code.
 *
 * Nao exige numero comercial nem aprovacao de template, mas roda em
 * infraestrutura propria e usa uma conexao nao oficial: envios em volume podem
 * levar ao bloqueio do numero pela Meta. Use com intervalo entre as mensagens.
 *
 * Para configurar, preencha no .env:
 *   WHATSAPP_DRIVER=evolution
 *   WHATSAPP_EVOLUTION_URL=https://seu-servidor
 *   WHATSAPP_EVOLUTION_KEY=...
 *   WHATSAPP_EVOLUTION_INSTANCE=...
 */
class DriverEvolution implements DriverDeWhatsapp
{
    public function enviar(string $telefone, string $texto): ResultadoEnvio
    {
        if (! $this->configurado()) {
            return ResultadoEnvio::falha($this->nome(), (string) $this->pendenciaDeConfiguracao());
        }

        $url = rtrim((string) config('whatsapp.evolution.url'), '/');
        $instancia = config('whatsapp.evolution.instance');

        try {
            $resposta = Http::withHeaders(['apikey' => config('whatsapp.evolution.key')])
                ->timeout(20)
                ->post("{$url}/message/sendText/{$instancia}", [
                    'number' => $telefone,
                    'text' => $texto,
                ]);
        } catch (Throwable $e) {
            return ResultadoEnvio::falha($this->nome(), 'Falha de conexão: '.$e->getMessage());
        }

        if ($resposta->successful()) {
            return ResultadoEnvio::sucesso($this->nome(), $resposta->json('key.id') ?? 'enviada');
        }

        return ResultadoEnvio::falha(
            $this->nome(),
            $resposta->json('message') ?? 'HTTP '.$resposta->status().': '.$resposta->body()
        );
    }

    public function nome(): string
    {
        return 'evolution';
    }

    public function configurado(): bool
    {
        return filled(config('whatsapp.evolution.url'))
            && filled(config('whatsapp.evolution.key'))
            && filled(config('whatsapp.evolution.instance'));
    }

    public function pendenciaDeConfiguracao(): ?string
    {
        if ($this->configurado()) {
            return null;
        }

        return 'Informe WHATSAPP_EVOLUTION_URL, WHATSAPP_EVOLUTION_KEY e WHATSAPP_EVOLUTION_INSTANCE no arquivo .env.';
    }

    public function requerAcaoManual(): bool
    {
        return false;
    }
}
