<?php
/**
 * Helper: Construye el body JSON para la solicitud de pago a Credinet (Sistecrédito)
 *
 * Uso: require_once 'helpers/createPaymentRequestBody.php';
 *
 * Ver: ../../../references/sistecredito-api.md#crear-orden
 */

/**
 * Genera el body JSON para POST https://api.credinet.co/pay/create
 *
 * @param string $invoice           Número de factura/pedido interno
 * @param string $description       Descripción del pago (visible al usuario)
 * @param int    $paymentMethodId   Siempre 2 para Sistecrédito — no cambiar
 * @param string $currency          Moneda: siempre "COP"
 * @param int    $value             Monto en COP sin decimales
 * @param bool   $sandboxIsActive   false en producción, true en pruebas
 * @param string $sandboxStatus     "Approved" | "Rejected" | "Pending" (solo aplica si sandboxIsActive es true)
 * @param string $urlResponse       URL de redirección post-pago (e-commerce o link de pago)
 * @param string $urlConfirmation   URL del webhook de confirmación (server-to-server)
 * @param string $methodConfirmation Método HTTP del webhook: "POST"
 * @param string $docType           Tipo de documento: "CC", "CE", "NIT", "TI", "PP"
 * @param string $document          Número de documento del cliente
 * @param string $authentication    Información de autenticación adicional (opcional, dejar vacío)
 * @return string  JSON codificado listo para enviar como CURLOPT_POSTFIELDS
 */
function createPaymentRequestBody(
    string $invoice,
    string $description,
    int    $paymentMethodId,
    string $currency,
    int    $value,
    bool   $sandboxIsActive,
    string $sandboxStatus,
    string $urlResponse,
    string $urlConfirmation,
    string $methodConfirmation,
    string $docType,
    string $document,
    string $authentication = ''
): string {
    $data = [
        'invoice'            => $invoice,
        'description'        => $description,
        'paymentMethod'      => [
            'paymentMethodId' => $paymentMethodId,  // 2 = Sistecrédito
        ],
        'currency'           => $currency,           // "COP"
        'value'              => $value,
        'sandbox'            => [
            'isActive' => $sandboxIsActive,          // false en producción
            'status'   => $sandboxStatus,            // "Approved" solo en sandbox
        ],
        'urlResponse'        => $urlResponse,
        'urlConfirmation'    => $urlConfirmation,
        'methodConfirmation' => $methodConfirmation, // "POST"
        'client'             => [
            'docType'  => $docType,   // "CC", "CE", "NIT", "TI", "PP"
            'document' => $document,
        ],
        'extraData'          => [
            'Authentication' => $authentication,     // vacío en la mayoría de los casos
        ],
    ];

    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
