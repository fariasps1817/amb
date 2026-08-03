<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motoristas', function (Blueprint $table) {
            $table->id();

            $table->string('nome_completo');
            // Nome curto ou tratamento usado na planilha da escala, onde o
            // espaco e minimo (ex.: "JOAO BERNARDO" para "JOAO BERNARDO DE
            // OLIVEIRA").
            $table->string('nome_curto', 60);

            $table->string('cpf', 14)->nullable();
            $table->date('data_nascimento')->nullable();

            $table->string('vinculo', 20)->default('contrato'); // efetivo | contrato
            $table->date('vinculo_inicio')->nullable();
            $table->date('vinculo_fim')->nullable(); // obrigatorio para contrato temporario

            $table->string('cnh_numero', 20)->nullable();
            $table->string('cnh_categoria', 10)->nullable();
            $table->date('cnh_validade')->nullable();

            $table->string('telefone_1', 20)->nullable();
            $table->string('telefone_2', 20)->nullable();

            $table->string('matricula', 30)->nullable();
            $table->string('status', 20)->default('ativo'); // ativo | inativo
            $table->text('observacao')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('cpf');
            $table->index('status');
            $table->index('nome_completo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motoristas');
    }
};
