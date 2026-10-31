<?php
// Isolated final-PHP image fixture: NO app data, no email/network/credentials.
require '/var/www/html/vendor/autoload.php';

foreach (['pdo_pgsql', 'mbstring', 'zip', 'xmlreader', 'gd', 'libxml'] as $name) {
    if (!extension_loaded($name)) {
        throw new RuntimeException('Missing extension: '.$name);
    }
}
if (version_compare(LIBXML_DOTTED_VERSION, '2.11.0', '<') || LIBXML_LOADED_VERSION < 21100) {
    throw new RuntimeException('libxml2 build/runtime not qualified for the tested CVE');
}
if (Composer\InstalledVersions::getPrettyVersion('symfony/mime') !== 'v7.4.22') {
    throw new RuntimeException('R05 Symfony Mime version no longer matches tested lock');
}

$path = tempnam(sys_get_temp_dir(), 'ikoma-xlsx-fixture-');
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create fixture');
}
$files = [
    '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Liste" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
    'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>nom complet</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Personne fictive</t></is></c></row></sheetData></worksheet>',
];
foreach ($files as $name => $value) {
    $zip->addFromString($name, $value);
}
$zip->close();
try {
    $reader = new OpenSpout\Reader\XLSX\Reader(new OpenSpout\Reader\XLSX\Options());
    $reader->open($path);
    $values = [];
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values[] = (string) $row->cells[0]->getValue();
            }
            break;
        }
    } finally {
        $reader->close();
    }
    if ($values !== ['nom complet', 'Personne fictive']) {
        throw new RuntimeException('OpenSpout XLSX values mismatch');
    }
} finally {
    unlink($path);
}
$stream = fopen('php://temp', 'w+');
fputcsv($stream, ['À fictif', 'valeur;fictive'], ';', '"', '');
rewind($stream);
if (fgetcsv($stream, 0, ';', '"', '') !== ['À fictif', 'valeur;fictive']) {
    throw new RuntimeException('CSV regression');
}
fclose($stream);
echo json_encode([
    'php' => PHP_VERSION,
    'libxml_compile' => LIBXML_DOTTED_VERSION,
    'libxml_runtime' => LIBXML_LOADED_VERSION,
    'symfony_mime' => 'v7.4.22',
    'extensions_checked' => 6,
    'csv_fixture' => true,
    'openspout_xlsx_fixture' => true,
    'scope' => 'isolated final PHP runtime; cannot replace application regression suite',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
