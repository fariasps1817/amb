<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Regime de plantao: horas de trabalho seguidas de horas de descanso.
 *
 * O setor opera em plantoes de 24 horas. O descanso e o que muda de unidade
 * para unidade: 72 horas na maioria (24/72) e 48 horas em algumas (24/48).
 *
 * Consequencia direta do regime e a quantidade de motoristas necessarios por
 * ambulancia, pois o ciclo tem que fechar sem lacuna e sem sobreposicao:
 *
 *   24/72 -> ciclo de 4 dias -> 4 motoristas (1 trabalha, 3 descansam)
 *   24/48 -> ciclo de 3 dias -> 3 motoristas (1 trabalha, 2 descansam)
 *   24/24 -> ciclo de 2 dias -> 2 motoristas
 *
 * Formula: motoristas = (trabalho + descanso) / trabalho
 */
final readonly class Regime
{
    public function __construct(
        public int $horasTrabalho,
        public int $horasDescanso,
    ) {
        if ($horasTrabalho <= 0) {
            throw new InvalidArgumentException('As horas de trabalho devem ser maiores que zero.');
        }

        if ($horasDescanso < 0) {
            throw new InvalidArgumentException('As horas de descanso não podem ser negativas.');
        }

        if (($horasTrabalho + $horasDescanso) % $horasTrabalho !== 0) {
            throw new InvalidArgumentException(
                "O regime {$horasTrabalho}/{$horasDescanso} não fecha um ciclo inteiro de plantões."
            );
        }
    }

    public static function de(int $horasTrabalho, int $horasDescanso): self
    {
        return new self($horasTrabalho, $horasDescanso);
    }

    /**
     * Constroi o regime a partir da notacao usada no dia a dia ("24/72").
     */
    public static function daNotacao(string $notacao): self
    {
        if (! preg_match('/^\s*(\d{1,3})\s*\/\s*(\d{1,3})\s*$/', $notacao, $m)) {
            throw new InvalidArgumentException("Regime inválido: \"{$notacao}\". Use o formato 24/72.");
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    /** Notacao curta impressa nos documentos: "24/72". */
    public function notacao(): string
    {
        return "{$this->horasTrabalho}/{$this->horasDescanso}";
    }

    /** Quantos motoristas a ambulancia precisa para cobrir o ciclo sem lacuna. */
    public function motoristasNecessarios(): int
    {
        return intdiv($this->horasTrabalho + $this->horasDescanso, $this->horasTrabalho);
    }

    /** Dias corridos entre dois plantoes do mesmo motorista. */
    public function intervaloEmDias(): int
    {
        return (int) ceil(($this->horasTrabalho + $this->horasDescanso) / 24);
    }

    /** Total de horas de um ciclo completo. */
    public function horasCiclo(): int
    {
        return $this->horasTrabalho + $this->horasDescanso;
    }

    public function descricao(): string
    {
        $n = $this->motoristasNecessarios();

        return sprintf(
            '%s — %dh de plantão e %dh de descanso (%d motorista%s por ambulância)',
            $this->notacao(),
            $this->horasTrabalho,
            $this->horasDescanso,
            $n,
            $n === 1 ? '' : 's'
        );
    }

    public function igual(self $outro): bool
    {
        return $this->horasTrabalho === $outro->horasTrabalho
            && $this->horasDescanso === $outro->horasDescanso;
    }

    /**
     * Regimes oferecidos no formulario de unidades.
     *
     * Somente regimes de plantao de 24 horas: o sistema escala um motorista por
     * ambulancia por dia. Regimes de meio turno (12/36) exigiriam dois plantoes
     * no mesmo dia e nao sao usados pelo setor.
     */
    public static function predefinidos(): array
    {
        return [
            '24/72' => '24/72 — 4 motoristas por ambulância',
            '24/48' => '24/48 — 3 motoristas por ambulância',
            '24/96' => '24/96 — 5 motoristas por ambulância',
            '24/120' => '24/120 — 6 motoristas por ambulância',
            '24/24' => '24/24 — 2 motoristas por ambulância',
        ];
    }
}
