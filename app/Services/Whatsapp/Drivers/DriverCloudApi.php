<?php

namespace App\Services\Whatsapp\Drivers;

use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Services\Whatsapp\ResultadoEnvio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Cloud API oficial da Meta.
 *
 * Exige numero comercial verificado, token de acesso e o id do numero de
 * telefone. Mensagens fora da janela de 24 horas de conversa precisam de
 * template aprovado — que e o caso do aviso de escala, enviado sem que o
 * motorista tenha escrito antes.
 *
 * Para configurar, preencha no .env:
 *   WHATSAPP_DRIVER=cloud
 *   WHATSAPP_CLOUD_TOKEN=...
 *   WHATSAPP_CLOUD_PHONE_ID=...
 */
class DriverCloudApi implements DriverDeWhatsapp
{
    public function enviar(string $telefone, string $texto): ResultadoEnvio
    {
        if (! $this->configurado()) {
            return ResultadoEnvio::falha($this->nome(), (string) $this->pendenciaDeConfiguracao());
        }

        $versao = config('whatsapp.cloud.version', 'v21.0');
        $phoneId = config('whatsapp.cloud.phone_id');

        try {
            $resposta = Http::withToken(config('whatsapp.cloud.token'))
                ->timeout(20)
                // Repete apenas falhas transitorias — conexao e erro de
                // servidor. Um 400 ("número não existe no WhatsApp") nao melhora
                // com nova tentativa, e queremos entregar essa mensagem ao
                // operador em vez de transforma-la em erro de conexao.
                ->retry(
                    times: 2,
                    sleepMilliseconds: 500,
                    when: fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->post("https://graph.facebook.com/{$versao}/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $telefone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $texto,
                    ],
                ]);
        } catch (Throwable $e) {
            return ResultadoEnvio::falha($this->nome(), 'Falha de conexão: '.$e->getMessage());
        }

        if ($resposta->successful()) {
            $id = $resposta->json('messages.0.id');

            return ResultadoEnvio::sucesso($this->nome(), $id ? "Mensagem {$id}" : $resposta->body());
        }

        return ResultadoEnvio::falha(
            $this->nome(),
            $resposta->json('error.message') ?? 'HTTP '.$resposta->status().': '.$resposta->body()
        );
    }

    public function nome(): string
    {
        return 'cloud';
    }

    public function configurado(): bool
    {
        return filled(config('whatsapp.cloud.token')) && filled(config('whatsapp.cloud.phone_id'));
    }

    public function pendenciaDeConfiguracao(): ?string
    {
        if ($this->configurado()) {
            return null;
        }

        return 'Informe WHATSAPP_CLOUD_TOKEN e WHATSAPP_CLOUD_PHONE_ID no arquivo .env.';
    }

    public function requerAcaoManual(): bool
    {
        return false;
    }
}
