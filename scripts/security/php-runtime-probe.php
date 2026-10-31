<?php

// Sonde de recette isolée, uniquement des constantes runtime et des données fictives.
function runtimeCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$image = $argv[1] ?? '';
runtimeCheck((bool) preg_match('/^sha256:[a-f0-9]{64}$/', $image), 'Identité image absente.');
$extensions = [];
foreach (['pdo_pgsql', 'mbstring', 'zip', 'xmlreader', 'gd', 'libxml'] as $name) {
    $extensions[$name] = extension_loaded($name);
    runtimeCheck($extensions[$name], 'Extension attendue absente : '.$name);
}

$stream = fopen('php://temp', 'w+');
$cells = ['Nom fictif', 'Équipe; fictive', 'Texte "fictif"'];
fputcsv($stream, $cells, ';', '"', '');
rewind($stream);
runtimeCheck(fgetcsv($stream, 0, ';', '"', '') === $cells, 'Régression CSV UTF-8.');
fclose($stream);

$previous = libxml_use_internal_errors(true);
libxml_clear_errors();
$reader = new XMLReader;
runtimeCheck($reader->XML('<root><item>fictif</item></root>', null, LIBXML_NONET), 'XML valide refusé.');
$found = false;
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::TEXT && $reader->value === 'fictif') {
        $found = true;
    }
}
$reader->close();
runtimeCheck($found && libxml_get_errors() === [], 'Régression de lecture XML.');
$reader = new XMLReader;
$reader->XML('<root><item></root>', null, LIBXML_NONET);
while ($reader->read()) {
    // Consommer le document mal formé sans accès réseau ni fichier externe.
}
$reader->close();
runtimeCheck(libxml_get_errors() !== [], 'XML mal formé non signalé.');
libxml_clear_errors();
libxml_use_internal_errors($previous);

$path = tempnam(sys_get_temp_dir(), 'r03-');
$zip = new ZipArchive;
runtimeCheck($zip->open($path, ZipArchive::OVERWRITE) === true, 'ZIP indisponible.');
$zip->addFromString('fictif.xml', '<root>fictif</root>');
$zip->close();
runtimeCheck($zip->open($path) === true, 'ZIP illisible.');
runtimeCheck($zip->getFromName('fictif.xml') === '<root>fictif</root>', 'Régression ZIP.');
$zip->close();
unlink($path);

$picture = imagecreatetruecolor(2, 2);
ob_start();
imagejpeg($picture);
$jpeg = ob_get_clean();
runtimeCheck(is_string($jpeg) && imagecreatefromstring($jpeg) !== false, 'Régression GD/JPEG.');

echo json_encode([
    'image_id' => $image,
    'php' => PHP_VERSION,
    'libxml_compile' => LIBXML_DOTTED_VERSION,
    'libxml_runtime' => LIBXML_LOADED_VERSION,
    'extensions' => $extensions,
    'checks' => ['csv_utf8' => true, 'xml_valide' => true, 'xml_invalide_signale' => true, 'zip' => true, 'gd_jpeg' => true],
    'scope' => 'régression isolée, pas un test d’exploitation CVE ni une preuve de correction',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
