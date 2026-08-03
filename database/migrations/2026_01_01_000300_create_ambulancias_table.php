<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frota. A unidade informada aqui e apenas a lotacao PADRAO (sugestao
        // ao montar a escala). O vinculo definitivo de cada mes fica em
        // escala_postos, permitindo remanejar o veiculo de unidade sem perder o
        // historico dos meses anteriores.
        Schema::create('ambulancias', function (Blueprint $table) {
            $table->id();

            $table->string('placa', 10);
            $table->string('renavam', 20)->nullable();
            $table->string('vinculo', 20)->default('propria'); // propria | alugada

            $table->string('marca', 60)->nullable();
            $table->string('modelo', 60)->nullable();
            $table->unsignedSmallInteger('ano_fabricacao')->nullable();
            $table->unsignedSmallInteger('ano_modelo')->nullable();
            $table->string('tipo', 40)->nullable(); // Basica, UTI, Resgate...

            // Identificacao interna que aparece na planilha ("SEDE 1", "SEDE 2").
            // Permite distinguir duas ambulancias da mesma unidade.
            $table->string('identificacao', 40)->nullable();

            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();

            $table->boolean('ativo')->default(true);
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->unique('placa');
            $table->index(['ativo', 'unidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambulancias');
    }
};
