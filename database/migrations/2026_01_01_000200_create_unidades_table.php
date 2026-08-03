<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidades de lotacao / referencia das ambulancias: UPA, postos de
        // saude da praia e do interior, sede etc.
        //
        // O regime de plantao e definido AQUI porque na pratica ele e uma
        // caracteristica da unidade: as ambulancias da UPA operam em 24/72 e as
        // do posto da praia em 24/48. Cada posto de escala herda o regime da
        // unidade, podendo excepcionalmente sobrescrever no mes.
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();

            $table->string('nome');
            $table->string('sigla', 30);
            $table->string('tipo', 40)->nullable(); // UPA, Posto de Saude, Sede...

            $table->string('endereco')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 9)->nullable();

            $table->string('responsavel')->nullable();
            $table->string('cargo_responsavel')->nullable();
            $table->string('telefone_1', 20)->nullable();
            $table->string('telefone_2', 20)->nullable();
            $table->string('email')->nullable();

            // Regime padrao de plantao da unidade (horas de trabalho/descanso).
            $table->unsignedSmallInteger('horas_trabalho')->default(24);
            $table->unsignedSmallInteger('horas_descanso')->default(72);

            $table->unsignedSmallInteger('ordem')->default(0); // ordem de impressao na planilha
            $table->boolean('ativo')->default(true);
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->unique('sigla');
            $table->index(['ativo', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades');
    }
};
