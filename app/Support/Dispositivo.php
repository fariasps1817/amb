<?php

namespace App\Support;

/**
 * Traduz a identificacao do navegador em algo que se le: "Celular · Android · Chrome".
 *
 * E uma aproximacao, porque o navegador diz o que quiser sobre si: o iPad recente
 * se apresenta como Mac, por exemplo. Serve para reconhecer o aparelho, nao como prova.
 */
final class Dispositivo
{
    public static function descrever(?string $navegador): string
    {
        if (blank($navegador)) {
            return 'Dispositivo desconhecido';
        }

        return collect([
            self::tipo($navegador),
            self::sistema($navegador),
            self::programa($navegador),
        ])->filter()->implode(' · ');
    }

    private static function tipo(string $navegador): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet/i', $navegador) => 'Tablet',
            (bool) preg_match('/Android/i', $navegador) && ! preg_match('/Mobile/i', $navegador) => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android/i', $navegador) => 'Celular',
            default => 'Computador',
        };
    }

    private static function sistema(string $navegador): ?string
    {
        return match (true) {
            str_contains($navegador, 'iPhone'), str_contains($navegador, 'iPod') => 'iPhone',
            str_contains($navegador, 'iPad') => 'iPad',
            str_contains($navegador, 'Android') => 'Android',
            str_contains($navegador, 'Windows') => 'Windows',
            str_contains($navegador, 'Macintosh') => 'Mac',
            str_contains($navegador, 'CrOS') => 'Chromebook',
            str_contains($navegador, 'Linux') => 'Linux',
            default => null,
        };
    }

    /** A ordem importa: quase todos dizem tambem "Chrome" e "Safari" por compatibilidade. */
    private static function programa(string $navegador): ?string
    {
        return match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $navegador) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $navegador) => 'Opera',
            str_contains($navegador, 'SamsungBrowser') => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/', $navegador) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/', $navegador) => 'Chrome',
            str_contains($navegador, 'Safari') => 'Safari',
            default => null,
        };
    }
}
