<?php

namespace App\Services\Escalas;

/**
 * Aviso produzido pela analise ou validacao de uma escala.
 *
 * Severidade "erro" impede a publicacao; "atencao" e "informacao" apenas
 * orientam o coordenador.
 */
final readonly class Alerta
{
    public const ERRO = 'erro';

    public const ATENCAO = 'atencao';

    public const INFORMACAO = 'informacao';

    public function __construct(
        public string $severidade,
        public string $codigo,
        public string $mensagem,
        /** Dados auxiliares para a tela montar links (ids de posto, motorista...). */
        public array $contexto = [],
    ) {}

    public static function erro(string $codigo, string $mensagem, array $contexto = []): self
    {
        return new self(self::ERRO, $codigo, $mensagem, $contexto);
    }

    public static function atencao(string $codigo, string $mensagem, array $contexto = []): self
    {
        return new self(self::ATENCAO, $codigo, $mensagem, $contexto);
    }

    public static function informacao(string $codigo, string $mensagem, array $contexto = []): self
    {
        return new self(self::INFORMACAO, $codigo, $mensagem, $contexto);
    }

    public function ehErro(): bool
    {
        return $this->severidade === self::ERRO;
    }

    public function rotuloSeveridade(): string
    {
        return match ($this->severidade) {
            self::ERRO => 'Erro',
            self::ATENCAO => 'Atenção',
            default => 'Informação',
        };
    }

    public function icone(): string
    {
        return match ($this->severidade) {
            self::ERRO => 'x-circle',
            self::ATENCAO => 'exclamation-triangle',
            default => 'information-circle',
        };
    }

    public function classes(): string
    {
        return match ($this->severidade) {
            self::ERRO => 'border-rose-200 bg-rose-50 text-rose-800',
            self::ATENCAO => 'border-amber-200 bg-amber-50 text-amber-800',
            default => 'border-sky-200 bg-sky-50 text-sky-800',
        };
    }
}
