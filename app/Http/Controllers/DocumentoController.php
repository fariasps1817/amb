<?php

namespace App\Http\Controllers;

use App\Models\Escala;
use Illuminate\View\View;

class DocumentoController extends Controller
{
    public function index(Escala $escala): View
    {
        return view('documentos.index', ['escala' => $escala]);
    }
}
