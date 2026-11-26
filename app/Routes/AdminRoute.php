<?php

declare(strict_types=1);

use App\Features\Adjustment\AdjustmentController;
use App\Features\Admin\AdminAuthController;
use App\Features\Admin\AdminTrainerController;
use App\Features\Commission\CommissionAdminController;
use App\Features\DiscountRule\DiscountRuleAdminController;
use App\Features\Admin\AdminLoginValidator;
use App\Features\Admin\EmpleadoRepository;
use App\Features\Order\OrderController;
use App\Features\Order\OrderAgentIdValidator;
use App\Features\Order\OrderSapCodeByAgentValidator;
use App\Features\Order\OrderSapCodeValidator;
use App\Features\Order\OrderTrackingValidator;
use App\Features\PaymentGateway\PaymentGatewayController;
use App\Features\Reports\ReportsController;
use App\Features\Reports\RedemptionReportController;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Middlewares\AdminMiddleware;
use App\Infrastructure\Middlewares\AdminRoleMiddleware;
use App\Infrastructure\Middlewares\RateLimitMiddleware;
use App\Infrastructure\Middlewares\ValidationMiddleware;
use Slim\Routing\RouteCollectorProxy;

return static function (\Slim\App $app): void {
    $app->group('/api/admin', function (RouteCollectorProxy $group) use ($app): void {

        $adminMiddleware = new AdminMiddleware(JwtService::fromEnv(), $app->getResponseFactory());
        $orderController = new OrderController();
        $reportsController = new ReportsController();

        // -----------------------------------------------------------------
        // Auth
        // -----------------------------------------------------------------
        $authController = new AdminAuthController();

        $group->post('/auth/login', [$authController, 'login'])
            ->add(new ValidationMiddleware(new AdminLoginValidator(), $app->getResponseFactory()))
            ->add(new RateLimitMiddleware($app->getResponseFactory(), id: 'admin-auth-login', limit: 5, interval: '1 minute'));

        // La cookie `pf_rt_admin` solo viaja a /api/admin/auth (spec seguridad/01).
        $group->post('/auth/refresh', [$authController, 'refresh'])
            ->add(new RateLimitMiddleware($app->getResponseFactory(), id: 'admin-auth-refresh', limit: 60, interval: '1 minute'));
        $group->post('/auth/logout', [$authController, 'logout']);

        // -----------------------------------------------------------------
        // Orders — CRUD
        // -----------------------------------------------------------------
        $group->get('/orders', [$orderController, 'list'])
            ->add($adminMiddleware);

        $group->get('/orders/{id:[0-9]+}', [$orderController, 'getById'])
            ->add($adminMiddleware);

        // No hay cambio de estado a mano: el estado del pago lo cambia solo la pasarela con su webhook,
        // y «REVERSADA» se pone con el ajuste «Reversar», que quita los puntos y la comisión (P-26, P-29).

        $group->patch('/orders/{id:[0-9]+}/sap-order-code', [$orderController, 'updateSapOrderCode'])
            ->add(new ValidationMiddleware(new OrderSapCodeValidator(), $app->getResponseFactory()))
            ->add($adminMiddleware);

        $group->patch('/orders/{id:[0-9]+}/sap-order-code/by-agent', [$orderController, 'updateSapOrderCodeByAgent'])
            ->add(new ValidationMiddleware(new OrderSapCodeByAgentValidator(), $app->getResponseFactory()))
            ->add($adminMiddleware);

        $group->patch('/orders/{id:[0-9]+}/tracking-number', [$orderController, 'updateTrackingNumber'])
            ->add(new ValidationMiddleware(new OrderTrackingValidator(), $app->getResponseFactory()))
            ->add($adminMiddleware);

        $group->patch('/orders/{id:[0-9]+}/agent-id', [$orderController, 'updateAgentId'])
            ->add(new ValidationMiddleware(new OrderAgentIdValidator(), $app->getResponseFactory()))
            ->add($adminMiddleware);

        $group->patch('/orders/{id:[0-9]+}/take', [$orderController, 'takeByAgent'])
            ->add(new ValidationMiddleware(new OrderAgentIdValidator(), $app->getResponseFactory()))
            ->add($adminMiddleware);

        $group->patch('/orders/{id:[0-9]+}/release', [$orderController, 'releaseByAgent'])
            ->add(new ValidationMiddleware(new OrderAgentIdValidator(), $app->getResponseFactory()))
            ->add($adminMiddleware);

        // -----------------------------------------------------------------
        // Agents — consulta de tb_empleados
        // -----------------------------------------------------------------
        $empleadoRepository = new EmpleadoRepository();

        $group->get('/agents', function (Psr\Http\Message\ServerRequestInterface $request, Psr\Http\Message\ResponseInterface $response) use ($empleadoRepository): Psr\Http\Message\ResponseInterface {
            $empleados = $empleadoRepository->findAll();
            $data = array_map(static fn ($e) => [
                'id'           => $e->id,
                'id_usuario'   => $e->id_usuario,
                'email'        => $e->email,
                'usuario'      => $e->usuario,
                'departamento' => $e->departamento,
                'foto'         => $e->foto,
                'celular'      => $e->celular,
                'sede'         => $e->sede,
                'cargo'        => $e->cargo,
                'F'            => $e->F,
            ], $empleados);
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        })->add($adminMiddleware);

        $group->get('/agents/{id:[0-9]+}', function (Psr\Http\Message\ServerRequestInterface $request, Psr\Http\Message\ResponseInterface $response, int $id) use ($empleadoRepository): Psr\Http\Message\ResponseInterface {
            $empleado = $empleadoRepository->findById($id);
            if ($empleado === false) {
                $response->getBody()->write(json_encode(['error' => 'Agent not found'], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            $data = [
                'id'           => $empleado->id,
                'id_usuario'   => $empleado->id_usuario,
                'email'        => $empleado->email,
                'usuario'      => $empleado->usuario,
                'departamento' => $empleado->departamento,
                'foto'         => $empleado->foto,
                'celular'      => $empleado->celular,
                'sede'         => $empleado->sede,
                'cargo'        => $empleado->cargo,
                'F'            => $empleado->F,
            ];
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        })->add($adminMiddleware);

        // =================================================================
        // Reports
        // =================================================================

        // Dashboard
        $group->get('/reports/dashboard', [$reportsController, 'dashboard'])
            ->add($adminMiddleware);

        // Sales — time series
        $group->get('/reports/sales/monthly', [$reportsController, 'salesMonthly'])
            ->add($adminMiddleware);

        $group->get('/reports/sales/daily', [$reportsController, 'salesDaily'])
            ->add($adminMiddleware);

        $group->get('/reports/sales/by-hour', [$reportsController, 'salesByHour'])
            ->add($adminMiddleware);

        // Sales — grouping
        $group->get('/reports/sales/by-gateway', [$reportsController, 'salesByGateway'])
            ->add($adminMiddleware);

        $group->get('/reports/sales/by-status', [$reportsController, 'salesByStatus'])
            ->add($adminMiddleware);

        // Rankings
        $group->get('/reports/top-products', [$reportsController, 'topProducts'])
            ->add($adminMiddleware);

        $group->get('/reports/top-customers', [$reportsController, 'topCustomers'])
            ->add($adminMiddleware);

        $group->get('/reports/top-categories', [$reportsController, 'topCategories'])
            ->add($adminMiddleware);

        // Geo
        $group->get('/reports/sales/by-city', [$reportsController, 'salesByCity'])
            ->add($adminMiddleware);

        $group->get('/reports/sales/by-department', [$reportsController, 'salesByDepartment'])
            ->add($adminMiddleware);



        // Customers
        $group->get('/reports/customers/new-vs-returning', [$reportsController, 'newVsReturning'])
            ->add($adminMiddleware);

        $group->get('/reports/customers/frequency', [$reportsController, 'customerFrequency'])
            ->add($adminMiddleware);

        // Users
        $group->get('/reports/users', [$reportsController, 'usersList'])
            ->add($adminMiddleware);

        // Products
        $group->get('/reports/products/performance', [$reportsController, 'productPerformance'])
            ->add($adminMiddleware);

        $group->get('/reports/products/stock-status', [$reportsController, 'stockStatus'])
            ->add($adminMiddleware);

        $group->get('/reports/products/discount-impact', [$reportsController, 'discountImpact'])
            ->add($adminMiddleware);

        // Carriers
        $group->get('/reports/carriers/distribution', [$reportsController, 'carrierDistribution'])
            ->add($adminMiddleware);

        // Approved orders with agents (SAP processing)
        $group->get('/reports/orders', [$reportsController, 'orders'])
            ->add($adminMiddleware);

        $group->get('/reports/approved-orders-with-agents', [$reportsController, 'approvedOrdersWithAgents'])
            ->add($adminMiddleware);

        // Downloads
        $group->get('/reports/orders/export', [$reportsController, 'ordersExport'])
            ->add($adminMiddleware);

        $group->get('/reports/orders/download', [$reportsController, 'ordersDownload'])
            ->add($adminMiddleware);

        $group->get('/reports/users/download', [$reportsController, 'usersDownload'])
            ->add($adminMiddleware);

        $group->get('/reports/products/download', [$reportsController, 'productsDownload'])
            ->add($adminMiddleware);


        // -----------------------------------------------------------------
        // Gestión de PremiumFitness: solo los cargos de `ADMIN_MANAGER_ROLES`.
        // -----------------------------------------------------------------
        $managersOnly = AdminRoleMiddleware::managersFromEnv($app->getResponseFactory());

        // Entrenadores
        $trainerController = new AdminTrainerController();

        $group->get('/trainers', [$trainerController, 'list'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/trainers/{id:[0-9]+}', [$trainerController, 'show'])->add($managersOnly)->add($adminMiddleware);
        $group->patch('/trainers/{id:[0-9]+}/status', [$trainerController, 'updateStatus'])->add($managersOnly)->add($adminMiddleware);

        // Reporte de canjes con puntos (spec negocio/07): muestra lo que gasta cada entrenador.
        $redemptionReport = new RedemptionReportController();

        $group->get('/reports/redemptions', [$redemptionReport, 'summary'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/reports/redemptions/export', [$redemptionReport, 'export'])->add($managersOnly)->add($adminMiddleware);

        // Estrategias por producto (spec negocio/01)
        $discountRules = new DiscountRuleAdminController();

        $group->get('/discount-rules', [$discountRules, 'list'])->add($managersOnly)->add($adminMiddleware);
        $group->post('/discount-rules', [$discountRules, 'create'])->add($managersOnly)->add($adminMiddleware);
        $group->put('/discount-rules/{id:[0-9]+}', [$discountRules, 'update'])->add($managersOnly)->add($adminMiddleware);
        $group->delete('/discount-rules/{id:[0-9]+}', [$discountRules, 'deactivate'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/products', [$discountRules, 'products'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/settings/business', [$discountRules, 'businessSettings'])->add($managersOnly)->add($adminMiddleware);

        // Cierre mensual y pago de la comisión (specs negocio/04 y negocio/05).
        // El cierre lo dispara el cron; aquí se consulta y se registra cada pago.
        $commission = new CommissionAdminController();

        $group->get('/commission/periods', [$commission, 'periods'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/commission/periods/{id:[0-9]+}', [$commission, 'period'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/commission/periods/{id:[0-9]+}/report', [$commission, 'report'])->add($managersOnly)->add($adminMiddleware);
        $group->post('/commission/settlements/{id:[0-9]+}/pay', [$commission, 'pay'])->add($managersOnly)->add($adminMiddleware);
        // POST y no PUT: PHP solo lee un formulario con archivo (multipart) en un POST.
        $group->post('/commission/settlements/{id:[0-9]+}/payment', [$commission, 'correctPayment'])->add($managersOnly)->add($adminMiddleware);
        $group->get('/commission/settlements/{id:[0-9]+}/receipt', [$commission, 'receipt'])->add($managersOnly)->add($adminMiddleware);

        // Ajustes por cancelación o devolución (RF-17). Los ve quien ve el pedido; los registra un gestor.
        $adjustments = new AdjustmentController();

        $group->get('/orders/{id:[0-9]+}/adjustments', [$adjustments, 'list'])->add($adminMiddleware);
        $group->post('/orders/{id:[0-9]+}/adjustments/preview', [$adjustments, 'preview'])->add($managersOnly)->add($adminMiddleware);
        $group->post('/orders/{id:[0-9]+}/adjustments', [$adjustments, 'create'])->add($managersOnly)->add($adminMiddleware);

        // -----------------------------------------------------------------
        // Pasarela Bold — estado y conciliación de un pago (uso del call center)
        // -----------------------------------------------------------------
        $pgController = new PaymentGatewayController();

        $group->get('/payment-gateway/state/bold', [$pgController, 'boldState'])
            ->add($adminMiddleware);
        $group->post('/payment-gateway/state/bold/reconcile', [$pgController, 'boldReconcile'])
            ->add($adminMiddleware);
    });
};
