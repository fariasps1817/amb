<?php

namespace App\Models;

use App\Support\Telefone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem de WhatsApp com os dias de plantao de um motorista no mes.
 */
#[Fillable([
    'escala_id', 'motorista_id', 'telefone', 'texto',
    'status', 'driver', 'enviada_em', 'enviada_por', 'retorno',
])]
class EscalaMensagem extends Model
{
    protected $table = 'escala_mensagens';

    public const PENDENTE = 'pendente';

    public const ENVIADA = 'enviada';

    public const ERRO = 'erro';

    protected function casts(): array
    {
        return [
            'enviada_em' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relacionamentos
    // -----------------------------------------------------------------

    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por');
    }

    // -----------------------------------------------------------------
    // Escopos
    // -----------------------------------------------------------------

    #[Scope]
    protected function pendentes(Builder $query): void
    {
        $query->where('status', self::PENDENTE);
    }

    #[Scope]
    protected function enviadas(Builder $query): void
    {
        $query->where('status', self::ENVIADA);
    }

    // -----------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------

    public function foiEnviada(): bool
    {
        return $this->status === self::ENVIADA;
    }

    public function comErro(): bool
    {
        return $this->status === self::ERRO;
    }

    public function statusRotulo(): string
    {
        return match ($this->status) {
            self::ENVIADA => 'Enviada',
            self::ERRO => 'Erro no envio',
            default => 'Pendente',
        };
    }

    public function corBadge(): string
    {
        return match ($this->status) {
            self::ENVIADA => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::ERRO => 'bg-rose-100 text-rose-800 ring-rose-200',
            default => 'bg-amber-100 text-amber-800 ring-amber-200',
        };
    }

    // -----------------------------------------------------------------
    // WhatsApp
    // -----------------------------------------------------------------

    /** Link wa.me com o texto ja preenchido, para envio manual. */
    public function link(): ?string
    {
        return Telefone::linkWhatsapp($this->telefone, $this->texto);
    }

    public function telefoneFormatado(): string
    {
        return Telefone::formatar($this->telefone);
    }

    public function marcarEnviada(?string $driver = null, ?string $retorno = null): void
    {
        $this->update([
            'status' => self::ENVIADA,
            'driver' => $driver ?? config('whatsapp.driver'),
            'enviada_em' => now(),
            'enviada_por' => auth()->id(),
            'retorno' => $retorno,
        ]);
    }

    public function marcarErro(string $retorno): void
    {
        $this->update([
            'status' => self::ERRO,
            'driver' => config('whatsapp.driver'),
            'retorno' => $retorno,
        ]);
    }
}
