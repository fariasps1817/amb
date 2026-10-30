<?php

namespace App\Services\Auditoria;

use App\Enums\AcaoAuditada;
use App\Models\RegistroDeAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra quem nao saiu do sistema: fechou o navegador, ou o celular apagou com a
 * tela aberta. Sem pedido de saida, a unica pista e a sessao que venceu na tabela.
 *
 * Precisa rodar ANTES da limpeza noturna, que apaga essas linhas. Foi por isso que a
 * coleta por sorteio do Laravel foi desligada em config/session.php: ela as apagaria
 * durante o dia, sem deixar nada para ler aqui.
 */
class SessoesExpiradas
{
    /** @return int Quantas foram registradas. */
    public function registrar(): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $duracao = (int) config('session.lifetime') * 60;
        $registradas = 0;

        DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->where('last_activity', '<=', now()->timestamp - $duracao)
            ->orderBy('last_activity')
            ->get()
            ->each(function (object $sessao) use ($duracao, &$registradas) {
                $usuario = User::query()->find($sessao->user_id);
                $ultimaAtividade = Carbon::createFromTimestamp($sessao->last_activity, config('app.timezone'));

                RegistroDeAuditoria::query()->create([
                    'user_id' => $sessao->user_id,
                    'usuario_nome' => $usuario?->nome ?? "Usuário #{$sessao->user_id}",
                    'usuario_login' => $usuario?->usuario,
                    'categoria' => AcaoAuditada::SessaoExpirada->categoria(),
                    'acao' => AcaoAuditada::SessaoExpirada->value,
                    'descricao' => 'Sessão expirada sem sair do sistema. Última atividade em '.$ultimaAtividade->format('d/m/Y \à\s H:i'),
                    'ip' => $sessao->ip_address,
                    'navegador' => $sessao->user_agent ? mb_substr($sessao->user_agent, 0, 250) : null,
                    'sessao' => $this->identificador($sessao->payload),
                    // A hora em que ela de fato acabou, e nao a da limpeza da madrugada.
                    'created_at' => $ultimaAtividade->copy()->addSeconds($duracao),
                ]);

                $registradas++;
            });

        return $registradas;
    }

    /**
     * O identificador da auditoria guardado no conteudo da sessao, quando ja havia um.
     * A sessao e gravada em JSON ("serialization" em config/session.php).
     */
    private function identificador(?string $conteudo): ?string
    {
        $dados = json_decode((string) base64_decode((string) $conteudo), true);

        return is_array($dados) ? ($dados[Auditoria::CHAVE_DA_SESSAO] ?? null) : null;
    }
}
