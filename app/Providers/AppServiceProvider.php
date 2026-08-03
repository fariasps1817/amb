<?php

namespace App\Providers;

use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Services\Whatsapp\Drivers\DriverCloudApi;
use App\Services\Whatsapp\Drivers\DriverDeLink;
use App\Services\Whatsapp\Drivers\DriverEvolution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // O driver de WhatsApp e escolhido por configuracao. O padrao ("link")
        // apenas gera as URLs wa.me para o operador enviar manualmente; os
        // demais entregam por API.
        $this->app->bind(DriverDeWhatsapp::class, function () {
            return match (config('whatsapp.driver', 'link')) {
                'cloud' => new DriverCloudApi,
                'evolution' => new DriverEvolution,
                default => new DriverDeLink,
            };
        });
    }

    public function boot(): void
    {
        // Datas por extenso em portugues nos documentos e nas telas
        // ("03 de agosto de 2026", "sáb", "AGOSTO/2026").
        Carbon::setLocale(config('app.locale', 'pt_BR'));
        Date::setLocale(config('app.locale', 'pt_BR'));

        // A coordenacao definiu que a senha pode ser simples, inclusive apenas
        // numerica. Mantemos somente um tamanho minimo.
        Password::defaults(fn () => Password::min(4));

        // Em desenvolvimento e nos testes, falha alto quando um atributo enviado
        // ao model nao esta em Fillable, em vez de descarta-lo em silencio —
        // esse descarte silencioso ja custou um campo de data que nunca gravava.
        //
        // Nao ativamos preventLazyLoading porque as views de documento percorrem
        // varias relacoes sob demanda de forma proposital.
        Model::preventSilentlyDiscardingAttributes(
            $this->app->isLocal() || $this->app->runningUnitTests()
        );
    }
}
