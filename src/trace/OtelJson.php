<?php

function read_traces($path, $params = [])
{
    // ini_set('memory_limit', '512M');
    $search = $params['search'] ?? null;
    $traceId = $params['trace-id'] ?? null;

    $traceFiles = glob($path . '/*.json'); // eg: traces/app-2025-06-04.json
    rsort($traceFiles); // más recientes primero

    $results = [];

    foreach ($traceFiles as $file) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            continue;
        }
        while (($line = fgets($handle)) !== false) {
            if (!trim($line)) {
                continue;
            }
            $span = json_decode($line, true);
            if (!is_array($span)) {
                continue;
            }
            if( $search && stripos($span['name'], $search) === false) {
                continue;
            }
            if ($traceId && $traceId !== ($span['traceId'] ?? null)) {
                continue;
            }
            if( !$traceId && (
                    !$span['parentSpanId'] || $span['parentSpanId']!=='0000000000000000' ) ) {
                continue;
            }
            $results[] = $span;
            if( count($results) > 1000 ) {
                break;
            }
        }
        fclose($handle);
    }
    return $results;
}
