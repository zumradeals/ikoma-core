<?php

namespace App\Services;

final class JournalExport
{
    public static function cell(string $value): string
    {
        return preg_match('/^[=+@\-\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }

    /** @param resource $stream
     * @param  list<string>  $values
     */
    public static function row($stream, array $values): void
    {
        fputcsv($stream, array_map(self::cell(...), $values), ';', '"', '', "\r\n");
    }
}
