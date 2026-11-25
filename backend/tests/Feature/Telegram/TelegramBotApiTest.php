<?php

declare(strict_types=1);

use App\Services\Telegram\TelegramBotApi;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

it('sends Bot API calls through the configured base URL', function () {
    config()->set('services.telegram.api_base', 'https://tg-proxy.test');

    Http::fake([
        'tg-proxy.test/*' => Http::response([
            'ok' => true,
            'result' => ['id' => 123456789],
        ]),
    ]);

    $response = app(TelegramBotApi::class)->call('123456789:test-token', 'getMe');

    expect($response->json('result.id'))->toBe(123456789);
    Http::assertSent(
        fn (ClientRequest $request): bool => $request->url()
            === 'https://tg-proxy.test/bot123456789:test-token/getMe'
    );
});
