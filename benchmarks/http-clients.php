<?php

declare(strict_types=1);

/*
 * Compares CPU and memory consumption of HTTP clients running on the Revolt event loop,
 * against a local server (tests/Fixtures/http-server.php).
 * Usage: php http-clients.php [repetitions]
 */

$repetitions = (int) ($argv[1] ?? 5);
$clients = ['revolt', 'guzzle', 'guzzle-5ms', 'symfony'];
$scenarios = [
    'latency: 500 req, 50 concurrent, 100ms, 1KB' => [500, 50, 100, 1024],
    'throughput: 10000 req, 50 concurrent, 0ms, 1KB' => [10000, 50, 0, 1024],
    'large bodies: 200 req, 10 concurrent, 0ms, 1MB' => [200, 10, 0, 1024 * 1024],
    'single: 50 req, 1 concurrent, 20ms, 1KB' => [50, 1, 20, 1024],
];

$port = 18080;
$server = proc_open([PHP_BINARY, __DIR__.'/../tests/Fixtures/http-server.php', (string) $port], [1 => ['pipe', 'w']], $pipes);
if (fgets($pipes[1]) !== "listening\n") {
    exit(1);
}

try {
    foreach ($scenarios as $scenarioName => $parameters) {
        echo "\n## $scenarioName (median of $repetitions)\n\n";
        printf("| %-10s | %9s | %9s | %9s | %9s | %10s | %9s | %13s |\n", 'client', 'wall (s)', 'user (s)', 'sys (s)', 'CPU %', 'PHP mem', 'max RSS', 'loop lag (ms)');
        echo '|', implode('|', array_map(fn ($width) => str_repeat('-', $width), [12, 11, 11, 11, 11, 12, 11, 15])), "|\n";

        foreach ($clients as $client) {
            $runs = [];
            for ($i = 0; $i < $repetitions; $i++) {
                $command = [PHP_BINARY, __DIR__.'/http-client-worker.php', $client, "http://127.0.0.1:$port", ...array_map('strval', $parameters)];
                $output = shell_exec(implode(' ', array_map('escapeshellarg', $command)));
                $runs[] = json_decode((string) $output, true) ?? throw new RuntimeException("Worker failed: $output");
            }
            $median = function (string $metric) use ($runs) {
                $values = array_column($runs, $metric);
                sort($values);

                return $values[intdiv(count($values), 2)];
            };
            $cpuRatios = array_map(fn ($run) => ($run['user'] + $run['system']) / $run['wall'], $runs);
            sort($cpuRatios);

            printf(
                "| %-10s | %9.3f | %9.3f | %9.3f | %8.0f%% | %8.1fMB | %7.1fMB | %13.1f |\n",
                $client,
                $median('wall'),
                $median('user'),
                $median('system'),
                $cpuRatios[intdiv(count($cpuRatios), 2)] * 100,
                $median('peakPhpMemory') / 1024 / 1024,
                $median('maxRss') / 1024 / 1024,
                $median('maxLoopLag') * 1000,
            );
        }
    }
} finally {
    proc_terminate($server);
}
