<?php

declare(strict_types=1);

use App\Features\Account\AccountController;
use App\Features\Account\AddressController;
use App\Features\Account\AddressValidator;
use App\Features\Auth\AuthCleanupController;
use App\Features\Auth\AuthController;
use App\Features\Auth\Validators\ChangePasswordValidator;
use App\Features\Auth\Validators\CouponValidator;
use App\Features\Auth\Validators\LoginValidator;
use App\Features\Auth\Validators\PhoneCodeValidator;
use App\Features\Auth\Validators\PhoneValidator;
use App\Features\Auth\Validators\ProfileValidator;
use App\Features\Auth\Validators\RegisterValidator;
use App\Features\Auth\Validators\ResetPasswordValidator;
use App\Features\Carrier\CarrierController;
use App\Features\Carrier\ServientregaTrackingService;
use App\Features\Checkout\CheckoutController;
use App\Features\Checkout\CheckoutValidator;
use App\Features\CronJob\CronJobController;
use App\Features\CronJob\ScheduledJobsController;
use App\Features\DietAccess\DietAccessIntegrationController;
use App\Features\Notification\PushDeviceController;
use App\Features\Order\UserOrderController;
use App\Features\PaymentGateway\PaymentGatewayController;
use App\Features\Product\ProductController;
use App\Features\Reference\ReferenceController;
use App\Features\Referral\ReferralController;
use App\Features\Trainer\TrainerController;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Middlewares\CronJobTokenMiddleware;
use App\Infrastructure\Middlewares\JwtMiddleware;
use App\Infrastructure\Middlewares\RateLimitMiddleware;
use App\Infrastructure\Middlewares\RoleMiddleware;
use App\Infrastructure\Middlewares\ValidationMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/*
 | API de la app web. Contrato: specs/backend/03-api-de-premiumfitness.plan.md.
 |
 | Toda ruta exige la sesión de la app web (`$jwt`), salvo:
 |   - las de la sección «Sin sesión»;
 |   - los webhooks de pago (los firma cada pasarela);
 |   - los crons (`X-Cron-Job-Token`).
 | Una ruta nueva sin su credencial hace fallar tests/E2E/Security/RoutesRequireCredentialsTest.php.
 |
 | Los endpoints leen el usuario del token (`jwt_claims`): el id nunca llega por la URL.
 */
return static function (\Slim\App $app): void {
    $app->group('/api', function (RouteCollectorProxy $group) use ($app): void {
        $factory = $app->getResponseFactory();
        $jwt     = new JwtMiddleware(JwtService::fromEnv(), $factory);

        $validate  = static fn (object $validator): ValidationMiddleware => new ValidationMiddleware($validator, $factory, apiFormat: true);
        $limitByIp = static fn (string $id, int $limit, string $interval = '1 minute'): RateLimitMiddleware
            => new RateLimitMiddleware($factory, id: $id, limit: $limit, interval: $interval);
        // Los códigos SMS se limitan por celular: repartir los intentos entre varias IP no sirve.
        $limitByPhone = static fn (string $id): RateLimitMiddleware
            => new RateLimitMiddleware($factory, id: $id, limit: 3, interval: '1 minute', bodyField: 'phone');

        // =====================================================================
        // Sin sesión
        // =====================================================================
        $group->get('/health', function (Request $request, Response $response): Response {
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });

        // ---------------------------------------------------------------------
        // Autenticación (spec seguridad/01). Ningún endpoint entrega una sesión
        // sin contraseña o sin un código verificado.
        // ---------------------------------------------------------------------
        $authController = new AuthController();

        $group->post('/auth/register', [$authController, 'register'])
            ->add($validate(new RegisterValidator()))
            ->add($limitByPhone('auth-register-phone'))
            ->add($limitByIp('auth-register', 10));

        $group->post('/auth/verify-phone', [$authController, 'verifyPhone'])
            ->add($validate(new PhoneCodeValidator()))
            ->add($limitByIp('auth-verify-phone', 5));

        $group->post('/auth/resend-code', [$authController, 'resendCode'])
            ->add($validate(new PhoneValidator()))
            ->add($limitByPhone('auth-resend-code-phone'))
            ->add($limitByIp('auth-resend-code', 10));

        $group->post('/auth/login', [$authController, 'login'])
            ->add($validate(new LoginValidator()))
            ->add($limitByIp('auth-login', 5));

        $group->post('/auth/refresh', [$authController, 'refresh'])
            ->add($limitByIp('auth-refresh', 60));

        $group->post('/auth/logout', [$authController, 'logout']);

        $group->post('/auth/forgot-password', [$authController, 'forgotPassword'])
            ->add($validate(new PhoneValidator()))
            ->add($limitByPhone('auth-forgot-password-phone'))
            ->add($limitByIp('auth-forgot-password', 10));

        $group->post('/auth/verify-reset-code', [$authController, 'verifyResetCode'])
            ->add($validate(new PhoneCodeValidator()))
            ->add($limitByIp('auth-verify-reset-code', 5));

        $group->post('/auth/reset-password', [$authController, 'resetPassword'])
            ->add($validate(new ResetPasswordValidator()))
            ->add($limitByIp('auth-reset-password', 5));

        // ---------------------------------------------------------------------
        // Listas de los formularios y enlace del entrenador
        // ---------------------------------------------------------------------
        $referenceController = new ReferenceController();
        $referralController  = new ReferralController();

        $group->get('/document-types', [$referenceController, 'documentTypes']);
        $group->get('/cities/search', [$referenceController, 'searchCities'])
            ->add($limitByIp('cities-search', 60));
        $group->get('/referrals/trainers/{id:[A-Za-z0-9_=-]{1,32}}', [$referralController, 'trainerOfLink'])
            ->add($limitByIp('referral-link', 30));

        // =====================================================================
        // Con sesión de la app web (cliente o entrenador)
        // =====================================================================

        // ---------------------------------------------------------------------
        // Cuenta
        // ---------------------------------------------------------------------
        $accountController = new AccountController();

        $group->put('/auth/password', [$authController, 'changePassword'])
            ->add($validate(new ChangePasswordValidator()))
            ->add($limitByIp('auth-change-password', 5))
            ->add($jwt);

        $group->get('/account', [$accountController, 'show'])->add($jwt);
        $group->put('/account', [$accountController, 'update'])
            ->add($validate(new ProfileValidator()))
            ->add($jwt);

        $group->get('/localities', [$referenceController, 'localities'])->add($jwt);

        $pushDevices = new PushDeviceController();

        $group->post('/account/push-devices', [$pushDevices, 'register'])->add($limitByIp('push-devices', 30))->add($jwt);
        $group->delete('/account/push-devices', [$pushDevices, 'unregister'])->add($jwt);

        $group->post('/client/coupon', [$referralController, 'linkByCoupon'])
            ->add($validate(new CouponValidator()))
            ->add($limitByIp('client-coupon', 10))
            ->add($jwt);

        // ---------------------------------------------------------------------
        // Catálogo: se lee de la tabla de productos de la intranet (spec catalogo/01).
        // El precio sale calculado para el rol de quien consulta.
        // ---------------------------------------------------------------------
        $productController = new ProductController();

        $group->get('/products', [$productController, 'list'])->add($jwt);
        $group->get('/products/{code:[0-9]{1,12}}', [$productController, 'show'])->add($jwt);

        $addressController = new AddressController();
        $addressInput      = $validate(new AddressValidator());

        $group->get('/account/addresses', [$addressController, 'list'])->add($jwt);
        $group->post('/account/addresses', [$addressController, 'create'])->add($addressInput)->add($jwt);
        $group->put('/account/addresses/{id:[0-9]+}', [$addressController, 'update'])->add($addressInput)->add($jwt);
        $group->delete('/account/addresses/{id:[0-9]+}', [$addressController, 'delete'])->add($jwt);
        $group->patch('/account/addresses/{id:[0-9]+}/main', [$addressController, 'setMain'])->add($jwt);

        // ---------------------------------------------------------------------
        // Compra (specs negocio/01 y catalogo/01). La app envía códigos y cantidades;
        // el backend calcula el valor y firma el pago. El pedido lo aprueba el webhook
        // de la pasarela, nunca la app.
        // ---------------------------------------------------------------------
        $checkoutController = new CheckoutController();

        $group->post('/checkout/preview', [$checkoutController, 'preview'])
            ->add($limitByIp('checkout-preview', 60))
            ->add($jwt);
        $group->get('/checkout/payment-gateways', [$checkoutController, 'gateways'])->add($jwt);
        $group->post('/checkout', [$checkoutController, 'place'])
            ->add($validate(new CheckoutValidator()))
            ->add($limitByIp('checkout-place', 10))
            ->add($jwt);

        $userOrderController = new UserOrderController();

        $group->get('/orders', [$userOrderController, 'list'])->add($jwt);
        $group->get('/orders/{id:[0-9]+}', [$userOrderController, 'show'])->add($jwt);
        // Volver a pagar un pedido propio que quedó pendiente (por ejemplo, si cerró la ventana de Bold).
        $group->post('/orders/{id:[0-9]+}/pay', [$checkoutController, 'payAgain'])
            ->add($limitByIp('orders-pay', 10))
            ->add($jwt);

        $pgController = new PaymentGatewayController();

        // =====================================================================
        // Área del entrenador: además de la sesión, exige `role = trainer`.
        // Los guards de la app solo ocultan pantallas; el rol se comprueba aquí.
        // =====================================================================
        $trainerOnly       = new RoleMiddleware('trainer', $factory);
        $trainerController = new TrainerController();

        $group->get('/trainer/dashboard', [$trainerController, 'dashboard'])->add($trainerOnly)->add($jwt);
        $group->get('/trainer/referrals', [$trainerController, 'referrals'])->add($trainerOnly)->add($jwt);
        $group->get('/trainer/points', [$trainerController, 'points'])->add($trainerOnly)->add($jwt);
        $group->get('/trainer/commission', [$trainerController, 'commission'])->add($trainerOnly)->add($jwt);
        $group->get('/trainer/commission/settlements/{id:[0-9]+}/receipt', [$trainerController, 'receipt'])->add($trainerOnly)->add($jwt);
        $group->get('/trainer/commission/conversion-preview', [$trainerController, 'conversionPreview'])->add($trainerOnly)->add($jwt);
        $group->post('/trainer/commission/convert', [$trainerController, 'convert'])
            ->add($limitByIp('trainer-convert', 10))->add($trainerOnly)->add($jwt);
        $group->get('/trainer/diet-access', [$trainerController, 'dietAccess'])->add($trainerOnly)->add($jwt);
        $group->post('/trainer/diet-access/redeem', [$trainerController, 'redeemDietAccess'])
            ->add($limitByIp('trainer-diet-redeem', 10))->add($trainerOnly)->add($jwt);
        $group->post('/trainer/redemptions/preview', [$trainerController, 'redemptionPreview'])
            ->add($limitByIp('trainer-redemption-preview', 60))->add($trainerOnly)->add($jwt);
        $group->post('/trainer/redemptions', [$trainerController, 'redeem'])
            ->add($limitByIp('trainer-redemption', 10))->add($trainerOnly)->add($jwt);

        // ---------------------------------------------------------------------
        // Transportadoras
        // ---------------------------------------------------------------------
        $carrierController = new CarrierController(ServientregaTrackingService::fromEnv());

        $group->get('/carriers/servientrega/tracking', [$carrierController, 'getServientregaTracking'])
            ->add($limitByIp('carrier-servientrega-tracking', 30))
            ->add($jwt);

        // =====================================================================
        // Servidor a servidor
        // =====================================================================

        // Acceso a la app de dietas: lo consulta su backend por documento, con `X-Api-Key`
        // (spec integraciones/02). Se limita por IP y por documento.
        $group->post('/integrations/diet-access', [new DietAccessIntegrationController(), 'check'])
            ->add(new RateLimitMiddleware($factory, id: 'diet-access-document', limit: 30, interval: '1 minute', bodyField: 'document'))
            ->add($limitByIp('diet-access', 300));

        // Webhook de pago de Bold: se verifica su firma y que lo pagado cubra el pedido.
        // No existen aquí el webhook genérico de radical (`/webhooks/orders`), que cambiaba el estado
        // de un pedido sin firma, ni los de Addi y Sistecrédito, que aceptaban el aviso sin verificarlo.
        $group->post('/payment-gateway/webhook/bold', [$pgController, 'webhookBold'])
            ->add($limitByIp('pg-wh-bold', 60));

        // Crons: el cron de cPanel llama con `X-Cron-Job-Token`. Nunca se programan dentro de la app.
        $cronToken         = CronJobTokenMiddleware::fromEnv($factory);
        $cronJobController = new CronJobController();

        $group->post('/cron-job/payment-event-jobs/process', [$cronJobController, 'processPaymentEventJobs'])
            ->add($limitByIp('cron-payment-events', 10))
            ->add($cronToken);
        $group->get('/cron-job/payment-gateway/bold/pending', [$pgController, 'boldPendingCron'])
            ->add($cronToken);
        $group->post('/cron-job/auth/cleanup', [new AuthCleanupController(), 'cleanup'])
            ->add($cronToken);

        $scheduledJobs = new ScheduledJobsController();

        $group->post('/cron-job/commission/close', [$scheduledJobs, 'closeCommission'])->add($cronToken);
        $group->post('/cron-job/points/expire', [$scheduledJobs, 'expirePoints'])->add($cronToken);
        $group->post('/cron-job/diet-access/notify', [$scheduledJobs, 'notifyDietAccess'])->add($cronToken);
        $group->post('/cron-job/push/send', [$scheduledJobs, 'sendPush'])->add($cronToken);
    });
};
