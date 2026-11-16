<?php

declare(strict_types=1);

namespace App\Features\Auth\VerificationCode;

class VerificationCodeModel
{
    public int     $id;
    public int     $user_id;
    public string  $code;
    public string  $purpose;
    public int     $attempts;
    public string  $expires_at;
}
