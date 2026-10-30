<?php

namespace App\Enums;

/**
 * Tipos de registro da auditoria, usados no filtro e no icone de cada linha.
 */
enum CategoriaDeAuditoria: string
{
    case Acesso = 'acesso';
    case Cadastro = 'cadastro';
    case Escala = 'escala';
    case Documento = 'documento';
    case Mensagem = 'mensagem';
    case Solicitacao = 'solicitacao';
    case Sistema = 'sistema';

    public function rotulo(): string
    {
        return match ($this) {
            self::Acesso => 'Entradas e saídas',
            self::Cadastro => 'Cadastros',
            self::Escala => 'Escalas',
            self::Documento => 'Documentos',
            self::Mensagem => 'Mensagens',
            self::Solicitacao => 'Solicitações',
            self::Sistema => 'Sistema',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Acesso => 'cadeado',
            self::Cadastro => 'motoristas',
            self::Escala => 'escalas',
            self::Documento => 'documentos',
            self::Mensagem => 'whatsapp',
            self::Solicitacao => 'sino',
            self::Sistema => 'configuracoes',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Acesso => 'bg-slate-100 text-slate-600',
            self::Cadastro => 'bg-sky-100 text-sky-700',
            self::Escala => 'bg-marca-100 text-marca-700',
            self::Documento => 'bg-violet-100 text-violet-700',
            self::Mensagem => 'bg-emerald-100 text-emerald-700',
            self::Solicitacao => 'bg-rose-100 text-rose-700',
            self::Sistema => 'bg-amber-100 text-amber-700',
        };
    }

    /** @return array<string, string> */
    public static function opcoes(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->rotulo()])->all();
    }
}
