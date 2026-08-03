<?php

namespace Tests\Unit;

use App\Support\Telefone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelefoneTest extends TestCase
{
    #[Test]
    #[DataProvider('numerosParaFormatar')]
    public function formata_numeros_brasileiros(?string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, Telefone::formatar($entrada));
    }

    public static function numerosParaFormatar(): array
    {
        return [
            'celular com DDD' => ['85986926853', '(85) 98692-6853'],
            'celular sem DDD' => ['98692 6853', '98692-6853'],
            'fixo com DDD' => ['8533342244', '(85) 3334-2244'],
            'fixo sem DDD' => ['33342244', '3334-2244'],
            'ja formatado' => ['(85) 98692-6853', '(85) 98692-6853'],
            'vazio' => ['', ''],
            'nulo' => [null, ''],
        ];
    }

    /**
     * O link do WhatsApp exige DDI + DDD + numero sem simbolos. Numeros
     * cadastrados sem DDD recebem o DDD padrao do municipio.
     */
    #[Test]
    #[DataProvider('numerosParaWhatsapp')]
    public function converte_para_o_formato_do_whatsapp(?string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, Telefone::paraWhatsapp($entrada, '55', '85'));
    }

    public static function numerosParaWhatsapp(): array
    {
        return [
            'celular sem DDD recebe o DDD padrao' => ['98692 6853', '5585986926853'],
            'celular com DDD' => ['(85) 98692-6853', '5585986926853'],
            'numero ja com DDI' => ['5585986926853', '5585986926853'],
            'fixo com DDD' => ['8533342244', '558533342244'],
            'curto demais' => ['1234', null],
            'vazio' => ['', null],
            'nulo' => [null, null],
        ];
    }

    #[Test]
    public function monta_o_link_do_whatsapp_com_a_mensagem(): void
    {
        $link = Telefone::linkWhatsapp('98692 6853', 'Plantões: 01/08 e 05/08');

        $this->assertStringStartsWith('https://wa.me/5585986926853?text=', $link);
        $this->assertStringContainsString('01%2F08', $link);
    }

    #[Test]
    public function nao_monta_link_para_numero_invalido(): void
    {
        $this->assertNull(Telefone::linkWhatsapp('123', 'oi'));
    }
}
