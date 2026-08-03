<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plantoes efetivamente gerados: o "X" da planilha mensal.
        //
        // Materializar os plantoes (em vez de calcular sempre na hora) permite
        // ajuste manual de trocas pontuais, serve de ancora para a rotacao do
        // mes seguinte e deixa os documentos e as mensagens de WhatsApp com
        // leitura direta.
        Schema::create('escala_plantoes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $table->foreignId('escala_posto_id')->constrained('escala_postos')->cascadeOnDelete();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();

            $table->date('data');
            $table->unsignedTinyInteger('posicao'); // posicao do ciclo que originou o plantao

            // Hora de entrada e saida impressas na folha de frequencia.
            $table->time('hora_entrada')->default('07:00');
            $table->time('hora_saida')->default('07:00');

            // Marcado quando o plantao foi alterado manualmente apos a geracao
            // automatica (troca entre motoristas, cobertura de falta).
            $table->boolean('ajuste_manual')->default(false);
            $table->string('observacao')->nullable();

            $table->timestamps();

            // Uma ambulancia tem um unico motorista por dia.
            $table->unique(['escala_posto_id', 'data']);
            $table->index(['escala_id', 'data']);
            $table->index(['motorista_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escala_plantoes');
    }
};
