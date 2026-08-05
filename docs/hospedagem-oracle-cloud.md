# Colocando o sistema no ar — Oracle Cloud Always Free

Guia passo a passo para hospedar o sistema em um servidor gratuito e permanente,
acessível de qualquer lugar por HTTPS.

Feito para quem nunca administrou um servidor Linux. Cada etapa explica **o que
está sendo feito e por quê**, não apenas o que clicar.

---

## Antes de começar: o mapa do caminho

```
ETAPA 1  Criar a conta na Oracle Cloud            você, no navegador     ~15 min
ETAPA 2  Criar a rede (VCN) pelo assistente       você, no navegador      ~5 min
ETAPA 3  Liberar as portas 80 e 443               você, no navegador      ~5 min
ETAPA 2b Criar a máquina virtual (Instance)       você, no navegador     ~10 min
ETAPA 4  Primeiro acesso por SSH                  nós dois, no terminal   ~5 min
ETAPA 5  Instalar Nginx, PHP e MySQL              eu, por SSH            ~15 min
ETAPA 6  Endereço na internet (DuckDNS)           você + eu               ~5 min
ETAPA 7  Certificado HTTPS (Let's Encrypt)        eu, por SSH             ~5 min
ETAPA 8  Publicar o sistema                       eu, por SSH            ~10 min
ETAPA 9  Backup automático do banco               eu, por SSH             ~5 min
```

Total: cerca de 1h20, quase tudo espera de instalação.

### Vocabulário que vai aparecer

| Termo | O que é |
|---|---|
| **Instance** | A máquina virtual. É o que outras empresas chamam de VPS. |
| **Tenancy** | Sua conta/organização na Oracle. |
| **Compartment** | Pasta lógica para organizar recursos. Usaremos a raiz. |
| **Shape** | O "tamanho" da máquina: quantos processadores e memória. |
| **VCN** | Virtual Cloud Network — a rede da sua máquina. |
| **Security List** | O firewall da rede. É onde liberamos as portas 80/443. |
| **SSH** | Terminal remoto seguro. É por onde administramos o servidor. |
| **Chave SSH** | Par de arquivos que substitui a senha. Mais seguro. |

### Sobre a chave SSH

Já foi criada, no padrão que você usa nos outros projetos:

```
C:\Users\Farias\.ssh\amb_oracle        ← chave PRIVADA. Nunca compartilhe.
C:\Users\Farias\.ssh\amb_oracle.pub    ← chave PÚBLICA. Esta vai para a Oracle.
```

A pública é como um cadeado que você distribui; a privada é a única chave que o
abre. Por isso não há senha para descobrir: sem o arquivo privado, ninguém entra.

---

## ETAPA 1 — Criar a conta

**Link:** https://www.oracle.com/br/cloud/free/

Clique em **"Comece sempre gratuito"** / *"Start for free"*.

### O que você vai preencher

1. País: **Brasil**, e-mail e nome.
2. Verificação do e-mail (chega um código).
3. Senha da conta e **nome do tenancy** — sugestão: `sms-cascavel`.

### A escolha da região — ATENÇÃO, é permanente

A região da conta (*home region*) **não pode ser alterada depois**. Ela define
onde ficam seus servidores e afeta a velocidade de acesso.

| Região | Latência do Ceará | Facilidade de conseguir máquina ARM |
|---|---|---|
| **Brazil Southeast (Vinhedo)** — `sa-vinhedo-1` | ótima (~30 ms) | média — **recomendada** |
| Brazil East (São Paulo) — `sa-saopaulo-1` | ótima (~25 ms) | difícil, muito disputada |
| US East (Ashburn) — `us-ashburn-1` | ruim (~120 ms) | mais fácil |

**Recomendação: Brazil Southeast (Vinhedo).** Bom desempenho e menos concorrência
que São Paulo pelas máquinas gratuitas.

### O cartão de crédito

Vai ser pedido, e isso incomoda — mas serve apenas para verificar que você é uma
pessoa real. A Oracle faz uma **pré-autorização de cerca de US$ 1, estornada em
alguns dias**. Enquanto ficarmos dentro do Always Free, não há cobrança.

Ao terminar, a conta pode levar de 5 a 20 minutos para ser liberada. Você recebe
um e-mail avisando.

> **Me avise quando a conta estiver criada** e me diga qual região você escolheu.

---

## ETAPA 2 — Criar a rede ANTES da máquina

> **Faça a rede primeiro.** Se você criar a instância deixando o formulário criar a
> rede, o interruptor **"Designar endereço IPv4 público"** fica travado, com a
> mensagem *"Você deve selecionar uma sub-rede pública"* — mesmo tendo marcado
> "criar nova sub-rede pública".
>
> Motivo: o criador de instância monta uma sub-rede simples, sem o **Internet
> Gateway**, que é a peça que liga a rede à internet. Sem endereço público, nem o
> SSH nem o site funcionam.

**Link:** https://cloud.oracle.com/networking/vcns

> ⚠️ **Existem dois botões parecidos nessa página. Use o assistente.**
>
> | Botão | O que faz |
> |---|---|
> | `Criar VCN` | apenas a rede vazia — pede o bloco CIDR e para aí |
> | **`Iniciar Assistente de VCN`** | rede + sub-redes + Internet Gateway + rotas |
>
> Se você cair na tela **"Criar uma Rede Virtual na Nuvem"** pedindo *Blocos CIDR
> IPv4* (com aviso de "Obrigatório"), está no botão errado: cancele e procure o
> assistente.

1. **Iniciar Assistente de VCN**
2. **Criar VCN com conectividade de Internet** → *Iniciar workflow*
3. Nome: `vcn-amb`
4. Deixe todos os blocos CIDR no padrão (`10.0.0.0/16`, `10.0.0.0/24`, `10.0.1.0/24`)
5. **Criar**

O assistente monta VCN, sub-rede pública, sub-rede privada, Internet Gateway,
NAT Gateway e as tabelas de rota — tudo já conectado.

### Se preferir o caminho manual

Dá para fazer sem o assistente, mas são quatro peças em sequência e cada uma pode
ser esquecida:

1. Criar a VCN com CIDR `10.0.0.0/16` e label de DNS `vcnamb`
2. Criar um **Gateway de Internet** na VCN
3. Editar a **Tabela de Rotas** padrão, acrescentando a rota `0.0.0.0/0` apontando
   para esse gateway — é isso que dá saída para a internet
4. Criar a **Sub-rede pública** com CIDR `10.0.0.0/24`, associada a essa tabela

Aproveite que está aqui e faça a **Etapa 3** (liberar as portas) antes de sair.

---

## ETAPA 2b — Criar a máquina virtual

No painel da Oracle, o caminho é: **☰ Menu → Compute → Instances → Create instance**

Link direto (depois de logado): https://cloud.oracle.com/compute/instances

> O formulário é dividido em etapas numeradas: *Informações básicas*, *Segurança*,
> *Rede*, *Armazenamento* e *Revisão*. Em **Segurança** não ative nada; em
> **Armazenamento** deixe o tamanho padrão e não ative política de backup, que
> pode gerar cobrança.

### Preenchimento campo por campo

**Name:** `amb-servidor`

**Image and shape** → botão **Edit**:

- **Image:** clique em *Change image* e escolha **Canonical Ubuntu 24.04**
  (ou 22.04 — ambas servem).
- **Shape:** clique em *Change shape* e tente nesta ordem:

  | Tentativa | Shape | Configuração |
  |---|---|---|
  | 1ª — ideal | `VM.Standard.A1.Flex` (Ampere ARM) | 2 OCPU e 12 GB |
  | 2ª — se der erro | `VM.Standard.E2.1.Micro` (AMD) | fixo, 1 GB |

  Procure a etiqueta verde **"Always Free-eligible"**. Se ela não aparecer, o
  recurso será cobrado — não prossiga.

> **Sobre o erro "Out of host capacity":** é comum na ARM e não é problema seu, é
> falta de estoque na região. Se acontecer, use a AMD (`E2.1.Micro`). Para este
> sistema — 49 motoristas, poucos usuários simultâneos — 1 GB é suficiente.
> Podemos migrar para ARM depois.

**Add SSH keys** — a parte importante:

- Marque **"Paste public keys"**
- Cole exatamente esta linha:

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIALacwv0wugICFRLWLEiQu7++zH2QmyH5NhFHrN9V3/s amb-oracle-20260804
```

**Rede (Etapa 3 do formulário):** aqui é onde a ordem importa. Com a VCN já criada:

- **Rede principal:** *Selecionar rede virtual na nuvem existente* → `vcn-amb`
- **Sub-rede:** *Selecionar sub-rede existente* → a que tem **"Public"** no nome
- **Designar endereço IPv4 público automaticamente:** agora deixa **ligar** ✓
- **IPv6:** pode deixar desligado

Clique em **Create**. A máquina fica *PROVISIONING* por 1-2 minutos e depois
*RUNNING* (verde).

> **Me envie o "Public IP address"** que aparece na tela da instância. É um
> endereço como `150.230.xxx.xxx`.

---

## ETAPA 3 — Liberar as portas 80 e 443

Por padrão a Oracle libera apenas a porta 22 (SSH). Sem este passo, o site não
abre no navegador — o servidor responde, mas o firewall da rede barra antes.

**Caminho:** na página da instância, seção *Primary VNIC* → clique no nome da
**Subnet** → clique na **Security List** (default) → **Add Ingress Rules**

Adicione **duas** regras:

| Campo | Regra 1 (HTTP) | Regra 2 (HTTPS) |
|---|---|---|
| Stateless | desmarcado | desmarcado |
| Source Type | CIDR | CIDR |
| Source CIDR | `0.0.0.0/0` | `0.0.0.0/0` |
| IP Protocol | TCP | TCP |
| Destination Port Range | `80` | `443` |
| Description | HTTP | HTTPS |

`0.0.0.0/0` significa "de qualquer lugar da internet" — é o esperado para um site
público. O acesso ao *sistema* continua protegido por usuário e senha.

> Há também um firewall **dentro** do Ubuntu (iptables), que eu configuro na
> Etapa 5. Os dois precisam estar abertos.

---

## ETAPA 4 — Primeiro acesso por SSH

Com o IP em mãos, testamos a conexão. O comando é:

```bash
ssh -i ~/.ssh/amb_oracle ubuntu@167.126.6.137
```

Na primeira vez aparece uma pergunta sobre autenticidade do host — responda `yes`.
Isso registra a identidade do servidor para detectar falsificação depois.

O usuário é **`ubuntu`** (padrão das imagens Ubuntu na Oracle) e ele tem poder de
administrador via `sudo`.

A partir daqui **eu assumo o comando**: rodo os comandos por SSH daqui e explico
cada bloco antes de executar. Você acompanha a saída.

---

## ETAPA 5 — Instalar o servidor

O que vai ser instalado e o papel de cada peça:

```
Nginx        recebe as requisições da internet e entrega as páginas
PHP 8.3-FPM  executa o código do sistema
MySQL 8      guarda os dados
Composer     instala as bibliotecas do Laravel
Node.js      compila o CSS e o JavaScript
Certbot      emite e renova o certificado HTTPS
```

Também vou:
- Abrir as portas 80/443 no firewall interno do Ubuntu
- Criar o banco `amb` e um usuário de banco com senha forte
- Ajustar o fuso horário para `America/Fortaleza`
- Configurar atualizações de segurança automáticas

---

## ETAPA 6 — Endereço na internet ✅ pronto

Um IP como `167.126.6.137` funciona, mas ninguém decora. E o certificado HTTPS
gratuito **não é emitido para número de IP** — só para nome. Por isso o nome não
é enfeite: é o que destrava o cadeado.

**Registrado no DuckDNS** (gratuito, sem prazo de validade):

```
ambulancia.duckdns.org  →  167.126.6.137
```

O servidor **reconfirma esse apontamento a cada 30 minutos**, sozinho. Serve para
dois fins: mantém o domínio ativo (o DuckDNS remove os abandonados) e conserta o
endereço automaticamente se a Oracle um dia trocar o IP da máquina.

| O quê | Onde |
|---|---|
| Script | `/usr/local/bin/atualizar-duckdns.sh` |
| Agendamento | `/etc/cron.d/duckdns` |
| Token | `/root/.token-duckdns` (só o root lê) |
| Registro | `/var/log/duckdns.log` |

> **O token do DuckDNS é uma senha.** Quem tiver acesso a ele consegue apontar
> `ambulancia.duckdns.org` para outro servidor. Nunca compartilhe prints da
> página do DuckDNS — o token aparece nela.

> **Alternativa institucional:** se a prefeitura tiver domínio próprio
> (`cascavel.ce.gov.br`), vale pedir ao TI um subdomínio apontando para este IP —
> algo como `ambulancias.cascavel.ce.gov.br`. Fica mais apropriado para uso
> oficial, e a troca depois é simples: emito um certificado novo e pronto. O
> DuckDNS resolve enquanto isso, e nada impede manter os dois.

---

## ETAPA 7 — Certificado HTTPS ✅ pronto

Sem HTTPS, tudo o que trafega vai em texto puro — inclusive as senhas dos
usuários. Considerando que o sistema guarda CPF, data de nascimento, telefone e
endereço de 49 servidores públicos, é obrigatório.

Certificado do **Let's Encrypt** emitido pelo `certbot`:

```
https://ambulancia.duckdns.org     cadeado, sem aviso de "não seguro"
http://ambulancia.duckdns.org      redireciona sozinho para https
```

| | |
|---|---|
| Validade | até 03/11/2026 |
| Renovação | automática, pelo `certbot.timer` do sistema |
| Certificado | `/etc/letsencrypt/live/ambulancia.duckdns.org/` |

A renovação foi **testada em modo simulado** e passou. Para repetir o teste:

```bash
sudo certbot renew --dry-run --no-random-sleep-on-renew
```

> O `--no-random-sleep-on-renew` importa: sem ele o certbot dorme até 8 minutos
> antes de agir, para não sobrecarregar o Let's Encrypt com milhões de servidores
> pedindo no mesmo instante. Parece travado, mas está só esperando.

---

## ETAPA 8 — Publicar o sistema

```
1. git clone do repositório em /var/www/amb
2. composer install --no-dev --optimize-autoloader
3. .env de produção  (APP_DEBUG=false, senha do banco, URL do site)
4. php artisan key:generate
5. php artisan migrate --force
6. npm install && npm run build
7. php artisan storage:link
8. cache de configuração, rotas e views
9. permissões das pastas storage e bootstrap/cache
```

Vou criar também um script `deploy.sh`: nas próximas atualizações, um comando
sozinho baixa as alterações, atualiza dependências, roda as migrations e limpa os
caches.

### Endurecimento de segurança

Um sistema exposto na internet pede mais cuidado que um em rede local:

- **Senha mínima maior em produção** — hoje o mínimo é 4 caracteres, definido para
  uso interno. Online, sugiro exigir 8 no servidor, mantendo 4 na sua máquina
  para testar sem incômodo.
- **Trocar a senha do `admin`** logo no primeiro acesso.
- `APP_DEBUG=false` — impede que erros exponham trechos de código e credenciais.
- HTTPS obrigatório, redirecionando quem chegar por HTTP.

---

## ETAPA 9 — Backup ✅ pronto

Banco de 0,8 MB: backup diário é trivial e custa quase nada. Já está rodando:

```
Todo dia às 2h  →  mysqldump compactado em /var/backups/amb/
                   mantém os últimos 30 dias, apaga os mais velhos
```

| O quê | Onde |
|---|---|
| Script | `/usr/local/bin/backup-amb.sh` |
| Agendamento | `/etc/cron.d/backup-amb` |
| Arquivos | `/var/backups/amb/amb-AAAA-MM-DD.sql.gz` |
| Registro | `/var/log/backup-amb.log` |

O backup foi **testado por restauração real** em um banco descartável: as 17
tabelas voltaram com a contagem exata de registros. Backup que nunca foi
restaurado não é backup — é esperança.

### Baixar uma cópia para o seu computador

Backup que mora só no mesmo servidor não protege contra perder o servidor.
De vez em quando, rode isto no PowerShell da sua máquina:

```powershell
scp -i $HOME\.ssh\amb_oracle ubuntu@167.126.6.137:/var/backups/amb/amb-*.sql.gz .
```

### Restaurar, se um dia precisar

```bash
gunzip -c /var/backups/amb/amb-2026-08-04.sql.gz | sudo mysql amb
```

---

## Depois que estiver no ar

### Atualizar o sistema ✅ pronto

Você continua desenvolvendo no Laragon. Quando quiser publicar:

```bash
# na sua máquina
git push

# no servidor (eu ou você)
ssh -i ~/.ssh/amb_oracle ubuntu@167.126.6.137 "/var/www/amb/deploy.sh"
```

O `deploy.sh` faz tudo sozinho, em oito passos:

1. backup do banco **antes** de mexer em qualquer coisa
2. põe o site em manutenção (ninguém grava dados no meio da troca)
3. baixa a versão nova do GitHub — se já estiver atualizado, para aqui
4. atualiza as dependências de PHP e JavaScript
5. aplica as migrações do banco
6. recompila CSS e JavaScript
7. regenera os caches do Laravel
8. tira o site da manutenção e confere se ele responde

Se qualquer passo falhar, o script **para, tira o site da manutenção sozinho**
e deixa a versão anterior no ar — depois mostra o comando exato para desfazer.
Você nunca fica com o sistema fora do ar por causa de um deploy ruim.

### Vale para seus outros sistemas

Esta mesma máquina comporta os outros projetos que você tem em `www`
(coopgestor, gesti, ialpha), cada um em seu subdomínio, compartilhando Nginx e
MySQL. Depois de configurada, acrescentar um site é questão de um arquivo de
configuração e um banco novo.

### Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Interruptor de IPv4 público travado | A sub-rede foi criada pelo formulário da instância, sem Internet Gateway. Crie a VCN pelo assistente antes (Etapa 2) |
| Navegador não abre o site | Portas 80/443 não liberadas na Security List (Etapa 3) |
| `Connection refused` no SSH | IP errado, ou instância ainda provisionando |
| `Permission denied (publickey)` | Chave pública não foi colada na criação da instância |
| Erro 502 no navegador | PHP-FPM parado — `sudo systemctl restart php8.4-fpm` |
| Erro 500 e tela branca | Ver `storage/logs/laravel.log` |
| Site fora do ar sem motivo | Instância recuperada por inatividade (raro em uso real) |

---

## O que eu preciso de você, em resumo

1. **Etapa 1:** criar a conta e me dizer a região escolhida
2. **Etapa 2:** criar a instância com a chave pública acima e me enviar o IP
3. **Etapa 3:** liberar as portas 80 e 443
4. **Etapa 6:** criar o nome no DuckDNS e me dizer qual

O restante eu faço por SSH, explicando cada passo. Se algo der erro, me envie o
texto ou o print — erro de servidor quase sempre diz exatamente o que falta.
