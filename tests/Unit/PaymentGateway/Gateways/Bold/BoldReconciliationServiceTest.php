<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Bold\BoldReconciliationService;

it('selects the latest event per payment_id so a later VOID_APPROVED overrides SALE_APPROVED', function (): void {
    $decoded = [
        'notifications' => [
            [
                'type' => 'SALE_APPROVED',
                'time' => 1700000100,
                'data' => [
                    'payment_id' => 'PAY-1',
                    'metadata' => ['reference' => '9001'],
                ],
            ],
            [
                'type' => 'VOID_APPROVED',
                'time' => 1700000500,
                'data' => [
                    'payment_id' => 'PAY-1',
                    'metadata' => ['reference' => '9001'],
                ],
            ],
        ],
    ];

    $selected = BoldReconciliationService::selectRelevantNotification($decoded, '9001', true);

    expect($selected)->not->toBeNull()
        ->and($selected['type'] ?? null)->toBe('VOID_APPROVED');
});

it('prioritizes an approved payment attempt over a newer rejected attempt for the same order', function (): void {
    $decoded = [
        'notifications' => [
            [
                'type' => 'SALE_REJECTED',
                'time' => 1700000100,
                'data' => [
                    'payment_id' => 'PAY-ATTEMPT-1',
                    'metadata' => ['reference' => '9002'],
                ],
            ],
            [
                'type' => 'SALE_APPROVED',
                'time' => 1700000200,
                'data' => [
                    'payment_id' => 'PAY-ATTEMPT-2',
                    'metadata' => ['reference' => '9002'],
                ],
            ],
            [
                'type' => 'SALE_REJECTED',
                'time' => 1700000300,
                'data' => [
                    'payment_id' => 'PAY-ATTEMPT-3',
                    'metadata' => ['reference' => '9002'],
                ],
            ],
        ],
    ];

    $selected = BoldReconciliationService::selectRelevantNotification($decoded, '9002', true);

    expect($selected)->not->toBeNull()
        ->and($selected['type'] ?? null)->toBe('SALE_APPROVED')
        ->and($selected['data']['payment_id'] ?? null)->toBe('PAY-ATTEMPT-2');
});

it('matches by payment_id when querying non-external reference', function (): void {
    $decoded = [
        'type' => 'SALE_APPROVED',
        'time' => 1700000999,
        'data' => [
            'payment_id' => 'BOLD_DIRECT_77',
            'metadata' => ['reference' => '9003'],
        ],
    ];

    $selected = BoldReconciliationService::selectRelevantNotification($decoded, 'BOLD_DIRECT_77', false);
    $unmatched = BoldReconciliationService::selectRelevantNotification($decoded, 'OTHER_ID', false);

    expect($selected)->not->toBeNull()
        ->and($selected['data']['payment_id'] ?? null)->toBe('BOLD_DIRECT_77')
        ->and($unmatched)->toBeNull();
});

