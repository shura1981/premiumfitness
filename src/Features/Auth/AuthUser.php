<?php

declare(strict_types=1);

namespace App\Features\Auth;

/**
 * Cuenta de la app web tal como la necesita la autenticación.
 */
class AuthUser
{
    public int     $id;
    public string  $full_name;
    public string  $num_cell;
    public string  $email;
    public string  $password;
    public string  $role;
    public string  $status;
    public string  $document_id;
    public int     $document_type_id;
    public string  $document_type_name;
    public ?string $trainer_name = null;

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * El usuario que recibe la app (contrato `User`).
     *
     * @return array{id:int, full_name:string, phone:string, role:string, has_trainer:bool, trainer_name:?string}
     */
    public function toSessionUser(): array
    {
        return [
            'id'           => $this->id,
            'full_name'    => $this->full_name,
            'phone'        => $this->num_cell,
            'role'         => $this->role,
            'has_trainer'  => $this->trainer_name !== null,
            'trainer_name' => $this->trainer_name,
        ];
    }

    /**
     * Contrato `Profile`.
     *
     * @return array<string, mixed>
     */
    public function toProfile(): array
    {
        return $this->toSessionUser() + [
            'email'              => $this->email,
            'document_type_id'   => $this->document_type_id,
            'document_type_name' => $this->document_type_name,
            'document_id'        => $this->document_id,
        ];
    }
}
