<?php

namespace App\Support;

/**
 * Normalizacao e formatacao de telefones brasileiros.
 *
 * Os numeros sao digitados de varias formas ("98692 6853", "(85) 98692-6853",
 * "85986926853"). Aqui centralizamos a conversao para exibicao e para o formato
 * E.164 exigido pelo WhatsApp.
 */
final class Telefone
{
    /** Mantem apenas digitos. */
    public static function digitos(?string $numero): string
    {
        return preg_replace('/\D+/', '', (string) $numero) ?? '';
    }

    /**
     * Formata para leitura: (85) 98692-6853 ou 98692-6853 quando sem DDD.
     */
    public static function formatar(?string $numero): string
    {
        $d = self::digitos($numero);

        return match (strlen($d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            9 => sprintf('%s-%s', substr($d, 0, 5), substr($d, 5)),
            8 => sprintf('%s-%s', substr($d, 0, 4), substr($d, 4)),
            0 => '',
            default => $d,
        };
    }

    /**
     * Converte para o formato aceito pelo WhatsApp: DDI + DDD + numero, sem
     * simbolos (ex.: 5585986926853).
     *
     * Quando o numero cadastrado nao traz DDD, aplica o DDD padrao do sistema.
     * Retorna null quando nao ha digitos suficientes para um telefone valido.
     */
    public static function paraWhatsapp(?string $numero, ?string $ddi = null, ?string $dddPadrao = null): ?string
    {
        $d = self::digitos($numero);

        if ($d === '') {
            return null;
        }

        $ddi = self::digitos($ddi ?? (string) config('whatsapp.ddi', '55'));
        $ddd = self::digitos($dddPadrao ?? (string) config('whatsapp.ddd_padrao', '85'));

        // Numero ja informado com o DDI na frente (13 ou 12 digitos).
        if (str_starts_with($d, $ddi) && strlen($d) >= strlen($ddi) + 10) {
            return $d;
        }

        // Celular ou fixo com DDD.
        if (strlen($d) === 11 || strlen($d) === 10) {
            return $ddi.$d;
        }

        // Sem DDD: completa com o DDD padrao.
        if (strlen($d) === 9 || strlen($d) === 8) {
            return $ddi.$ddd.$d;
        }

        return null;
    }

    /** Link wa.me com a mensagem ja preenchida. */
    public static function linkWhatsapp(?string $numero, string $mensagem = ''): ?string
    {
        $destino = self::paraWhatsapp($numero);

        if ($destino === null) {
            return null;
        }

        $url = "https://wa.me/{$destino}";

        return $mensagem === '' ? $url : $url.'?text='.rawurlencode($mensagem);
    }
}
