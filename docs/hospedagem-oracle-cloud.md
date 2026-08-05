# Montar o servidor do zero — Oracle Cloud Always Free

Roteiro completo para colocar o sistema no ar em um servidor **gratuito e
permanente**, acessível de qualquer lugar por HTTPS.

Escrito depois de fazer isso de verdade, uma vez, tropeçando em tudo o que dava
para tropeçar. Cada armadilha que custou tempo está marcada **no ponto exato onde
ela aparece**, com o sintoma que você vai ver na tela e o motivo.

Feito para quem nunca administrou um servidor Linux. Cada etapa explica **o que
está sendo feito e por quê**, não apenas o que clicar.

---

## O que você terá ao final

```
https://seu-nome.duckdns.org        sistema no ar, com cadeado
├── certificado que se renova sozinho
├── backup do banco todo dia às 2h, guardando 30 dias
├── git push publica sozinho, depois de passar nos testes
├── bloqueio automático contra tentativa de invasão
└── custo: R$ 0,00 por mês, para sempre
```

**Tempo:** cerca de 2 horas na primeira vez, quase tudo espera de instalação.
Seguindo este roteiro sem os erros que cometemos, fica em 1 hora.

---

## ⚠️ Leia isto antes de começar

Seis coisas que custaram tempo. Se você souber delas de antemão, o caminho fica
reto.

### 1. "Avaliação Grátis" e "Always Free" são coisas diferentes

Ao criar a conta, aparece uma faixa dizendo *"Você está em uma Avaliação Grátis"*
com US$ 300 de crédito por 30 dias. Isso **não** é a camada gratuita permanente.

| | Avaliação Grátis | Always Free |
|---|---|---|
| Duração | 30 dias | permanente |
| Recursos | quase todos | uma lista curta e específica |
| Ao terminar | tudo que não for Always Free **é desligado** |

Se você criar uma máquina potente durante a avaliação, ela funciona por 30 dias e
depois some. **Só crie recursos marcados como "Always Free eligible".**

### 2. Só duas famílias de máquina são gratuitas

Este é o erro mais caro possível. Ao escolher o tamanho da máquina você vê AMD,
Intel e Ampere — e a maioria é **paga**.

| Família | Gratuito? |
|---|---|
| **Ampere** `VM.Standard.A1.Flex` | ✅ 4 núcleos e 24 GB no total |
| **AMD** `VM.Standard.E2.1.Micro` | ✅ 2 máquinas de 1 núcleo e 1 GB |
| AMD `E3`/`E4`/`E5.Flex` | ❌ pago |
| Intel qualquer | ❌ pago |

> **Como identificar:** procure a etiqueta verde **"Always Free eligible"** /
> *"Always Free elegível"*. Ela só aparece em duas abas: **Ampere** e
> **Especialidade e geração anterior**. Se você estiver na aba AMD ou Intel e não
> vir a etiqueta, está prestes a criar algo cobrado.
>
> Quase criamos uma `E4.Flex` de 32 GB. Custaria cerca de **US$ 90 por mês**, e
> ela pareceria funcionar normalmente durante os 30 dias de avaliação.

### 3. Crie a REDE antes da MÁQUINA

Se você criar a máquina primeiro e deixar o formulário criar a rede, o
interruptor **"Designar endereço IPv4 público"** fica **travado**, com a mensagem
*"Você deve selecionar uma sub-rede pública"* — mesmo tendo marcado "criar nova
sub-rede pública".

**Motivo:** o formulário da instância monta uma sub-rede simples, sem o *Internet
Gateway* — a peça que liga a rede à internet. Sem endereço público, nem o SSH nem
o site funcionam, e não há como consertar sem refazer.

### 4. Dois botões parecidos na tela de rede

Na página de redes existem **`Criar VCN`** e **`Iniciar Assistente de VCN`**.

| Botão | O que faz |
|---|---|
| `Criar VCN` | só a rede vazia — pede o bloco CIDR e para aí |
| **`Iniciar Assistente de VCN`** | rede + sub-redes + gateway + rotas, tudo ligado |

Se você cair numa tela pedindo *"Blocos CIDR IPv4"* com aviso de **Obrigatório**,
e a lista de blocos estiver **vazia**, está no botão errado. Cancele e procure o
assistente.

### 5. "Out of host capacity" não é erro seu

Ao criar a máquina Ampere é comum aparecer:

```
Erro de API
Capacidade insuficiente para a forma VM.Standard.A1.Flex no domínio
de disponibilidade AD-1
```

É **falta de estoque** na região, não problema da sua conta. A Ampere vive
esgotada no Brasil. Duas saídas:

- Comece com a AMD `E2.1.Micro` (1 GB) e migre depois — foi o que fizemos
- Deixe um script tentando de tempos em tempos (Etapa 18)

> Vinhedo tem **um único domínio de disponibilidade**, então não há um segundo
> lugar para tentar dentro da região. Isso explica a escassez.

### 6. 1 GB de RAM exige memória de troca

Se você ficar com a `E2.1.Micro`, ela tem 954 MB utilizáveis. Um Laravel com
MySQL usa mais que isso em picos — a geração de PDF pediu 266 MB só de PHP.
Sem *swap*, o Linux mata processos no meio. A Etapa 7 resolve.

---

## Vocabulário que vai aparecer

| Termo | O que é |
|---|---|
| **Instance** | A máquina virtual. É o que outras empresas chamam de VPS. |
| **Tenancy** | Sua conta/organização na Oracle. |
| **Compartment** | Pasta lógica para organizar recursos. Usaremos a raiz. |
| **Shape** | O "tamanho" da máquina: quantos processadores e memória. |
| **OCPU** | Um núcleo físico. Costuma equivaler a 2 processadores lógicos. |
| **VCN** | *Virtual Cloud Network* — a rede da sua máquina. |
| **Security List** | O firewall **da rede**. É onde liberamos as portas 80/443. |
| **iptables** | O firewall **de dentro** do Ubuntu. Os dois precisam estar abertos. |
| **SSH** | Terminal remoto seguro. É por onde administramos o servidor. |
| **Chave SSH** | Par de arquivos que substitui a senha. |
| **AD** | *Availability Domain* — um centro de dados dentro da região. |

---

## O mapa do caminho

```
PARTE 1 — NO NAVEGADOR
  1  Criar a conta na Oracle                         ~15 min
  2  Gerar a chave SSH no seu computador              ~2 min
  3  Criar a rede (VCN) pelo assistente               ~5 min
  4  Liberar as portas 80 e 443                       ~5 min
  5  Criar a máquina virtual                         ~10 min
  6  Primeiro acesso por SSH                          ~5 min

PARTE 2 — NO SERVIDOR
  7  Memória de troca e fuso horário                  ~5 min
  8  Firewall interno do Ubuntu                       ~5 min
  9  Instalar Nginx, PHP, MySQL, Composer e Node     ~20 min
 10  Criar o banco de dados                           ~3 min
 11  Publicar o sistema                              ~15 min
 12  Ajustar os limites do PHP                        ~5 min

PARTE 3 — ENDEREÇO E CADEADO
 13  Endereço gratuito no DuckDNS                     ~5 min
 14  Certificado HTTPS                                ~5 min

PARTE 4 — DEIXAR RODANDO SOZINHO
 15  Backup diário do banco                           ~5 min
 16  Deploy automático a cada git push               ~15 min
 17  Agendador de tarefas                             ~2 min
 18  Caçador de capacidade Ampere (opcional)         ~10 min
```

> **Nossa instalação, para referência.** Onde o roteiro disser `SEU_IP`,
> `SEU_DOMINIO` ou `seu-usuario`, troque pelos seus. Os nossos são:
>
> | | |
> |---|---|
> | IP | `167.126.6.137` |
> | Domínio | `ambulancia.duckdns.org` |
> | Região | `sa-vinhedo-1` (Brazil Southeast / Vinhedo) |
> | Máquina | `VM.Standard.E2.1.Micro` — 1 OCPU, 1 GB |
> | Chave SSH | `~/.ssh/amb_oracle` |
> | Repositório | `github.com/fariasps1817/amb` |

---
---

# PARTE 1 — No navegador

## ETAPA 1 — Criar a conta

**Link:** https://www.oracle.com/br/cloud/free/

Clique em **"Comece sempre gratuito"** / *"Start for free"*.

### O que preencher

1. País: **Brasil**, e-mail e nome
2. Verificação do e-mail (chega um código)
3. Senha da conta e **nome do tenancy** — algo como `sms-cascavel`

### A escolha da região é PERMANENTE

A região da conta (*home region*) **não pode ser alterada depois**. Ela define
onde ficam seus servidores e afeta a velocidade de acesso.

| Região | Latência do Ceará | Conseguir máquina Ampere |
|---|---|---|
| **Brazil Southeast (Vinhedo)** `sa-vinhedo-1` | ótima (~30 ms) | média — **recomendada** |
| Brazil East (São Paulo) `sa-saopaulo-1` | ótima (~25 ms) | difícil, muito disputada |
| US East (Ashburn) `us-ashburn-1` | ruim (~120 ms) | mais fácil |

**Recomendação: Vinhedo.** Bom desempenho e menos concorrência que São Paulo
pelas máquinas gratuitas.

### O cartão de crédito

Vai ser pedido, e incomoda — mas serve só para verificar que você é uma pessoa
real. A Oracle faz uma **pré-autorização de cerca de US$ 1, estornada em alguns
dias**. Dentro do Always Free não há cobrança.

A conta leva de 5 a 20 minutos para ser liberada. Chega um e-mail avisando.

---

## ETAPA 2 — Gerar a chave SSH

Faça isto **antes** de criar a máquina: a chave pública é colada no formulário de
criação, e depois não dá para acrescentar sem trabalho extra.

No **PowerShell** do seu computador:

```powershell
ssh-keygen -t ed25519 -f $HOME\.ssh\amb_oracle -C "amb-oracle"
```

Quando pedir *passphrase*, pode deixar em branco (Enter duas vezes) — a chave já
fica protegida pelo seu usuário do Windows.

Isso cria dois arquivos:

```
C:\Users\SEU_USUARIO\.ssh\amb_oracle       ← PRIVADA. Nunca compartilhe.
C:\Users\SEU_USUARIO\.ssh\amb_oracle.pub   ← PÚBLICA. Esta vai para a Oracle.
```

A pública é como um cadeado que você distribui; a privada é a única chave que o
abre. Por isso não existe senha para descobrir: **sem o arquivo privado, ninguém
entra**.

Para ver o conteúdo da pública, que você vai colar daqui a pouco:

```powershell
Get-Content $HOME\.ssh\amb_oracle.pub
```

Sai uma linha só, começando com `ssh-ed25519`.

---

## ETAPA 3 — Criar a rede (VCN)

> ⚠️ **Esta etapa vem antes da máquina.** Ver armadilha nº 3 no início.

**Link:** https://cloud.oracle.com/networking/vcns

1. Clique em **`Iniciar Assistente de VCN`**
   *(não o `Criar VCN` — ver armadilha nº 4)*
2. Escolha **"Criar VCN com conectividade de Internet"** → *Iniciar workflow*
3. Nome: `vcn-amb`
4. Deixe todos os blocos CIDR no padrão:
   `10.0.0.0/16`, `10.0.0.0/24`, `10.0.1.0/24`
5. **Criar**

O assistente monta de uma vez a VCN, a sub-rede pública, a sub-rede privada, o
Internet Gateway, o NAT Gateway e as tabelas de rota — tudo já conectado. É esse
"tudo já conectado" que o formulário da instância não faz.

<details>
<summary>Se preferir montar à mão</summary>

São quatro peças em sequência, e esquecer qualquer uma quebra o conjunto:

1. Criar a VCN com CIDR `10.0.0.0/16` e rótulo de DNS `vcnamb`
2. Criar um **Gateway de Internet** na VCN
3. Editar a **Tabela de Rotas** padrão, acrescentando a rota `0.0.0.0/0`
   apontando para esse gateway — é isso que dá saída para a internet
4. Criar a **Sub-rede pública** com CIDR `10.0.0.0/24`, associada a essa tabela

</details>

---

## ETAPA 4 — Liberar as portas 80 e 443

Aproveite que está na tela de rede. Por padrão a Oracle libera apenas a porta 22
(SSH). Sem este passo o site não abre: o servidor responde, mas o firewall da
rede barra antes.

**Caminho:** clique na VCN → **Sub-redes** → a sub-rede **pública** → a
**Security List** (`Default Security List for vcn-amb`) → **Add Ingress Rules**

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

> Existe um segundo firewall **dentro** do Ubuntu. Ele é tratado na Etapa 8, e
> os dois precisam estar abertos para o site funcionar.

---

## ETAPA 5 — Criar a máquina virtual

**Link:** https://cloud.oracle.com/compute/instances → **Create instance**

O formulário é dividido em etapas numeradas. Em **Segurança** não ative nada; em
**Armazenamento** deixe o tamanho padrão e **não** ative política de backup, que
pode gerar cobrança.

### Nome

`amb-servidor` — ou o que preferir.

### Imagem e formato → botão **Editar**

**Imagem:** *Alterar imagem* → **Canonical Ubuntu 24.04**

> Atenção ao escolher: existe uma versão **x86_64** e uma **aarch64**. A
> arquitetura precisa combinar com a máquina:
>
> | Se escolher a máquina | Use a imagem |
> |---|---|
> | AMD `E2.1.Micro` | `x86_64` |
> | Ampere `A1.Flex` | `aarch64` |

**Formato:** *Alterar formato* → tente nesta ordem:

| Tentativa | Aba | Formato | Configuração |
|---|---|---|---|
| 1ª — ideal | **Ampere** | `VM.Standard.A1.Flex` | 2 OCPU e 12 GB |
| 2ª — se der erro | **Especialidade e geração anterior** | `VM.Standard.E2.1.Micro` | fixo, 1 GB |

> ⚠️ **Confirme a etiqueta "Always Free elegível"** antes de seguir. Ela só
> aparece nessas duas abas. Ver armadilha nº 2.

> Se aparecer **"Capacidade insuficiente"**, é falta de estoque — ver armadilha
> nº 5. Vá para a `E2.1.Micro` e migre depois pela Etapa 18. Para um sistema com
> 49 motoristas e poucos usuários ao mesmo tempo, 1 GB dá conta.

### Chaves SSH

- Marque **"Colar chaves públicas"** / *Paste public keys*
- Cole a linha inteira que saiu do `Get-Content` na Etapa 2

### Rede — onde a ordem importa

Com a VCN já criada na Etapa 3:

| Campo | Escolha |
|---|---|
| Rede principal | *Selecionar rede virtual na nuvem existente* → `vcn-amb` |
| Sub-rede | *Selecionar sub-rede existente* → a que tem **"Public"** no nome |
| Designar endereço IPv4 público | **ligado** ✓ — agora ele destrava |
| IPv6 | pode deixar desligado |

Clique em **Criar**. A máquina fica *PROVISIONING* por 1 a 2 minutos e depois
*RUNNING* (verde).

**Anote o "Public IP address"** que aparece na tela — algo como `150.230.x.x`.

---

## ETAPA 6 — Primeiro acesso por SSH

No PowerShell:

```powershell
ssh -i $HOME\.ssh\amb_oracle ubuntu@SEU_IP
```

Na primeira vez aparece uma pergunta sobre autenticidade do host — responda
`yes`. Isso grava a identidade do servidor para detectar falsificação depois.

O usuário é **`ubuntu`** (padrão das imagens Ubuntu na Oracle), e ele tem poder
de administrador via `sudo`.

Se aparecer `Permission denied (publickey)`, a chave pública não foi colada
corretamente na criação — é mais rápido apagar a instância e refazer a Etapa 5.

---
---

# PARTE 2 — No servidor

Daqui em diante os comandos rodam **dentro** do servidor, pela sessão SSH.

## ETAPA 7 — Memória de troca e fuso horário

Obrigatório se a máquina tem 1 GB. Ver armadilha nº 6.

```bash
# 2 GB de memória de troca em disco
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile

# torna permanente, para sobreviver a reinícios
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# fuso horário
sudo timedatectl set-timezone America/Fortaleza

# conferindo
free -h
date
```

O *swap* não substitui memória real — disco é ordens de grandeza mais lento —
mas evita que o Linux mate processos em picos.

---

## ETAPA 8 — Firewall interno do Ubuntu

> ⚠️ **A ordem das regras importa, e é onde se erra.** O iptables avalia de cima
> para baixo e para na primeira que combina. A imagem da Oracle já vem com uma
> regra `REJECT` no final. Se você acrescentar o `ACCEPT` **depois** dela, ele
> nunca é alcançado — e o site continua sem abrir, sem nenhum erro visível.

Primeiro veja onde está o `REJECT`:

```bash
sudo iptables -L INPUT --line-numbers -n
```

A saída mostra algo assim:

```
num  target     prot opt source               destination
1    ACCEPT     all  --  0.0.0.0/0            0.0.0.0/0    state RELATED,ESTABLISHED
2    ACCEPT     icmp --  0.0.0.0/0            0.0.0.0/0
3    ACCEPT     all  --  0.0.0.0/0            0.0.0.0/0
4    ACCEPT     tcp  --  0.0.0.0/0            0.0.0.0/0    tcp dpt:22
5    REJECT     all  --  0.0.0.0/0            0.0.0.0/0    reject-with icmp-host-prohibited
```

O `REJECT` está na linha **5**. Insira as novas regras **nessa posição**, o que
empurra o `REJECT` para baixo:

```bash
sudo iptables -I INPUT 5 -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -p tcp --dport 443 -j ACCEPT

# confira: os ACCEPT de 80 e 443 precisam aparecer ANTES do REJECT
sudo iptables -L INPUT --line-numbers -n

# salva para sobreviver a reinícios
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y iptables-persistent
sudo netfilter-persistent save
```

---

## ETAPA 9 — Instalar o servidor

O que cada peça faz:

```
Nginx        recebe as requisições da internet e entrega as páginas
PHP 8.4-FPM  executa o código do sistema
MySQL 8      guarda os dados
Composer     instala as bibliotecas do Laravel
Node.js      compila o CSS e o JavaScript
```

```bash
sudo apt-get update
sudo apt-get upgrade -y
```

### PHP 8.4 — não use o do Ubuntu

> ⚠️ **Armadilha real.** O Ubuntu 24.04 traz PHP 8.3.6. O `composer.lock` deste
> projeto exige **PHP ≥ 8.4.1**, porque foi gerado numa máquina com 8.5. Instalar
> o PHP do Ubuntu leva a:
>
> ```
> Your lock file does not contain a compatible set of packages
> symfony/console v8.1 requires php >=8.4.1
> ```
>
> A tentação é rodar `composer update` para resolver. **Não faça isso**: ele
> atualizaria todas as bibliotecas para versões que nunca foram testadas.
> Instale o PHP certo.

```bash
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update

sudo apt-get install -y \
    nginx \
    php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-gd php8.4-bcmath php8.4-intl \
    mysql-server \
    git unzip curl

# o php8.4-cli é o que faz o "php artisan" funcionar; o fpm sozinho
# atende o navegador mas não dá linha de comando
php -v
```

### Composer e Node

```bash
# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# conferindo tudo
php -v && composer --version && node -v && nginx -v && mysql --version
```

---

## ETAPA 10 — Criar o banco de dados

```bash
# gera uma senha forte e guarda onde só o root lê
openssl rand -base64 24 | sudo tee /root/.senha-bd-amb >/dev/null
sudo chmod 600 /root/.senha-bd-amb

SENHA=$(sudo cat /root/.senha-bd-amb)

sudo mysql <<SQL
CREATE DATABASE amb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'amb'@'localhost' IDENTIFIED BY '$SENHA';
GRANT ALL PRIVILEGES ON amb.* TO 'amb'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "Banco criado. A senha está em /root/.senha-bd-amb"
```

A senha fica fora do repositório e fora do histórico do terminal.

---

## ETAPA 11 — Publicar o sistema

```bash
sudo mkdir -p /var/www
sudo chown ubuntu:ubuntu /var/www
git clone https://github.com/fariasps1817/amb.git /var/www/amb
cd /var/www/amb

composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

> ⚠️ Use **`npm ci`**, não `npm install`. O `install` reescreve o
> `package-lock.json` no servidor (remove dependências específicas do Windows),
> sujando a árvore do git e fazendo o próximo `git pull` falhar. O `ci` instala
> exatamente o que está no arquivo e não o altera.

### Arquivo de configuração

```bash
cp .env.example .env
nano .env
```

Ajuste estas linhas:

```ini
APP_NAME="Coordenação de Ambulâncias"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://SEU_IP

APP_TIMEZONE=America/Fortaleza
APP_LOCALE=pt_BR

DB_CONNECTION=mysql
DB_DATABASE=amb
DB_USERNAME=amb
DB_PASSWORD=cole-aqui-o-conteudo-de-/root/.senha-bd-amb

SESSION_DRIVER=database
SESSION_LIFETIME=30
```

`APP_DEBUG=false` é obrigatório em produção: com `true`, qualquer erro mostra
trechos do código e credenciais para quem estiver na tela.

```bash
php artisan key:generate
php artisan migrate --force

# cria o usuário inicial (admin / 1234) e a identidade institucional
php artisan db:seed --force

php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# o Nginx roda como www-data e precisa escrever nessas pastas
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Configurar o Nginx

```bash
sudo tee /etc/nginx/sites-available/amb >/dev/null <<'CONF'
server {
    listen 80;
    listen [::]:80;
    server_name _;

    root /var/www/amb/public;
    index index.php;

    charset utf-8;
    client_max_body_size 8M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # a emissão das 49 folhas de frequência leva ~32s; o padrão de 60s
        # ficaria apertado demais
        fastcgi_read_timeout 120;
    }

    # arquivos compilados não mudam de nome sem mudar de conteúdo
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # nunca servir .env, .git e afins -- MAS deixando passar /.well-known,
    # que é por onde o Let's Encrypt verifica que o servidor é seu.
    # Um "deny all" sem essa exceção faz a emissão do certificado falhar.
    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/amb-access.log;
    error_log  /var/log/nginx/amb-error.log;
}
CONF

sudo ln -sf /etc/nginx/sites-available/amb /etc/nginx/sites-enabled/amb
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Abra `http://SEU_IP` no navegador. A tela de acesso deve aparecer.

---

## ETAPA 12 — Ajustar os limites do PHP

> ⚠️ **Sem este passo o sistema abre, mas a emissão de PDF falha.** Os padrões do
> PHP são `memory_limit = 128M` e `max_execution_time = 30`. As 49 folhas de
> frequência pedem **266 MB** e levam **32 segundos**. Pela linha de comando
> funciona (o CLI não tem limite de tempo), então o erro só aparece no navegador
> — o que engana na hora de testar.

```bash
sudo tee -a /etc/php/8.4/fpm/php.ini >/dev/null <<'INI'

; Ajustes para a emissão dos PDFs da escala
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 8M
post_max_size = 12M
date.timezone = America/Fortaleza
INI

sudo systemctl restart php8.4-fpm
```

---
---

# PARTE 3 — Endereço e cadeado

## ETAPA 13 — Endereço gratuito no DuckDNS

Um IP funciona, mas ninguém decora. Mais importante: **certificado HTTPS gratuito
não é emitido para número de IP**, só para nome. O nome não é enfeite — é o que
destrava o cadeado.

**Link:** https://www.duckdns.org

1. Entre com Google ou GitHub (não cria senha nova)
2. Digite só o prefixo, sem `www` e sem `.duckdns.org` — ex.: `ambulancia`
3. **add domain**
4. No campo **current ip**, apague o que estiver lá e cole o **IP do servidor**

> O DuckDNS preenche sozinho com o IP do **seu computador**. Está errado — quem
> tem de responder é o servidor. Substitua.

5. **update ip**

> 🔒 **O `token` que aparece nessa página é uma senha.** Quem o tiver consegue
> apontar seu domínio para outro servidor. Nunca compartilhe prints dessa tela.

### Confirmar o endereço sozinho, para sempre

Isso mantém o domínio ativo (o DuckDNS remove os abandonados) e conserta o
apontamento automaticamente se a Oracle trocar o IP da máquina:

```bash
# guarda o token onde só o root lê
echo 'SEU-TOKEN-AQUI' | sudo tee /root/.token-duckdns >/dev/null
sudo chmod 600 /root/.token-duckdns

sudo tee /usr/local/bin/atualizar-duckdns.sh >/dev/null <<'SCRIPT'
#!/bin/bash
# Sem o parâmetro "ip", o DuckDNS usa o IP de onde a requisição veio -- ou
# seja, o próprio servidor. Assim o endereço se conserta sozinho.
set -euo pipefail

TOKEN=$(cat /root/.token-duckdns)
LOG=/var/log/duckdns.log

RESPOSTA=$(curl -s -m 30 "https://www.duckdns.org/update?domains=SEU_SUBDOMINIO&token=$TOKEN&ip=")

if [ "$RESPOSTA" = "OK" ]; then
    [ -f "$LOG" ] && [ "$(date +%F)" = "$(date -r "$LOG" +%F)" ] || \
        echo "$(date '+%Y-%m-%d %H:%M') ok" >> "$LOG"
else
    echo "$(date '+%Y-%m-%d %H:%M') FALHA: $RESPOSTA" >> "$LOG"
    exit 1
fi
SCRIPT

sudo chmod 700 /usr/local/bin/atualizar-duckdns.sh
echo '*/30 * * * * root /usr/local/bin/atualizar-duckdns.sh' | sudo tee /etc/cron.d/duckdns
sudo chmod 644 /etc/cron.d/duckdns

sudo /usr/local/bin/atualizar-duckdns.sh && echo "DuckDNS respondeu OK"
```

Confira se o nome já resolve:

```bash
nslookup SEU_DOMINIO.duckdns.org 8.8.8.8
```

---

## ETAPA 14 — Certificado HTTPS

Sem HTTPS tudo trafega em texto puro, inclusive as senhas. Como o sistema guarda
CPF, data de nascimento, telefone e endereço de servidores públicos, é
obrigatório.

Primeiro o Nginx precisa reconhecer o nome:

```bash
sudo sed -i 's/server_name _;/server_name SEU_DOMINIO.duckdns.org;/' \
    /etc/nginx/sites-available/amb
sudo nginx -t && sudo systemctl reload nginx
```

Depois o certificado:

```bash
sudo apt-get install -y certbot python3-certbot-nginx

sudo certbot --nginx \
  -d SEU_DOMINIO.duckdns.org \
  --non-interactive --agree-tos --redirect --no-eff-email \
  -m seu-email@exemplo.com
```

O `--redirect` faz quem chegar por `http://` ser levado para `https://`.

Atualize o endereço no sistema, senão os links internos saem como `http://`:

```bash
cd /var/www/amb
sed -i 's|^APP_URL=.*|APP_URL=https://SEU_DOMINIO.duckdns.org|' .env
php artisan config:cache
```

### Testar a renovação

```bash
sudo certbot renew --dry-run --no-random-sleep-on-renew
```

> ⚠️ **O `--no-random-sleep-on-renew` importa.** Sem ele o certbot dorme **até 8
> minutos** antes de agir, para que milhões de servidores no mundo não peçam
> renovação no mesmo instante. Parece travado, mas está só esperando — quase
> saímos caçando problema de rede por causa disso.

A renovação automática já vem agendada pelo `certbot.timer` do sistema:

```bash
systemctl is-enabled certbot.timer
```

---
---

# PARTE 4 — Deixar rodando sozinho

## ETAPA 15 — Backup diário do banco

```bash
# arquivo de credencial, para a senha não passar pela linha de comando
SENHA=$(sudo cat /root/.senha-bd-amb)
sudo tee /root/.my-amb.cnf >/dev/null <<CNF
[client]
user=amb
password=$SENHA
CNF
sudo chmod 600 /root/.my-amb.cnf

sudo tee /usr/local/bin/backup-amb.sh >/dev/null <<'SCRIPT'
#!/bin/bash
# Backup diário do banco. Mantém os últimos 30 dias.
set -euo pipefail

DESTINO=/var/backups/amb
ARQUIVO="$DESTINO/amb-$(date +%Y-%m-%d).sql.gz"
LOG=/var/log/backup-amb.log

mkdir -p "$DESTINO"

# --no-tablespaces: o usuário 'amb' não tem o privilégio PROCESS, e não
# precisamos dos metadados de tablespace para restaurar.
if mysqldump --defaults-file=/root/.my-amb.cnf \
     --single-transaction --quick --routines --triggers --no-tablespaces \
     amb 2>>"$LOG" | gzip > "$ARQUIVO"
then
    find "$DESTINO" -name 'amb-*.sql.gz' -mtime +30 -delete
    echo "$(date '+%Y-%m-%d %H:%M') ok   $(basename "$ARQUIVO") $(du -h "$ARQUIVO" | cut -f1)" >> "$LOG"
else
    rm -f "$ARQUIVO"
    echo "$(date '+%Y-%m-%d %H:%M') FALHA ao gerar o backup" >> "$LOG"
    exit 1
fi
SCRIPT

sudo chmod 700 /usr/local/bin/backup-amb.sh
echo '0 2 * * * root /usr/local/bin/backup-amb.sh' | sudo tee /etc/cron.d/backup-amb
sudo chmod 644 /etc/cron.d/backup-amb

sudo /usr/local/bin/backup-amb.sh && echo "backup ok"
```

> ⚠️ **Sem `--no-tablespaces` o `mysqldump` reclama:**
> `Access denied; you need (at least one of) the PROCESS privilege(s)`.
> Ele ainda gera o arquivo, mas polui o log e faz o cron mandar e-mail de erro
> toda noite. Os metadados de tablespace não são necessários para restaurar.

### Teste a restauração — isto não é opcional

Backup que nunca foi restaurado não é backup, é esperança.

```bash
ARQ=$(sudo ls -t /var/backups/amb/*.sql.gz | head -1)
sudo mysql -e "DROP DATABASE IF EXISTS amb_restauracao_teste;
               CREATE DATABASE amb_restauracao_teste CHARACTER SET utf8mb4;"
sudo bash -c "zcat '$ARQ' | mysql amb_restauracao_teste"

# compare as contagens
for t in motoristas unidades ambulancias escala_plantoes; do
  o=$(sudo mysql -N -e "SELECT COUNT(*) FROM amb.$t")
  r=$(sudo mysql -N -e "SELECT COUNT(*) FROM amb_restauracao_teste.$t")
  printf "%-18s produção=%-5s restaurado=%s\n" "$t" "$o" "$r"
done

sudo mysql -e "DROP DATABASE amb_restauracao_teste;"
```

### Levar uma cópia para o seu computador

Backup que mora só no mesmo servidor não protege contra perder o servidor:

```powershell
scp -i $HOME\.ssh\amb_oracle ubuntu@SEU_IP:/var/backups/amb/amb-*.sql.gz .
```

### Restaurar de verdade, se precisar

```bash
gunzip -c /var/backups/amb/amb-2026-08-05.sql.gz | sudo mysql amb
```

---

## ETAPA 16 — Deploy automático a cada `git push`

O objetivo: você trabalha na sua máquina, faz `git push`, e o site se atualiza
sozinho — **desde que os testes passem**.

```
git push  →  testes no GitHub  →  passou?  →  deploy no servidor
                                  falhou?  →  para aqui, produção intacta
```

### 16.1 — O script de deploy

Já vem versionado no repositório como `deploy.sh`. Instale-o:

```bash
cd /var/www/amb
sudo cp deploy.sh /usr/local/bin/ 2>/dev/null || true
chmod +x deploy.sh
git config core.fileMode false   # Linux marca 755 no que veio do Windows
```

Ele faz, em oito passos: backup → manutenção → baixa → dependências →
migrações → caches → compila → volta ao ar e confere. Se qualquer passo falhar,
**tira o site da manutenção sozinho** e deixa a versão anterior no ar.

> ⚠️ **Duas armadilhas que o script já contorna, e valem entender:**
>
> **Ele se sobrescreve enquanto roda.** O `git pull` troca o próprio `deploy.sh`
> no passo 3. Como o bash lê o arquivo aos poucos, conforme executa, ele passaria
> a executar bytes da versão antiga misturados com a nova. Por isso o script se
> copia para `/tmp` e roda de lá.
>
> **A verificação final precisa do `Host` certo.** Depois que o Nginx ganha
> `server_name` próprio, `curl http://localhost` cai no servidor padrão e
> responde **404** mesmo com o site no ar.

### 16.2 — Chave dedicada para o GitHub

**Não use sua chave pessoal.** Crie uma que só consiga rodar o deploy.

No seu computador:

```powershell
ssh-keygen -t ed25519 -f $HOME\.ssh\amb_deploy_ci -N '""' -C "github-actions-deploy"
Get-Content $HOME\.ssh\amb_deploy_ci.pub
```

No servidor, instale-a com **comando forçado**:

```bash
CHAVE='cole-aqui-a-linha-da-chave-publica'

LINHA="command=\"/var/www/amb/deploy.sh\",no-agent-forwarding,no-port-forwarding,no-pty,no-user-rc,no-X11-forwarding $CHAVE"
echo "$LINHA" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

O `command=` faz o SSH **ignorar** o que for pedido e rodar sempre o deploy. As
restrições seguintes fecham túnel, encaminhamento e terminal.

**Teste que a restrição funciona:**

```powershell
ssh -i $HOME\.ssh\amb_deploy_ci ubuntu@SEU_IP "cat /var/www/amb/.env"
```

Se aparecer o deploy rodando em vez do conteúdo do arquivo, está correto. Mesmo
que esse segredo vaze do GitHub, o pior que alguém faz é disparar um deploy do
seu próprio código.

### 16.3 — Os dois segredos no GitHub

**Link:** `https://github.com/SEU_USUARIO/SEU_REPO/settings/secrets/actions`

| Segredo | Conteúdo |
|---|---|
| `SSH_CHAVE_DEPLOY` | o arquivo `amb_deploy_ci` inteiro (a chave **privada**) |
| `SSH_IMPRESSAO_DIGITAL` | a saída de `ssh-keyscan -t ed25519 SEU_IP` |

Para copiar a chave privada sem erro de digitação:

```powershell
Get-Content $HOME\.ssh\amb_deploy_ci -Raw | Set-Clipboard
```

> O segundo segredo é a identidade do servidor. Sem ele, o GitHub entregaria seu
> código a qualquer máquina que respondesse por aquele endereço.

### 16.4 — O fluxo de trabalho

Já vem versionado em `.github/workflows/deploy.yml`. Ele sobe Ubuntu, PHP 8.4 e
MySQL 8 — as mesmas versões do servidor —, roda a suíte e só publica se passar.

> ⚠️ **Duas armadilhas encontradas na prática:**
>
> **O MySQL do container mente sobre estar pronto.** O teste de saúde
> `mysqladmin ping` responde antes de a inicialização terminar; o banco e as
> permissões podem ainda não existir. O fluxo tem um passo que espera uma
> consulta real funcionar.
>
> **`php artisan test | tail` esconde falhas.** O código de saída observado passa
> a ser o do `tail`, que é sempre zero. Descobrimos assim um provedor de dados que
> passava três argumentos para um teste que recebia um — o PHPUnit trata como
> aviso e **encerra com código 1**, reprovando a suíte inteira com todos os
> testes verdes. Verifique sempre com `php artisan test > /dev/null; echo $?`.

### 16.5 — Ler o erro quando o CI falha

O log do Actions só é legível por quem tem permissão de administrador no
repositório. Por isso o fluxo publica o trecho da falha como **comentário do
commit**, que é público:

```bash
curl -s "https://api.github.com/repos/SEU_USUARIO/SEU_REPO/commits/SHA/comments"
```

---

## ETAPA 17 — Agendador de tarefas

Uma única linha de cron chama o agendador do Laravel; o que roda fica versionado
em `routes/console.php`, e não espalhado em arquivos soltos do servidor.

```bash
echo '* * * * * ubuntu cd /var/www/amb && php artisan schedule:run >> /dev/null 2>&1' \
  | sudo tee /etc/cron.d/amb-agendador
sudo chmod 644 /etc/cron.d/amb-agendador

cd /var/www/amb && php artisan schedule:list
```

Hoje ele descarta, de madrugada, o histórico de acessos com mais de 6 meses e as
sessões vencidas.

---

## ETAPA 18 — Caçador de capacidade Ampere (opcional)

Só faz sentido se você ficou na `E2.1.Micro` de 1 GB e quer subir para a Ampere
sem pagar nada.

### Por que não existe botão de "aumentar"

A Oracle **tem** a opção *Edit shape*, e ela funciona dentro da mesma família.
Mas AMD e Ampere são **arquiteturas diferentes** — `x86_64` e `aarch64`. É a
mesma distância entre um programa de PC e um de celular: o sistema instalado no
disco foi compilado para um conjunto de instruções e não roda no outro.

Precisa ser uma máquina **nova**, com a imagem ARM. Com backup testado,
`deploy.sh` e este roteiro, reconstruir é repetir os passos — e a máquina atual
fica no ar o tempo todo, porque só se reaponta o DuckDNS no final.

### Instalar a interface de linha de comando da Oracle

```bash
sudo apt-get install -y python3-pip python3-venv
python3 -m venv ~/.oci-cli-venv
~/.oci-cli-venv/bin/pip install --quiet --upgrade pip oci-cli
sudo ln -sf ~/.oci-cli-venv/bin/oci /usr/local/bin/oci
oci --version
```

### Gerar a chave de API

```bash
mkdir -p ~/.oci && chmod 700 ~/.oci
openssl genrsa -out ~/.oci/api_oracle.pem 2048
chmod 600 ~/.oci/api_oracle.pem
openssl rsa -pubout -in ~/.oci/api_oracle.pem -out ~/.oci/api_oracle_public.pem
cat ~/.oci/api_oracle_public.pem
```

### Autorizar na Oracle

**Link:** https://cloud.oracle.com/identity/domains/my-profile/api-keys
→ **Add API key**

> ⚠️ **Não cole o texto.** A colagem estraga as quebras de linha e a Oracle
> responde:
>
> ```
> Erro de API
> A chave tem um tipo de chave pública inválido {0}
> ```
>
> Use a opção **"Choose public key file"** e envie o arquivo. Para trazê-lo do
> servidor:
>
> ```powershell
> scp -i $HOME\.ssh\amb_oracle ubuntu@SEU_IP:/home/ubuntu/.oci/api_oracle_public.pem $HOME\Desktop\
> ```

Ao final aparece a janela **Configuration file preview** com o bloco `[DEFAULT]`.
Grave-o no servidor:

```bash
cat > ~/.oci/config <<'CFG'
[DEFAULT]
user=ocid1.user.oc1..xxxxx
fingerprint=xx:xx:xx:...
tenancy=ocid1.tenancy.oc1..xxxxx
region=sa-vinhedo-1
key_file=/home/ubuntu/.oci/api_oracle.pem
CFG
chmod 600 ~/.oci/config

# testando
oci iam region-subscription list --output table
```

### Descobrir os identificadores

```bash
export SUPPRESS_LABEL_WARNING=True
TENANCY=$(grep '^tenancy=' ~/.oci/config | cut -d= -f2)

# domínios de disponibilidade
oci iam availability-domain list --compartment-id "$TENANCY" --query 'data[].name'

# sub-rede da máquina atual
INST=$(oci compute instance list --compartment-id "$TENANCY" \
       --lifecycle-state RUNNING --query 'data[0].id' --raw-output)
oci compute instance list-vnics --instance-id "$INST" --query 'data[0]."subnet-id"'

# imagem Ubuntu 24.04 para ARM
oci compute image list --compartment-id "$TENANCY" \
  --operating-system "Canonical Ubuntu" --operating-system-version "24.04" \
  --shape VM.Standard.A1.Flex \
  --query 'data[0:3].{nome:"display-name",id:id}' --output table
```

### Configurar e agendar

```bash
cat > ~/.oci/ampere.conf <<CFG
COMPARTIMENTO=$TENANCY
SUBREDE=ocid1.subnet.oc1...
IMAGEM=ocid1.image.oc1...
NOME=serv-ampere
DISCO_GB=50
DOMINIOS="LtOi:SA-VINHEDO-1-AD-1"
CONFIGURACOES="4:24 2:12 1:6"
CHAVE_SSH="$(grep -v 'command=' ~/.ssh/authorized_keys | head -1)"
CFG
chmod 600 ~/.oci/ampere.conf

sudo cp /var/www/amb/scripts/tentar-ampere.sh /usr/local/bin/
sudo chmod 755 /usr/local/bin/tentar-ampere.sh
/usr/local/bin/tentar-ampere.sh --testar

# a cada 7 minutos; o flock impede que uma tentativa comece antes da anterior terminar
sudo tee /etc/cron.d/tentar-ampere >/dev/null <<'CRON'
SUPPRESS_LABEL_WARNING=True
*/7 * * * * ubuntu flock -n /tmp/ampere.lock /usr/local/bin/tentar-ampere.sh >/dev/null 2>&1
CRON
sudo chmod 644 /etc/cron.d/tentar-ampere
```

O script pede 4 OCPUs e 24 GB primeiro e vai reduzindo para 2/12 e 1/6. Vale
aceitar o que houver: **1 OCPU e 6 GB já é seis vezes a memória da `E2.1.Micro`**,
e ampliar depois é dentro da mesma família, onde o redimensionamento funciona.
Ao conseguir, ele grava o endereço da nova máquina e **se desagenda**.

Acompanhe com `tail -f ~/.oci/ampere.log`.

---
---

# Verificação final

Rode tudo isto. Cada linha deve responder o esperado:

```bash
# o site responde por HTTPS
curl -s -o /dev/null -w "site: %{http_code}\n" https://SEU_DOMINIO.duckdns.org/entrar
# esperado: 200

# http redireciona para https
curl -s -o /dev/null -w "redirect: %{http_code}\n" http://SEU_DOMINIO.duckdns.org/entrar
# esperado: 301

# o certificado renova
sudo certbot renew --dry-run --no-random-sleep-on-renew
# esperado: "Congratulations, all simulated renewals succeeded"

# o backup roda e o arquivo tem conteúdo
sudo /usr/local/bin/backup-amb.sh && sudo ls -lh /var/backups/amb/

# o firewall interno está na ordem certa
sudo iptables -L INPUT --line-numbers -n | head -8
# esperado: ACCEPT das portas 80 e 443 ANTES do REJECT

# as tarefas automáticas estão agendadas
ls /etc/cron.d/
# esperado: amb-agendador, backup-amb, duckdns

# a memória de troca está ativa
free -h

# o deploy funciona
/var/www/amb/deploy.sh
```

E no navegador: entre no sistema e **gere as três folhas de PDF**. É o teste que
mais exige do servidor — se as folhas de frequência saírem, o resto sai.

---

# Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Interruptor de IPv4 público travado | A sub-rede foi criada pelo formulário da instância, sem Internet Gateway. Refaça pela Etapa 3 |
| Lista de blocos CIDR vazia | Está no botão `Criar VCN` em vez do assistente (armadilha nº 4) |
| `Capacidade insuficiente para a forma` | Falta de estoque de Ampere na região. Use a AMD e veja a Etapa 18 |
| Navegador não abre o site | Portas 80/443 fechadas — confira **os dois** firewalls (Etapas 4 e 8) |
| Site inacessível mesmo com as regras criadas | O `ACCEPT` foi inserido **depois** do `REJECT` no iptables (Etapa 8) |
| `Connection refused` no SSH | IP errado, ou instância ainda provisionando |
| `Permission denied (publickey)` | Chave pública não foi colada na criação da instância |
| Erro 502 no navegador | PHP-FPM parado — `sudo systemctl restart php8.4-fpm` |
| Erro 500 e tela branca | Ver `tail -50 /var/www/amb/storage/logs/laravel.log` |
| `lock file does not contain a compatible set of packages` | PHP do Ubuntu é 8.3; instale o 8.4 pelo PPA (Etapa 9). **Não** rode `composer update` |
| PDF falha só no navegador, funciona no terminal | Limites do PHP-FPM (Etapa 12) |
| `certbot renew` parece travado | Ele dorme até 8 min de propósito. Use `--no-random-sleep-on-renew` |
| `mysqldump: PROCESS privilege` | Falta `--no-tablespaces` (Etapa 15) |
| `git pull` reclama de `package-lock.json` | Foi usado `npm install`; use `npm ci` |
| Deploy diz 404 mas o site está no ar | A verificação usou `Host: localhost`, que não bate com o `server_name` |
| Site fora do ar sem motivo | Instância recuperada por inatividade — ver abaixo |

### Sobre a recuperação por inatividade

A Oracle recolhe máquinas gratuitas **ociosas**. O critério é ficar 7 dias com
CPU, rede **e** memória abaixo de 10% — e basta **um** dos três para escapar.

Um servidor com MySQL rodando usa mais de 50% da memória de uma máquina de 1 GB,
então nunca é considerado ocioso. Sem risco na prática, mas vale conferir:

```bash
free -m | awk 'NR==2{printf "memória em uso: %.0f%%\n", $3/$2*100}'
```

---

# O que é grátis e o que não é

| Recurso | Always Free |
|---|---|
| Ampere `A1.Flex` | 4 OCPUs e 24 GB no total, até 4 máquinas |
| AMD `E2.1.Micro` | 2 máquinas de 1 OCPU e 1 GB |
| Armazenamento em bloco | 200 GB no total, incluindo os discos de boot |
| Tráfego de saída | 10 TB por mês |
| IP público | 2 endereços |
| Banco autônomo | 2 instâncias de 20 GB |
| **Qualquer AMD E3/E4/E5 ou Intel** | ❌ **pago** |
| Política de backup de volume | ❌ **pago** |
| Balanceador de carga (acima do mínimo) | ❌ **pago** |

As duas famílias de máquina são **cotas separadas** — você pode ter as duas ao
mesmo tempo.

---

# Vale para seus outros sistemas

Esta mesma máquina comporta outros projetos, cada um em seu subdomínio,
compartilhando Nginx e MySQL. Depois de configurada, acrescentar um site é
questão de um arquivo em `/etc/nginx/sites-available/`, um banco novo e um
subdomínio no DuckDNS.

Para cada sistema novo, os passos que se repetem são: Etapa 10 (banco), Etapa 11
(publicar), Etapa 13 (subdomínio) e Etapa 14 (certificado). O resto — servidor,
firewall, swap, backup, agendador — já está pronto e é compartilhado.
