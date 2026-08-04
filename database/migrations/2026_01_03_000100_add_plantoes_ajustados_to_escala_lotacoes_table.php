<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escala_lotacoes', function (Blueprint $table) {
            // Quantidade de plantoes informada manualmente pelo operador, que
            // substitui a contagem da escala na coluna PLANTOES do documento.
            //
            // Fica em uma coluna separada de plantoes_previstos de proposito: a
            // contagem automatica e refeita a cada geracao de plantoes e
            // sobrescreveria um valor digitado. Aqui o ajuste sobrevive, e
            // limpa-lo devolve o numero calculado.
            //
            // Caso tipico: motorista com 8 plantoes previstos que faltou um dia
            // — o RH precisa ver 7.
            $table->unsignedSmallInteger('plantoes_ajustados')->nullable()->after('plantoes_previstos');
        });
    }

    public function down(): void
    {
        Schema::table('escala_lotacoes', function (Blueprint $table) {
            $table->dropColumn('plantoes_ajustados');
        });
    }
};
