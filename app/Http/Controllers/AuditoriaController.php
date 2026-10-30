<?php

namespace App\Http\Controllers;

use App\Enums\AcaoAuditada;
use App\Enums\CategoriaDeAuditoria;
use App\Models\Escala;
use App\Models\RegistroDeAuditoria;
use App\Services\Auditoria\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O que cada pessoa fez no sistema. Traz CPF e telefone completos no antes e
 * depois, e por isso e restrito ao administrador pelo middleware "admin" na rota.
 */
class AuditoriaController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);

        return view('auditoria.index', [
            'registros' => RegistroDeAuditoria::query()
                ->filtrado($filtros)
                ->maisRecentesPrimeiro()
                ->paginate(50)
                ->withQueryString(),
            'filtros' => $filtros,
            'pessoas' => $this->pessoas(),
            'categorias' => CategoriaDeAuditoria::opcoes(),
            'escalas' => Escala::query()
                ->maisRecentes()
                ->limit(24)
                ->get()
                ->mapWithKeys(fn (Escala $e) => [$e->id => $e->titulo()]),
            'meses' => RegistroDeAuditoria::MESES_DE_HISTORICO,
        ]);
    }

    /** Cada sessao em uma linha: entrada, saida e quantas acoes houve. */
    public function sessoes(Request $request): View
    {
        $filtros = $this->filtros($request);
        $encerramentos = collect(AcaoAuditada::ENCERRAMENTOS)->map(fn (AcaoAuditada $a) => "'{$a->value}'")->implode(',');
        $categoriaDeAcesso = CategoriaDeAuditoria::Acesso->value;

        $sessoes = RegistroDeAuditoria::query()
            ->whereNotNull('sessao')
            ->filtrado(['usuario' => $filtros['usuario'] ?? null, 'de' => $filtros['de'] ?? null, 'ate' => $filtros['ate'] ?? null])
            ->selectRaw("sessao, MIN(id) as primeiro_id, MIN(created_at) as inicio, MAX(created_at) as fim, SUM(categoria <> '{$categoriaDeAcesso}') as acoes, MAX(CASE WHEN acao IN ({$encerramentos}) THEN acao END) as encerramento")
            ->withCasts(['inicio' => 'datetime', 'fim' => 'datetime'])
            ->groupBy('sessao')
            ->orderByDesc('fim')
            ->paginate(30)
            ->withQueryString();

        return view('auditoria.sessoes', [
            'sessoes' => $sessoes,
            // Quem e de onde: da primeira linha da sessao.
            'primeiros' => RegistroDeAuditoria::query()->whereIn('id', $sessoes->pluck('primeiro_id'))->get()->keyBy('id'),
            'filtros' => $filtros,
            'pessoas' => $this->pessoas(),
        ]);
    }

    /** Tudo o que aconteceu em uma sessao, na ordem em que aconteceu. */
    public function sessao(string $sessao): View
    {
        $registros = RegistroDeAuditoria::query()
            ->where('sessao', $sessao)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        abort_if($registros->isEmpty(), 404);

        $encerramento = $registros->first(fn (RegistroDeAuditoria $r) => in_array(
            AcaoAuditada::tryFrom($r->acao),
            AcaoAuditada::ENCERRAMENTOS,
            true,
        ));

        return view('auditoria.sessao', [
            'registros' => $registros,
            'primeiro' => $registros->first(),
            'encerramento' => $encerramento ? AcaoAuditada::from($encerramento->acao)->rotuloDeEncerramento() : null,
        ]);
    }

    /**
     * Planilha com o recorte dos filtros, para abrir no Excel: separada por ponto e
     * virgula e com a marca de UTF-8, senao os acentos saem errados.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $filtros = $this->filtros($request);

        // A planilha sai do sistema com CPF e telefone: quem a tirou fica anotado.
        Auditoria::registrar(AcaoAuditada::AuditoriaExportada, 'Exportou o histórico da auditoria em planilha');

        return response()->streamDownload(function () use ($filtros) {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Data e hora', 'Pessoa', 'Usuário', 'Tipo', 'O que fez', 'Alterações', 'Endereço de rede', 'Dispositivo', 'Sessão'], ';', escape: '');

            RegistroDeAuditoria::query()
                ->filtrado($filtros)
                ->lazyById(500)
                ->each(fn (RegistroDeAuditoria $r) => fputcsv($saida, array_map($this->celula(...), [
                    $r->created_at?->format('d/m/Y H:i:s'),
                    $r->autor(),
                    $r->usuario_login,
                    $r->categoria->rotulo(),
                    $r->descricao,
                    $this->alteracoesEmTexto($r),
                    $r->ip,
                    $r->dispositivo(),
                    $r->sessao,
                ]), ';', escape: ''));

            fclose($saida);
        }, 'auditoria-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'usuario' => ['nullable', 'integer'],
            'categoria' => ['nullable', Rule::enum(CategoriaDeAuditoria::class)],
            'escala' => ['nullable', 'integer'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'busca' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * Quem ja apareceu na auditoria, inclusive usuarios ja excluidos.
     *
     * @return array<int, string>
     */
    private function pessoas(): array
    {
        return RegistroDeAuditoria::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, MAX(usuario_nome) as nome')
            ->groupBy('user_id')
            ->orderBy('nome')
            ->pluck('nome', 'user_id')
            ->all();
    }

    private function alteracoesEmTexto(RegistroDeAuditoria $registro): string
    {
        return collect($registro->alteracoes ?? [])
            ->map(fn (array $a) => $a['campo'].': '.(filled($a['de']) ? $a['de'].' → ' : '').($a['para'] ?? '(vazio)'))
            ->implode(' | ');
    }

    /**
     * Texto que comeca com estes sinais seria lido pelo Excel como formula, e parte
     * do que vai na planilha vem da pagina aberta, que qualquer um preenche.
     */
    private function celula(?string $valor): ?string
    {
        return $valor !== null && preg_match('/^[=+\-@\t\r]/', $valor) ? "'{$valor}" : $valor;
    }
}
