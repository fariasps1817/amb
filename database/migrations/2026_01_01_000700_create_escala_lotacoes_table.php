<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folha de lotacao do mes: uma linha por motorista ativo.
        //
        // Esta e a tabela que garante o requisito de "destino para todos": ao
        // fechar a escala, todo motorista ativo precisa ter uma linha aqui, e
        // essa linha precisa ter OU um posto (esta escalado) OU um tipo de
        // destino administrativo (reserva, ferias, licenca...).
        //
        // Ela tambem alimenta diretamente a lista mensal de ocorrencias, que
        // imprime nome, lotacao, vinculo, plantoes previstos e observacao de
        // todo o efetivo, escalado ou nao.
        Schema::create('escala_lotacoes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();

            // Escalado em um posto...
            $table->foreignId('escala_posto_id')->nullable()->constrained('escala_postos')->cascadeOnDelete();
            // ...na posicao N do ciclo (1 = pega o primeiro dia do posto).
            $table->unsignedTinyInteger('posicao')->nullable();

            // ...ou com destino administrativo.
            $table->string('tipo_destino', 20)->nullable(); // reserva | apoio | ferias | licenca | ...
            $table->foreignId('unidade_apoio_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();

            // Quantidade de plantoes no mes. Calculada pelo gerador para quem
            // esta escalado; editavel para destinos administrativos.
            $table->unsignedSmallInteger('plantoes_previstos')->default(0);

            // Coluna OCORRENCIA da lista mensal ("Inicio em 01/07/2026",
            // "Ferias de 01 a 30/07/26", "Licenca").
            $table->string('observacao')->nullable();

            $table->timestamps();

            // Um motorista tem um unico destino por mes.
            $table->unique(['escala_id', 'motorista_id']);
            // Duas pessoas nao podem ocupar a mesma posicao do mesmo posto.
            $table->unique(['escala_posto_id', 'posicao']);
            $table->index(['escala_id', 'tipo_destino']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escala_lotacoes');
    }
};
