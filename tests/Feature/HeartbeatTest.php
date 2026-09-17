<?php

use App\Support\Heartbeat;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

it('pinga l endpoint di fallimento quando un job fallisce', function () {
    config(['services.healthchecks.ping_url' => 'https://hc.example/ping/abc']);
    Http::fake();

    event(new JobFailed('database', Mockery::mock(Job::class), new RuntimeException('boom')));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.example/ping/abc/fail');
});

it('non pinga nulla quando l URL non e configurato', function () {
    config(['services.healthchecks.ping_url' => null]);
    Http::fake();

    app(Heartbeat::class)->ok();
    app(Heartbeat::class)->failed();

    Http::assertNothingSent();
});

it('un guardiano irraggiungibile non diventa lui il guasto', function () {
    Exceptions::fake();
    config(['services.healthchecks.ping_url' => 'https://hc.example/ping/abc']);
    Http::fake(fn () => throw new ConnectionException('hc.example irraggiungibile'));

    // Se `rescue` sparisse, l'eccezione uscirebbe di qui e il test fallirebbe.
    app(Heartbeat::class)->ok();

    Exceptions::assertReported(ConnectionException::class);
});
