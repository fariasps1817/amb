<?php

namespace App\Services\Escalas;

/**
 * Retorno da geracao de plantoes de uma escala mensal.
 */
final class ResultadoGeracao
{
    /** @param array<int, Alerta> $alertas */
    public function __construct(
        public int $plantoesCriados = 0,
        public int $plantoesRemovidos = 0,
        public int $ajustesPreservados = 0,
        public int $diasDescobertos = 0,
        public array $alertas = [],
    ) {}

    public function adicionarAlerta(Alerta $alerta): void
    {
        $this->alertas[] = $alerta;
    }

    /** @return array<int, Alerta> */
    public function erros(): array
    {
        return array_values(array_filter($this->alertas, fn (Alerta $a) => $a->ehErro()));
    }

    public function temErros(): bool
    {
        return $this->erros() !== [];
    }

    /** Frase curta para a mensagem de sucesso da tela. */
    public function resumo(): string
    {
        $partes = ["{$this->plantoesCriados} plantões gerados"];

        if ($this->ajustesPreservados > 0) {
            $partes[] = "{$this->ajustesPreservados} ajuste(s) manual(is) preservado(s)";
        }

        if ($this->diasDescobertos > 0) {
            $partes[] = "{$this->diasDescobertos} dia(s) sem motorista";
        }

        return implode(' · ', $partes);
    }
}
