<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // Les policies sont utilisées par tous les contrôleurs métier : chaque
    // ressource est filtrée par l'utilisateur propriétaire.
    use AuthorizesRequests, ValidatesRequests;
}
