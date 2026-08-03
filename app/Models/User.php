<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['nome', 'usuario', 'email', 'password', 'perfil', 'ativo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const PERFIS = [
        'admin' => 'Administrador',
        'operador' => 'Operador',
        'leitor' => 'Consulta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'ativo' => 'boolean',
            'ultimo_acesso_em' => 'datetime',
        ];
    }

    public function perfilRotulo(): string
    {
        return self::PERFIS[$this->perfil] ?? (string) $this->perfil;
    }

    public function ehAdmin(): bool
    {
        return $this->perfil === 'admin';
    }

    /** O perfil de consulta apenas visualiza e imprime; nao altera dados. */
    public function podeEditar(): bool
    {
        return in_array($this->perfil, ['admin', 'operador'], true);
    }

    /** Primeiro nome, usado na saudacao do topo da tela. */
    public function primeiroNome(): string
    {
        $partes = preg_split('/\s+/', trim((string) $this->nome)) ?: [];

        return $partes[0] ?? (string) $this->nome;
    }

    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim((string) $this->nome)) ?: [];
        $partes = array_values(array_filter($partes, fn ($p) => $p !== ''));

        if ($partes === []) {
            return '?';
        }

        $primeira = mb_substr($partes[0], 0, 1);
        $ultima = count($partes) > 1 ? mb_substr((string) end($partes), 0, 1) : '';

        return mb_strtoupper($primeira.$ultima);
    }
}
