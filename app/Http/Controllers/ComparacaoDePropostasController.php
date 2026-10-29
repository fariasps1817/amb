<?php

namespace App\Http\Controllers;

use App\Models\Escala;
use App\Services\Escalas\ComparadorDePropostas;
use Illuminate\View\View;

/**
 * Propostas de escala do mes, lado a lado.
 *
 * E a tela que o coordenador abre na frente da Secretaria de Saude, quase
 * sempre no celular: os numeros de cada alternativa, o que ainda impede cada
 * uma de ser publicada e o que muda de uma para a outra.
 *
 * Apenas consulta -- a escolha se faz publicando a proposta, na tela dela --
 * e por isso aberta a qualquer usuario, inclusive o perfil de consulta.
 */
class ComparacaoDePropostasController extends Controller
{
    public function __invoke(Escala $escala): View
    {
        $comparador = ComparadorDePropostas::doMesDe($escala);
        $escalas = $comparador->escalas();

        return view('escalas.comparar', [
            'escala' => $escala,
            'quadros' => $comparador->quadros(),
            'diferentes' => $comparador->indicadoresQueDiferem(),
            'ambulancias' => $comparador->diferencasDeAmbulancias(),
            'motoristas' => $comparador->diferencasDeMotoristas(),
            'oficial' => $escalas->first(fn (Escala $e) => $e->oficial()),
            'semDecisao' => Escala::propostasSemDecisao($escala->ano, $escala->mes),
        ]);
    }
}
