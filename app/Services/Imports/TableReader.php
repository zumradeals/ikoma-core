<?php

namespace App\Services\Imports;

use DateTimeInterface;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

/** Lecture locale uniquement ; les formules sont des chaînes, jamais évaluées. */
final class TableReader
{
    public function check(UploadedFile $file): string
    {
        if (! $file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            $this->fail('Le fichier doit faire au plus 5 Mo.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getPathname();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($extension === 'csv') {
            $raw = file_get_contents($path);
            if (! in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'], true) || ! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
                $this->fail('Choisissez un CSV UTF-8 ou un tableur XLSX valide.');
            }

            return 'csv';
        }
        if ($extension !== 'xlsx' || ! in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            $this->fail('Choisissez un CSV UTF-8 ou un tableur XLSX valide.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Le tableur XLSX est illisible.');
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                $this->fail('Le tableur XLSX est invalide.');
            }
            $expanded = 0;
            if ($zip->numFiles > 2000) {
                $this->fail('Le tableur contient trop de fichiers internes.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $expanded += $stat['size'];
                if ($expanded > 50 * 1024 * 1024 || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/vbaProject|externalLinks|embeddings/i', $name)) {
                    $this->fail('Le tableur contient un élément non autorisé ou est trop volumineux.');
                }
                if (preg_match('/\.(xml|rels)$/i', $name)) {
                    $xml = $zip->getFromIndex($i);
                    if (preg_match('/<!DOCTYPE|<!ENTITY|TargetMode\s*=\s*["\x27]External/i', $xml)) {
                        $this->fail('Les liens externes et contenus actifs ne sont pas acceptés.');
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return 'xlsx';
    }

    /** @return Generator<int, list<string>> Numéro réel de ligne, en-tête compris. */
    public function rows(string $path, string $format): Generator
    {
        if ($format === 'csv') {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                $this->fail('Le fichier est illisible.');
            }
            try {
                $head = fgets($handle);
                rewind($handle);
                $delimiter = count(str_getcsv($head ?: '', ';', '"', '')) > count(str_getcsv($head ?: '', ',', '"', '')) ? ';' : ',';
                $line = 0;
                while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    $line++;
                    if ($line > 5001) {
                        $this->fail('Le fichier dépasse 5 000 lignes de données.');
                    } yield $line => array_map(fn ($v) => (string) $v, $cells);
                }
            } finally {
                fclose($handle);
            }

            return;
        }
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                // Une liste = la première feuille. Aucun parcours de liens externes.
                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($number > 5001) {
                        $this->fail('Le fichier dépasse 5 000 lignes de données.');
                    }
                    yield $number => array_map(fn ($cell) => $cell->getValue() instanceof DateTimeInterface ? $cell->getValue()->format('Y-m-d H:i:s') : (string) $cell->getValue(), $row->cells);
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
