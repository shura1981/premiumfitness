<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Features\Auth\PasswordReset\PasswordResetRepository;
use App\Features\Auth\RefreshToken\InvalidRefreshTokenException;
use App\Features\Auth\RefreshToken\RefreshTokenService;
use App\Features\Referral\ReferralLink;
use App\Features\Referral\ReferralService;
use App\Features\Trainer\CouponCodeGenerator;
use App\Features\Trainer\TrainerRepository;
use App\Infrastructure\Auth\AuthConfig;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use Throwable;

/**
 * Autenticación de la app web (spec seguridad/01): registro con verificación del celular,
 * login con celular y contraseña, sesión renovable y recuperación de la contraseña.
 *
 * Ningún método entrega una sesión sin contraseña o sin un código verificado.
 */
class AuthService
{
    private const APP               = 'web';
    private const RESET_TTL_SECONDS = 600;
    private const INVALID_LOGIN     = 'El celular o la contraseña no coinciden.';
    private const INVALID_CODE      = 'El código no es válido o ya venció. Pide uno nuevo.';

    /** Mensaje del login de un rol cuando la cuenta es del otro (spec seguridad/01, S-08). */
    private const WRONG_AREA = [
        'trainer' => 'Esta cuenta es de entrenador. Entra por el acceso de entrenadores.',
        'client'  => 'Esta cuenta es de cliente. Entra por el acceso de clientes.',
    ];

    /** Hash de una contraseña que nadie conoce: iguala el tiempo de respuesta cuando el celular no existe. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$L0Fyd0hBRTBlcGhjanFiVA$KjiWS69D9d9immw943t8NGFhuYxAQU7FCfQkcjons24';

    private readonly AuthUserRepository $users;
    private readonly TrainerRepository $trainers;
    private readonly PasswordResetRepository $resetTokens;
    private readonly RefreshTokenService $refreshTokens;
    private readonly VerificationCodeService $codes;
    private readonly ReferralService $referrals;
    private readonly LoginThrottle $throttle;
    private readonly PasswordHasher $hasher;
    private readonly JwtService $jwt;
    private readonly AuthConfig $config;

    public function __construct()
    {
        $this->users         = new AuthUserRepository();
        $this->trainers      = new TrainerRepository();
        $this->resetTokens   = new PasswordResetRepository();
        $this->refreshTokens = new RefreshTokenService();
        $this->codes         = new VerificationCodeService();
        $this->referrals     = new ReferralService();
        $this->throttle      = new LoginThrottle();
        $this->hasher        = new PasswordHasher();
        $this->jwt           = JwtService::fromEnv();
        $this->config        = AuthConfig::fromEnv();
    }

    // -------------------------------------------------------------------------
    // Registro y verificación del celular
    // -------------------------------------------------------------------------

    /**
     * Crea la cuenta sin verificar y envía el código por SMS. Devuelve los segundos de validez.
     *
     * @param array<string, mixed> $input Ya validado por `RegisterValidator`.
     * @throws ApiException
     */
    public function register(array $input): int
    {
        $data     = $this->registrationData($input);
        $existing = $this->users->findByPhone($data['phone']);

        if ($existing !== false && $existing->status !== 'pending') {
            throw ApiException::conflict('phone_taken', 'Este celular ya tiene una cuenta. Inicia sesión.');
        }

        $exceptId = $existing === false ? null : $existing->id;

        if ($this->users->emailTaken($data['email'], $exceptId)) {
            throw ApiException::conflict('email_taken', 'Este correo ya está en uso en otra cuenta.');
        }

        if ($this->users->documentTaken($data['document_id'], $exceptId)) {
            throw ApiException::conflict('document_taken', 'Este documento ya tiene una cuenta.');
        }

        if ($data['coupon_code'] !== null && $this->trainers->couponTakenByOther($data['coupon_code'], $exceptId)) {
            throw ApiException::conflict('coupon_taken', 'Ese cupón ya lo usa otro entrenador. Elige otro.');
        }

        $userId = $this->inTransaction(function () use ($data, $existing): int {
            if ($existing === false) {
                $userId = $this->users->createPending($data);
            } else {
                // Quien registró el celular antes nunca lo verificó: la cuenta es de quien lo verifique.
                $userId = $existing->id;
                $this->users->replacePending($userId, $data);
            }

            $this->syncTrainerRow($userId, $data['role'], $data['full_name'], $data['coupon_code']);

            return $userId;
        });

        return $this->codes->send($userId, VerificationCodeService::PURPOSE_PHONE, $data['phone']);
    }

    /**
     * Verifica el celular, activa la cuenta y abre la sesión.
     *
     * @throws ApiException
     */
    public function verifyPhone(string $phone, string $code, ?ReferralLink $link, string $ip, string $userAgent): SessionResult
    {
        $user = $this->users->findByPhone((string) PhoneNumber::normalize($phone));

        if (
            $user === false
            || $user->status !== 'pending'
            || !$this->codes->verify($user->id, VerificationCodeService::PURPOSE_PHONE, $code)
        ) {
            throw ApiException::invalid(self::INVALID_CODE, 'invalid_code');
        }

        $this->users->activate($user->id);
        $this->referrals->applyLink($user->id, $user->role, $link, isNewAccount: true);

        return $this->openSession($user->id, $ip, $userAgent);
    }

    /** Reenvía el código a una cuenta sin verificar. No revela si el celular existe. */
    public function resendCode(string $phone): int
    {
        $user = $this->users->findByPhone((string) PhoneNumber::normalize($phone));

        if ($user !== false && $user->status === 'pending') {
            return $this->codes->send($user->id, VerificationCodeService::PURPOSE_PHONE, $user->num_cell);
        }

        return $this->codes->ttlSeconds();
    }

    // -------------------------------------------------------------------------
    // Sesión
    // -------------------------------------------------------------------------

    /**
     * Login del área de un rol: `/entrar` (client) o `/coach/entrar` (trainer). La cuenta del otro rol
     * no abre sesión; se comprueba después de la contraseña para no revelar el rol de un celular.
     *
     * @throws ApiException
     */
    public function login(string $phone, string $password, string $role, ?ReferralLink $link, string $ip, string $userAgent): SessionResult
    {
        $phone = PhoneNumber::normalize($phone) ?? '';

        if (($retryAfter = $this->throttle->retryAfter($phone)) > 0) {
            throw ApiException::tooManyRequests($retryAfter, 'Demasiados intentos con esta cuenta. Espera unos minutos o recupera tu contraseña.');
        }

        $user = $this->users->findByPhone($phone);

        if (!$this->hasher->verify($password, $user === false ? self::DUMMY_HASH : $user->password) || $user === false) {
            $this->throttle->registerFailure($phone);

            throw new ApiException(401, 'invalid_credentials', self::INVALID_LOGIN);
        }

        $this->throttle->clear($phone);

        if ($user->role !== $role) {
            throw new ApiException(403, 'wrong_area', self::WRONG_AREA[$user->role] ?? self::INVALID_LOGIN, extra: ['role' => $user->role]);
        }

        if ($user->status === 'suspended') {
            throw ApiException::forbidden('Tu cuenta está suspendida. Escríbenos para revisarla.', 'account_suspended');
        }

        if ($user->status === 'pending') {
            throw new ApiException(403, 'phone_not_verified', 'Falta verificar tu celular.', extra: ['phone' => $user->num_cell]);
        }

        if ($this->hasher->needsRehash($user->password)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->referrals->applyLink($user->id, $user->role, $link, isNewAccount: false);

        return $this->openSession($user->id, $ip, $userAgent);
    }

    /**
     * Renueva la sesión con el refresh token de la cookie.
     *
     * @throws ApiException 401 si no hay sesión
     */
    public function refresh(?string $refreshToken, string $ip, string $userAgent): SessionResult
    {
        if ($refreshToken === null) {
            throw ApiException::unauthorized();
        }

        try {
            $rotation = $this->refreshTokens->rotate(self::APP, $refreshToken, $ip, $userAgent);
        } catch (InvalidRefreshTokenException) {
            throw ApiException::unauthorized();
        }

        $user = $this->users->findById($rotation->ownerId);

        if ($user === false || !$user->isActive()) {
            $this->refreshTokens->revokeAllForUser($rotation->ownerId);

            throw ApiException::unauthorized();
        }

        return new SessionResult($this->accessToken($user), $this->jwt->ttlSeconds(), $user->toSessionUser(), $rotation->issued);
    }

    public function logout(?string $refreshToken): void
    {
        if ($refreshToken !== null) {
            $this->refreshTokens->revokeFamilyOf($refreshToken);
        }
    }

    // -------------------------------------------------------------------------
    // Contraseña
    // -------------------------------------------------------------------------

    /** Envía el código de recuperación. Responde igual exista o no la cuenta. */
    public function forgotPassword(string $phone): int
    {
        $user = $this->users->findByPhone((string) PhoneNumber::normalize($phone));

        if ($user !== false && $user->isActive()) {
            return $this->codes->send($user->id, VerificationCodeService::PURPOSE_RESET, $user->num_cell);
        }

        return $this->codes->ttlSeconds();
    }

    /**
     * Cambia el código SMS por un token de un solo uso para fijar la contraseña.
     *
     * @return array{reset_token:string, expires_in:int}
     * @throws ApiException
     */
    public function verifyResetCode(string $phone, string $code): array
    {
        $user = $this->users->findByPhone((string) PhoneNumber::normalize($phone));

        if (
            $user === false
            || !$user->isActive()
            || !$this->codes->verify($user->id, VerificationCodeService::PURPOSE_RESET, $code)
        ) {
            throw ApiException::invalid(self::INVALID_CODE, 'invalid_code');
        }

        $token = bin2hex(random_bytes(32));

        $this->resetTokens->invalidateAllForUser($user->id);
        $this->resetTokens->create($user->id, $this->config->hash($token), date('Y-m-d H:i:s', time() + self::RESET_TTL_SECONDS));

        return ['reset_token' => $token, 'expires_in' => self::RESET_TTL_SECONDS];
    }

    /**
     * Fija la contraseña nueva y cierra todas las sesiones.
     *
     * @throws ApiException
     */
    public function resetPassword(string $resetToken, string $password): void
    {
        $record = $this->resetTokens->findValidToken($this->config->hash($resetToken));

        if ($record === false) {
            throw ApiException::invalid('El enlace para cambiar la contraseña venció. Pide un código nuevo.', 'invalid_token');
        }

        $this->inTransaction(function () use ($record, $password): void {
            $this->resetTokens->markAsUsed($record->id);
            $this->users->updatePasswordHash($record->user_id, $this->hasher->hash($password));
            $this->refreshTokens->revokeAllForUser($record->user_id);
        });
    }

    /**
     * Cambia la contraseña desde la cuenta. Cierra las demás sesiones y abre una nueva para esta.
     *
     * @throws ApiException
     */
    public function changePassword(int $userId, string $currentPassword, string $password, string $ip, string $userAgent): SessionResult
    {
        $user = $this->users->findById($userId);

        if ($user === false || !$this->hasher->verify($currentPassword, $user->password)) {
            throw ApiException::invalid(
                'La contraseña actual no coincide.',
                errors: ['current_password' => 'La contraseña actual no coincide.'],
            );
        }

        $this->inTransaction(function () use ($userId, $password): void {
            $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
            $this->refreshTokens->revokeAllForUser($userId);
        });

        return $this->openSession($userId, $ip, $userAgent);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function openSession(int $userId, string $ip, string $userAgent): SessionResult
    {
        /** @var AuthUser $user Releído: trae el entrenador recién asociado. */
        $user = $this->users->findById($userId);

        return new SessionResult(
            $this->accessToken($user),
            $this->jwt->ttlSeconds(),
            $user->toSessionUser(),
            $this->refreshTokens->issue(self::APP, $user->id, $ip, $userAgent),
        );
    }

    private function accessToken(AuthUser $user): string
    {
        return $this->jwt->issue(['sub' => $user->id, 'role' => $user->role, 'app' => self::APP]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{full_name:string, document_type_id:int, document_id:string, phone:string, email:string, password_hash:string, role:string, coupon_code:?string}
     * @throws ApiException
     */
    private function registrationData(array $input): array
    {
        $documentTypeId = (int) $input['document_type_id'];

        if (!$this->users->documentTypeExists($documentTypeId)) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['document_type_id' => 'Elige un tipo de documento.']);
        }

        return [
            'full_name'        => trim(preg_replace('/\s+/', ' ', (string) $input['full_name']) ?? ''),
            'document_type_id' => $documentTypeId,
            'document_id'      => trim((string) $input['document_id']),
            'phone'            => (string) PhoneNumber::normalize($input['phone']),
            'email'            => mb_strtolower(trim((string) $input['email'])),
            'password_hash'    => $this->hasher->hash((string) $input['password']),
            'role'             => (string) $input['role'],
            'coupon_code'      => $input['role'] === 'trainer' && trim((string) ($input['coupon_code'] ?? '')) !== ''
                ? strtoupper(trim((string) $input['coupon_code']))
                : null,
        ];
    }

    /** Un entrenador tiene su fila en `trainers` con el cupón; un cliente, no. */
    private function syncTrainerRow(int $userId, string $role, string $fullName, ?string $couponCode): void
    {
        $exists = $this->trainers->exists($userId);

        if ($role === 'trainer' && !$exists) {
            // El entrenador puede elegir su cupón; si no, se genera con su nombre.
            $this->trainers->create($userId, $couponCode ?? new CouponCodeGenerator($this->trainers)->generate($fullName));
        }

        if ($role === 'trainer' && $exists && $couponCode !== null) {
            $this->trainers->updateCoupon($userId, $couponCode);
        }

        if ($role === 'client' && $exists) {
            $this->trainers->delete($userId);
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
