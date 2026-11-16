<?php

declare(strict_types=1);

namespace App\Features\Auth\PasswordReset;

class PasswordResetModel
{
    public int     $id;
    public int     $user_id;
    public string  $token;
    public string  $expires_at;
    public ?string $used_at;
    public string  $created_at;
}
