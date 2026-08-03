<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Controle das mensagens de WhatsApp enviadas a cada motorista com os
        // dias de plantao do mes. Serve de comprovacao ("a escala foi
        // comunicada") e evita envio duplicado.
        Schema::create('escala_mensagens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();

            $table->string('telefone', 20)->nullable();
            $table->text('texto');

            $table->string('status', 20)->default('pendente'); // pendente | enviada | erro
            $table->string('driver', 20)->nullable();          // link | cloud | evolution
            $table->timestamp('enviada_em')->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retorno')->nullable();

            $table->timestamps();

            $table->unique(['escala_id', 'motorista_id']);
            $table->index(['escala_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escala_mensagens');
    }
};
