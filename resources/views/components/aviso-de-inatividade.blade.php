{{--
    Encerramento da sessao por inatividade.

    Quem enforce de verdade e o servidor: a sessao do Laravel expira depois de
    SESSION_LIFETIME minutos sem nenhuma requisicao. Esta tela existe para que
    isso nao aconteca em silencio — o usuario e avisado antes, com contagem
    regressiva, e pode continuar conectado com um clique.

    Detalhes que a implementacao precisa cobrir:

    - Digitar um formulario longo nao gera requisicao nenhuma. Sem os avisos
      periodicos abaixo, o servidor encerraria a sessao de alguem que esta
      trabalhando. Por isso, enquanto ha atividade, avisamos o servidor a cada
      poucos minutos.

    - Com o sistema aberto em varias abas, cada uma teria o proprio relogio. O
      momento da ultima atividade fica no localStorage, compartilhado entre elas.

    - Depois que o aviso aparece, mexer o mouse nao o dispensa: e preciso clicar.
      Assim ninguem continua conectado por esbarrao no teclado.
--}}

@props([
    // Minutos de inatividade ate encerrar. Segue a sessao do servidor.
    'minutos' => (int) config('session.lifetime'),

    // Antecedencia do aviso, em segundos.
    'antecedencia' => 120,
])

@php
    // Com sessao muito curta nao ha espaco para avisar com 2 minutos de
    // antecedencia; nesse caso avisamos no ultimo terco do tempo.
    $antecedencia = min($antecedencia, (int) floor($minutos * 60 / 3));
@endphp

<div
    x-data="avisoDeInatividade({
        minutos: {{ $minutos }},
        antecedencia: {{ $antecedencia }},
        rotaRenovar: @js(route('sessao.renovar')),
    })"
    x-cloak
    @mousemove.window.passive="registrarAtividade()"
    @mousedown.window.passive="registrarAtividade()"
    @keydown.window.passive="registrarAtividade()"
    @scroll.window.passive="registrarAtividade()"
    @touchstart.window.passive="registrarAtividade()"
    class="nao-imprimir"
>
    <form method="POST" action="{{ route('logout') }}" x-ref="formulario" class="hidden">
        @csrf
        <input type="hidden" name="inatividade" value="1">
    </form>

    <div
        x-show="mostrando"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="titulo-inatividade"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <x-icone nome="relogio" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h2 id="titulo-inatividade" class="text-base font-semibold text-slate-900">
                        Sua sessão vai ser encerrada
                    </h2>
                    <p class="mt-1 text-sm text-slate-600">
                        O sistema não registra atividade sua há algum tempo. Por segurança, o acesso será
                        encerrado em <strong class="tabular-nums text-slate-900" x-text="contagem"></strong>.
                    </p>
                </div>
            </div>

            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    @click="sairAgora()"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100"
                >
                    Sair agora
                </button>
                <button
                    type="button"
                    @click="continuar()"
                    x-ref="continuar"
                    class="rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-marca-700"
                >
                    Continuar conectado
                </button>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('avisoDeInatividade', (config) => ({
                    // Um minuto a menos que a sessao do servidor, de proposito.
                    // O encerramento e um POST, e um POST com a sessao ja morta
                    // cai na tela "419 Page Expired" em vez da tela de login.
                    // Esta folga garante que saiamos enquanto ainda da tempo.
                    limite: Math.max(config.minutos * 60000 - 60000, 30000),
                    antecedencia: config.antecedencia * 1000,

                    // Avisar o servidor a cada 4 minutos ja mantem a sessao viva
                    // com folga, sem gerar trafego desnecessario.
                    intervaloDeRenovacao: 240000,

                    chaveAtividade: 'amb:ultima-atividade',
                    chaveRenovacao: 'amb:ultima-renovacao',

                    mostrando: false,
                    restante: 0,
                    relogio: null,
                    atividadeLocal: Date.now(),
                    renovacaoLocal: Date.now(),

                    init() {
                        this.registrarAtividade(true)
                        this.relogio = setInterval(() => this.verificar(), 1000)
                    },

                    destroy() {
                        clearInterval(this.relogio)
                    },

                    /** Le do localStorage para valer entre abas; cai no valor local se indisponivel. */
                    ler(chave, alternativa) {
                        try {
                            const valor = parseInt(window.localStorage.getItem(chave), 10)
                            return Number.isFinite(valor) ? valor : alternativa
                        } catch (e) {
                            return alternativa
                        }
                    },

                    gravar(chave, valor) {
                        try {
                            window.localStorage.setItem(chave, valor)
                        } catch (e) {
                            // Navegador com armazenamento bloqueado: seguimos so com o valor local.
                        }
                    },

                    registrarAtividade(forcar = false) {
                        // Com o aviso na tela, so o botao dispensa.
                        if (this.mostrando && ! forcar) {
                            return
                        }

                        const agora = Date.now()

                        // mousemove dispara dezenas de vezes por segundo e
                        // gravar no localStorage e uma operacao sincrona: sem
                        // esta trava, mover o mouse travaria a interface.
                        // Um segundo de resolucao e de sobra para um relogio
                        // que conta em minutos.
                        if (! forcar && agora - this.atividadeLocal < 1000) {
                            return
                        }

                        this.atividadeLocal = agora
                        this.gravar(this.chaveAtividade, agora)
                    },

                    verificar() {
                        const ocioso = Date.now() - this.ler(this.chaveAtividade, this.atividadeLocal)

                        if (ocioso >= this.limite) {
                            this.expirar()
                            return
                        }

                        if (ocioso >= this.limite - this.antecedencia) {
                            const jaEstavaVisivel = this.mostrando
                            this.mostrando = true
                            this.restante = Math.max(0, Math.ceil((this.limite - ocioso) / 1000))

                            if (! jaEstavaVisivel) {
                                this.$nextTick(() => this.$refs.continuar?.focus())
                            }

                            return
                        }

                        this.mostrando = false
                        this.manterSessaoViva(ocioso)
                    },

                    /** Enquanto houver atividade, avisa o servidor de tempos em tempos. */
                    manterSessaoViva(ocioso) {
                        const agora = Date.now()
                        const ultimaRenovacao = this.ler(this.chaveRenovacao, this.renovacaoLocal)

                        if (ocioso > this.intervaloDeRenovacao) {
                            return
                        }

                        if (agora - ultimaRenovacao < this.intervaloDeRenovacao) {
                            return
                        }

                        this.renovar()
                    },

                    renovar() {
                        this.renovacaoLocal = Date.now()
                        this.gravar(this.chaveRenovacao, this.renovacaoLocal)

                        fetch(config.rotaRenovar, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                            },
                            credentials: 'same-origin',
                        }).then((resposta) => {
                            // 419 = sessao ja expirada no servidor; 401 = deslogado.
                            if (resposta.status === 419 || resposta.status === 401) {
                                this.expirar()
                            }
                        }).catch(() => {
                            // Falha de rede nao deve deslogar ninguem: na proxima
                            // volta do relogio tentamos de novo.
                        })
                    },

                    continuar() {
                        this.mostrando = false
                        this.registrarAtividade(true)
                        this.renovar()
                    },

                    sairAgora() {
                        clearInterval(this.relogio)
                        this.$refs.formulario.submit()
                    },

                    expirar() {
                        clearInterval(this.relogio)
                        this.$refs.formulario.submit()
                    },

                    get contagem() {
                        const minutos = Math.floor(this.restante / 60)
                        const segundos = this.restante % 60

                        return minutos + ':' + String(segundos).padStart(2, '0')
                    },
                }))
            })
        </script>
    @endpush
@endonce
