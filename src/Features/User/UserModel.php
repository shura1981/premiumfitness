<?php

declare(strict_types=1);

namespace App\Features\User;

class UserModel
{
    public int     $id;
    public int     $document_type_id;
    public string  $document_type_name;
    public string  $document_id;
    public string  $full_name;
    public ?string $num_cell;
    public string  $email;
    public ?string $url_image;
    public ?string $token_push_notification;
    public ?string $socket_token;
    public string  $created_at;
    public string  $updated_at;
}
