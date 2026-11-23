<?php

declare(strict_types=1);

namespace App\Features\Order;

class OrderModel
{
    public int     $order_id;
    public int     $user_id;
    public ?int    $agent_id          = null;
    public string  $amount;
    /** `client_purchase`, `trainer_purchase` o `redemption` (canje con puntos). */
    public string  $order_type = 'client_purchase';
    /** Puntos que costó un canje; null en una compra. */
    public ?int    $redemption_points = null;
    /** null en un canje con puntos: no pasa por la pasarela. */
    public ?string $payment_gateway = null;
    public ?string $payment_state_json = null;
    public ?int    $sap_order_code    = null;
    public ?string $tracking_number   = null;
    public ?string $carrier_name      = null;
    public ?int    $status_id         = null;
    public ?int    $address_id        = null;
    public ?string $order_notes       = null;
    public string  $created_at;
    public ?string $updated_at        = null;

    /** Nombre del estado (JOIN status_orders.value). Null si sin status. */
    public ?string $status_value      = null;

    /** Datos del usuario (JOIN users + document_types). */
    public ?string $user_full_name    = null;
    public ?string $user_email        = null;
    public ?string $user_num_cell     = null;
    public ?string $user_document_id  = null;
    public ?int    $user_document_type_id = null;
    public ?string $user_document_type_name = null;

    /** Datos de entrega (JOIN addresses + cities + address_locality_map + localities). */
    public ?string $delivery_address  = null;
    public ?string $delivery_address_name = null;
    public ?int    $delivery_city_id  = null;
    public ?string $delivery_city_name = null;
    public ?string $delivery_department = null;
    public ?string $delivery_city_dept = null;
    public ?string $delivery_dan_code = null;
    public ?int    $delivery_locality_id = null;
    public ?string $delivery_locality_name = null;

    /** Datos del agente asignado (JOIN crisenri_intranet.tb_empleados). */
    public ?string $agent_name = null;
    public ?string $agent_photo = null;
    public ?string $agent_email = null;
    public ?string $agent_cellphone = null;
    public ?string $agent_role = null;
    public ?string $agent_department = null;

    /** @var list<OrderItemModel> Items cargados en findById. */
    public array $items = [];
}
