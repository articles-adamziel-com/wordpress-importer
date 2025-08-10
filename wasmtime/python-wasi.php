<?php
$dir = __DIR__ . '/wasi-python';
$wasm = file_get_contents($dir . '/opt/wasi-python/bin/python3.wasm');
$inst = new Wasmtime\Instance($wasm, [], [
    'wasi' => [
        'dir'  => $dir,
        'env'  => [
            'PYTHONHOME' => '/opt/wasi-python',
            'PYTHONPATH' => '/opt/wasi-python/lib/python3.10',
        ],
        'args' => ['python3', '-c', "print('Hello from python.wasm')"],
    ],
]);
$inst->call('_start');
?>
