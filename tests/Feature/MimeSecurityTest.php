<?php

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\ParameterizedHeader;

it('refuse les noms de paramètres MIME invalides sur les trois points d’entrée', function (string $name) {
    expect(fn () => new ParameterizedHeader('Content-Disposition', 'attachment', [$name => 'fictif']))
        ->toThrow(RfcComplianceException::class);

    $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'fictif.pdf']);
    expect(fn () => $header->setParameter($name, 'fictif'))->toThrow(RfcComplianceException::class);
    expect(fn () => $header->setParameters([$name => 'fictif']))->toThrow(RfcComplianceException::class);
    expect($header->getParameter('filename'))->toBe('fictif.pdf');

    $headers = new Headers;
    expect(fn () => $headers->addParameterizedHeader('Content-Disposition', 'attachment', [$name => 'fictif']))
        ->toThrow(RfcComplianceException::class);
})->with(["filename\r\nX-Fictif: injected", 'file name', 'file;name', 'file=name', 'file"name', '', "name\t"]);

it('préserve les paramètres valides et la sérialisation des messages et pièces jointes fictifs', function () {
    $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'rapport fictif.pdf']);
    $header->setParameter('x-ikoma', 'fictif');
    expect($header->getBodyAsString())->toContain('attachment', 'filename="rapport fictif.pdf"', 'x-ikoma=fictif');

    $email = (new Email)->from('expediteur@exemple.test')->to('destinataire@exemple.test')
        ->subject('Message fictif de recette')->text('Contenu fictif')
        ->attach('donnees fictives', 'rapport fictif.txt', 'text/plain');
    expect($email->toString())->toContain('multipart/mixed', 'attachment;', 'rapport fictif.txt');
});
