<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pedidos de transporte de paciente que chegam de fora do sistema. Por
        // enquanto so a alta hospitalar (do hospital ate a residencia em
        // Cascavel); o campo "tipo" deixa lugar para a consulta eletiva.
        Schema::create('solicitacoes_transporte', function (Blueprint $table) {
            $table->id();

            // Numero informado a quem pediu. Sai do id, depois de gravar.
            $table->string('protocolo', 20)->nullable()->unique();
            $table->string('tipo', 30)->default('alta_hospitalar');
            $table->string('status', 20)->default('aguardando');

            // Identifica o envio do formulario: o reenvio do mesmo envio (toque
            // duplo, internet que cai antes da resposta) nao vira outro pedido.
            $table->uuid('chave_envio')->nullable()->unique();

            // Sempre do relogio do servidor, nunca do formulario.
            $table->timestamp('solicitada_em');

            $table->string('hospital_nome', 150);
            $table->string('hospital_cidade', 100);
            $table->string('hospital_bairro', 100);

            $table->string('solicitante_nome', 120);
            $table->string('solicitante_funcao', 80);
            $table->string('solicitante_telefone', 20);
            // A pagina e aberta: quem envia declara ser do hospital, e o setor
            // confirma por telefone.
            $table->boolean('declaracao_profissional')->default(false);

            $table->string('paciente_nome', 150);
            $table->unsignedSmallInteger('paciente_idade');
            $table->string('paciente_idade_unidade', 5)->default('anos'); // anos | meses
            $table->string('acompanhante_nome', 150)->nullable();
            $table->string('contato_telefone_1', 20);
            $table->string('contato_telefone_2', 20)->nullable();

            $table->string('destino_endereco', 200);
            $table->string('destino_bairro', 100);
            $table->string('destino_referencia', 200)->nullable();
            $table->string('destino_municipio', 60);

            $table->string('locomocao', 20); // App\Enums\Locomocao
            $table->boolean('consciente')->default(false);
            $table->boolean('orientado')->default(false);
            $table->boolean('puerpera_com_rn')->default(false);
            $table->boolean('necessita_oxigenio')->default(false);
            $table->boolean('necessita_aspirador')->default(false);
            $table->boolean('necessita_tecnico')->default(false);
            $table->text('observacoes_paciente')->nullable();

            // Mesmo paciente e mesmo telefone em poucas horas: entra, mas avisa.
            $table->foreignId('possivel_duplicada_de')->nullable()->constrained('solicitacoes_transporte')->nullOnDelete();

            // De onde veio o envio, para o caso de abuso da pagina aberta.
            $table->string('ip', 45)->nullable();
            $table->string('navegador')->nullable();

            // Desfecho, registrado pela coordenacao.
            $table->foreignId('motorista_id')->nullable()->constrained('motoristas')->nullOnDelete();
            $table->dateTime('atendida_em')->nullable();
            $table->string('motivo_nao_atendimento', 30)->nullable(); // App\Enums\MotivoNaoAtendimento
            $table->text('observacoes_atendimento')->nullable();
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registrada_em')->nullable();

            $table->timestamps();

            $table->index(['status', 'solicitada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_transporte');
    }
};
