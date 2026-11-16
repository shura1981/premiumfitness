<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Features\Order\OrderRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReportsController
{
    private ReportsRepository $repository;
    private OrderRepository $orderRepository;

    public function __construct()
    {
        $this->repository = new ReportsRepository();
        $this->orderRepository = new OrderRepository();
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function dashboard(Request $request, Response $response): Response
    {
        return $this->json($response, $this->repository->dashboard());
    }

    // =========================================================================
    // SALES — TIME SERIES
    // =========================================================================

    public function salesMonthly(Request $request, Response $response): Response
    {
        $year = (int) ($request->getQueryParams()['year'] ?? date('Y'));

        return $this->json($response, $this->repository->salesMonthly($year));
    }

    public function salesDaily(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $dateFrom = (string) ($query['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
        $dateTo   = (string) ($query['date_to'] ?? date('Y-m-d'));

        return $this->json($response, $this->repository->salesDaily($dateFrom, $dateTo));
    }

    // =========================================================================
    // SALES — BY GATEWAY
    // =========================================================================

    public function salesByGateway(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->salesByGateway($dateFrom, $dateTo));
    }

    // =========================================================================
    // SALES — BY STATUS
    // =========================================================================

    public function salesByStatus(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->salesByStatus($dateFrom, $dateTo));
    }

    // =========================================================================
    // SALES — BY HOUR
    // =========================================================================

    public function salesByHour(Request $request, Response $response): Response
    {
        $date = (string) ($request->getQueryParams()['date'] ?? date('Y-m-d'));

        return $this->json($response, $this->repository->salesByHour($date));
    }

    // =========================================================================
    // RANKINGS
    // =========================================================================

    public function topProducts(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $limit = (int) ($request->getQueryParams()['limit'] ?? 10);

        return $this->json($response, $this->repository->topProducts($dateFrom, $dateTo, $limit));
    }

    public function topCustomers(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $limit = (int) ($request->getQueryParams()['limit'] ?? 10);

        return $this->json($response, $this->repository->topCustomers($dateFrom, $dateTo, $limit));
    }

    public function topCategories(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->topCategories($dateFrom, $dateTo));
    }

    // =========================================================================
    // GEO
    // =========================================================================

    public function salesByCity(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $limit = max(1, min(100, (int) ($request->getQueryParams()['limit'] ?? 20)));

        return $this->json($response, $this->repository->salesByCity($dateFrom, $dateTo, $limit));
    }

    public function salesByDepartment(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->salesByDepartment($dateFrom, $dateTo));
    }


    // =========================================================================
    // CUSTOMERS
    // =========================================================================

    public function newVsReturning(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->newVsReturning($dateFrom, $dateTo));
    }

    public function customerFrequency(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->customerFrequency($dateFrom, $dateTo));
    }

    // =========================================================================
    // PRODUCTS
    // =========================================================================

    public function productPerformance(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        return $this->json($response, $this->repository->productPerformance($dateFrom, $dateTo, $page, $perPage));
    }

    public function stockStatus(Request $request, Response $response): Response
    {
        return $this->json($response, $this->repository->stockStatus());
    }

    public function discountImpact(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->discountImpact($dateFrom, $dateTo));
    }

    // =========================================================================
    // CARRIERS
    // =========================================================================

    public function carrierDistribution(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->carrierDistribution($dateFrom, $dateTo));
    }

    // =========================================================================
    // APPROVED ORDERS WITH AGENTS
    // =========================================================================

    public function approvedOrdersWithAgents(Request $request, Response $response): Response
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);

        return $this->json($response, $this->repository->approvedOrdersWithAgents($dateFrom, $dateTo));
    }

    // =========================================================================
    // ORDERS REPORT (PAGINADO)
    // =========================================================================

    public function orders(Request $request, Response $response): Response
    {
        [$page, $perPage] = $this->pagination($request);
        $filters = $this->extractOrdersFilters($request);

        return $this->json(
            $response,
            $this->orderRepository->findProcessedForReports($page, $perPage, $filters)
        );
    }

    /**
     * Export de pedidos procesados sin paginación para Excel.
     */
    public function ordersExport(Request $request, Response $response): Response
    {
        $filters = $this->extractOrdersFilters($request);
        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $rangeError = $this->validateMandatoryOneMonthRange($dateFrom, $dateTo);

        if ($rangeError !== null) {
            return $this->json($response, [
                'error' => $rangeError,
            ], 422);
        }

        return $this->json(
            $response,
            $this->orderRepository->exportProcessedRowsForReports($filters)
        );
    }

    // =========================================================================
    // USERS
    // =========================================================================

    public function usersList(Request $request, Response $response): Response
    {
        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));
        $q       = (string) ($query['q'] ?? '');

        return $this->json($response, $this->repository->usersList($page, $perPage, $q));
    }

    // =========================================================================
    // DOWNLOADS
    // =========================================================================

    public function ordersDownload(Request $request, Response $response): Response
    {
        $query    = $request->getQueryParams();
        $dateFrom = (string) ($query['date_from'] ?? '');
        $dateTo   = (string) ($query['date_to'] ?? '');
        $status   = (string) ($query['status'] ?? '');
        $gateway  = (string) ($query['gateway'] ?? '');

        return $this->json($response, $this->repository->ordersDownload($dateFrom, $dateTo, $status, $gateway));
    }

    public function productsDownload(Request $request, Response $response): Response
    {
        $query    = $request->getQueryParams();
        $dateFrom = (string) ($query['date_from'] ?? '');
        $dateTo   = (string) ($query['date_to'] ?? '');

        return $this->json($response, $this->repository->productsDownload($dateFrom, $dateTo));
    }

    public function usersDownload(Request $request, Response $response): Response
    {
        $query    = $request->getQueryParams();
        $dateFrom = (string) ($query['date_from'] ?? '');
        $dateTo   = (string) ($query['date_to'] ?? '');
        $q        = (string) ($query['q'] ?? '');

        return $this->json($response, $this->repository->usersDownload($dateFrom, $dateTo, $q));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** @return array{string, string} */
    private function dateRange(Request $request): array
    {
        $query    = $request->getQueryParams();
        $dateFrom = (string) ($query['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
        $dateTo   = (string) ($query['date_to'] ?? date('Y-m-d'));

        return [$dateFrom, $dateTo];
    }

    /** @return array{0: int, 1: int} */
    private function pagination(Request $request): array
    {
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        return [$page, $perPage];
    }

    /**
     * @return array{status_id?: int, q?: string, date_from?: string, date_to?: string, sort_order?: string, sort_by?: string}
     */
    private function extractOrdersFilters(Request $request): array
    {
        $query = $request->getQueryParams();
        $filters = [];

        if (isset($query['status_id']) && is_numeric($query['status_id'])) {
            $filters['status_id'] = (int) $query['status_id'];
        }

        if (!empty($query['q']) && is_string($query['q'])) {
            $filters['q'] = $query['q'];
        }

        if (!empty($query['date_from']) && is_string($query['date_from'])) {
            $filters['date_from'] = $query['date_from'];
        }

        if (!empty($query['date_to']) && is_string($query['date_to'])) {
            $filters['date_to'] = $query['date_to'];
        }

        if (!empty($query['sort_order']) && is_string($query['sort_order'])) {
            $filters['sort_order'] = $query['sort_order'];
        }

        if (!empty($query['sort_by']) && is_string($query['sort_by'])) {
            $filters['sort_by'] = $query['sort_by'];
        }

        return $filters;
    }

    private function validateMandatoryOneMonthRange(string $dateFrom, string $dateTo): ?string
    {
        if ($dateFrom === '' || $dateTo === '') {
            return 'date_from y date_to son obligatorios.';
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);

        if (!$from || !$to || $from->format('Y-m-d') !== $dateFrom || $to->format('Y-m-d') !== $dateTo) {
            return 'date_from y date_to deben tener formato YYYY-MM-DD.';
        }

        if ($to < $from) {
            return 'date_to no puede ser menor que date_from.';
        }

        $maxTo = $from->modify('+1 month');

        if ($to > $maxTo) {
            return 'El rango de fechas no puede ser mayor a un mes.';
        }

        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
