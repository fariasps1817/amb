/*
 * Comportamentos gerais da interface.
 *
 * Escrito em JavaScript puro de proposito: este arquivo roda antes de o Alpine
 * (que vem junto com o Livewire) assumir a pagina, e continua funcionando nas
 * telas que nao usam Livewire nenhum.
 */

// ---------------------------------------------------------------------------
// Mascaras de digitacao
// ---------------------------------------------------------------------------

/*
 * Campos marcados com data-mascara aceitam apenas digitos e sao pontuados
 * enquanto o usuario digita. A pontuacao e so visual: o servidor descarta tudo
 * o que nao e digito antes de validar (Telefone::digitos), entao o banco
 * continua guardando o numero limpo.
 */

const soDigitos = (texto) => texto.replace(/\D+/g, '');

const mascaras = {
    cpf: {
        limite: 11,
        formatar(d) {
            if (d.length <= 3) return d;
            if (d.length <= 6) return `${d.slice(0, 3)}.${d.slice(3)}`;
            if (d.length <= 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;

            return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
        },
    },

    telefone: {
        limite: 11,
        formatar(d) {
            if (d.length <= 2) return d;

            // Celular tem 9 digitos depois do DDD; fixo tem 8. So da para saber
            // qual e quando o 11o digito chega -- ate la, pontuamos como fixo.
            const corte = d.length > 10 ? 7 : 6;

            if (d.length <= corte) return `(${d.slice(0, 2)}) ${d.slice(2)}`;

            return `(${d.slice(0, 2)}) ${d.slice(2, corte)}-${d.slice(corte)}`;
        },
    },
};

/*
 * Reescreve o campo ja pontuado, preservando a posicao do cursor.
 *
 * O cursor nao pode ser guardado como indice de caractere: a pontuacao entra e
 * sai sozinha e deslocaria tudo. Contamos quantos DIGITOS ficam antes dele e
 * recolocamos o cursor depois do mesmo tanto de digitos no texto novo. Sem
 * isso, corrigir um numero no meio do campo jogaria o cursor para o fim a cada
 * tecla digitada.
 */
const aplicarMascara = (campo) => {
    const mascara = mascaras[campo.dataset.mascara];

    if (! mascara) return;

    const focado = document.activeElement === campo;
    const posicao = focado ? (campo.selectionStart ?? campo.value.length) : 0;
    const digitosAntes = soDigitos(campo.value.slice(0, posicao)).length;

    const formatado = mascara.formatar(soDigitos(campo.value).slice(0, mascara.limite));

    if (formatado === campo.value) return;

    campo.value = formatado;

    if (! focado) return;

    let contados = 0;
    let novaPosicao = digitosAntes === 0 ? 0 : formatado.length;

    for (let i = 0; i < formatado.length && digitosAntes > 0; i++) {
        if (/\d/.test(formatado[i])) contados++;

        if (contados === digitosAntes) {
            novaPosicao = i + 1;
            break;
        }
    }

    campo.setSelectionRange(novaPosicao, novaPosicao);
};

// Delegado no documento para valer tambem nos campos que aparecem depois, como
// os de um bloco que o Alpine revela ou o Livewire renderiza.
document.addEventListener('input', (evento) => {
    if (evento.target.dataset?.mascara) {
        aplicarMascara(evento.target);
    }
});

// Primeira pontuacao: o valor vem do banco somente com digitos.
const pontuarCamposExistentes = () => {
    document.querySelectorAll('[data-mascara]').forEach(aplicarMascara);
};

document.addEventListener('DOMContentLoaded', pontuarCamposExistentes);
document.addEventListener('livewire:navigated', pontuarCamposExistentes);
