<?php

namespace App\Livewire;

use App\Enums\AcaoAuditada;
use App\Models\Escala;
use App\Models\EscalaPosto;
use App\Services\Auditoria\Auditoria;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lista compacta das ambulancias do mes, para mudar a sequencia com setas.
 *
 * Fica separada dos blocos da montagem de proposito: levar a SEDE 4 do fim
 * para depois da SEDE 3 sao varias casas, e com os blocos grandes cada toque
 * empurra o bloco para fora da tela. Aqui a lista inteira cabe no celular.
 *
 * Abre pelo evento "abrir-ordenacao", disparado pelo botao da pagina.
 */
class OrdenarPostos extends Component
{
    #[Locked]
    public Escala $escala;

    /**
     * Para onde recarregar ao concluir, quando a pagina nao e Livewire e so
     * mostra a nova ordem depois de recarregada (a escala pronta). Na montagem
     * fica vazio: ela escuta "postos-reordenados" e se atualiza sozinha.
     */
    #[Locked]
    public ?string $recarregar = null;

    public bool $aberto = false;

    public bool $alterou = false;

    public function mount(Escala $escala, ?string $recarregar = null): void
    {
        abort_unless(auth()->user()->podeEditar() && $escala->editavel(), 403);

        $this->escala = $escala;
        $this->recarregar = $recarregar;
    }

    #[Computed]
    public function postos(): Collection
    {
        return $this->escala->postos()->with(['unidade', 'ambulancia'])->get();
    }

    #[On('abrir-ordenacao')]
    public function abrir(): void
    {
        $this->aberto = true;
    }

    public function mover(int $postoId, int $direcao, MontadorDeEscala $montador): void
    {
        abort_unless(auth()->user()->podeEditar() && $this->escala->fresh()->editavel(), 403);

        $posto = EscalaPosto::query()->where('escala_id', $this->escala->id)->with('unidade')->findOrFail($postoId);

        if ($montador->moverPosto($posto, $direcao)) {
            Auditoria::registrar(
                AcaoAuditada::PostoReordenado,
                "Moveu {$posto->rotuloLotacao()} para ".($direcao < 0 ? 'cima' : 'baixo').' na ordem das ambulâncias',
                $posto,
                $this->escala,
            );
        }

        $this->alterou = true;
        unset($this->postos);

        $this->dispatch('postos-reordenados');
    }

    public function concluir(): void
    {
        if ($this->alterou && $this->recarregar !== null) {
            $this->redirect($this->recarregar);

            return;
        }

        $this->aberto = false;
        $this->alterou = false;
    }

    public function render()
    {
        return view('livewire.ordenar-postos');
    }
}
