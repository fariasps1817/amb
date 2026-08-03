<?php

namespace Database\Factories;

use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidade>
 */
class UnidadeFactory extends Factory
{
    protected $model = Unidade::class;

    public function definition(): array
    {
        $bairro = $this->faker->unique()->city();

        return [
            'nome' => 'Posto de Saúde '.$bairro,
            'sigla' => mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $bairro), 0, 10)).$this->faker->unique()->numberBetween(1, 999),
            'tipo' => 'Posto de Saúde',
            'endereco' => $this->faker->streetAddress(),
            'bairro' => $bairro,
            'cidade' => 'Cascavel',
            'uf' => 'CE',
            'responsavel' => $this->faker->name(),
            'telefone_1' => '9'.$this->faker->numerify('########'),
            'horas_trabalho' => 24,
            'horas_descanso' => 72,
            'ordem' => 0,
            'ativo' => true,
        ];
    }

    /** Unidade em regime 24/72 — quatro motoristas por ambulancia. */
    public function regime2472(): static
    {
        return $this->state(fn () => ['horas_trabalho' => 24, 'horas_descanso' => 72]);
    }

    /** Unidade em regime 24/48 — tres motoristas por ambulancia. */
    public function regime2448(): static
    {
        return $this->state(fn () => ['horas_trabalho' => 24, 'horas_descanso' => 48]);
    }

    public function inativa(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
