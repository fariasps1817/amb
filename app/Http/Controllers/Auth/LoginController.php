<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MotivoDeAcesso;
use App\Http\Controllers\Controller;
use App\Models\TentativaDeAcesso;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Autenticacao por nome de usuario e senha.
 *
 * A coordenacao definiu senha simples, podendo ser apenas numerica. Como o
 * sistema fica exposto na internet, a protecao contra tentativa exaustiva nao
 * vem da forca da senha e sim de limitar o ritmo das tentativas.
 *
 * Sao duas camadas independentes, e nao uma so:
 *
 *   CONTA   usuario + computador de origem
 *           Impede que insistam na senha de uma pessoa especifica.
 *
 *   ORIGEM  apenas o computador de origem
 *           Impede a varredura, em que um mesmo computador tenta dezenas de
 *           nomes diferentes. A camada de conta sozinha nao pega esse caso,
 *           porque cada nome tentado tem o proprio contador.
 *
 * Toda tentativa fica registrada em tentativas_de_acesso, com o motivo, para
 * que o bloqueio nao aconteca em silencio.
 */
class LoginController extends Controller
{
    /** Erros na mesma conta, a partir do mesmo computador, antes de bloquear. */
    private const TENTATIVAS_POR_CONTA = 5;

    private const BLOQUEIO_CONTA_SEGUNDOS = 300;      // 5 minutos

    /** Erros vindos do mesmo computador, somando todas as contas. */
    private const TENTATIVAS_POR_ORIGEM = 20;

    private const BLOQUEIO_ORIGEM_SEGUNDOS = 1800;    // 30 minutos

    public function mostrar(): View
    {
        return view('auth.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'usuario' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $usuarioDigitado = $dados['usuario'];
        $chaveConta = $this->chaveDaConta($request, $usuarioDigitado);
        $chaveOrigem = $this->chaveDaOrigem($request);

        // A origem e verificada primeiro: e o bloqueio mais abrangente.
        $this->recusarSeBloqueado(
            $request, $usuarioDigitado, $chaveOrigem,
            self::TENTATIVAS_POR_ORIGEM, MotivoDeAcesso::OrigemBloqueada, 'auth.origem_bloqueada',
        );

        $this->recusarSeBloqueado(
            $request, $usuarioDigitado, $chaveConta,
            self::TENTATIVAS_POR_CONTA, MotivoDeAcesso::ContaBloqueada, 'auth.conta_bloqueada',
        );

        $credenciais = [
            'usuario' => $usuarioDigitado,
            'password' => $dados['password'],
        ];

        if (! Auth::attempt($credenciais, $request->boolean('lembrar'))) {
            // A distincao entre nome inexistente e senha errada fica so no
            // registro interno. Na tela a mensagem e sempre a mesma, senao o
            // proprio sistema confirmaria quais nomes de usuario existem.
            $this->registrarFalha($request, $usuarioDigitado, $chaveConta, $chaveOrigem,
                User::query()->where('usuario', $usuarioDigitado)->exists()
                    ? MotivoDeAcesso::SenhaIncorreta
                    : MotivoDeAcesso::UsuarioInexistente,
            );

            throw ValidationException::withMessages([
                'usuario' => trans('auth.failed'),
            ]);
        }

        /** @var User $usuario */
        $usuario = Auth::user();

        // Usuario desativado nao acessa, mesmo com a senha correta.
        if (! $usuario->ativo) {
            Auth::logout();
            $request->session()->invalidate();

            $this->registrarFalha($request, $usuarioDigitado, $chaveConta, $chaveOrigem,
                MotivoDeAcesso::UsuarioInativo);

            throw ValidationException::withMessages([
                'usuario' => trans('auth.inativo'),
            ]);
        }

        RateLimiter::clear($chaveConta);
        RateLimiter::clear($chaveOrigem);

        $request->session()->regenerate();

        TentativaDeAcesso::registrar($request, $usuarioDigitado, MotivoDeAcesso::Sucesso);

        $usuario->forceFill(['ultimo_acesso_em' => now()])->save();

        return redirect()->intended(route('painel'));
    }

    public function sair(Request $request): RedirectResponse
    {
        // O aviso de inatividade envia este campo para que a tela de login
        // explique por que a sessao terminou, em vez de so mostrar o formulario.
        $porInatividade = $request->boolean('inatividade');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with(
            $porInatividade ? 'atencao' : 'sucesso',
            $porInatividade ? trans('auth.inatividade') : 'Sessão encerrada.',
        );
    }

    /**
     * Interrompe a entrada quando o limite daquela camada foi estourado.
     *
     * A tentativa bloqueada tambem e registrada: e justamente a insistencia
     * depois do bloqueio que caracteriza um ataque, e nao um esquecimento.
     */
    private function recusarSeBloqueado(
        Request $request,
        string $usuario,
        string $chave,
        int $limite,
        MotivoDeAcesso $motivo,
        string $mensagem,
    ): void {
        if (! RateLimiter::tooManyAttempts($chave, $limite)) {
            return;
        }

        TentativaDeAcesso::registrar($request, $usuario, $motivo);

        throw ValidationException::withMessages([
            'usuario' => trans($mensagem, [
                'tempo' => $this->tempoLegivel(RateLimiter::availableIn($chave)),
            ]),
        ]);
    }

    /** Conta o erro nas duas camadas e registra o motivo. */
    private function registrarFalha(
        Request $request,
        string $usuario,
        string $chaveConta,
        string $chaveOrigem,
        MotivoDeAcesso $motivo,
    ): void {
        RateLimiter::hit($chaveConta, self::BLOQUEIO_CONTA_SEGUNDOS);
        RateLimiter::hit($chaveOrigem, self::BLOQUEIO_ORIGEM_SEGUNDOS);

        TentativaDeAcesso::registrar($request, $usuario, $motivo);
    }

    private function chaveDaConta(Request $request, string $usuario): string
    {
        return 'login:conta:'.Str::transliterate(Str::lower($usuario)).'|'.$request->ip();
    }

    private function chaveDaOrigem(Request $request): string
    {
        return 'login:origem:'.$request->ip();
    }

    /** "45 segundos", "3 minutos" — evita frases como "em 1800 segundos". */
    private function tempoLegivel(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos.' '.($segundos === 1 ? 'segundo' : 'segundos');
        }

        $minutos = (int) ceil($segundos / 60);

        return $minutos.' '.($minutos === 1 ? 'minuto' : 'minutos');
    }
}
