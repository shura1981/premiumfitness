<?php

declare(strict_types=1);

use App\Features\PaymentGateway\PaymentGatewayController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return static function (\Slim\App $app): void {

    // El backend no sirve páginas: la app web y el admin viven en sus subdominios (spec arquitectura/02).
    $app->get('/', function (Request $request, Response $response): Response {
        $response->getBody()->write(json_encode(['name' => 'PremiumFitness API', 'status' => 'ok'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    });

    // La tienda pública HTML de radical no existe aquí: PremiumFitness no vende sin sesión.
    // La recuperación de la contraseña va por la API y por SMS (spec seguridad/01, regla 7),
    // y el autologin por QR (`/qr`) tampoco existe: el «QR» es el enlace del entrenador (regla 12).

    // -------------------------------------------------------------------------
    // Payment Gateway Response Pages — HTML público para redirects del proveedor
    // -------------------------------------------------------------------------
    $paymentGatewayController = new PaymentGatewayController();

    $app->get('/response/bold', [$paymentGatewayController, 'responseBold']);

    // Las apps Angular (web y admin) no se sirven desde el backend: cada una se despliega como
    // estáticos en su subdominio (spec arquitectura/02).
};
