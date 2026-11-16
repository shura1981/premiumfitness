<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Bold\BoldSignatureVerifier;

it('accepts a raw webhook body signed with HMAC-SHA256 over base64(rawBody)', function (): void {
    $secretKey = 'QtZt50jk7Wgc0SZuceq-Aw';
    $rawBody   = '{"type":"SALE_APPROVED","data":{"payment_id":"TX123","metadata":{"reference":"501"}}}';
    $signature = hash_hmac('sha256', base64_encode($rawBody), $secretKey);

    $verifier = new BoldSignatureVerifier($secretKey);

    expect($verifier->verify($rawBody, $signature))->toBeTrue()
        ->and($verifier->verify($rawBody, strtoupper($signature)))->toBeTrue();
});

it('rejects a tampered raw body', function (): void {
    $secretKey = 'QtZt50jk7Wgc0SZuceq-Aw';
    $rawBody   = '{"type":"SALE_APPROVED","data":{"amount":{"total":100000}}}';
    $signature = hash_hmac('sha256', base64_encode($rawBody), $secretKey);
    $tampered  = '{"type":"SALE_APPROVED","data":{"amount":{"total":999999}}}';

    expect((new BoldSignatureVerifier($secretKey))->verify($tampered, $signature))->toBeFalse();
});

it('rejects missing signature, empty body or empty secret key', function (): void {
    $rawBody = '{"type":"SALE_APPROVED"}';

    expect((new BoldSignatureVerifier('secret'))->verify($rawBody, ''))->toBeFalse()
        ->and((new BoldSignatureVerifier('secret'))->verify('   ', 'abcd'))->toBeFalse()
        ->and((new BoldSignatureVerifier(''))->verify($rawBody, 'abcd'))->toBeFalse();
});

