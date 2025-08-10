<?php
$dir = __DIR__ . '/wasi-python';
$wasm = file_get_contents($dir . '/opt/wasi-python/bin/python3.wasm');

// Write a simple Python program into the preopened directory so it can be
// executed by the WASI build.
$program = "print('Hello from python.wasm')\n";
file_put_contents($dir . '/hello.py', $program);

$inst = new Wasmtime\Instance($wasm, [], [
    'wasi' => [
        'dir'  => $dir,
        'env'  => [
            'PYTHONHOME' => '/opt/wasi-python',
            'PYTHONPATH' => '/opt/wasi-python/lib/python3.10',
        ],
        // Pass the script path as argv[0] inside the sandbox.
        'args' => ['/hello.py'],
    ],
]);

$inst->call('_start');
?>
