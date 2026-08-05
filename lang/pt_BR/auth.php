<?php

return [
    'failed' => 'Usuário ou senha incorretos.',
    'password' => 'A senha informada está incorreta.',
    'throttle' => 'Muitas tentativas de acesso. Tente novamente em :seconds segundos.',
    'inativo' => 'Este usuário está inativo. Procure o administrador do sistema.',

    // Bloqueios temporarios. As mensagens sao diferentes de proposito: uma
    // acontece por engano com a propria senha; a outra indica que este
    // computador ja errou muitas vezes, possivelmente com contas diferentes.
    'conta_bloqueada' => 'Este usuário ficou bloqueado por :tempo depois de várias tentativas erradas. Se a senha foi esquecida, procure o administrador do sistema.',
    'origem_bloqueada' => 'Este computador ficou bloqueado por :tempo depois de muitas tentativas de acesso sem sucesso.',

    'inatividade' => 'Sua sessão foi encerrada por inatividade. Entre novamente para continuar.',
];
