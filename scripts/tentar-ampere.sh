#!/bin/bash
#
# Tenta criar a maquina gratuita Ampere (ARM) na Oracle Cloud ate conseguir.
#
# A camada Always Free da 4 OCPUs e 24 GB de memoria em maquinas Ampere, mas
# elas vivem esgotadas nas regioes do Brasil. A vaga aparece e some em minutos,
# entao tentar na mao e loteria. Este script tenta de tempos em tempos, sozinho.
#
# Estrategia: pede primeiro a maior configuracao e vai reduzindo. Uma maquina
# de 1 OCPU e 6 GB ja seria seis vezes a memoria da atual, entao vale aceitar
# o que aparecer -- dentro da mesma familia da para ampliar depois, quando
# houver capacidade, sem precisar reinstalar nada.
#
# Uso:
#   /usr/local/bin/tentar-ampere.sh          uma tentativa
#   /usr/local/bin/tentar-ampere.sh --testar  so valida a configuracao
#
# A configuracao fica em ~/.oci/ampere.conf, fora do repositorio, porque
# carrega os identificadores da conta.

set -uo pipefail

CONFIGURACAO="$HOME/.oci/ampere.conf"
LOG="$HOME/.oci/ampere.log"
MARCADOR="$HOME/.oci/ampere-criada"
CRON=/etc/cron.d/tentar-ampere

registrar() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"
}

# Ja conseguimos numa execucao anterior: nao ha o que fazer.
if [ -f "$MARCADOR" ]; then
    exit 0
fi

if [ ! -f "$CONFIGURACAO" ]; then
    echo "Falta $CONFIGURACAO" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$CONFIGURACAO"

: "${COMPARTIMENTO:?}" "${SUBREDE:?}" "${IMAGEM:?}" "${NOME:?}" "${CHAVE_SSH:?}"
: "${DOMINIOS:?}" "${CONFIGURACOES:?}" "${DISCO_GB:=50}"

if [ "${1:-}" = "--testar" ]; then
    echo "Compartimento: $COMPARTIMENTO"
    echo "Sub-rede:      $SUBREDE"
    echo "Imagem:        $IMAGEM"
    echo "Dominios:      $DOMINIOS"
    echo "Configuracoes: $CONFIGURACOES"
    echo ""
    echo "Testando o acesso a conta..."
    oci iam availability-domain list --compartment-id "$COMPARTIMENTO" \
        --query 'data[].name' --raw-output 2>&1 | head -5
    exit $?
fi

# Cada configuracao e um par "nucleos:memoria". A ordem importa: comecamos
# pela maior e vamos reduzindo ate alguma caber na capacidade disponivel.
for dominio in $DOMINIOS; do
    for par in $CONFIGURACOES; do
        nucleos="${par%%:*}"
        memoria="${par##*:}"

        saida=$(oci compute instance launch \
            --compartment-id "$COMPARTIMENTO" \
            --availability-domain "$dominio" \
            --shape VM.Standard.A1.Flex \
            --shape-config "{\"ocpus\":$nucleos,\"memoryInGBs\":$memoria}" \
            --image-id "$IMAGEM" \
            --subnet-id "$SUBREDE" \
            --assign-public-ip true \
            --display-name "$NOME" \
            --boot-volume-size-in-gbs "$DISCO_GB" \
            --metadata "{\"ssh_authorized_keys\":\"$CHAVE_SSH\"}" \
            --wait-for-state RUNNING \
            --wait-interval-seconds 15 \
            2>&1)

        if [ $? -eq 0 ]; then
            identificador=$(echo "$saida" | python3 -c \
                'import sys,json; print(json.load(sys.stdin)["data"]["id"])' 2>/dev/null)

            registrar "CONSEGUIMOS  ${nucleos} OCPU / ${memoria} GB em $dominio"
            registrar "instancia: $identificador"

            echo "$identificador" > "$MARCADOR"

            # Missao cumprida: o script se desagenda para nao criar uma segunda.
            [ -f "$CRON" ] && sudo rm -f "$CRON" && registrar "agendamento removido"

            ip=$(oci compute instance list-vnics --instance-id "$identificador" \
                 --query 'data[0]."public-ip"' --raw-output 2>/dev/null)
            registrar "endereco: ${ip:-ainda sem IP}"

            echo "Maquina Ampere criada: ${nucleos} OCPU / ${memoria} GB, IP ${ip:-pendente}"
            exit 0
        fi

        # "Out of host capacity" e o caso comum e esperado: nao e erro de
        # configuracao, e falta de estoque. Passamos para a proxima tentativa
        # sem encher o log.
        if echo "$saida" | grep -qiE 'out of (host )?capacity|OutOfCapacity'; then
            continue
        fi

        # Limite da conta atingido: insistir nao adianta e gastaria chamadas.
        if echo "$saida" | grep -qiE 'LimitExceeded|QuotaExceeded'; then
            registrar "PARANDO — limite da conta atingido em ${nucleos}/${memoria}:"
            registrar "$(echo "$saida" | head -3)"
            continue
        fi

        # Qualquer outro erro merece registro: pode ser identificador errado,
        # chave de API vencida ou permissao faltando.
        registrar "ERRO em ${nucleos}/${memoria} @ $dominio:"
        registrar "$(echo "$saida" | head -5)"
    done
done

# Log resumido nas tentativas sem sucesso: uma linha por hora basta para saber
# que o script continua vivo, sem gerar um arquivo gigante.
ultima=$(grep -c 'sem capacidade' "$LOG" 2>/dev/null || echo 0)
if [ ! -f "$LOG" ] || [ "$(date +%H)" != "$(date -r "$LOG" +%H 2>/dev/null)" ]; then
    registrar "sem capacidade ainda (tentativa nº $((ultima + 1)))"
fi

exit 1
