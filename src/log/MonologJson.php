<?php

function read_logs($path, $params = [])
{
    $search = $params['search'] ?? null;
    $traceId = $params['trace-id'] ?? null;
    $spanId = $params['span-id'] ?? null;
    $level = $params['level'] ?? null;
    $levelName = $params['level-name'] ?? null;
    $to = isset($params['to']) ? strtotime($params['to']) : null;
    $from = isset($params['from']) ? strtotime($params['from']) : null;

    $logFiles = glob($path . '/*.log'); // incluye app.log, app.log.1, etc.

    rsort($logFiles); // orden descendente
    $results = [];
    foreach ($logFiles as $file) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            continue;
        }
        while (($line = fgets($handle)) !== false) {
            if (!trim($line)) {
                continue;
            }
            $record = json_decode($line, true);
            if (!$record) {
                continue;
            }
            // Filtrado por fecha
            $timestamp = strtotime($record['datetime'] ?? '');
            if ($from && $timestamp < $from) {
                continue;
            }
            if ($to && $timestamp > $to) {
                continue;
            }
            // Filtrado por trace/span
            if ($traceId && ($record['extra']['traceId'] ?? null) !== $traceId) {
                continue;
            }
            if ($spanId && ($record['extra']['spanId'] ?? null) !== $spanId) {
                continue;
            }
            // Búsqueda textual
            if ($search && stripos($line, $search) === false) {
                continue;
            }
            if ($level && ($record['level'] < $level)) {
                continue;
            }
            if ($levelName && ($record['level_name'] !== $levelName)) {
                continue;
            }
            $results[] = $record;
            if( count($results) > 1000 ) {
                break;
            }
        }
        fclose($handle);
    }
    return $results;
}
