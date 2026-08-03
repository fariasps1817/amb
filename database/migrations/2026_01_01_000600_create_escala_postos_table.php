<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posto de escala = um "bloco" da planilha mensal.
        //
        // Corresponde a combinacao unidade + ambulancia + regime dentro de um
        // mes. Na planilha impressa e o grupo de linhas que compartilha a mesma
        // placa (coluna P) e a mesma lotacao (coluna LOT).
        //
        // Como a ambulancia pode ser remanejada de unidade a cada mes, o
        // vinculo definitivo fica aqui e nao no cadastro do veiculo. Uma mesma
        // unidade pode ter varios postos (varias ambulancias).
        Schema::create('escala_postos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('ambulancia_id')->nullable()->constrained('ambulancias')->nullOnDelete();

            // Regime vigente no mes. Copiado da unidade na criacao do posto e
            // congelado aqui, para que alterar o cadastro da unidade nao
            // reescreva escalas passadas.
            $table->unsignedSmallInteger('horas_trabalho')->default(24);
            $table->unsignedSmallInteger('horas_descanso')->default(72);

            // Rotulo impresso na coluna LOT ("SEDE 1", "GUANACES"). Quando
            // vazio usa a sigla da unidade.
            $table->string('rotulo', 40)->nullable();

            // Primeiro dia de plantao do posto no mes. Permite iniciar a escala
            // no meio do mes (ex.: ambulancia nova a partir de 04/08).
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();

            // Quando verdadeiro, a rotacao continua do ultimo plantao do mes
            // anterior; quando falso, reinicia na posicao 1 no primeiro dia.
            $table->boolean('continuar_rotacao')->default(true);

            $table->unsignedSmallInteger('ordem')->default(0);
            $table->text('observacao')->nullable();

            $table->timestamps();

            // Uma ambulancia nao pode ocupar dois postos no mesmo mes.
            $table->unique(['escala_id', 'ambulancia_id']);
            $table->index(['escala_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escala_postos');
    }
};
