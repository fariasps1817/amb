<?php

namespace App\Enums;

/**
 * Desfecho de uma tentativa de entrada no sistema.
 *
 * Distinguir "usuario inexistente" de "senha incorreta" so vale no registro
 * interno: para quem esta na tela de login a mensagem e sempre a mesma, senao
 * o proprio sistema confirmaria quais nomes de usuario existem.
 */
enum MotivoDeAcesso: string
{
    case Sucesso = 'sucesso';
    case SenhaIncorreta = 'senha_incorreta';
    case UsuarioInexistente = 'usuario_inexistente';
    case UsuarioInativo = 'usuario_inativo';
    case ContaBloqueada = 'conta_bloqueada';
    case OrigemBloqueada = 'origem_bloqueada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Sucesso => 'Entrou',
            self::SenhaIncorreta => 'Senha incorreta',
            self::UsuarioInexistente => 'Usuário não existe',
            self::UsuarioInativo => 'Usuário desativado',
            self::ContaBloqueada => 'Bloqueado — conta',
            self::OrigemBloqueada => 'Bloqueado — origem',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Sucesso => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::SenhaIncorreta, self::UsuarioInativo => 'bg-amber-100 text-amber-800 ring-amber-200',
            self::UsuarioInexistente => 'bg-rose-100 text-rose-800 ring-rose-200',
            self::ContaBloqueada, self::OrigemBloqueada => 'bg-rose-200 text-rose-900 ring-rose-300',
        };
    }

    /** Tentativas que nao resultaram em entrada no sistema. */
    public function ehFalha(): bool
    {
        return $this !== self::Sucesso;
    }

    /** Explicacao para quem le a tela de monitoramento sem conhecer os termos. */
    public function explicacao(): string
    {
        return match ($this) {
            self::Sucesso => 'Entrada normal no sistema.',
            self::SenhaIncorreta => 'O usuário existe, mas a senha estava errada. Pode ser esquecimento.',
            self::UsuarioInexistente => 'Tentaram um nome de usuário que não existe. Vários seguidos indicam varredura automática.',
            self::UsuarioInativo => 'A senha estava certa, mas o usuário está desativado no cadastro.',
            self::ContaBloqueada => 'Esta conta já estava temporariamente bloqueada por excesso de erros.',
            self::OrigemBloqueada => 'Este endereço de rede já estava temporariamente bloqueado por excesso de erros.',
        };
    }
}
