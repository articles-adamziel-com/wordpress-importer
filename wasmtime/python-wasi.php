<?php
$dir = __DIR__ . '/wasi-python';
$wasm = file_get_contents($dir . '/opt/wasi-python/bin/python3.wasm');
$code = "print('Hello from python.wasm')";

$inst = new Wasmtime\Instance($wasm, [], [
    'wasi' => [
        'dir'  => $dir,
        'env'  => [
            'PYTHONHOME' => '/opt/wasi-python',
            'PYTHONPATH' => '/opt/wasi-python/lib/python3.10',
        ],
        // Execute code passed via CLI arguments to avoid file lookup issues.
        'args' => ['python3.wasm', '-c', $code],
    ],
]);

$inst->call('_start');
?>
