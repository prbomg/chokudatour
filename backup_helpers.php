<?php

function exportCsvCell($value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string)$value);
    if ($value !== '' && preg_match('/^[=+\-@]/u', $value)) $value = "'" . $value;
    return $value;
}

function sendCsvDownload(string $filename, array $headers, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_map('exportCsvCell', $headers), ';', '"', '\\');
    foreach ($rows as $row) fputcsv($output, array_map('exportCsvCell', array_values($row)), ';', '"', '\\');
    fclose($output);
    exit;
}

function databaseTableNames(PDO $pdo): array
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
    $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    return array_map(static fn(array $row): string => (string)$row[0], $rows);
}

function databaseCreateStatement(PDO $pdo, string $table): string
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (string)$stmt->fetchColumn();
    }
    $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
    return (string)($row[1] ?? '');
}

function sqlLiteral(PDO $pdo, $value): string
{
    if ($value === null) return 'NULL';
    if (is_bool($value)) return $value ? '1' : '0';
    return $pdo->quote((string)$value);
}

function createDatabaseBackup(PDO $pdo): string
{
    $path = tempnam(sys_get_temp_dir(), 'chokudatour-db-');
    if ($path === false) throw new RuntimeException('Не удалось создать временный файл резервной копии.');
    $handle = fopen($path, 'wb');
    if ($handle === false) { @unlink($path); throw new RuntimeException('Не удалось открыть временный файл резервной копии.'); }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $transaction = false;
    try {
        if ($driver === 'mysql') { $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); $transaction = true; }
        fwrite($handle, "-- ChokudaTour database backup\n-- Created: " . date(DATE_ATOM) . "\n\n");
        if ($driver === 'mysql') fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach (databaseTableNames($pdo) as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n" . databaseCreateStatement($pdo, $table) . ";\n\n");
            $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns = implode(',', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row)));
                $values = implode(',', array_map(static fn($value): string => sqlLiteral($pdo, $value), array_values($row)));
                fwrite($handle, "INSERT INTO {$quotedTable} ({$columns}) VALUES ({$values});\n");
            }
            fwrite($handle, "\n");
        }
        if ($driver === 'mysql') fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        if ($transaction) { $pdo->commit(); $transaction = false; }
        fclose($handle);
        return $path;
    } catch (Throwable $e) {
        if ($transaction && $pdo->inTransaction()) $pdo->rollBack();
        fclose($handle);
        @unlink($path);
        throw $e;
    }
}

function zipDosTime(int $timestamp): array
{
    $parts = getdate($timestamp);
    $year = max(1980, (int)$parts['year']);
    return [(($parts['hours'] & 31) << 11) | (($parts['minutes'] & 63) << 5) | ((int)$parts['seconds'] >> 1), (($year - 1980) << 9) | (($parts['mon'] & 15) << 5) | ($parts['mday'] & 31)];
}

function createStoredZip(string $path, array $entries): void
{
    $output = fopen($path, 'wb');
    if ($output === false) throw new RuntimeException('Не удалось открыть временный ZIP-архив.');
    $central = [];
    try {
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', (string)$entry['name']);
            $source = $entry['source'] ?? null;
            $content = array_key_exists('content', $entry) ? (string)$entry['content'] : null;
            $size = $source !== null ? (int)filesize($source) : strlen($content);
            $crc = (int)hexdec($source !== null ? hash_file('crc32b', $source) : hash('crc32b', $content));
            [$dosTime,$dosDate] = zipDosTime($source !== null ? (int)filemtime($source) : time());
            $offset = ftell($output);
            $flags = 0x0800;
            fwrite($output, pack('VvvvvvVVVvv',0x04034b50,20,$flags,0,$dosTime,$dosDate,$crc,$size,$size,strlen($name),0) . $name);
            if ($source !== null) { $input=fopen($source,'rb'); if ($input===false) throw new RuntimeException('Не удалось прочитать файл чека.'); stream_copy_to_stream($input,$output); fclose($input); }
            else fwrite($output, $content);
            $central[] = compact('name','flags','dosTime','dosDate','crc','size','offset');
        }
        $centralOffset = ftell($output);
        foreach ($central as $entry) fwrite($output, pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,$entry['flags'],0,$entry['dosTime'],$entry['dosDate'],$entry['crc'],$entry['size'],$entry['size'],strlen($entry['name']),0,0,0,0,0,$entry['offset']) . $entry['name']);
        $centralSize = ftell($output) - $centralOffset;
        fwrite($output, pack('VvvvvVVv',0x06054b50,0,0,count($central),count($central),$centralSize,$centralOffset,0));
        fclose($output);
    } catch (Throwable $e) { fclose($output); @unlink($path); throw $e; }
}

function createReceiptsArchive(PDO $pdo): array
{
    $path = tempnam(sys_get_temp_dir(), 'chokudatour-receipts-');
    if ($path === false) throw new RuntimeException('Не удалось создать временный архив.');
    $rows = $pdo->query("SELECT ex.id,ex.event_id,ex.receipt_path,e.tour_date,t.name tour_name FROM expenses ex LEFT JOIN events e ON e.id=ex.event_id LEFT JOIN tours_catalog t ON t.id=e.tour_id WHERE COALESCE(ex.receipt_path,'')!='' ORDER BY e.tour_date,ex.id")->fetchAll(PDO::FETCH_ASSOC);
    $manifest = "ID расхода;ID выезда;Дата;Маршрут;Файл\n";
    $entries = []; $added = 0;
    foreach ($rows as $row) {
        $relative = (string)$row['receipt_path'];
        if (!preg_match('~^uploads/[A-Za-z0-9._-]+$~D', $relative)) continue;
        $source = __DIR__ . '/' . $relative;
        if (!is_file($source)) continue;
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $archiveName = sprintf('%s/event-%d_expense-%d.%s', (string)($row['tour_date'] ?: 'no-date'), (int)$row['event_id'], (int)$row['id'], preg_match('/^[a-z0-9]{2,5}$/D', $extension) ? $extension : 'bin');
        $entries[] = ['name'=>$archiveName,'source'=>$source];
        $manifest .= implode(';', array_map('exportCsvCell', [(int)$row['id'],(int)$row['event_id'],$row['tour_date'],$row['tour_name'],$archiveName])) . "\n";
        $added++;
    }
    $entries[] = ['name'=>'manifest.csv','content'=>"\xEF\xBB\xBF" . $manifest];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) { @unlink($path); throw new RuntimeException('Не удалось открыть архив чеков.'); }
        foreach ($entries as $entry) isset($entry['source']) ? $zip->addFile($entry['source'],$entry['name']) : $zip->addFromString($entry['name'],$entry['content']);
        $zip->close();
    } else createStoredZip($path, $entries);
    return [$path, $added];
}

function sendTemporaryDownload(string $path, string $filename, string $contentType): never
{
    if (!is_file($path)) throw new RuntimeException('Файл выгрузки не найден.');
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    readfile($path);
    @unlink($path);
    exit;
}

function saveLastBackup(PDO $pdo, string $filename): void
{
    $values = ['last_database_backup_at'=>date('Y-m-d H:i:s'),'last_database_backup_file'=>$filename];
    foreach ($values as $key=>$value) {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM global_settings WHERE setting_key=?'); $stmt->execute([$key]);
        if ($stmt->fetchColumn()) $pdo->prepare('UPDATE global_settings SET setting_value=? WHERE setting_key=?')->execute([$value,$key]);
        else $pdo->prepare('INSERT INTO global_settings(setting_key,setting_value) VALUES (?,?)')->execute([$key,$value]);
    }
}
