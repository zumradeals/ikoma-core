<?php
// Recette isolée, données fictives uniquement ; pas de secret, base ou application cliente.
$expected = ['pdo_pgsql', 'mbstring', 'zip', 'xmlreader', 'gd', 'libxml'];
foreach ($expected as $ext) {
    if (!extension_loaded($ext)) {
        throw new RuntimeException('Extension absente : '.$ext);
    }
}
if (version_compare(LIBXML_DOTTED_VERSION, '2.11.0', '<')) {
    throw new RuntimeException('libxml2 compilée non corrigée pour la CVE étudiée');
}
if (LIBXML_LOADED_VERSION < 21100) {
    throw new RuntimeException('libxml2 chargée non corrigée pour la CVE étudiée');
}
$stream = fopen('php://temp', 'w+');
$cells = ['Nom fictif', 'Équipe; fictive', 'Texte "fictif"'];
fputcsv($stream, $cells, ';', '"', '');
rewind($stream);
if (fgetcsv($stream, 0, ';', '"', '') !== $cells) {
    throw new RuntimeException('CSV en erreur');
}
fclose($stream);
libxml_use_internal_errors(true);
$reader = new XMLReader();
if (!$reader->XML('<root><value>fictif</value></root>', null, LIBXML_NONET)) {
    throw new RuntimeException('XML valide refusé');
}
while ($reader->read()) {}
$reader->close();
$xml = new XMLReader();
$xml->XML('<root><value></root>', null, LIBXML_NONET);
while ($xml->read()) {}
$xml->close();
if (libxml_get_errors() === []) {
    throw new RuntimeException('XML mal formé non signalé');
}
libxml_clear_errors();
$file = tempnam(sys_get_temp_dir(), 'ikoma-alpine-');
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('ZIP absent');
}
$zip->addFromString('fictif.xml', '<root>fictif</root>');
$zip->close();
if ($zip->open($file) !== true || $zip->getFromName('fictif.xml') !== '<root>fictif</root>') {
    throw new RuntimeException('ZIP illisible');
}
$zip->close();
unlink($file);
$image = imagecreatetruecolor(2, 2);
ob_start();
imagejpeg($image);
$bytes = ob_get_clean();
if (!is_string($bytes) || imagecreatefromstring($bytes) === false) {
    throw new RuntimeException('GD/JPEG en erreur');
}
echo json_encode([
  'qualification' => 'php-alpine-extensions-et-fichiers-fictifs',
  'php' => PHP_VERSION,
  'libxml_compile' => LIBXML_DOTTED_VERSION,
  'libxml_runtime' => LIBXML_LOADED_VERSION,
  'extensions' => $expected,
  'checks' => ['csv' => true, 'xml' => true, 'zip' => true, 'gd' => true],
  'limite' => 'Ne prouve pas la compatibilite integrale du code IKOMA, ni un correctif en production',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
