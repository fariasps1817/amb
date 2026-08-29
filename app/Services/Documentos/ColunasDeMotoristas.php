<?php

namespace App\Services\Documentos;

/**
 * Colunas escolhidas para a relacao de motoristas em PDF.
 *
 * O coordenador monta a folha conforme a situacao: uma lista de contato pede
 * telefone, uma conferencia de habilitacao pede CNH, uma lista para assinar
 * pede um campo em branco. Em vez de um modulo de relatorios, a propria
 * listagem decide o que vai no documento.
 *
 * Esta classe guarda o catalogo e responde as tres perguntas que o layout faz:
 * quais colunas, com que largura, e se a folha cabe em pe.
 */
final class ColunasDeMotoristas
{
    /**
     * Sem o nome nao ha relacao nenhuma, entao esta coluna nao pode ser
     * desmarcada. A interface a mostra travada; aqui ela e imposta de novo,
     * porque a requisicao pode chegar sem ela.
     */
    public const OBRIGATORIA = 'servidor';

    /** @var list<string> */
    public const PADRAO = ['servidor', 'telefone', 'vinculo'];

    /**
     * Catalogo, na ordem em que as colunas saem impressas.
     *
     * "minimo" e a largura em milimetros que o conteudo mais longo daquela
     * coluna ocupa a 8pt. Nao e chute: a 8pt uma maiuscula da DejaVu Sans mede
     * cerca de 2mm, e os valores saem do pior caso de cada coluna mais o
     * espacamento da celula -- 35 caracteres no nome completo, 18 no
     * tratamento, "(85) 98692-6853" no telefone -- com alguma folga para o
     * cadastro crescer.
     *
     * E deles que saem a divisao do espaco, a decisao de virar a folha e a de
     * recuar a fonte. Errar para menos aqui nao aperta o texto: faz a celula
     * quebrar em duas linhas e a folha inteira perde o alinhamento.
     */
    private const CATALOGO = [
        'servidor' => ['rotulo' => 'Servidor', 'minimo' => 78, 'centro' => false],
        'nome_curto' => ['rotulo' => 'Tratamento', 'minimo' => 40, 'centro' => false],
        'telefone' => ['rotulo' => 'Telefone', 'minimo' => 30, 'centro' => true],
        'vinculo' => ['rotulo' => 'Vínculo', 'minimo' => 22, 'centro' => true],
        'cnh' => ['rotulo' => 'CNH (val)', 'minimo' => 22, 'centro' => true],
        'status' => ['rotulo' => 'Situação', 'minimo' => 17, 'centro' => true],
        'cpf' => ['rotulo' => 'CPF', 'minimo' => 28, 'centro' => true],
        'nascimento' => ['rotulo' => 'Data nasc.', 'minimo' => 22, 'centro' => true],
        'observacao' => ['rotulo' => 'Observação', 'minimo' => 35, 'centro' => false],
    ];

    /** Coluna do numero de ordem: sempre presente e sempre estreita. */
    private const NUMERO_MINIMO = 8;

    /**
     * Largura util da folha, ja descontadas as margens declaradas na view:
     * 210 - 20 em pe, 297 - 16 deitada.
     */
    private const UTIL_RETRATO = 190;

    private const UTIL_PAISAGEM = 281;

    /** Corpo da tabela, em pontos. O menor entra so quando o maior nao cabe. */
    private const FONTE_NORMAL = 8;

    private const FONTE_REDUZIDA = 7;

    /** @param  list<string>  $colunas  ja normalizadas */
    private function __construct(private readonly array $colunas) {}

    /**
     * @param  mixed  $pedidas  o que veio da requisicao, sem garantia nenhuma
     */
    public static function de(mixed $pedidas): self
    {
        $pedidas = is_array($pedidas) ? array_map('strval', array_filter($pedidas, 'is_scalar')) : [];

        // A ordem impressa e a do catalogo, e nao a ordem em que os campos
        // chegaram no formulario: a folha precisa sair sempre igual.
        $colunas = array_values(array_filter(
            array_keys(self::CATALOGO),
            fn (string $chave) => in_array($chave, $pedidas, true)
        ));

        if ($colunas === []) {
            return new self(self::PADRAO);
        }

        if (! in_array(self::OBRIGATORIA, $colunas, true)) {
            array_unshift($colunas, self::OBRIGATORIA);
        }

        return new self($colunas);
    }

    /** Rotulos para os checkboxes da tela. @return array<string, string> */
    public static function opcoes(): array
    {
        return array_map(fn (array $c) => $c['rotulo'], self::CATALOGO);
    }

    /** @return list<string> */
    public function chaves(): array
    {
        return $this->colunas;
    }

    public function tem(string $chave): bool
    {
        return in_array($chave, $this->colunas, true);
    }

    public function rotulo(string $chave): string
    {
        return self::CATALOGO[$chave]['rotulo'];
    }

    public function centralizada(string $chave): bool
    {
        return self::CATALOGO[$chave]['centro'];
    }

    /**
     * Em pe as colunas nao caberiam sem espremer o conteudo ate quebrar linha,
     * entao a folha vira sozinha. E preferivel uma folha deitada a uma folha
     * ilegivel.
     */
    public function paisagem(): bool
    {
        return $this->somaDosMinimos() > self::UTIL_RETRATO;
    }

    /**
     * Com o catalogo inteiro marcado, as colunas pedem cerca de 300mm e nem a
     * folha deitada da conta. Em vez de espremer e deixar quebrar linha, o
     * corpo recua um ponto -- a largura exigida encolhe junto, na mesma
     * proporcao, e tudo volta a caber.
     */
    public function fonte(): int
    {
        return $this->somaDosMinimos() > self::UTIL_PAISAGEM
            ? self::FONTE_REDUZIDA
            : self::FONTE_NORMAL;
    }

    /** Cabecalho, sempre um ponto abaixo do corpo. */
    public function fonteDoCabecalho(): float
    {
        return $this->fonte() - 1;
    }

    /**
     * Largura exigida depois do recuo de fonte, em mm. Serve para conferir que
     * a escolha de fonte de fato resolveu o aperto.
     */
    public function larguraExigida(): float
    {
        return $this->somaDosMinimos() * $this->fonte() / self::FONTE_NORMAL;
    }

    public function larguraDaFolha(): int
    {
        return $this->paisagem() ? self::UTIL_PAISAGEM : self::UTIL_RETRATO;
    }

    public function orientacao(): string
    {
        return $this->paisagem() ? 'landscape' : 'portrait';
    }

    public function margem(): string
    {
        return $this->paisagem() ? '8mm 8mm 12mm 8mm' : '10mm 10mm 14mm 10mm';
    }

    /**
     * O campo em branco so serve se couber a caneta: com ele na folha, as
     * linhas ganham altura.
     */
    public function temCampoLivre(): bool
    {
        return $this->tem('observacao');
    }

    /**
     * Largura de cada coluna em porcentagem, incluindo a do numero.
     *
     * O numero fica com o que precisa e nada mais; a sobra da folha e repartida
     * entre as demais na proporcao do que cada uma pede. Assim a coluna do nome,
     * que e a mais faminta, e a que mais engorda quando ha poucas colunas.
     *
     * @return array<string, float>
     */
    public function larguras(): array
    {
        $util = $this->paisagem() ? self::UTIL_PAISAGEM : self::UTIL_RETRATO;

        $numero = self::NUMERO_MINIMO / $util * 100;
        $sobra = 100 - $numero;

        $pedido = array_sum(array_map(
            fn (string $chave) => self::CATALOGO[$chave]['minimo'],
            $this->colunas
        ));

        $larguras = ['numero' => round($numero, 2)];

        foreach ($this->colunas as $chave) {
            $larguras[$chave] = round(self::CATALOGO[$chave]['minimo'] / $pedido * $sobra, 2);
        }

        return $larguras;
    }

    private function somaDosMinimos(): int
    {
        return self::NUMERO_MINIMO + array_sum(array_map(
            fn (string $chave) => self::CATALOGO[$chave]['minimo'],
            $this->colunas
        ));
    }
}
