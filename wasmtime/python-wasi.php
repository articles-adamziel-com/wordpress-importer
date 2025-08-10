<?php
$dir = __DIR__ . '/wasi-python';
$wasm = file_get_contents($dir . '/bin/python3.wasm');
$inst = new Wasmtime\Instance($wasm, [], [
    'wasi' => [
        'dir'  => $dir,
        'env'  => ['PYTHONHOME' => '/'],
        'args' => ['python3', '-c', "print('Hello from python.wasm')"],
    ],
]);
$inst->call('_start');
?>
