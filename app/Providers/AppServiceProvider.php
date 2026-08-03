<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

        // Em desenvolvimento, falha alto quando um atributo enviado ao model
        // nao esta em Fillable, em vez de descarta-lo em silencio. Nao ativamos
        // preventLazyLoading porque as views de PDF percorrem varias relacoes
        // sob demanda de forma proposital.
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());
    }
}
