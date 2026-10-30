<?php

namespace App\Services\Auditoria;

use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Support\Telefone;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Antes e depois dos campos de um model, ja no texto que o administrador vai ler:
 * "Ativo" no lugar de "ativo", "SEDE" no lugar de "unidade_id = 3".
 *
 * CPF e telefone vao completos, por decisao da coordenacao. A senha nunca: dela so
 * se registra que mudou.
 */
final class AlteracoesDoModelo
{
    /** Campos que nao dizem nada a quem le, ou que nao podem ser guardados. */
    private const IGNORADOS = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'ultimo_acesso_em', 'password', 'remember_token',
    ];

    private const ROTULOS = [
        'cpf' => 'CPF',
        'cep' => 'CEP',
        'cnpj' => 'CNPJ',
        'uf' => 'UF',
        'email' => 'E-mail',
        'nome' => 'Nome',
        'nome_completo' => 'Nome completo',
        'nome_curto' => 'Nome curto',
        'data_nascimento' => 'Data de nascimento',
        'vinculo' => 'Vínculo',
        'vinculo_inicio' => 'Início do vínculo',
        'vinculo_fim' => 'Fim do vínculo',
        'cnh_numero' => 'Número da CNH',
        'cnh_categoria' => 'Categoria da CNH',
        'cnh_validade' => 'Validade da CNH',
        'telefone_1' => 'Telefone',
        'telefone_2' => 'Outro telefone',
        'matricula' => 'Matrícula',
        'status' => 'Situação',
        'observacao' => 'Observação',
        'endereco' => 'Endereço',
        'horas_trabalho' => 'Horas de trabalho',
        'horas_descanso' => 'Horas de descanso',
        'renavam' => 'Renavam',
        'ano_fabricacao' => 'Ano de fabricação',
        'ano_modelo' => 'Ano do modelo',
        'identificacao' => 'Identificação',
        'unidade_id' => 'Unidade',
        'unidade_apoio_id' => 'Unidade de apoio',
        'ambulancia_id' => 'Ambulância',
        'motorista_id' => 'Motorista',
        'rotulo' => 'Nome na planilha',
        'data_inicio' => 'Início da operação',
        'data_fim' => 'Fim da operação',
        'continuar_rotacao' => 'Continua a fila do mês anterior',
        'periodo_inicio' => 'Início do período',
        'periodo_fim' => 'Fim do período',
        'plantoes_previstos' => 'Plantões previstos',
        'plantoes_ajustados' => 'Plantões ajustados',
        'publicada_em' => 'Publicada em',
        'observacoes_atendimento' => 'Observações do atendimento',
        'atendida_em' => 'Atendida em',
        'motivo_nao_atendimento' => 'Motivo',
        'registrada_por' => 'Registrada por',
        'registrada_em' => 'Registrada em',
        'responsavel_setor' => 'Responsável pelo setor',
        'cargo_responsavel' => 'Cargo do responsável',
        'rodape_documentos' => 'Rodapé dos documentos',
        'layout_planilha' => 'Layout da planilha',
    ];

    /** Campos que guardam o id de outro cadastro: registra-se o nome. */
    private const REFERENCIAS = [
        'unidade_id' => [Unidade::class, 'sigla'],
        'unidade_apoio_id' => [Unidade::class, 'sigla'],
        'motorista_id' => [Motorista::class, 'nome_curto'],
        'registrada_por' => [User::class, 'nome'],
    ];

    /**
     * O que acabou de mudar no ultimo save() do model.
     *
     * @return list<array{campo: string, de: ?string, para: ?string}>
     */
    public static function mudancas(Model $model): array
    {
        $mudancas = [];
        $anteriores = $model->getPrevious();

        foreach ($model->getChanges() as $campo => $novo) {
            if ($campo === 'password') {
                $mudancas[] = ['campo' => 'Senha', 'de' => null, 'para' => '(alterada)'];
            }

            if (self::registravel($model, $campo)) {
                $mudancas[] = self::linha($model, $campo, $anteriores[$campo] ?? null, $novo);
            }
        }

        return $mudancas;
    }

    /** @return list<array{campo: string, de: ?string, para: ?string}> */
    public static function criacao(Model $model): array
    {
        $linhas = [];

        foreach ($model->getAttributes() as $campo => $valor) {
            if ($valor !== null && self::registravel($model, $campo)) {
                $linhas[] = self::linha($model, $campo, null, $valor);
            }
        }

        return $linhas;
    }

    /** @return list<array{campo: string, de: ?string, para: ?string}> */
    public static function exclusao(Model $model): array
    {
        $linhas = [];

        foreach ($model->getAttributes() as $campo => $valor) {
            if ($valor !== null && self::registravel($model, $campo)) {
                $linhas[] = self::linha($model, $campo, $valor, null);
            }
        }

        return $linhas;
    }

    private static function registravel(Model $model, string $campo): bool
    {
        return ! in_array($campo, self::IGNORADOS, true) && ! in_array($campo, $model->getHidden(), true);
    }

    /** @return array{campo: string, de: ?string, para: ?string} */
    private static function linha(Model $model, string $campo, mixed $de, mixed $para): array
    {
        return [
            'campo' => self::ROTULOS[$campo] ?? Str::ucfirst(str_replace('_', ' ', $campo)),
            'de' => self::legivel($model, $campo, $de),
            'para' => self::legivel($model, $campo, $para),
        ];
    }

    /** Passa o valor bruto pelos casts do model, para sair como a tela mostraria. */
    private static function legivel(Model $model, string $campo, mixed $bruto): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        if (isset(self::REFERENCIAS[$campo])) {
            [$classe, $coluna] = self::REFERENCIAS[$campo];

            return $classe::query()->withoutGlobalScopes()->find($bruto)?->{$coluna} ?? "#{$bruto}";
        }

        if (str_starts_with($campo, 'telefone')) {
            return Telefone::formatar((string) $bruto);
        }

        $valor = (clone $model)->setRawAttributes([$campo => $bruto])->getAttribute($campo);

        return match (true) {
            $valor === null => null,
            $valor instanceof BackedEnum => method_exists($valor, 'rotulo') ? $valor->rotulo() : (string) $valor->value,
            $valor instanceof DateTimeInterface => $valor->format($valor->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i'),
            is_bool($valor) => $valor ? 'Sim' : 'Não',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE),
            default => (string) $valor,
        };
    }
}
