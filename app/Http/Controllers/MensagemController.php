<?php

namespace App\Http\Controllers;

use App\Models\Escala;
use Illuminate\View\View;

class MensagemController extends Controller
{
    public function index(Escala $escala): View
    {
        return view('mensagens.index', ['escala' => $escala]);
    }
}
