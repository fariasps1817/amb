#!/bin/bash
#
# Panorama do servidor em uma tela.
#
# Uso, de qualquer lugar:
#     ssh -i ~/.ssh/amb_oracle ubuntu@SEU_IP status
#
# Tambem aparece automaticamente a cada acesso por SSH.

set -uo pipefail

verde()   { printf '\033[32m%s\033[0m' "$1"; }
amarelo() { printf '\033[33m%s\033[0m' "$1"; }
vermelho(){ printf '\033[31m%s\033[0m' "$1"; }

linha() { printf '  %-26s %s\n' "$1" "$2"; }

echo ""
echo "  ╭──────────────────────────────────────────────────────────╮"
echo "  │  SERVIDOR — $(date '+%d/%m/%Y %H:%M')                              │"
echo "  ╰──────────────────────────────────────────────────────────╯"
echo ""

# --- Sistema no ar ---------------------------------------------------------

ENDERECO=$(grep -oP '^APP_URL=https?://\K.*' /var/www/amb/.env 2>/dev/null | tr -d '\r/')
CODIGO=$(curl -s -m 10 -o /dev/null -w '%{http_code}' "https://${ENDERECO}/entrar" 2>/dev/null)

if [ "$CODIGO" = "200" ]; then
    linha "Sistema" "$(verde "no ar")  https://${ENDERECO}"
else
    linha "Sistema" "$(vermelho "HTTP ${CODIGO:-sem resposta}")  https://${ENDERECO}"
fi

VERSAO=$(cd /var/www/amb && git log -1 --format='%h %s' 2>/dev/null | cut -c1-46)
linha "Versão publicada" "$VERSAO"

# --- Certificado -----------------------------------------------------------

VENCE=$(sudo openssl x509 -enddate -noout -in "/etc/letsencrypt/live/${ENDERECO}/fullchain.pem" 2>/dev/null | cut -d= -f2)
if [ -n "$VENCE" ]; then
    DIAS=$(( ( $(date -d "$VENCE" +%s) - $(date +%s) ) / 86400 ))
    [ "$DIAS" -gt 20 ] && COR=$(verde "$DIAS dias") || COR=$(amarelo "$DIAS dias")
    linha "Certificado HTTPS" "vence em $COR"
fi

# --- Recursos --------------------------------------------------------------

echo ""
free -m | awk 'NR==2{printf "  %-26s %s MB de %s MB usados (%.0f%%)\n", "Memória", $3, $2, $3/$2*100}'
free -m | awk 'NR==3{if ($2>0) printf "  %-26s %s MB de %s MB\n", "Memória de troca", $3, $2}'
df -h / | awk 'NR==2{printf "  %-26s %s de %s usados (%s)\n", "Disco", $3, $2, $5}'
uptime | sed 's/.*load average: /Carga média: /' | awk '{printf "  %-26s %s\n", "Carga do processador", $3" "$4" "$5}'

# --- Backup ----------------------------------------------------------------

echo ""
ULTIMO=$(sudo ls -t /var/backups/amb/*.sql.gz 2>/dev/null | head -1)
if [ -n "$ULTIMO" ]; then
    QUANDO=$(date -r "$ULTIMO" '+%d/%m %H:%M')
    TAMANHO=$(du -h "$ULTIMO" 2>/dev/null | cut -f1)
    QUANTOS=$(sudo ls /var/backups/amb/*.sql.gz 2>/dev/null | wc -l)
    linha "Último backup" "$(verde "$QUANDO")  ($TAMANHO · $QUANTOS guardados)"
else
    linha "Último backup" "$(vermelho "nenhum")"
fi

# --- Caça à máquina Ampere -------------------------------------------------

echo ""
if [ -f "$HOME/.oci/ampere-criada" ]; then
    linha "Máquina Ampere" "$(verde "CONSEGUIMOS!")"
    linha "" "$(cat "$HOME/.oci/ampere-criada")"
elif [ -f /etc/cron.d/tentar-ampere ]; then
    TENTATIVAS=$(cat "$HOME/.oci/ampere.contador" 2>/dev/null || echo 0)
    CONFIGS=$(grep -oP 'CONFIGURACOES="\K[^"]+' "$HOME/.oci/ampere.conf" 2>/dev/null)
    linha "Máquina Ampere" "$(amarelo "procurando")  ($TENTATIVAS tentativas)"
    linha "  buscando" "$CONFIGS  (núcleos:GB)"

    if [ -f "$HOME/.oci/ampere.pausa" ]; then
        ATE=$(date -d "@$(cat "$HOME/.oci/ampere.pausa")" '+%H:%M' 2>/dev/null)
        linha "  em pausa até" "$ATE (limite de chamadas da Oracle)"
    fi

    ULTIMA=$(tail -1 "$HOME/.oci/ampere.log" 2>/dev/null | cut -c1-52)
    [ -n "$ULTIMA" ] && linha "  último registro" "$ULTIMA"
else
    linha "Máquina Ampere" "busca desativada"
fi

echo ""
