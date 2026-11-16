<?php

declare(strict_types=1);

use App\Features\User\UserRepository;
use Tests\Support\Seed;

function userAddressTestCityId(): int
{
    $stmt = Seed::pdo()->query('SELECT id FROM cities ORDER BY id LIMIT 1');
    $cityId = $stmt !== false ? $stmt->fetchColumn() : false;

    if ($cityId === false) {
        throw new RuntimeException('No cities found in database.');
    }

    return (int) $cityId;
}

function attachUserAddressTestSeedAddress(int $userId, int $cityId, string $address, bool $isMain = true): int
{
    $pdo = Seed::pdo();

    $pdo->prepare('INSERT INTO addresses (city_id, address, address_name) VALUES (?, ?, ?)')
        ->execute([$cityId, $address, 'ENTREGA']);

    $addressId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO address_user_map (user_id, address_id, is_main) VALUES (?, ?, ?)')
        ->execute([$userId, $addressId, $isMain ? 1 : 0]);

    return $addressId;
}

it('reuses the existing SAP address when profile submits the same address', function (): void {
    $repository = new UserRepository();
    $cityId = userAddressTestCityId();
    $userId = Seed::user();
    $sapAddress = 'Calle 100 # 15-20';
    $sapAddressId = attachUserAddressTestSeedAddress($userId, $cityId, $sapAddress);

    $repository->createOrUseMainAddress($userId, [
        'city_id' => $cityId,
        'address' => '  calle 100 # 15-20  ',
        'address_name' => 'Casa',
    ]);

    $addresses = $repository->findAddressesByUserId($userId);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]->id)->toBe($sapAddressId)
        ->and($addresses[0]->is_main)->toBe(1);
});

it('reuses a SAP address when common abbreviations and separators differ', function (): void {
    $repository = new UserRepository();
    $cityId = userAddressTestCityId();
    $userId = Seed::user();
    $sapAddressId = attachUserAddressTestSeedAddress($userId, $cityId, 'CL 32B 4AE 23');

    $repository->markAsPremium($userId);
    $repository->registerPremiumAddress($userId, [
        'city_id' => $cityId,
        'address' => 'Cll. 32b # 4ae-23',
        'address_name' => 'Casa',
    ]);

    $addresses = $repository->findAddressesByUserId($userId);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]->id)->toBe($sapAddressId)
        ->and($addresses[0]->is_main)->toBe(1)
        ->and($repository->findPremiumRegistrationStatusByUserId($userId))->toBe([
            'is_premium' => true,
            'profile_pending' => true,
            'address_pending' => false,
        ]);
});

it('creates a new profile address and marks it as main', function (): void {
    $repository = new UserRepository();
    $cityId = userAddressTestCityId();
    $userId = Seed::user();
    $oldAddressId = attachUserAddressTestSeedAddress($userId, $cityId, 'Carrera 7 # 45-10');

    $repository->createOrUseMainAddress($userId, [
        'city_id' => $cityId,
        'address' => 'Calle 80 # 12-34',
        'address_name' => 'Apartamento',
    ]);

    $addresses = $repository->findAddressesByUserId($userId);
    $mainAddress = array_values(array_filter(
        $addresses,
        static fn ($address): bool => $address->is_main === 1,
    ));

    expect($addresses)->toHaveCount(2)
        ->and($mainAddress)->toHaveCount(1)
        ->and($mainAddress[0]->address)->toBe('Calle 80 # 12-34')
        ->and($mainAddress[0]->id)->not->toBe($oldAddressId);
});

it('tracks premium profile and address registration independently', function (): void {
    $repository = new UserRepository();
    $cityId = userAddressTestCityId();
    $userId = Seed::user();
    $sapAddress = 'Carrera 15 # 100-20';
    $sapAddressId = attachUserAddressTestSeedAddress($userId, $cityId, $sapAddress);
    $user = $repository->findById($userId);

    expect($user)->not->toBeFalse();

    $repository->markAsPremium($userId);

    expect($repository->findPremiumRegistrationStatusByUserId($userId))->toBe([
        'is_premium' => true,
        'profile_pending' => true,
        'address_pending' => true,
    ]);

    $repository->updatePremiumProfile($userId, [
        'document_type_id' => $user->document_type_id,
        'document_id' => $user->document_id,
        'full_name' => 'Cliente Premium Actualizado',
        'num_cell' => $user->num_cell,
        'email' => $user->email,
        'url_image' => $user->url_image,
    ]);

    expect($repository->findPremiumRegistrationStatusByUserId($userId))->toBe([
        'is_premium' => true,
        'profile_pending' => false,
        'address_pending' => true,
    ]);

    $repository->registerPremiumAddress($userId, [
        'city_id' => $cityId,
        'address' => '  carrera 15 # 100-20 ',
        'address_name' => 'Casa',
    ]);

    $addresses = $repository->findAddressesByUserId($userId);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]->id)->toBe($sapAddressId)
        ->and($addresses[0]->is_main)->toBe(1)
        ->and($repository->findPremiumRegistrationStatusByUserId($userId))->toBe([
            'is_premium' => true,
            'profile_pending' => false,
            'address_pending' => false,
        ]);
});

it('does not reset premium completion when a customer scans the QR again', function (): void {
    $repository = new UserRepository();
    $cityId = userAddressTestCityId();
    $userId = Seed::user();
    $user = $repository->findById($userId);

    expect($user)->not->toBeFalse();

    $repository->markAsPremium($userId);
    $repository->updatePremiumProfile($userId, [
        'document_type_id' => $user->document_type_id,
        'document_id' => $user->document_id,
        'full_name' => $user->full_name,
        'num_cell' => $user->num_cell,
        'email' => $user->email,
        'url_image' => $user->url_image,
    ]);
    $repository->registerPremiumAddress($userId, [
        'city_id' => $cityId,
        'address' => 'Calle 12 # 34-56',
        'address_name' => 'Casa',
    ]);

    $repository->markAsPremium($userId);

    expect($repository->findPremiumRegistrationStatusByUserId($userId))->toBe([
        'is_premium' => true,
        'profile_pending' => false,
        'address_pending' => false,
    ]);
});
