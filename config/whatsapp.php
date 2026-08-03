<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver de envio
    |--------------------------------------------------------------------------
    |
    | link      -> Apenas gera as URLs wa.me. O envio e feito manualmente pelo
    |              operador, clicando no botao de cada motorista. Sem custo e
    |              sem risco de bloqueio do numero.
    | cloud     -> WhatsApp Cloud API oficial da Meta.
    | evolution -> Evolution API (self-hosted).
    |
    */

    'driver' => env('WHATSAPP_DRIVER', 'link'),

    /*
    |--------------------------------------------------------------------------
    | Normalizacao de numeros
    |--------------------------------------------------------------------------
    |
    | Os telefones sao cadastrados como "98692 6853" ou "(85) 98692-6853".
    | Para montar o link wa.me precisamos do formato E.164 sem simbolos:
    | 5585986926853. Quando o numero cadastrado nao tiver DDD, usamos o
    | ddd_padrao abaixo.
    |
    */

    'ddi' => env('WHATSAPP_DDI', '55'),

    'ddd_padrao' => env('WHATSAPP_DDD_PADRAO', '85'),

    /*
    |--------------------------------------------------------------------------
    | Meta Cloud API
    |--------------------------------------------------------------------------
    */

    'cloud' => [
        'token' => env('WHATSAPP_CLOUD_TOKEN'),
        'phone_id' => env('WHATSAPP_CLOUD_PHONE_ID'),
        'version' => env('WHATSAPP_CLOUD_VERSION', 'v21.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evolution API
    |--------------------------------------------------------------------------
    */

    'evolution' => [
        'url' => env('WHATSAPP_EVOLUTION_URL'),
        'key' => env('WHATSAPP_EVOLUTION_KEY'),
        'instance' => env('WHATSAPP_EVOLUTION_INSTANCE'),
    ],

];
