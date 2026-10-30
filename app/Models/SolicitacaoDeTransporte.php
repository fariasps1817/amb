<?php

namespace App\Models;

use App\Enums\Locomocao;
use App\Enums\MotivoNaoAtendimento;
use App\Enums\StatusSolicitacao;
use App\Enums\TipoSolicitacao;
use App\Support\Telefone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de transporte de paciente que chega de fora do sistema.
 *
 * Traz dados de saude: so Administrador e Operador veem (middleware "editor").
 */
#[Fillable([
    'protocolo', 'tipo', 'status', 'chave_envio', 'solicitada_em',
    'hospital_nome', 'hospital_cidade', 'hospital_bairro',
    'solicitante_nome', 'solicitante_funcao', 'solicitante_telefone', 'declaracao_profissional',
    'paciente_nome', 'paciente_idade', 'paciente_idade_unidade', 'acompanhante_nome',
    'contato_telefone_1', 'contato_telefone_2',
    'destino_endereco', 'destino_bairro', 'destino_referencia', 'destino_municipio',
    'locomocao', 'consciente', 'orientado', 'puerpera_com_rn',
    'necessita_oxigenio', 'necessita_aspirador', 'necessita_tecnico', 'observacoes_paciente',
    'possivel_duplicada_de', 'ip', 'navegador',
    'motorista_id', 'atendida_em', 'motivo_nao_atendimento', 'observacoes_atendimento',
    'registrada_por', 'registrada_em',
])]
class SolicitacaoDeTransporte extends Model
{
    /** O destino da alta e sempre aqui: o pedido nao escolhe o municipio. */
    public const MUNICIPIO_DESTINO = 'Cascavel';

    public const UF_DESTINO = 'CE';

    protected $table = 'solicitacoes_transporte';

    protected function casts(): array
    {
        return [
            'tipo' => TipoSolicitacao::class,
            'status' => StatusSolicitacao::class,
            'solicitada_em' => 'datetime',
            'declaracao_profissional' => 'boolean',
            'paciente_idade' => 'integer',
            'locomocao' => Locomocao::class,
            'consciente' => 'boolean',
            'orientado' => 'boolean',
            'puerpera_com_rn' => 'boolean',
            'necessita_oxigenio' => 'boolean',
            'necessita_aspirador' => 'boolean',
            'necessita_tecnico' => 'boolean',
            'atendida_em' => 'datetime',
            'motivo_nao_atendimento' => MotivoNaoAtendimento::class,
            'registrada_em' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    /** Inclui o excluido: o historico do atendimento nao pode perder o nome. */
    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class)->withTrashed();
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    public function duplicadaDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possivel_duplicada_de');
    }

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function aguardando(Builder $query): void
    {
        $query->where('status', StatusSolicitacao::Aguardando);
    }

    /** Busca por protocolo, paciente, hospital ou solicitante. */
    #[Scope]
    protected function busca(Builder $query, ?string $termo): void
    {
        $termo = trim((string) $termo);

        if ($termo === '') {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('protocolo', 'like', "%{$termo}%")
            ->orWhere('paciente_nome', 'like', "%{$termo}%")
            ->orWhere('hospital_nome', 'like', "%{$termo}%")
            ->orWhere('solicitante_nome', 'like', "%{$termo}%"));
    }

    // -----------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------

    public function estaAguardando(): bool
    {
        return $this->status === StatusSolicitacao::Aguardando;
    }

    public function foiAtendida(): bool
    {
        return $this->status === StatusSolicitacao::Atendida;
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    /** "72 anos", "1 ano", "3 meses", "1 mês". */
    public function idadeTexto(): string
    {
        $idade = $this->paciente_idade;

        return $this->paciente_idade_unidade === 'meses'
            ? $idade.($idade === 1 ? ' mês' : ' meses')
            : $idade.($idade === 1 ? ' ano' : ' anos');
    }

    /** "Rua A, 10 · Centro · Cascavel-CE". */
    public function destinoCompleto(): string
    {
        return collect([
            $this->destino_endereco,
            $this->destino_bairro,
            $this->destino_municipio.'-'.self::UF_DESTINO,
        ])->filter()->implode(' · ');
    }

    /**
     * O que a equipe precisa saber antes de sair com a ambulancia: locomocao e
     * tudo que foi marcado, em etiquetas curtas para a lista e os detalhes.
     *
     * @return list<string>
     */
    public function situacaoEmEtiquetas(): array
    {
        return array_values(array_filter([
            $this->locomocao?->rotulo(),
            $this->necessita_oxigenio ? 'Oxigênio' : null,
            $this->necessita_aspirador ? 'Aspirador' : null,
            $this->necessita_tecnico ? 'Técnico de enfermagem' : null,
            $this->puerpera_com_rn ? 'Puérpera com recém-nascido' : null,
            $this->consciente ? 'Consciente' : null,
            $this->orientado ? 'Orientado' : null,
        ]));
    }

    public function telefoneSolicitante(): string
    {
        return Telefone::formatar($this->solicitante_telefone);
    }
}
