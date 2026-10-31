<?php

use Composer\InstalledVersions;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

require '/var/www/html/vendor/autoload.php';

$image = $argv[1] ?? '';
$version = InstalledVersions::getPrettyVersion('symfony/mime');
$number = ltrim((string) $version, 'v');
if (! preg_match('/^sha256:[a-f0-9]{64}$/', $image) || ! version_compare($number, '7.4.22', '>=') || ! version_compare($number, '8.0.0', '<')) {
    throw new RuntimeException('Version Mime corrigée ou identité image non établie.');
}
$rejected = false;
try {
    new ParameterizedHeader('Content-Disposition', 'attachment', ["name\r\nX-Fictif: injected" => 'fictif']);
} catch (RfcComplianceException) {
    $rejected = true;
}
$valid = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'fictif.pdf']);
if (! $rejected || ! str_contains($valid->getBodyAsString(), 'filename=fictif.pdf')) {
    throw new RuntimeException('Comportement MIME corrigé non établi dans le runtime final.');
}
echo json_encode([
    'image_id' => $image,
    'symfony_mime' => $version,
    'invalid_parameter_name_rejected' => true,
    'valid_parameter_serialized' => true,
    'mail_sent' => false,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
