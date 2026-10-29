<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um mes pode ter varias propostas em rascunho enquanto aguarda a
        // aprovacao dos superiores, mas no maximo uma escala oficial
        // (publicada ou arquivada).
        Schema::table('escalas', function (Blueprint $table) {
            $table->dropUnique(['ano', 'mes']);

            // "Proposta B", "Com as 2 contratacoes". Vazio na escala unica do mes.
            $table->string('nome', 60)->nullable()->after('status');
            // De qual escala esta proposta foi duplicada.
            $table->foreignId('origem_id')->nullable()->after('nome')->constrained('escalas')->nullOnDelete();

            // Registro da aprovacao, informado ao publicar.
            $table->string('aprovada_por', 120)->nullable()->after('publicada_em');
            $table->date('aprovada_em')->nullable()->after('aprovada_por');
            $table->foreignId('publicada_por')->nullable()->after('aprovada_em')->constrained('users')->nullOnDelete();

            // A garantia de uma oficial por mes fica no proprio banco: a coluna
            // so tem valor para publicada e arquivada, e o indice unico ignora
            // os nulos. Duas publicacoes simultaneas nao passam.
            $table->unsignedInteger('mes_oficial')
                ->nullable()
                ->storedAs("case when status in ('publicada', 'arquivada') then ano * 100 + mes end");
            $table->unique('mes_oficial');
        });
    }

    public function down(): void
    {
        Schema::table('escalas', function (Blueprint $table) {
            $table->dropUnique(['mes_oficial']);
            $table->dropColumn('mes_oficial');
            $table->dropConstrainedForeignId('publicada_por');
            $table->dropColumn(['aprovada_por', 'aprovada_em']);
            $table->dropConstrainedForeignId('origem_id');
            $table->dropColumn('nome');

            // Volta a uma por mes: falha se ainda houver propostas paralelas,
            // de proposito -- apagar dados para voltar o esquema seria pior.
            $table->unique(['ano', 'mes']);
        });
    }
};
