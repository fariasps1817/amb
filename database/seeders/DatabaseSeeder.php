<?php

namespace Database\Seeders;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->criarAdministrador();
        $this->criarIdentidadeInstitucional();
    }

    /**
     * Usuario inicial para o primeiro acesso.
     *
     * A senha e simples de proposito, conforme definido pela coordenacao, e deve
     * ser trocada apos o primeiro uso.
     */
    private function criarAdministrador(): void
    {
        $usuario = User::query()->firstOrCreate(
            ['usuario' => 'admin'],
            [
                'nome' => 'Administrador',
                'password' => Hash::make('1234'),
                'perfil' => 'admin',
                'ativo' => true,
            ]
        );

        if ($usuario->wasRecentlyCreated) {
            $this->command?->newLine();
            $this->command?->info('Acesso inicial criado — usuário: admin · senha: 1234');
            $this->command?->warn('Troque a senha no primeiro acesso.');
        }
    }

    private function criarIdentidadeInstitucional(): void
    {
        $configuracao = Configuracao::atual();

        if (filled($configuracao->secretaria)) {
            return;
        }

        $configuracao->update([
            'secretaria' => 'Secretaria Municipal de Saúde',
            'setor' => 'Coordenação de Ambulâncias',
            'uf' => 'CE',
        ]);
    }
}
