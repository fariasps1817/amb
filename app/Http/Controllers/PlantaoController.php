<?php

namespace App\Http\Controllers;

use App\Services\Whatsapp\PlantaoDoDia;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * Plantao de um dia: quem esta em cada ambulancia e o texto pronto para
 * avisar as unidades pelo WhatsApp.
 */
class PlantaoController extends Controller
{
    public function __invoke(Request $request, PlantaoDoDia $plantao): View
    {
        $dia = $this->dia($request->string('dia')->toString());
        $comSobreaviso = $request->boolean('sobreaviso');

        return view('plantao.index', [
            'dia' => $dia,
            'escala' => $plantao->escala($dia),
            'plantoes' => $plantao->plantoes($dia),
            'semPlantonista' => $plantao->semPlantonista($dia),
            'sobreaviso' => $plantao->sobreaviso($dia),
            'comSobreaviso' => $comSobreaviso,
            'texto' => $plantao->texto($dia, $comSobreaviso),
        ]);
    }

    /** Dia pedido no endereco; hoje quando ausente ou invalido. */
    private function dia(string $valor): Carbon
    {
        if ($valor !== '') {
            try {
                return Carbon::createFromFormat('!Y-m-d', $valor);
            } catch (Throwable) {
                // Data digitada errada no endereco: mostra hoje.
            }
        }

        return today();
    }
}
