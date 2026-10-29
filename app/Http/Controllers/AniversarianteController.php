<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\Motorista;
use App\Support\Aniversario;
use App\Support\Telefone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * Aniversariantes do efetivo, de hoje ou de outro periodo.
 *
 * A lista de motoristas ja filtra aniversariantes, mas em formato de cadastro.
 * Esta tela e a do dia a dia no celular: quem faz anos, quantos completa, e os
 * botoes para ligar ou mandar os parabens.
 */
class AniversarianteController extends Controller
{
    public function __invoke(Request $request): View
    {
        $dia = $this->dia($request->string('dia')->toString());

        $periodo = $request->string('periodo')->toString();
        $periodo = Aniversario::valido($periodo) ? $periodo : Aniversario::HOJE;

        $consulta = Motorista::query()->ativos();

        $dia !== null
            ? Aniversario::aplicarDia($consulta, $dia)
            : Aniversario::aplicar($consulta, $periodo);

        $setor = Configuracao::atual()->setor ?: 'Coordenação de Ambulâncias';

        $aniversariantes = $consulta->get()
            ->map(function (Motorista $motorista) use ($periodo, $dia, $setor) {
                $data = Aniversario::dataNoFiltro($motorista, $periodo, $dia);

                return [
                    'motorista' => $motorista,
                    'data' => $data,
                    'idade' => $data->year - $motorista->data_nascimento->year,
                    'parabens' => Telefone::linkWhatsapp(
                        $motorista->telefone_1,
                        "Feliz aniversário, {$motorista->nome_curto}! 🎂\n\n"
                            ."A {$setor} deseja a você muita saúde, paz e alegria. Parabéns!"
                    ),
                ];
            })
            ->sortBy(fn (array $a) => $a['data']->format('Ymd').'|'.$a['motorista']->nome_completo)
            ->values();

        // Lotacao no mes corrente, para identificar de qual ambulancia e a pessoa.
        $escala = Escala::query()->doMes(today()->year, today()->month)->with('lotacoes.posto.unidade')->first();

        return view('aniversariantes.index', [
            'aniversariantes' => $aniversariantes,
            'periodo' => $dia !== null ? null : $periodo,
            'dia' => $dia,
            'descricao' => $dia !== null
                ? 'Aniversariantes de '.$dia->format('d/m')
                : Aniversario::descricao($periodo),
            'lotacoes' => $escala?->lotacoes->keyBy('motorista_id') ?? collect(),
        ]);
    }

    private function dia(string $valor): ?Carbon
    {
        if ($valor === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $valor);
        } catch (Throwable) {
            return null;
        }
    }
}
