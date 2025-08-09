#!/bin/sh
set -e
VERSION=v0.32.0
if [ ! -d "wasmtime-$VERSION-x86_64-linux-c-api" ]; then
  curl -LO https://github.com/bytecodealliance/wasmtime/releases/download/$VERSION/wasmtime-$VERSION-x86_64-linux-c-api.tar.xz
  tar -xf wasmtime-$VERSION-x86_64-linux-c-api.tar.xz
fi
phpize
CFLAGS="-I$(pwd)/wasmtime-$VERSION-x86_64-linux-c-api/include" \
LDFLAGS="-L$(pwd)/wasmtime-$VERSION-x86_64-linux-c-api/lib -lwasmtime" \
./configure --enable-wasmtime
make

# build wasm examples
if command -v wat2wasm >/dev/null 2>&1; then
  wat2wasm add.wat -o add.wasm
  wat2wasm fac.wat -o fac.wasm
fi

if command -v rustc >/dev/null 2>&1; then
  rustup target add wasm32-wasip1 >/dev/null 2>&1
  rustc --target wasm32-wasip1 readfile.rs -O -o readfile.wasm
fi

echo "Build finished. To run:"
echo "LD_LIBRARY_PATH=$(pwd)/wasmtime-$VERSION-x86_64-linux-c-api/lib php -d extension=$(pwd)/modules/wasmtime.so run.php"
