<?php

declare(strict_types=1);

namespace App\Features\Admin;

class EmpleadoModel
{
    public int     $id;
    public int     $id_usuario;
    public string  $email;
    public string  $pass;
    public string  $usuario;
    public string  $departamento;
    public ?string $foto;
    public ?int    $celular;
    public ?string $sede;
    public ?string $cargo;
    public string  $F;
}
