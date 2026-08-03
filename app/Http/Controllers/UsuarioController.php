<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestao dos usuarios do sistema. Restrito ao perfil de administrador pelo
 * middleware "admin" declarado nas rotas.
 */
class UsuarioController extends Controller
{
    public function index(): View
    {
        return view('usuarios.index', [
            'usuarios' => User::query()->orderBy('nome')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('usuarios.form', ['usuario' => new User(['perfil' => 'operador', 'ativo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate($this->regras());
        $dados['ativo'] = $request->boolean('ativo');

        User::query()->create($dados);

        return redirect()->route('usuarios.index')->with('sucesso', 'Usuário cadastrado.');
    }

    public function edit(User $usuario): View
    {
        return view('usuarios.form', ['usuario' => $usuario]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $dados = $request->validate($this->regras($usuario));
        $dados['ativo'] = $request->boolean('ativo');

        // Senha em branco na edicao significa "manter a atual".
        if (blank($dados['password'] ?? null)) {
            unset($dados['password']);
        }

        // O administrador nao pode desativar a propria conta e ficar sem acesso.
        if ($usuario->is($request->user()) && ! $dados['ativo']) {
            return back()->with('erro', 'Você não pode desativar a sua própria conta.');
        }

        $usuario->update($dados);

        return redirect()->route('usuarios.index')->with('sucesso', 'Usuário atualizado.');
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        if ($usuario->is($request->user())) {
            return back()->with('erro', 'Você não pode excluir a sua própria conta.');
        }

        // Precisa restar pelo menos um administrador ativo.
        $outrosAdmins = User::query()
            ->where('perfil', 'admin')
            ->where('ativo', true)
            ->whereKeyNot($usuario->id)
            ->count();

        if ($usuario->ehAdmin() && $outrosAdmins === 0) {
            return back()->with('erro', 'Este é o único administrador ativo. Cadastre outro antes de excluí-lo.');
        }

        $nome = $usuario->nome;
        $usuario->delete();

        return redirect()->route('usuarios.index')->with('sucesso', "Usuário {$nome} excluído.");
    }

    /**
     * A coordenacao definiu senha simples, podendo ser apenas numerica. Por isso
     * exigimos somente um minimo de 4 caracteres.
     */
    private function regras(?User $usuario = null): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'usuario' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'usuario')->ignore($usuario?->id),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password' => [$usuario ? 'nullable' : 'required', 'string', 'min:4', 'max:255', 'confirmed'],
            'perfil' => ['required', Rule::in(array_keys(User::PERFIS))],
            'ativo' => ['boolean'],
        ];
    }
}
