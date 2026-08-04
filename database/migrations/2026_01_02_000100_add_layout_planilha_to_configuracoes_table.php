<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            // Layout da planilha mensal de plantoes:
            //   classico -> colunas de placa e lotacao a esquerda, como no
            //               documento historico da secretaria
            //   agrupado -> faixa de identificacao por ambulancia, que libera
            //               espaco para nome e telefone
            $table->string('layout_planilha', 20)->default('classico')->after('rodape_documentos');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn('layout_planilha');
        });
    }
};
