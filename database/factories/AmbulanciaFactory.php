<?php

namespace Database\Factories;

use App\Enums\VinculoAmbulancia;
use App\Models\Ambulancia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ambulancia>
 */
class AmbulanciaFactory extends Factory
{
    protected $model = Ambulancia::class;

    public function definition(): array
    {
        $ano = $this->faker->numberBetween(2015, (int) now()->year);

        return [
            // Placa no padrao Mercosul: LLLNLNN.
            'placa' => mb_strtoupper($this->faker->unique()->bothify('???#?##')),
            'renavam' => $this->faker->numerify('###########'),
            'vinculo' => $this->faker->randomElement(VinculoAmbulancia::cases()),
            'marca' => $this->faker->randomElement(['Fiat', 'Renault', 'Mercedes-Benz', 'Peugeot']),
            'modelo' => $this->faker->randomElement(['Ducato', 'Master', 'Sprinter', 'Boxer']),
            'ano_fabricacao' => $ano,
            'ano_modelo' => $ano + 1,
            'tipo' => 'Básica',
            'ativo' => true,
        ];
    }

    public function propria(): static
    {
        return $this->state(fn () => ['vinculo' => VinculoAmbulancia::Propria]);
    }

    public function alugada(): static
    {
        return $this->state(fn () => ['vinculo' => VinculoAmbulancia::Alugada]);
    }

    public function inativa(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
