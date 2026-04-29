<?php

namespace Incoder\DDD\Support\Helper;

class CsvHelper
{
    /**
     * Read a CSV file and return an array of rows.
     *
     * @param string $filePath  Path to the CSV file
     * @param bool $hasHeader   Whether the CSV has a header row
     * @return array
     */
    public static function read(string $filePath, bool $hasHeader = true): array
    {
        $rows = [];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("CSV file not found or not readable: {$filePath}");
        }

        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = $hasHeader ? fgetcsv($handle, 5000, ',') : null;

            while (($data = fgetcsv($handle, 5000, ',')) !== false) {
                // Convert each value to ASCII, replacing unsupported characters
                $data = array_map(function ($value) {
                    return mb_convert_encoding($value, 'ASCII', 'UTF-8');
                    // return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
                }, $data);

                if ($hasHeader && $header) {
                    $rows[] = array_combine($header, $data);
                } else {
                    $rows[] = $data;
                }
            }

            fclose($handle);
        }

        return $rows;
    }
}
