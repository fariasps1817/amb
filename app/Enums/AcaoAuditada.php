<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Cada acao que entra na auditoria. O que vem antes do ponto define a categoria.
 *
 * O tests/Feature/Auditoria/CoberturaDaAuditoriaTest barra rota ou acao de tela
 * nova que ainda nao tenha dito se entra aqui.
 */
enum AcaoAuditada: string
{
    case SessaoEntrou = 'sessao.entrou';
    case SessaoRetomada = 'sessao.retomada';
    case SessaoSaiu = 'sessao.saiu';
    case SessaoInatividade = 'sessao.inatividade';
    case SessaoExpirada = 'sessao.expirada';

    case MotoristaCriado = 'motorista.criado';
    case MotoristaAlterado = 'motorista.alterado';
    case MotoristaInativado = 'motorista.inativado';
    case MotoristaExcluido = 'motorista.excluido';

    case UnidadeCriada = 'unidade.criada';
    case UnidadeAlterada = 'unidade.alterada';
    case UnidadeDesativada = 'unidade.desativada';
    case UnidadeExcluida = 'unidade.excluida';

    case AmbulanciaCriada = 'ambulancia.criada';
    case AmbulanciaAlterada = 'ambulancia.alterada';
    case AmbulanciaDesativada = 'ambulancia.desativada';
    case AmbulanciaExcluida = 'ambulancia.excluida';

    case UsuarioCriado = 'usuario.criado';
    case UsuarioAlterado = 'usuario.alterado';
    case UsuarioExcluido = 'usuario.excluido';

    case ConfiguracaoAlterada = 'configuracao.alterada';
    case ConfiguracaoImagemRemovida = 'configuracao.imagem_removida';

    case EscalaCriada = 'escala.criada';
    case EscalaDuplicada = 'escala.duplicada';
    case EscalaRenomeada = 'escala.renomeada';
    case EscalaGerada = 'escala.gerada';
    case EscalaPublicada = 'escala.publicada';
    case EscalaReaberta = 'escala.reaberta';
    case EscalaArquivada = 'escala.arquivada';
    case EscalaRestaurada = 'escala.restaurada';
    case EscalaExcluida = 'escala.excluida';

    case PostoAdicionado = 'montagem.posto_adicionado';
    case PostoRemovido = 'montagem.posto_removido';
    case PostoUnidadeAlterada = 'montagem.posto_unidade';
    case PostoVigenciaAlterada = 'montagem.posto_vigencia';
    case PostoRotacaoAlterada = 'montagem.posto_rotacao';
    case PostoReordenado = 'montagem.posto_reordenado';
    case MotoristaLotado = 'montagem.lotou';
    case PosicaoLiberada = 'montagem.liberou';
    case PosicaoTrocada = 'montagem.trocou_posicao';
    case VagasPreenchidas = 'montagem.preencheu_vagas';
    case DestinoDefinido = 'montagem.definiu_destino';
    case DestinoLimpo = 'montagem.limpou_destino';
    case DetalhesDaLotacaoAlterados = 'montagem.detalhes';
    case OcorrenciaRemovida = 'montagem.removeu_ocorrencia';
    case RestantesParaReserva = 'montagem.restantes_reserva';

    case DocumentoEmitido = 'documento.emitido';

    case MensagensPreparadas = 'mensagem.preparadas';
    case MensagemConfirmada = 'mensagem.confirmada';
    case MensagemEnviada = 'mensagem.enviada';
    case MensagemFalhou = 'mensagem.falhou';
    case MensagensEmLote = 'mensagem.lote';
    case PlantaoCompartilhado = 'mensagem.plantao';

    case SolicitacaoRecebida = 'solicitacao.recebida';
    case SolicitacaoAtendida = 'solicitacao.atendida';
    case SolicitacaoNaoAtendida = 'solicitacao.nao_atendida';
    case SolicitacaoReaberta = 'solicitacao.reaberta';
    case SolicitacaoExcluida = 'solicitacao.excluida';
    case SolicitacaoCompartilhada = 'solicitacao.compartilhada';

    case AuditoriaExportada = 'auditoria.exportada';

    /** Como uma sessao pode terminar. Sem nenhuma destas, ela ainda esta aberta. */
    public const ENCERRAMENTOS = [self::SessaoSaiu, self::SessaoInatividade, self::SessaoExpirada];

    public function categoria(): CategoriaDeAuditoria
    {
        return match (Str::before($this->value, '.')) {
            'sessao' => CategoriaDeAuditoria::Acesso,
            'motorista', 'unidade', 'ambulancia', 'usuario' => CategoriaDeAuditoria::Cadastro,
            'escala', 'montagem' => CategoriaDeAuditoria::Escala,
            'documento' => CategoriaDeAuditoria::Documento,
            'mensagem' => CategoriaDeAuditoria::Mensagem,
            'solicitacao' => CategoriaDeAuditoria::Solicitacao,
            default => CategoriaDeAuditoria::Sistema,
        };
    }

    public function rotuloDeEncerramento(): string
    {
        return match ($this) {
            self::SessaoSaiu => 'Saiu do sistema',
            self::SessaoInatividade => 'Encerrada por inatividade',
            self::SessaoExpirada => 'Expirou sem sair',
            default => $this->value,
        };
    }
}
