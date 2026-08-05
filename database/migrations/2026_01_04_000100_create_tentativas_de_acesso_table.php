<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro das tentativas de entrada no sistema.
 *
 * O sistema fica exposto na internet e guarda dados pessoais de servidores.
 * Sem este registro nao ha como saber se alguem esta tentando adivinhar senhas:
 * o bloqueio automatico agiria em silencio e ninguem ficaria sabendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tentativas_de_acesso', function (Blueprint $table) {
            $table->id();

            // O que foi digitado no campo usuario, mesmo que nao exista.
            // E justamente o nome inexistente que denuncia uma varredura.
            $table->string('usuario');

            // 45 caracteres acomodam tambem enderecos IPv6.
            $table->string('ip', 45);

            $table->string('navegador', 255)->nullable();
            $table->boolean('sucesso')->default(false);
            $table->string('motivo', 30)->nullable();

            $table->timestamp('created_at')->nullable();

            // Consultas da tela de monitoramento: por origem, por conta e a
            // listagem cronologica.
            $table->index(['ip', 'created_at']);
            $table->index(['usuario', 'created_at']);
            $table->index(['sucesso', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tentativas_de_acesso');
    }
};
