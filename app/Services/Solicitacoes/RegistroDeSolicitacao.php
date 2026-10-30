<?php

namespace App\Services\Solicitacoes;

use App\Enums\StatusSolicitacao;
use App\Enums\TipoSolicitacao;
use App\Models\SolicitacaoDeTransporte;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unico ponto por onde um pedido de transporte entra no sistema.
 *
 * E aqui que o aviso ao grupo de WhatsApp da coordenacao sera ligado, quando a
 * API for definida -- depois de gravar e sem poder falhar o registro: o hospital
 * nao pode perder o pedido porque o WhatsApp caiu.
 */
class RegistroDeSolicitacao
{
    /** Janela em que o mesmo paciente e telefone sugerem um pedido repetido. */
    private const HORAS_DE_POSSIVEL_DUPLICIDADE = 6;

    /**
     * @param  array<string, mixed>  $dados  Campos ja validados do formulario.
     */
    public function registrar(array $dados, ?string $ip, ?string $navegador): SolicitacaoDeTransporte
    {
        $chave = $dados['chave_envio'] ?? null;
        $reenvio = $chave ? $this->reenvioDoMesmoEnvio($chave, $dados) : null;

        if ($reenvio !== null) {
            return $reenvio;
        }

        // A mesma chave com outro paciente: quem enviou voltou a pagina e
        // preencheu um novo pedido. E outro pedido, nao o reenvio do primeiro.
        if ($chave && SolicitacaoDeTransporte::query()->where('chave_envio', $chave)->exists()) {
            $chave = null;
        }

        try {
            return DB::transaction(function () use ($dados, $chave, $ip, $navegador) {
                $solicitacao = SolicitacaoDeTransporte::query()->create([
                    ...$dados,
                    'tipo' => TipoSolicitacao::AltaHospitalar,
                    'status' => StatusSolicitacao::Aguardando,
                    'chave_envio' => $chave,
                    // Do servidor, nunca do formulario: fuso de Fortaleza.
                    'solicitada_em' => now(),
                    'destino_municipio' => SolicitacaoDeTransporte::MUNICIPIO_DESTINO,
                    'declaracao_profissional' => true,
                    'possivel_duplicada_de' => $this->possivelDuplicada($dados)?->id,
                    'ip' => $ip,
                    'navegador' => $navegador !== null ? Str::limit($navegador, 250, '') : null,
                ]);

                $solicitacao->update([
                    'protocolo' => sprintf(
                        '%s-%d-%05d',
                        TipoSolicitacao::AltaHospitalar->sigla(),
                        $solicitacao->solicitada_em->year,
                        $solicitacao->id,
                    ),
                ]);

                return $solicitacao;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dois envios iguais ao mesmo tempo: o outro ja gravou.
            return $this->reenvioDoMesmoEnvio((string) $chave, $dados) ?? throw $e;
        }
    }

    /**
     * Toque duplo, ou internet que caiu antes da resposta e a pessoa enviou de
     * novo: devolve o pedido ja gravado, em vez de criar outro.
     *
     * @param  array<string, mixed>  $dados
     */
    private function reenvioDoMesmoEnvio(string $chave, array $dados): ?SolicitacaoDeTransporte
    {
        return SolicitacaoDeTransporte::query()
            ->where('chave_envio', $chave)
            ->where('paciente_nome', $dados['paciente_nome'])
            ->where('contato_telefone_1', $dados['contato_telefone_1'])
            ->first();
    }

    /**
     * Mesmo paciente e mesmo telefone em poucas horas. O pedido entra assim
     * mesmo -- pode ser uma correcao, ou outra pessoa do hospital --, mas a
     * coordenacao e avisada antes de mandar duas ambulancias.
     *
     * @param  array<string, mixed>  $dados
     */
    private function possivelDuplicada(array $dados): ?SolicitacaoDeTransporte
    {
        return SolicitacaoDeTransporte::query()
            ->where('solicitada_em', '>=', now()->subHours(self::HORAS_DE_POSSIVEL_DUPLICIDADE))
            ->where('paciente_nome', $dados['paciente_nome'])
            ->where('contato_telefone_1', $dados['contato_telefone_1'])
            ->orderBy('id')
            ->first();
    }
}
