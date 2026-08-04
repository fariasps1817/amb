# Coordenação de Ambulâncias

Sistema de gestão do setor de coordenação de ambulâncias de uma secretaria municipal de saúde:
cadastro de motoristas, unidades e frota, montagem das escalas mensais de plantão, emissão dos
documentos oficiais e comunicação da escala aos motoristas pelo WhatsApp.

---

## Como o sistema pensa a escala

Toda a lógica gira em torno de uma regra: **o regime de plantão da unidade determina quantos
motoristas cada ambulância precisa.**

| Regime | Plantão | Descanso | Motoristas por ambulância | Volta a trabalhar |
|--------|---------|----------|---------------------------|-------------------|
| 24/72  | 24h     | 72h      | **4**                     | a cada 4 dias     |
| 24/48  | 24h     | 48h      | **3**                     | a cada 3 dias     |
| 24/96  | 24h     | 96h      | **5**                     | a cada 5 dias     |

Os motoristas ocupam as posições 1..N de uma fila que gira um dia para cada um:

```
UPA Centro · ambulância HUH1020 · regime 24/72

dia 01 → André     dia 05 → André      dia 09 → André
dia 02 → Paulo     dia 06 → Paulo      ...
dia 03 → Ricardo   dia 07 → Ricardo
dia 04 → Luiz      dia 08 → Luiz
```

### Rotação contínua entre meses

Reiniciar a fila no dia 1º de todo mês quebraria o descanso na virada, porque os meses não têm
múltiplos de 3 ou 4 dias. Por isso a fila **retoma de onde parou**:

```
AGOSTO/2026 (31 dias)      SETEMBRO/2026
... 29 → André             01 → Luiz      ← retoma na posição 4
    30 → Paulo             02 → André
    31 → Ricardo           03 → Paulo
                           04 → Ricardo   ← 72h após o plantão de 31/08
```

Cada ambulância pode ser configurada para reiniciar no dia 1º, quando for o caso.

---

## Requisitos

| Item     | Versão mínima | Observação                                 |
|----------|---------------|--------------------------------------------|
| PHP      | 8.3           | com `pdo_mysql`                            |
| MySQL    | 8.0           | ou MariaDB 10.6+                           |
| Composer | 2.x           |                                            |
| Node.js  | 20            | apenas para compilar CSS/JS                |

Tudo isso já vem no Laragon.

## Instalação

```bash
git clone https://github.com/fariasps1817/amb.git
cd amb

composer install
cp .env.example .env
php artisan key:generate

# crie o banco (ajuste usuário e senha se necessário)
mysql -uroot -e "CREATE DATABASE amb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate
php artisan db:seed          # cria o usuário inicial
php artisan storage:link     # necessário para as logos aparecerem

npm install
npm run build
```

Acesso inicial: usuário **admin**, senha **1234** — troque no primeiro acesso.

### No Laragon

O Laragon detecta a pasta `public/` e cria o vhost automaticamente. Basta **reiniciar o Laragon**
(menu → Recarregar) e o sistema responde em `http://amb.test`.

Sem Laragon, use `php artisan serve`.

### Dados de demonstração

Para ver o sistema em funcionamento com a estrutura completa de um setor real — 9 unidades,
11 ambulâncias, 49 motoristas e a escala do mês corrente já montada:

```bash
php artisan migrate:fresh
php artisan db:seed --class=DemonstracaoSeeder
```

---

## Como usar

### 1. Cadastros iniciais

**Unidades** — cadastre a UPA, os postos de saúde e a sede. O campo **regime de plantão** é o mais
importante: a tela mostra ao vivo quantos motoristas cada ambulância daquela unidade vai exigir.
O campo *ordem* define a sequência dos blocos na planilha impressa.

**Ambulâncias** — placa (padrão antigo ou Mercosul), RENAVAM, vínculo (própria ou alugada) e anos.
A unidade informada aqui é a lotação **padrão**: ao montar cada mês você pode remanejar o veículo.
Quando uma unidade tem mais de uma ambulância, use o campo *identificação* (`SEDE 1`, `SEDE 2`) —
é ele que aparece na coluna de lotação dos documentos.

**Motoristas** — nome completo e nome curto (o curto é o que cabe na planilha). Contrato temporário
exige data de término, para o sistema avisar quando o motorista deixa de poder ser escalado.
Telefone é obrigatório para motoristas ativos, pois é por ele que a escala é comunicada.

**Identidade institucional** — brasão, logos, endereço, telefones e slogan. É o que aparece no
cabeçalho e no rodapé de todos os PDFs.

### 2. Montagem da escala do mês

1. **Escalas → Nova escala.** A tela mostra, antes de começar, se o efetivo cobre a frota.
   Marque *repetir a estrutura do mês anterior* para copiar ambulâncias, regimes e equipes.
2. **Montagem.** Cada ambulância abre as vagas que o regime exige. Encaixe um motorista em cada
   posição; as setas reordenam, mudando o dia em que cada um pega plantão.
3. **Destinos e ocorrências.** Quem não está em uma ambulância recebe uma situação:
   sobreaviso/reserva, apoio em carro extra, férias, licença ou atestado. O sistema não deixa
   publicar com motorista sem destino.
   É nessa tela que também se registra a **ocorrência de quem está escalado** — uma falta, um
   atestado, uma troca de plantão: o botão *Registrar ocorrência* na linha do motorista. O texto vai
   para a coluna OCORRÊNCIA da lista mensal e o motorista continua escalado.
   No mesmo painel dá para **ajustar a quantidade de plantões**: quem tinha 8 previstos e faltou a um
   passa a constar com 7. O número da escala continua guardado como base, e o ajuste sobrevive a uma
   nova geração de plantões — apagar a ocorrência devolve a contagem automática.
4. **Gerar plantões.** A fila é aplicada dia a dia.
5. **Publicar.** Antes de liberar, o sistema verifica descanso insuficiente (inclusive na virada do
   mês), motorista em duas ambulâncias no mesmo dia, CNH vencida, contrato encerrado e dias sem
   cobertura.

Trocas pontuais feitas à mão são marcadas como ajuste manual e **sobrevivem** a uma nova geração.

### 3. Documentos

| Documento | Formato | Conteúdo |
|-----------|---------|----------|
| Planilha de plantões | A4 paisagem | Calendário com um X no dia de plantão de cada motorista, mais uma página final com os condutores fora de escala |
| Lista mensal de ocorrências | A4 retrato | Todo o efetivo em ordem alfabética, com lotação, vínculo, plantões previstos e observações |
| Folhas de frequência | A4 retrato | Uma folha por motorista, com espaço para assinatura nos dias de plantão |

A planilha traz, na última página, a **relação dos condutores fora de escala** agrupada por situação
(sobreaviso, apoio, férias, licença), com o fechamento do efetivo — é o que o RH espera receber junto
da escala. Para enviar só a grade às unidades, use `?fora_de_escala=0` ou o botão na tela de
documentos.

### 4. Comunicação aos motoristas

Em **Mensagens**, o sistema monta um texto por motorista listando os dias de plantão, a unidade, a
ambulância e o horário. Você confere e envia. Fica registrado quem enviou, quando e por qual meio.

---

## WhatsApp: modos de envio

Configurado em `.env` pela variável `WHATSAPP_DRIVER`.

### `link` — padrão, envio manual

Gera a URL `wa.me` com o texto pronto. Você clica, o WhatsApp abre com a mensagem preenchida e você
aperta enviar. **Sem custo, sem número comercial e sem risco de bloqueio** por envio automatizado.

### `cloud` — WhatsApp Cloud API (Meta)

Envio automático em lote pela API oficial. Exige número comercial verificado e, para mensagens fora
da janela de 24 horas — que é o caso do aviso de escala —, **template aprovado** pela Meta.

```env
WHATSAPP_DRIVER=cloud
WHATSAPP_CLOUD_TOKEN=
WHATSAPP_CLOUD_PHONE_ID=
```

### `evolution` — Evolution API

Servidor próprio que conecta um número comum por QR Code. Não exige número comercial, mas usa uma
conexão não oficial: **envios em volume podem levar ao bloqueio do número**.

```env
WHATSAPP_DRIVER=evolution
WHATSAPP_EVOLUTION_URL=
WHATSAPP_EVOLUTION_KEY=
WHATSAPP_EVOLUTION_INSTANCE=
```

Os números são cadastrados livremente (`98692 6853`, `(85) 98692-6853`) e convertidos para E.164.
Quando não há DDD, o sistema aplica o `WHATSAPP_DDD_PADRAO`.

---

## Perfis de acesso

| Perfil | Pode |
|--------|------|
| Administrador | tudo, incluindo gerenciar usuários |
| Operador | cadastrar, montar escalas, emitir documentos e enviar mensagens |
| Consulta | apenas visualizar e imprimir |

A senha pode ser simples, inclusive só números (mínimo de 4 caracteres), conforme definido pela
coordenação. As tentativas de login são limitadas por usuário e IP.

---

## Arquitetura

```
app/
├── Enums/                    Vínculo, status, tipo de destino, status da escala
├── Support/
│   ├── Regime.php            Deriva nº de motoristas a partir do regime de plantão
│   └── Telefone.php          Formatação BR e conversão para E.164
├── Services/
│   ├── Escalas/
│   │   ├── MontadorDeEscala.php      Cria o mês, copia do anterior, lota motoristas
│   │   ├── GeradorDeEscala.php       Gira a fila e materializa os plantões
│   │   ├── AnalisadorDeEfetivo.php   Dimensiona o efetivo, aponta falta e sobra
│   │   └── ValidadorDeEscala.php     Descanso, aptidão e cobertura antes de publicar
│   ├── Documentos/           Montagem dos dados e emissão dos três PDFs
│   └── Whatsapp/             Montagem das mensagens e drivers de entrega
├── Livewire/                 Telas interativas de montagem e destinos
├── Models/
└── Http/
```

### Decisões que valem explicar

**Plantões são materializados, não calculados na hora.** Gravar cada plantão permite trocas pontuais
entre motoristas, serve de âncora para a rotação do mês seguinte e deixa documentos e mensagens com
leitura direta.

**O regime é congelado no posto.** Cada ambulância guarda o regime vigente no mês. Alterar o cadastro
de uma unidade não reescreve escalas passadas.

**Cadastros com histórico são inativados, não excluídos.** Apagar um motorista que consta em escalas
antigas removeria as lotações por cascata e desfiguraria documentos já emitidos.

**Uma tabela para toda a folha de lotação do mês.** `escala_lotacoes` tem uma linha por motorista
ativo, com posto (escalado) ou tipo de destino (reserva, férias...). É isso que garante que a lista
mensal de ocorrências contemple 100% do efetivo.

---

## Desenvolvimento

```bash
composer dev        # servidor, fila, logs e Vite juntos
php artisan test    # 150 testes
./vendor/bin/pint   # formatação
```

Os testes rodam em um banco MySQL separado, porque o PHP do Laragon não traz `pdo_sqlite`:

```bash
mysql -uroot -e "CREATE DATABASE amb_testes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

A cobertura se concentra no que quebra silenciosamente: a rotação atravessando três meses seguidos
sem violar o descanso, o dimensionamento por regime, as validações que barram a publicação e a
montagem dos três documentos.

---

## Limitações conhecidas

- **Regimes de meio turno (12/36) não são suportados.** O sistema escala um motorista por ambulância
  por dia; dois turnos no mesmo dia exigiriam mudança no modelo.
- **Nas colunas de placa e lotação da planilha o texto vai na horizontal**, não girado 90° como no
  documento antigo — o dompdf não implementa rotação de texto. As colunas foram alargadas para
  compensar, e há o layout *agrupado* como alternativa (Identidade institucional → Layout da
  planilha).
- **As larguras de coluna da planilha estão calibradas para o papel A4 paisagem.** O dompdf ignora
  `<colgroup>` e larguras em classes CSS quando a tabela tem muitas colunas: elas precisam ir em
  `style="width"` na primeira linha, com células separadas. Mexer nisso pode fazer as colunas
  colapsarem umas sobre as outras.
- **O envio automático em lote depende de API contratada.** No modo padrão o envio é manual, um
  motorista por vez.

---

## Stack

Laravel 13 · MySQL 8 · Livewire 3 · Tailwind CSS 4 · dompdf ·
timezone `America/Fortaleza` · interface e documentos em pt-BR.
