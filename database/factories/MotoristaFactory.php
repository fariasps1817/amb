<?php

namespace Database\Factories;

use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Models\Motorista;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Motorista>
 */
class MotoristaFactory extends Factory
{
    protected $model = Motorista::class;

    public function definition(): array
    {
        $nome = mb_strtoupper($this->faker->name('male'));
        $partes = explode(' ', $nome);

        return [
            'nome_completo' => $nome,
            'nome_curto' => mb_substr(implode(' ', array_slice($partes, 0, 2)), 0, 60),
            'cpf' => $this->faker->unique()->numerify('###########'),
            'data_nascimento' => $this->faker->dateTimeBetween('-60 years', '-25 years'),
            'vinculo' => $this->faker->randomElement(Vinculo::cases()),
            'vinculo_inicio' => $this->faker->dateTimeBetween('-6 years', '-1 year'),
            'vinculo_fim' => null,
            'cnh_numero' => $this->faker->numerify('###########'),
            'cnh_categoria' => $this->faker->randomElement(['D', 'AD', 'E', 'AE']),
            'cnh_validade' => $this->faker->dateTimeBetween('+6 months', '+4 years'),
            'telefone_1' => '9'.$this->faker->numerify('########'),
            'telefone_2' => null,
            'status' => StatusMotorista::Ativo,
        ];
    }

    public function efetivo(): static
    {
        return $this->state(fn () => [
            'vinculo' => Vinculo::Efetivo,
            'vinculo_fim' => null,
        ]);
    }

    public function contratado(?string $fim = null): static
    {
        return $this->state(fn () => [
            'vinculo' => Vinculo::Contrato,
            'vinculo_inicio' => now()->startOfYear(),
            'vinculo_fim' => $fim ?? now()->endOfYear()->toDateString(),
        ]);
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['status' => StatusMotorista::Inativo]);
    }

    public function cnhVencida(): static
    {
        return $this->state(fn () => ['cnh_validade' => now()->subMonth()->toDateString()]);
    }
}
