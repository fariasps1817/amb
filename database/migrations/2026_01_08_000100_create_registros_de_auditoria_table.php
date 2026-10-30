<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Linha do tempo do que cada pessoa fez no sistema.
        Schema::create('registros_de_auditoria', function (Blueprint $table) {
            $table->id();

            // Sem chave estrangeira, de proposito, aqui e em escala_id e alvo_id:
            // o registro precisa continuar dizendo quem fez e em que escala mesmo
            // depois de o usuario ou a escala serem excluidos. Pelo mesmo motivo o
            // nome e o login ficam copiados.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('usuario_nome')->nullable();
            $table->string('usuario_login')->nullable();

            $table->string('categoria', 20);
            $table->string('acao', 50);
            $table->string('descricao', 500);

            $table->string('alvo_tipo', 40)->nullable();
            $table->unsignedBigInteger('alvo_id')->nullable();
            $table->unsignedBigInteger('escala_id')->nullable();

            // Lista de {campo, de, para}. Lista e nao objeto: o tipo JSON do MySQL
            // reordena as chaves de um objeto, e os campos sairiam embaralhados.
            $table->json('alteracoes')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('navegador')->nullable();

            // Identifica a sessao, sem ser o id dela: esse e credencial e muda ao entrar.
            $table->uuid('sessao')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['categoria', 'created_at']);
            $table->index(['escala_id', 'created_at']);
            $table->index(['sessao', 'created_at']);
            $table->index(['alvo_tipo', 'alvo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_de_auditoria');
    }
};
