<?php

namespace Tests\Unit;

use App\Support\Regime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RegimeTest extends TestCase
{
    /**
     * A regra central do sistema: o regime determina quantos motoristas cada
     * ambulancia precisa para fechar o ciclo sem lacuna.
     */
    #[Test]
    #[DataProvider('regimes')]
    public function calcula_a_quantidade_de_motoristas_por_ambulancia(
        int $trabalho,
        int $descanso,
        int $motoristasEsperados,
        int $intervaloEsperado,
    ): void {
        $regime = new Regime($trabalho, $descanso);

        $this->assertSame($motoristasEsperados, $regime->motoristasNecessarios());
        $this->assertSame($intervaloEsperado, $regime->intervaloEmDias());
    }

    public static function regimes(): array
    {
        return [
            '24/72 exige quatro motoristas' => [24, 72, 4, 4],
            '24/48 exige tres motoristas' => [24, 48, 3, 3],
            '24/24 exige dois motoristas' => [24, 24, 2, 2],
            '24/96 exige cinco motoristas' => [24, 96, 5, 5],
            '24/120 exige seis motoristas' => [24, 120, 6, 6],
        ];
    }

    #[Test]
    public function le_a_notacao_usada_no_dia_a_dia(): void
    {
        $regime = Regime::daNotacao('24/72');

        $this->assertSame(24, $regime->horasTrabalho);
        $this->assertSame(72, $regime->horasDescanso);
        $this->assertSame('24/72', $regime->notacao());
    }

    #[Test]
    public function aceita_notacao_com_espacos(): void
    {
        $this->assertSame('24/48', Regime::daNotacao(' 24 / 48 ')->notacao());
    }

    #[Test]
    public function recusa_notacao_invalida(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Regime::daNotacao('vinte e quatro');
    }

    /**
     * Um regime que nao fecha ciclo inteiro geraria escala com sobreposicao ou
     * lacuna, por isso e rejeitado na origem.
     */
    #[Test]
    public function recusa_regime_que_nao_fecha_ciclo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Regime(24, 50);
    }

    #[Test]
    public function recusa_horas_de_trabalho_zeradas(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Regime(0, 72);
    }

    #[Test]
    public function descreve_o_regime_em_portugues(): void
    {
        $this->assertSame(
            '24/72 — 24h de plantão e 72h de descanso (4 motoristas por ambulância)',
            (new Regime(24, 72))->descricao()
        );
    }
}
