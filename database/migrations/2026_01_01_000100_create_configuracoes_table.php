<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identidade institucional aplicada aos cabecalhos e rodapes de todos
        // os documentos gerados em PDF. Registro unico (singleton): a
        // aplicacao sempre trabalha com a linha de id = 1.
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();

            $table->string('municipio')->default('');
            $table->string('prefeitura')->default('');
            $table->string('secretaria')->default('');
            $table->string('setor')->default('');
            $table->string('slogan')->nullable();

            $table->string('endereco')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('cnpj', 18)->nullable();

            $table->string('telefone_1', 20)->nullable();
            $table->string('telefone_2', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('site')->nullable();

            // Caminhos relativos no disco publico (storage/app/public/...).
            $table->string('logo_prefeitura')->nullable();
            $table->string('logo_secretaria')->nullable();
            $table->string('brasao')->nullable();
            $table->string('imagem_ambulancia')->nullable();

            $table->string('responsavel_setor')->nullable();
            $table->string('cargo_responsavel')->nullable();
            $table->string('rodape_documentos')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
    }
};
