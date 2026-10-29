<?php

namespace App\Services\Escalas;

use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Comparacao das escalas de um mes: as propostas em rascunho, as descartadas
 * e, quando ja houver, a publicada.
 *
 * Quem escolhe entre as propostas e a Secretaria de Saude, nao o coordenador.
 * A conversa gira em torno de tres perguntas, e a comparacao responde as tres
 * lado a lado:
 *
 *   "Quanto custa?"            -> vagas a contratar, ambulancias a definir.
 *   "Ja da para publicar?"     -> pendencias que impedem a publicacao.
 *   "O que muda de uma a outra?" -> ambulancias e motoristas que diferem.
 *
 * Nas diferencas so entra o que de fato difere: com dezenas de motoristas,
 * uma lista completa esconderia as tres ou quatro mudancas que motivaram a
 * proposta.
 */
final class ComparadorDePropostas
{
    /** Numeros de cada proposta, na ordem em que aparecem na tela. */
    public const INDICADORES = [
        'postos' => 'Ambulâncias',
        'vagas' => 'Vagas preenchidas',
        'a_contratar' => 'Vagas a contratar',
        'a_definir' => 'Ambulâncias a definir',
        'escalados' => 'Motoristas escalados',
        'reserva' => 'Reserva / apoio',
        'sem_definicao' => 'Sem definição',
        'plantoes' => 'Plantões gerados',
    ];

    /** Situacao, na proposta, da ambulancia que ficou de fora dela. */
    public const AMBULANCIA_AUSENTE = 'Não entra';

    /** @var Collection<int, array>|null */
    private ?Collection $quadros = null;

    /** @param  Collection<int, Escala>  $escalas */
    private function __construct(private readonly Collection $escalas) {}

    /** Todas as escalas do mes da escala informada, na ordem de criacao. */
    public static function doMesDe(Escala $escala): self
    {
        $escalas = $escala->doMesmoMes();

        // Tudo o que o analisador e o validador percorrem, de uma vez: sem
        // isso, cada proposta repetiria as consultas posto a posto.
        $escalas->load([
            'postos.unidade',
            'postos.ambulancia',
            'postos.lotacoes',
            'postos.plantoes',
            'lotacoes.motorista',
            'lotacoes.posto',
        ]);

        return new self($escalas);
    }

    /** @return Collection<int, Escala> */
    public function escalas(): Collection
    {
        return $this->escalas;
    }

    /** "Proposta B", ou "Escala do mês" quando ela e a unica e nao tem nome. */
    public static function nome(Escala $escala): string
    {
        return $escala->nome ?: 'Escala do mês';
    }

    // -----------------------------------------------------------------
    // Numeros e pendencias de cada proposta
    // -----------------------------------------------------------------

    /**
     * Um quadro por escala do mes.
     *
     * @return Collection<int, array{
     *     escala: Escala,
     *     nome: string,
     *     numeros: array<string, int|string>,
     *     erros: list<string>
     * }>
     */
    public function quadros(): Collection
    {
        return $this->quadros ??= $this->escalas->map(function (Escala $escala) {
            $resumo = AnalisadorDeEfetivo::para($escala)->resumo();

            // So o que impede a publicacao: os avisos (CNH vencendo, sem
            // reserva...) nao pesam na escolha entre uma proposta e outra.
            $erros = collect(ValidadorDeEscala::para($escala)->validar())
                ->filter(fn (Alerta $alerta) => $alerta->ehErro())
                ->map(fn (Alerta $alerta) => $alerta->mensagem)
                ->values()
                ->all();

            return [
                'escala' => $escala,
                'nome' => self::nome($escala),
                'numeros' => [
                    'postos' => $resumo['postos'],
                    'vagas' => $resumo['vagas_ocupadas'].'/'.$resumo['vagas_necessarias'],
                    'a_contratar' => $resumo['vagas_livres'],
                    'a_definir' => $escala->postos->whereNull('ambulancia_id')->count(),
                    'escalados' => $resumo['vagas_ocupadas'],
                    'reserva' => $resumo['disponiveis'],
                    'sem_definicao' => $resumo['sem_definicao'],
                    'plantoes' => $resumo['plantoes'],
                ],
                'erros' => $erros,
            ];
        });
    }

    /**
     * Indicadores cujo valor muda de uma proposta para outra -- a tela os
     * destaca, para o olho ir direto ao que pesa na decisao.
     *
     * @return list<string>
     */
    public function indicadoresQueDiferem(): array
    {
        if ($this->escalas->count() < 2) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::INDICADORES),
            fn (string $chave) => $this->quadros()->pluck("numeros.{$chave}")->unique()->count() > 1
        ));
    }

    // -----------------------------------------------------------------
    // Diferencas entre as propostas
    // -----------------------------------------------------------------

    /**
     * Ambulancias que nao estao em todas as propostas, ou que estao em todas
     * mas com outra lotacao ou outro regime (o remanejamento de uma ambulancia
     * de uma unidade para outra tambem e uma diferenca).
     *
     * O posto e reconhecido pela ambulancia; sem ambulancia definida, pela
     * unidade e pelo rotulo -- e a SEDE 4 "a definir" da proposta que pede um
     * veiculo a mais.
     *
     * @return Collection<int, array{rotulo: string, situacoes: list<array{nome: string, valor: string, ausente: bool}>}>
     */
    public function diferencasDeAmbulancias(): Collection
    {
        $linhas = [];

        foreach ($this->escalas as $escala) {
            // Dois postos sem ambulancia com o mesmo rotulo na mesma unidade
            // sao duas ambulancias a definir, e nao uma so.
            $repeticoes = [];

            foreach ($escala->postos as $posto) {
                $chave = $this->chaveDoPosto($posto);
                $repeticoes[$chave] = ($repeticoes[$chave] ?? 0) + 1;
                $chave .= '#'.$repeticoes[$chave];

                $linhas[$chave]['rotulo'] ??= $this->rotuloDoPosto($posto);
                $linhas[$chave]['valores'][$escala->id] = $posto->rotuloLotacao().' ('.$posto->regimeNotacao().')';
            }
        }

        return $this->soOQueDifere($linhas, fn () => self::AMBULANCIA_AUSENTE);
    }

    /**
     * Motoristas cuja situacao muda entre as propostas: o posto em que estao
     * ("SEDE 1"), o destino ("Sobreaviso / Reserva", "Férias") ou nenhum.
     *
     * A posicao no ciclo nao conta: trocar a ordem dentro da mesma ambulancia
     * muda os dias, mas nao e o que a Secretaria decide.
     *
     * @return Collection<int, array{rotulo: string, situacoes: list<array{nome: string, valor: string, ausente: bool}>}>
     */
    public function diferencasDeMotoristas(): Collection
    {
        $linhas = [];

        foreach ($this->escalas as $escala) {
            foreach ($escala->lotacoes as $lotacao) {
                if ($lotacao->motorista === null) {
                    continue;
                }

                $linhas[$lotacao->motorista_id]['motorista'] ??= $lotacao->motorista;
                $linhas[$lotacao->motorista_id]['valores'][$escala->id] = $this->situacaoDoMotorista($lotacao);
            }
        }

        // Em ordem alfabetica, ignorando acentos, como nos documentos.
        uasort($linhas, fn (array $a, array $b) => $this->chaveAlfabetica($a['motorista'])
            <=> $this->chaveAlfabetica($b['motorista']));

        foreach ($linhas as &$linha) {
            $linha['rotulo'] = $linha['motorista']->nome_curto;
        }
        unset($linha);

        // Fora da folha de uma proposta: para quem esta ativo, e o mesmo que
        // estar la sem destino -- o analisador conta como sem definicao.
        return $this->soOQueDifere(
            $linhas,
            fn (array $linha) => $linha['motorista']->ativo() ? 'Sem definição' : '—'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Completa cada linha com o valor de todas as escalas, na ordem delas, e
     * descarta as linhas em que todas dizem o mesmo.
     *
     * @param  array<string|int, array{rotulo: string, valores: array<int, string>}>  $linhas
     * @param  callable(array): string  $ausente  Valor para a escala que nao tem a linha.
     */
    private function soOQueDifere(array $linhas, callable $ausente): Collection
    {
        return collect($linhas)
            ->map(function (array $linha) use ($ausente) {
                $situacoes = $this->escalas->map(fn (Escala $escala) => [
                    'nome' => self::nome($escala),
                    'valor' => $linha['valores'][$escala->id] ?? $ausente($linha),
                    'ausente' => ! isset($linha['valores'][$escala->id]),
                ])->values()->all();

                return ['rotulo' => $linha['rotulo'], 'situacoes' => $situacoes];
            })
            ->filter(fn (array $linha) => count(array_unique(array_column($linha['situacoes'], 'valor'))) > 1)
            ->values();
    }

    private function chaveDoPosto(EscalaPosto $posto): string
    {
        return $posto->ambulancia_id !== null
            ? 'ambulancia:'.$posto->ambulancia_id
            : 'unidade:'.$posto->unidade_id.':'.$posto->rotuloLotacao();
    }

    /** "THQ4H34 · SEDE 1", ou "SEDE 4 · ambulância a definir". */
    private function rotuloDoPosto(EscalaPosto $posto): string
    {
        if ($posto->ambulancia_id === null) {
            return $posto->rotuloLotacao().' · ambulância a definir';
        }

        $ambulancia = $posto->ambulancia;

        if ($ambulancia === null) {
            return 'Ambulância #'.$posto->ambulancia_id;
        }

        return $ambulancia->placaFormatada()
            .($ambulancia->identificacao ? ' · '.mb_strtoupper($ambulancia->identificacao) : '');
    }

    private function situacaoDoMotorista(EscalaLotacao $lotacao): string
    {
        if ($lotacao->escalado()) {
            return $lotacao->posto?->rotuloLotacao() ?? 'Escalado';
        }

        if ($lotacao->tipo_destino !== null) {
            return $lotacao->tipo_destino->rotulo();
        }

        return $lotacao->motorista->ativo() ? 'Sem definição' : '—';
    }

    private function chaveAlfabetica(Motorista $motorista): string
    {
        return Str::transliterate(mb_strtoupper($motorista->nome_completo));
    }
}
