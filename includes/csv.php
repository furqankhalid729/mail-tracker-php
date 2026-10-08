<?php
declare(strict_types=1);

/**
 * CSV upload + batched reading, shared by the customer and lead import wizards.
 * The file is read in byte-offset batches, so large files never sit in memory.
 */

function csv_to_utf8(array $row): array
{
    return array_map(fn($v) => mb_check_encoding((string) $v, 'UTF-8') ? trim((string) $v) : trim(mb_convert_encoding((string) $v, 'UTF-8', 'Windows-1252')), $row);
}

/** Read up to $limit rows starting at byte $offset. Returns [rows, nextOffset, eof]. */
function csv_read_batch(string $path, string $delimiter, int $offset, int $limit): array
{
    $fh = fopen($path, 'r');
    fseek($fh, $offset);
    $rows = [];
    while (count($rows) < $limit && ($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue; // blank line
        }
        $rows[] = csv_to_utf8($row);
    }
    $next = ftell($fh);
    $eof = feof($fh);
    fclose($fh);
    return [$rows, $next, $eof];
}

/**
 * Validate an uploaded CSV, store it under uploads/imports/{ws}/ and read its header row.
 * Returns ['error' => message] or [file (relative to UPLOAD_PATH), name, size, delimiter, headers, data_offset].
 */
function csv_store_upload(?array $f, int $wsId): array
{
    $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = $f && $f['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed. Check the file size (max ' . UPLOAD_LIMIT_MB . ' MB unless your host allows more).'];
    }
    if (!in_array($ext, ['csv', 'txt'], true) || !in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'], true)) {
        return ['error' => 'Please upload a .csv file.'];
    }
    $rel = 'imports/' . $wsId . '/' . random_token(16) . '.csv';
    upload_dir('imports/' . $wsId);
    move_uploaded_file($f['tmp_name'], UPLOAD_PATH . '/' . $rel);

    $fh = fopen(UPLOAD_PATH . '/' . $rel, 'r');
    $first = (string) fgets($fh);
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    rewind($fh);
    if (fread($fh, 3) !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $headers = csv_to_utf8(fgetcsv($fh, 0, $delimiter, '"', '\\') ?: []);
    $dataOffset = ftell($fh);
    fclose($fh);

    if (count($headers) < 1 || implode('', $headers) === '') {
        @unlink(UPLOAD_PATH . '/' . $rel);
        return ['error' => 'Could not read a header row from that file.'];
    }
    return [
        'file' => $rel,
        'name' => basename((string) $f['name']),
        'size' => filesize(UPLOAD_PATH . '/' . $rel),
        'delimiter' => $delimiter,
        'headers' => $headers,
        'data_offset' => $dataOffset,
    ];
}
