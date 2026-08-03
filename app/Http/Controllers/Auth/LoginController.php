<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
 * A coordenacao definiu senha simples (pode ser apenas numerica). Ainda assim
 * limitamos as tentativas por usuario/IP para evitar tentativa exaustiva.
 */
class LoginController extends Controller
{
    /** Tentativas antes do bloqueio temporario. */
    private const TENTATIVAS = 8;

    private const BLOQUEIO_SEGUNDOS = 60;

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

        $chave = $this->chaveDeLimite($request, $dados['usuario']);

        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS)) {
            throw ValidationException::withMessages([
                'usuario' => trans('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($chave),
                ]),
            ]);
        }

        $credenciais = [
            'usuario' => $dados['usuario'],
            'password' => $dados['password'],
        ];

        if (! Auth::attempt($credenciais, $request->boolean('lembrar'))) {
            RateLimiter::hit($chave, self::BLOQUEIO_SEGUNDOS);

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

            throw ValidationException::withMessages([
                'usuario' => trans('auth.inativo'),
            ]);
        }

        RateLimiter::clear($chave);
        $request->session()->regenerate();

        $usuario->forceFill(['ultimo_acesso_em' => now()])->save();

        return redirect()->intended(route('painel'));
    }

    public function sair(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('sucesso', 'Sessão encerrada.');
    }

    private function chaveDeLimite(Request $request, string $usuario): string
    {
        return Str::transliterate(Str::lower($usuario)).'|'.$request->ip();
    }
}
