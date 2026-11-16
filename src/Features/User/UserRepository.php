<?php

declare(strict_types=1);

namespace App\Features\User;

use App\Features\Address\AddressModel;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use RuntimeException;
use Throwable;

class UserRepository extends AbstractRepository
{
    private PasswordHasher $hasher;

    public function __construct()
    {
        parent::__construct(DB::getInstance());
        $this->hasher = new PasswordHasher();
    }

    /** @return list<UserModel> */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT u.id, u.document_type_id, dt.name AS document_type_name,
                    u.document_id, u.full_name, u.num_cell, u.email,
                    u.url_image, u.token_push_notification, u.socket_token,
                    u.created_at, u.updated_at
             FROM users u
             JOIN document_types dt ON u.document_type_id = dt.id
             ORDER BY u.full_name',
            UserModel::class
        );
    }

    public function findById(int $id): UserModel|false
    {
        return $this->fetchOne(
            'SELECT u.id, u.document_type_id, dt.name AS document_type_name,
                    u.document_id, u.full_name, u.num_cell, u.email,
                    u.url_image, u.token_push_notification, u.socket_token,
                    u.created_at, u.updated_at
             FROM users u
             JOIN document_types dt ON u.document_type_id = dt.id
             WHERE u.id = ?',
            UserModel::class,
            [$id]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $hashedPassword = $this->hasher->hash((string) $data['password']);

        $this->execute(
            'INSERT INTO users (document_type_id, document_id, full_name, num_cell, email, password, url_image)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['document_type_id'],
                $data['document_id'],
                $data['full_name'],
                $data['num_cell'] ?? '',
                $data['email'],
                $hashedPassword,
                $data['url_image'] ?? null,
            ]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = $this->hasher->hash((string) $data['password']);

            return $this->execute(
                'UPDATE users SET document_type_id = ?, document_id = ?, full_name = ?,
                        num_cell = ?, email = ?, password = ?, url_image = ?
                 WHERE id = ?',
                [
                    $data['document_type_id'],
                    $data['document_id'],
                    $data['full_name'],
                    $data['num_cell'] ?? '',
                    $data['email'],
                    $data['password'],
                    $data['url_image'] ?? null,
                    $id,
                ]
            );
        }

        return $this->execute(
            'UPDATE users SET document_type_id = ?, document_id = ?, full_name = ?,
                    num_cell = ?, email = ?, url_image = ?
             WHERE id = ?',
            [
                $data['document_type_id'],
                $data['document_id'],
                $data['full_name'],
                $data['num_cell'] ?? '',
                $data['email'],
                $data['url_image'] ?? null,
                $id,
            ]
        );
    }

    /**
     * Actualiza únicamente la contraseña de un usuario.
     */
    public function updatePassword(int $id, string $plainPassword): bool
    {
        $hash = $this->hasher->hash($plainPassword);

        return $this->execute(
            'UPDATE users SET password = ? WHERE id = ?',
            [$hash, $id]
        );
    }

    /**
     * Activa el acceso minorista después de comprobar el código enviado al
     * correo y reemplaza la contraseña temporal del checkout.
     */
    public function activateRetailAccess(int $id, string $plainPassword): bool
    {
        return $this->execute(
            'UPDATE users SET password = ?, email_verified_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$this->hasher->hash($plainPassword), $id]
        );
    }

    public function isEmailVerified(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT email_verified_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '';
    }

    public function updateRetailProfile(int $id, string $fullName, string $numCell): bool
    {
        return $this->execute(
            'UPDATE users SET full_name = ?, num_cell = ? WHERE id = ?',
            [$fullName, $numCell, $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function markAsPremium(int $id): bool
    {
        return $this->execute(
            'UPDATE users SET is_premium = 1 WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array{is_premium: bool, profile_pending: bool, address_pending: bool}|null
     */
    public function findPremiumRegistrationStatusByUserId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_premium = 1 AS is_premium,
                    premium_profile_completed_at,
                    premium_address_completed_at
             FROM users
             WHERE id = ?'
        );
        $stmt->execute([$id]);

        /** @var array{is_premium: int|string, premium_profile_completed_at: ?string, premium_address_completed_at: ?string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $isPremium = (int) $row['is_premium'] === 1;

        return [
            'is_premium' => $isPremium,
            'profile_pending' => $isPremium && $row['premium_profile_completed_at'] === null,
            'address_pending' => $isPremium && $row['premium_address_completed_at'] === null,
        ];
    }

    /** @param array<string, mixed> $data */
    public function updatePremiumProfile(int $id, array $data): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->update($id, $data);
            $this->execute(
                'UPDATE users
                 SET premium_profile_completed_at = COALESCE(premium_profile_completed_at, CURRENT_TIMESTAMP)
                 WHERE id = ? AND is_premium = 1',
                [$id]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Premium profile update failed: ' . $e->getMessage(), previous: $e);
        }
    }

    // -------------------------------------------------------------------------
    // Lookup methods
    // -------------------------------------------------------------------------

    public function findByDocumentId(string $documentId): UserModel|false
    {
        return $this->fetchOne(
            'SELECT u.id, u.document_type_id, dt.name AS document_type_name,
                    u.document_id, u.full_name, u.num_cell, u.email,
                    u.url_image, u.token_push_notification, u.socket_token,
                    u.created_at, u.updated_at
             FROM users u
             JOIN document_types dt ON u.document_type_id = dt.id
             WHERE u.document_id = ?',
            UserModel::class,
            [$documentId]
        );
    }

    public function findByEmail(string $email): UserModel|false
    {
        return $this->fetchOne(
            'SELECT u.id, u.document_type_id, dt.name AS document_type_name,
                    u.document_id, u.full_name, u.num_cell, u.email,
                    u.url_image, u.token_push_notification, u.socket_token,
                    u.created_at, u.updated_at
             FROM users u
             JOIN document_types dt ON u.document_type_id = dt.id
             WHERE u.email = ?',
            UserModel::class,
            [$email]
        );
    }

    /**
     * Cuenta cuántos usuarios tienen un email que coincide con el patrón LIKE dado.
     * Útil para resolver duplicados del correo genérico de SAP (ej. elitenutgroup%@gmail.com).
     */
    public function countByEmailLike(string $emailLike): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ?');
        $stmt->execute([$emailLike]);

        return (int) $stmt->fetchColumn();
    }

    public function findByNumCell(string $numCell): UserModel|false
    {
        return $this->fetchOne(
            'SELECT u.id, u.document_type_id, dt.name AS document_type_name,
                    u.document_id, u.full_name, u.num_cell, u.email,
                    u.url_image, u.token_push_notification, u.socket_token,
                    u.created_at, u.updated_at
             FROM users u
             JOIN document_types dt ON u.document_type_id = dt.id
             WHERE u.num_cell = ?',
            UserModel::class,
            [$numCell]
        );
    }

    /**
     * Obtiene únicamente el `socket_token` del usuario para enviar eventos
     * al gateway WebSocket sin cargar el modelo completo.
     */
    public function findSocketTokenById(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT socket_token FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result === false) {
            return null;
        }

        $token = $result['socket_token'];

        return is_string($token) ? $token : null;
    }

    /**
     * Actualiza el `socket_token` del usuario (null = desconectado).
     */
    public function updateSocketToken(int $id, ?string $token): bool
    {
        return $this->execute(
            'UPDATE users SET socket_token = ? WHERE id = ?',
            [$token, $id]
        );
    }

    /**
     * Obtiene el hash de contraseña del usuario (no presente en UserModel).
     */
    public function findPasswordHashById(int $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? (string) $result['password'] : false;
    }

    // -------------------------------------------------------------------------
    // Junction: address_user_map
    // -------------------------------------------------------------------------

    /** @return list<AddressModel> */
    public function findAddressesByUserId(int $userId): array
    {
        return $this->fetchAll(
            'SELECT a.id, a.city_id, a.address, a.address_name, a.created_at, a.updated_at,
                    c.name AS city_name, c.city_dept, c.department,
                    alm.locality_id, l.name AS locality_name,
                    aum.is_main
             FROM address_user_map aum
             JOIN addresses a ON aum.address_id = a.id
             JOIN cities c ON a.city_id = c.id
             LEFT JOIN address_locality_map alm ON alm.address_id = a.id
             LEFT JOIN localities l ON l.id = alm.locality_id
             WHERE aum.user_id = ?
             ORDER BY aum.is_main DESC, a.address_name',
            AddressModel::class,
            [$userId]
        );
    }

    public function attachAddress(int $userId, int $addressId, bool $isMain = false): bool
    {
        return $this->execute(
            'INSERT IGNORE INTO address_user_map (user_id, address_id, is_main) VALUES (?, ?, ?)',
            [$userId, $addressId, $isMain ? 1 : 0]
        );
    }

    /** @param array<string, mixed> $addressData */
    public function createOrUseMainAddress(int $userId, array $addressData): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->createOrUseMainAddressInsideTransaction($userId, $addressData);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Address creation failed: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @param array<string, mixed> $addressData */
    public function registerPremiumAddress(int $userId, array $addressData): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->createOrUseMainAddressInsideTransaction($userId, $addressData);
            $this->execute(
                'UPDATE users
                 SET premium_address_completed_at = COALESCE(premium_address_completed_at, CURRENT_TIMESTAMP)
                 WHERE id = ? AND is_premium = 1',
                [$userId]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Premium address registration failed: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Marca una dirección como principal para un usuario.
     * Desmarca la anterior y marca la nueva en una transacción.
     *
     * @throws RuntimeException Si la transacción falla
     */
    public function setMainAddress(int $userId, int $addressId): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->markLinkedAddressAsMain($userId, $addressId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Failed to set main address: ' . $e->getMessage(), previous: $e);
        }
    }

    public function detachAddress(int $userId, int $addressId): bool
    {
        return $this->execute(
            'DELETE FROM address_user_map WHERE user_id = ? AND address_id = ?',
            [$userId, $addressId]
        );
    }

    /**
     * Elimina la vinculación y el registro físico de la dirección de forma atómica.
     *
     * @internal Solo para uso administrativo. Para desvincular una dirección de un usuario
     *           sin borrar el registro (preservando historial en orders) usar detachAddress().
     *
     * @throws RuntimeException Si la transacción falla por cualquier motivo
     */
    public function deleteAddress(int $userId, int $addressId): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->execute(
                'DELETE FROM address_user_map WHERE user_id = ? AND address_id = ?',
                [$userId, $addressId]
            );
            $this->execute(
                'DELETE FROM addresses WHERE id = ?',
                [$addressId]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Address deletion failed: ' . $e->getMessage(), previous: $e);
        }
    }

    // -------------------------------------------------------------------------
    // Operación compuesta con transacción
    // -------------------------------------------------------------------------

    /**
     * Crea el usuario y su primera dirección de forma atómica.
     *
     * Flujo dentro de la misma conexión PDO (singleton DB::getInstance()):
     *   1. INSERT addresses           → address_id  (nueva fila en addresses)
     *   2. INSERT users               → user_id     (nueva fila en users, password hasheado)
     *   3. INSERT address_user_map    → junction    (vincula user_id ↔ address_id)
     *
     * Si cualquiera de los tres INSERTs lanza una excepción, se ejecuta ROLLBACK
     * y ningún cambio queda persistido en la base de datos.
     *
     * @param array<string, mixed> $userData    Campos del usuario (password en texto plano)
     * @param array<string, mixed> $addressData Campos de la dirección (city_id, address, address_name)
     * @return UserModel El usuario recién creado con JOIN a document_types
     * @throws RuntimeException Si la transacción falla por cualquier motivo
     */
    public function createWithAddress(array $userData, array $addressData): UserModel
    {
        $this->pdo->beginTransaction();

        try {
            // Paso 1 — crear la dirección y capturar su ID generado por AUTO_INCREMENT
            $this->execute(
                'INSERT INTO addresses (city_id, address, address_name) VALUES (?, ?, ?)',
                [$addressData['city_id'], $addressData['address'], $addressData['address_name']]
            );
            $addressId = (int) $this->pdo->lastInsertId();

            // Paso 2 — crear el usuario; hashear password antes de persistir
            $hashedPassword = $this->hasher->hash((string) $userData['password']);
            $this->execute(
                'INSERT INTO users (document_type_id, document_id, full_name, num_cell, email, password, url_image)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $userData['document_type_id'],
                    $userData['document_id'],
                    $userData['full_name'],
                    $userData['num_cell'] ?? '',
                    $userData['email'],
                    $hashedPassword,
                    $userData['url_image'] ?? null,
                ]
            );
            $userId = (int) $this->pdo->lastInsertId();

            // Paso 3 — vincular user_id ↔ address_id en la junction table (primera dirección = principal)
            $this->execute(
                'INSERT INTO address_user_map (user_id, address_id, is_main) VALUES (?, ?, 1)',
                [$userId, $addressId]
            );

            $this->pdo->commit();

            $user = $this->findById($userId);

            if ($user === false) {
                throw new RuntimeException('Failed to retrieve user after creation.');
            }

            return $user;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('User creation with address failed: ' . $e->getMessage(), previous: $e);
        }
    }

    private function findMatchingAddressIdForUser(int $userId, int $cityId, string $address): ?int
    {
        $normalizedAddress = self::normalizeAddress($address);

        foreach ($this->findAddressesByUserId($userId) as $storedAddress) {
            if ($storedAddress->city_id !== $cityId) {
                continue;
            }

            if (self::normalizeAddress($storedAddress->address) === $normalizedAddress) {
                return $storedAddress->id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $addressData */
    private function createOrUseMainAddressInsideTransaction(int $userId, array $addressData): void
    {
        $cityId    = (int) $addressData['city_id'];
        $address   = trim((string) $addressData['address']);
        $name      = trim((string) $addressData['address_name']);
        $addressId = $this->findMatchingAddressIdForUser($userId, $cityId, $address);

        if ($addressId === null) {
            $addressId = $this->createAddressInsideTransaction($cityId, $address, $name);
        }

        $this->attachAsOnlyMainAddress($userId, $addressId);

        if (array_key_exists('locality_id', $addressData)) {
            $this->syncAddressLocality($addressId, $addressData['locality_id']);
        }
    }

    private function createAddressInsideTransaction(int $cityId, string $address, string $name): int
    {
        $this->execute(
            'INSERT INTO addresses (city_id, address, address_name) VALUES (?, ?, ?)',
            [$cityId, $address, $name]
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function markLinkedAddressAsMain(int $userId, int $addressId): void
    {
        $this->execute(
            'UPDATE address_user_map SET is_main = 0 WHERE user_id = ? AND is_main = 1',
            [$userId]
        );
        $this->execute(
            'UPDATE address_user_map SET is_main = 1 WHERE user_id = ? AND address_id = ?',
            [$userId, $addressId]
        );
    }

    private function attachAsOnlyMainAddress(int $userId, int $addressId): void
    {
        $this->execute(
            'UPDATE address_user_map SET is_main = 0 WHERE user_id = ? AND is_main = 1',
            [$userId]
        );
        $this->execute(
            'INSERT INTO address_user_map (user_id, address_id, is_main) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE is_main = VALUES(is_main)',
            [$userId, $addressId]
        );
    }

    private function syncAddressLocality(int $addressId, mixed $localityId): void
    {
        $normalizedLocalityId = (int) $localityId;

        if ($normalizedLocalityId <= 0) {
            $this->execute('DELETE FROM address_locality_map WHERE address_id = ?', [$addressId]);
            return;
        }

        $this->execute(
            'INSERT INTO address_locality_map (address_id, locality_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locality_id = VALUES(locality_id)',
            [$addressId, $normalizedLocalityId]
        );
    }

    private static function normalizeAddress(string $address): string
    {
        $normalized = strtolower(trim($address));
        $normalized = preg_replace(
            [
                '/\b(?:calle|cll|cl)\b/u',
                '/\b(?:carrera|cra|cr)\b/u',
                '/\b(?:avenida|avda|av)\b/u',
                '/\b(?:transversal|transv|tv)\b/u',
                '/\b(?:diagonal|diag|dg)\b/u',
            ],
            ['cl', 'cr', 'av', 'tv', 'dg'],
            $normalized
        );
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized ?? '');

        return $normalized ?? '';
    }
}
