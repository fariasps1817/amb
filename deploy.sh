#!/bin/bash
#
# Atualiza o sistema no servidor com a ultima versao publicada no GitHub.
#
# Uso, ja conectado ao servidor por SSH:
#     /var/www/amb/deploy.sh
#
# O que ele faz, em ordem:
#   1. faz um backup do banco antes de qualquer coisa
#   2. poe o site em manutencao, para ninguem gravar dados no meio da troca
#   3. baixa a nova versao do GitHub
#   4. atualiza as dependencias (PHP e JavaScript)
#   5. aplica as migracoes do banco
#   6. recompila o CSS e o JavaScript
#   7. regenera os caches do Laravel
#   8. tira o site da manutencao
#
# Se qualquer etapa falhar, o script para e tira o site da manutencao,
# deixando a versao anterior no ar.

set -euo pipefail

APP=/var/www/amb
cd "$APP"

# Mesmo que algo falhe no meio, o site nao pode ficar preso em manutencao.
encerrar() {
    if [ -f "$APP/storage/framework/down" ]; then
        php artisan up >/dev/null 2>&1 || true
        echo ""
        echo "!! O deploy falhou. O site voltou ao ar na versao anterior ($ANTES)."
        echo "   Para desfazer completamente:  git reset --hard $ANTES && $APP/deploy.sh"
    fi
}
trap encerrar EXIT

ANTES=$(git rev-parse --short HEAD)

echo "=================================================="
echo " Atualizando o sistema de ambulancias"
echo " versao atual: $ANTES"
echo "=================================================="

echo ""
echo "[1/8] Backup do banco antes de mexer em qualquer coisa"
sudo /usr/local/bin/backup-amb.sh
echo "      guardado em /var/backups/amb/"

echo ""
echo "[2/8] Colocando o site em manutencao"
php artisan down --render="errors::503" --retry=60 >/dev/null

echo ""
echo "[3/8] Baixando a nova versao do GitHub"
git fetch --quiet origin main
DEPOIS=$(git rev-parse --short origin/main)

if [ "$ANTES" = "$DEPOIS" ]; then
    echo "      Ja esta na ultima versao. Nada a fazer."
    php artisan up >/dev/null
    trap - EXIT
    exit 0
fi

git merge --ff-only origin/main
echo "      $ANTES -> $DEPOIS"
git log --oneline "$ANTES..$DEPOIS" | sed 's/^/        /'

echo ""
echo "[4/8] Atualizando dependencias"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
echo "      PHP ok"
# npm ci instala exatamente o que esta no package-lock.json e nao o reescreve.
npm ci --silent
echo "      JavaScript ok"

echo ""
echo "[5/8] Aplicando migracoes do banco"
php artisan migrate --force

echo ""
echo "[6/8] Recompilando CSS e JavaScript"
npm run build

echo ""
echo "[7/8] Regenerando os caches"
php artisan optimize:clear >/dev/null
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null
sudo chown -R ubuntu:www-data "$APP/storage" "$APP/bootstrap/cache"
sudo systemctl reload php8.4-fpm
echo "      caches e permissoes ok"

echo ""
echo "[8/8] Tirando o site da manutencao"
php artisan up >/dev/null
trap - EXIT

echo ""
echo "=================================================="
echo " Pronto. No ar na versao $DEPOIS"
echo "=================================================="
echo ""
echo "Conferindo se o site responde:"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' http://localhost/entrar)
if [ "$CODIGO" = "200" ]; then
    echo "  tela de acesso respondeu HTTP 200 — tudo certo"
else
    echo "  ATENCAO: a tela de acesso respondeu HTTP $CODIGO (esperado 200)."
    echo "  Veja o erro em: tail -30 $APP/storage/logs/laravel.log"
    exit 1
fi
