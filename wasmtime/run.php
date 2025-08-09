<?php
$wasm = file_get_contents(__DIR__ . '/add.wasm');
$inst = new Wasmtime\Instance($wasm);
echo "add(2,3)=" . $inst->call('add', [2,3]) . PHP_EOL;

$wasm = file_get_contents(__DIR__ . '/fac.wasm');
$inst = new Wasmtime\Instance($wasm);
echo "fac(5)=" . $inst->call('fac', [5]) . PHP_EOL;

$wasm = file_get_contents(__DIR__ . '/readfile.wasm');
$inst = new Wasmtime\Instance($wasm, [], ['wasi' => ['dir' => __DIR__]]);
$inst->call('_start');
?>
