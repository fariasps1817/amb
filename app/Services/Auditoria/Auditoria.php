<?php

namespace App\Services\Auditoria;

use App\Enums\AcaoAuditada;
use App\Models\Escala;
use App\Models\RegistroDeAuditoria;
use App\Services\Escalas\ResultadoGeracao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Por onde tudo entra na auditoria.
 *
 * O registro e chamado na camada que atende a pessoa (controllers e telas), e nao
 * nos services: la se sabe o que ela quis fazer. "Preencher as vagas" e uma linha na
 * auditoria, mesmo sendo vinte lotacoes no service.
 *
 * Uma falha aqui nunca impede a acao de quem esta trabalhando: vai para o log.
 */
final class Auditoria
{
    /** Chave, na sessao de quem esta logado, do identificador usado na auditoria. */
    public const CHAVE_DA_SESSAO = 'auditoria_sessao';

    /**
     * @param  list<array{campo: string, de: ?string, para: ?string}>  $alteracoes
     * @param  int|null  $naoRepetirPor  Segundos em que a mesma acao nao gera nova linha:
     *                                   o leitor de PDF do navegador pede o arquivo mais de uma vez.
     */
    public static function registrar(
        AcaoAuditada $acao,
        string $descricao,
        ?Model $alvo = null,
        ?Escala $escala = null,
        array $alteracoes = [],
        ?int $naoRepetirPor = null,
    ): void {
        try {
            $usuario = auth()->user();
            $requisicao = request();

            if ($escala !== null) {
                $descricao .= ' — '.$escala->titulo();
            }

            $descricao = Str::limit($descricao, 497);

            if ($naoRepetirPor !== null && self::acabouDeAcontecer($acao, $descricao, $usuario?->getAuthIdentifier(), $naoRepetirPor)) {
                return;
            }

            RegistroDeAuditoria::query()->create([
                'user_id' => $usuario?->getAuthIdentifier(),
                'usuario_nome' => $usuario?->nome,
                'usuario_login' => $usuario?->usuario,
                'categoria' => $acao->categoria(),
                'acao' => $acao->value,
                'descricao' => $descricao,
                'alvo_tipo' => $alvo !== null ? Str::snake(class_basename($alvo)) : null,
                'alvo_id' => $alvo?->getKey(),
                'escala_id' => $escala?->getKey(),
                'alteracoes' => $alteracoes !== [] ? $alteracoes : null,
                'ip' => $requisicao->ip(),
                'navegador' => Str::limit((string) $requisicao->userAgent(), 250, '') ?: null,
                'sessao' => $usuario !== null ? self::identificadorDaSessao() : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Cadastro novo, com tudo o que foi preenchido. */
    public static function criacao(AcaoAuditada $acao, Model $model, string $descricao, ?Escala $escala = null): void
    {
        self::registrar($acao, $descricao, $model, $escala, AlteracoesDoModelo::criacao($model));
    }

    /**
     * Antes e depois do ultimo save() do model. Salvar sem mudar nada nao e uma acao:
     * nao gera linha.
     */
    public static function alteracao(AcaoAuditada $acao, Model $model, string $descricao, ?Escala $escala = null): void
    {
        $mudancas = AlteracoesDoModelo::mudancas($model);

        if ($mudancas !== []) {
            self::registrar($acao, $descricao, $model, $escala, $mudancas);
        }
    }

    /** Guarda o que o cadastro tinha: depois de excluido, so resta aqui. */
    public static function exclusao(AcaoAuditada $acao, Model $model, string $descricao): void
    {
        self::registrar($acao, $descricao, $model, null, AlteracoesDoModelo::exclusao($model));
    }

    /**
     * Mesmo com erro em um posto os demais foram gerados, por isso olha o que mudou
     * e nao o erro. Vem de dois lugares: o botao da escala e o da montagem.
     */
    public static function geracaoDePlantoes(Escala $escala, ResultadoGeracao $resultado, bool $descartouAjustes = false): void
    {
        if ($resultado->plantoesCriados === 0 && $resultado->plantoesRemovidos === 0) {
            return;
        }

        self::registrar(
            AcaoAuditada::EscalaGerada,
            'Gerou os plantões'.($descartouAjustes ? ', descartando as trocas manuais' : '').' ('.$resultado->resumo().')',
            $escala,
            $escala,
        );
    }

    /**
     * Detalhes que nao sao antes e depois de um campo. O que vier vazio fica de fora.
     *
     * @param  array<string, ?string>  $campos  Rotulo => valor.
     * @return list<array{campo: string, de: ?string, para: ?string}>
     */
    public static function detalhes(array $campos): array
    {
        $linhas = [];

        foreach ($campos as $campo => $valor) {
            if (filled($valor)) {
                $linhas[] = ['campo' => $campo, 'de' => null, 'para' => $valor];
            }
        }

        return $linhas;
    }

    private static function acabouDeAcontecer(AcaoAuditada $acao, string $descricao, mixed $usuario, int $segundos): bool
    {
        return RegistroDeAuditoria::query()
            ->where('user_id', $usuario)
            ->where('acao', $acao->value)
            ->where('descricao', $descricao)
            ->where('created_at', '>=', now()->subSeconds($segundos))
            ->exists();
    }

    /**
     * Identifica a sessao na linha do tempo. Nasce na primeira acao, e nao no login,
     * para cobrir tambem quem volta pela sessao mantida neste aparelho.
     */
    private static function identificadorDaSessao(): ?string
    {
        $requisicao = request();

        if (! $requisicao->hasSession()) {
            return null;
        }

        $sessao = $requisicao->session();

        if (! $sessao->has(self::CHAVE_DA_SESSAO)) {
            $sessao->put(self::CHAVE_DA_SESSAO, (string) Str::uuid());
        }

        return $sessao->get(self::CHAVE_DA_SESSAO);
    }
}
