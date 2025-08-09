PHP_ARG_ENABLE([wasmtime], [whether to enable wasmtime extension],
[  --enable-wasmtime   Enable wasmtime extension], no)

if test "$PHP_WASMTIME" != "no"; then
  AC_DEFINE([HAVE_WASMTIME], [1], [Define to enable wasmtime extension])
  AC_DEFINE([WASMTIME_FEATURE_WASI], [1], [Enable WASI support])
  PHP_NEW_EXTENSION(wasmtime, wasmtime.c, $ext_shared)
fi
