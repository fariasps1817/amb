<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Escala mensal: uma por mes de referencia.
        Schema::create('escalas', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');

            $table->string('status', 20)->default('rascunho'); // rascunho | publicada | arquivada
            $table->text('observacao')->nullable();

            $table->timestamp('gerada_em')->nullable();
            $table->timestamp('publicada_em')->nullable();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ano', 'mes']);
            $table->index(['ano', 'mes', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalas');
    }
};
