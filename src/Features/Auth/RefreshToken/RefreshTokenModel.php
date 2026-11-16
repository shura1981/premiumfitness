<?php

declare(strict_types=1);

namespace App\Features\Auth\RefreshToken;

class RefreshTokenModel
{
    public int     $id;
    public ?int    $user_id;
    public ?int    $agent_id;
    public string  $app;
    public string  $family_id;
    public string  $expires_at;
    public ?string $revoked_at;
    public ?int    $replaced_by_id;

    public function ownerId(): int
    {
        return (int) ($this->app === 'admin' ? $this->agent_id : $this->user_id);
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) <= time();
    }
}
