<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Publicar com pendencias e uma decisao de quem coordena -- a escala
        // precisa sair mesmo com um descanso curto ou uma vaga aberta. Fica
        // registrado o que foi aceito, como estava naquele momento, e o motivo.
        // E daqui que a auditoria vai ler quando existir.
        Schema::table('escalas', function (Blueprint $table) {
            $table->json('pendencias_aceitas')->nullable()->after('publicada_por');
            $table->text('justificativa_publicacao')->nullable()->after('pendencias_aceitas');
        });
    }

    public function down(): void
    {
        Schema::table('escalas', function (Blueprint $table) {
            $table->dropColumn(['pendencias_aceitas', 'justificativa_publicacao']);
        });
    }
};
