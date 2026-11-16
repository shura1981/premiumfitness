<?php

declare(strict_types=1);

namespace App\Features\Address;

class AddressModel
{
    public int     $id;
    public int     $city_id;
    public string  $address;
    public string  $address_name;
    public string  $created_at;
    public string  $updated_at;
    public string  $city_name;
    public string  $city_dept;
    public string  $department;
    public ?int    $locality_id;
    public ?string $locality_name;
    public int     $is_main;
}
