<?php

namespace App\Services\Escalas;

use App\Enums\StatusMotorista;
use App\Enums\TipoDestino;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\Motorista;
use Illuminate\Support\Collection;

/**
 * Dimensionamento do efetivo de uma escala mensal.
 *
 * Responde as duas perguntas que o coordenador faz ao montar o mes:
 *
 *   "Tenho motorista suficiente para cobrir todas as ambulancias?"
 *   "Sobrou gente: quem vai para reserva, ferias ou licenca?"
 *
 * A conta de necessidade vem do regime de cada posto: um posto 24/72 exige 4
 * motoristas, um 24/48 exige 3.
 */
class AnalisadorDeEfetivo
{
    public function __construct(protected Escala $escala) {}

    public static function para(Escala $escala): self
    {
        return new self($escala);
    }

    // -----------------------------------------------------------------
    // Contagens
    // -----------------------------------------------------------------

    /** Total de motoristas com cadastro ativo na base. */
    public function totalAtivos(): int
    {
        return Motorista::query()->where('status', StatusMotorista::Ativo)->count();
    }

    /** Soma das vagas de todos os postos do mes (o que o mes exige). */
    public function vagasNecessarias(): int
    {
        return $this->escala->postos->sum(fn ($posto) => $posto->vagas());
    }

    public function vagasOcupadas(): int
    {
        return $this->lotacoes()->filter(fn (EscalaLotacao $l) => $l->escalado())->count();
    }

    public function vagasLivres(): int
    {
        return max(0, $this->vagasNecessarias() - $this->vagasOcupadas());
    }

    /** Motoristas com destino administrativo definido (reserva, ferias...). */
    public function totalComDestino(): int
    {
        return $this->lotacoes()
            ->filter(fn (EscalaLotacao $l) => ! $l->escalado() && $l->tipo_destino !== null)
            ->count();
    }

    /** Quantos permanecem a disposicao (reserva ou apoio). */
    public function totalDisponiveis(): int
    {
        return $this->lotacoes()->filter(fn (EscalaLotacao $l) => $l->disponivel())->count();
    }

    /**
     * Motoristas ativos que ainda nao foram escalados nem receberam destino.
     * Estes sao os que o requisito de fechamento do mes exige resolver.
     *
     * @return Collection<int, Motorista>
     */
    public function motoristasSemDefinicao(): Collection
    {
        $definidos = $this->lotacoes()
            ->filter(fn (EscalaLotacao $l) => $l->definido())
            ->pluck('motorista_id')
            ->all();

        return Motorista::query()
            ->where('status', StatusMotorista::Ativo)
            ->whereNotIn('id', $definidos ?: [0])
            ->ordenadoPorNome()
            ->get();
    }

    /**
     * Motoristas ativos que ainda podem ser lotados em um posto: nao estao
     * escalados e nao estao em ferias, licenca ou atestado.
     *
     * @return Collection<int, Motorista>
     */
    public function motoristasDisponiveisParaLotar(): Collection
    {
        $indisponiveis = $this->lotacoes()
            ->filter(fn (EscalaLotacao $l) => $l->escalado()
                || in_array($l->tipo_destino, [
                    TipoDestino::Ferias,
                    TipoDestino::Licenca,
                    TipoDestino::Atestado,
                    TipoDestino::Cedido,
                ], true))
            ->pluck('motorista_id')
            ->all();

        return Motorista::query()
            ->where('status', StatusMotorista::Ativo)
            ->whereNotIn('id', $indisponiveis ?: [0])
            ->ordenadoPorNome()
            ->get();
    }

    /**
     * Saldo do efetivo: positivo indica sobra de motoristas, negativo indica
     * falta para fechar todos os postos.
     */
    public function saldo(): int
    {
        return $this->totalAtivos() - $this->vagasNecessarias();
    }

    // -----------------------------------------------------------------
    // Alertas
    // -----------------------------------------------------------------

    /**
     * @return array<int, Alerta>
     */
    public function alertas(): array
    {
        $alertas = [];

        $necessarias = $this->vagasNecessarias();
        $ativos = $this->totalAtivos();
        $saldo = $ativos - $necessarias;

        if ($this->escala->postos->isEmpty()) {
            $alertas[] = Alerta::atencao(
                'sem_postos',
                'Nenhuma ambulância foi incluída nesta escala. Adicione os postos para começar a montagem.'
            );

            return $alertas;
        }

        // 1. Efetivo insuficiente para o mes.
        if ($saldo < 0) {
            $faltam = abs($saldo);
            $alertas[] = Alerta::erro(
                'efetivo_insuficiente',
                sprintf(
                    'Faltam %d motorista(s) para atender os regimes de plantão: as %d ambulâncias do mês exigem %d motoristas e a base tem apenas %d ativos.',
                    $faltam,
                    $this->escala->postos->count(),
                    $necessarias,
                    $ativos,
                ),
                ['faltam' => $faltam]
            );
        }

        // 2. Postos ainda incompletos.
        $incompletos = $this->escala->postos->filter(fn ($p) => $p->vagasLivres() > 0);

        if ($incompletos->isNotEmpty()) {
            foreach ($incompletos as $posto) {
                $alertas[] = Alerta::erro(
                    'posto_incompleto',
                    sprintf(
                        '%s (%s): faltam %d de %d motoristas.',
                        $posto->descricao(),
                        $posto->regimeNotacao(),
                        $posto->vagasLivres(),
                        $posto->vagas(),
                    ),
                    ['posto_id' => $posto->id]
                );
            }
        }

        // 3. Motoristas ativos sem destino definido.
        $semDefinicao = $this->motoristasSemDefinicao();

        if ($semDefinicao->isNotEmpty()) {
            $alertas[] = Alerta::erro(
                'sem_definicao',
                sprintf(
                    '%d motorista(s) ativo(s) ainda sem destino: %s. Defina se estão escalados, de reserva/sobreaviso, férias ou licença.',
                    $semDefinicao->count(),
                    $semDefinicao->take(6)->map(fn (Motorista $m) => $m->nome_curto)->implode(', ')
                        .($semDefinicao->count() > 6 ? ' e outros' : ''),
                ),
                ['motoristas' => $semDefinicao->pluck('id')->all()]
            );
        }

        // 4. Sobra de efetivo: informa quantos podem ir para reserva.
        if ($saldo > 0 && $semDefinicao->isEmpty() && $incompletos->isEmpty()) {
            $alertas[] = Alerta::informacao(
                'sobra_efetivo',
                sprintf(
                    '%d motorista(s) além do necessário para os postos — %d em reserva/sobreaviso ou apoio e %d em férias, licença ou afastamento.',
                    $saldo,
                    $this->totalDisponiveis(),
                    max(0, $this->totalComDestino() - $this->totalDisponiveis()),
                )
            );
        }

        // 5. Nenhuma reserva: qualquer falta no mes fica sem cobertura.
        if ($this->totalDisponiveis() === 0 && $this->vagasOcupadas() > 0) {
            $alertas[] = Alerta::atencao(
                'sem_reserva',
                'Nenhum motorista em reserva/sobreaviso. Faltas e atestados no mês ficarão sem cobertura imediata.'
            );
        }

        return $alertas;
    }

    /**
     * Numeros do painel de resumo da escala.
     *
     * @return array<string, int>
     */
    public function resumo(): array
    {
        return [
            'ativos' => $this->totalAtivos(),
            'postos' => $this->escala->postos->count(),
            'vagas_necessarias' => $this->vagasNecessarias(),
            'vagas_ocupadas' => $this->vagasOcupadas(),
            'vagas_livres' => $this->vagasLivres(),
            'com_destino' => $this->totalComDestino(),
            'disponiveis' => $this->totalDisponiveis(),
            'sem_definicao' => $this->motoristasSemDefinicao()->count(),
            'saldo' => $this->saldo(),
            'plantoes' => $this->escala->plantoes()->count(),
        ];
    }

    /**
     * Distribuicao dos motoristas por tipo de destino, para o painel.
     *
     * @return array<string, int>
     */
    public function porDestino(): array
    {
        $contagem = [];

        foreach (TipoDestino::cases() as $tipo) {
            $total = $this->lotacoes()
                ->filter(fn (EscalaLotacao $l) => ! $l->escalado() && $l->tipo_destino === $tipo)
                ->count();

            if ($total > 0) {
                $contagem[$tipo->value] = $total;
            }
        }

        return $contagem;
    }

    /** @return Collection<int, EscalaLotacao> */
    protected function lotacoes(): Collection
    {
        if (! $this->escala->relationLoaded('lotacoes')) {
            $this->escala->load('lotacoes');
        }

        return $this->escala->lotacoes;
    }
}
