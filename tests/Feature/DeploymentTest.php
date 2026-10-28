<?php

it('reconnaît le HTTPS annoncé par le proxy du serveur pour que les liens, cookies et ressources restent en https', function () {
    $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'ikoma.dgafrique.com'])->get('/login')->assertOk();
    expect(app('request')->isSecure())->toBeTrue()->and(app('request')->getHost())->toBe('ikoma.dgafrique.com');
});
