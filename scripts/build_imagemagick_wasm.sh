#!/usr/bin/env bash
set -eo pipefail

# Build ImageMagick and its dependencies to WebAssembly using Emscripten

ROOT_DIR=$(pwd)
BUILD_DIR="$ROOT_DIR/build/imagemagick-wasm"
DIST_DIR="$ROOT_DIR/dist"
mkdir -p "$BUILD_DIR" "$DIST_DIR"
cd "$BUILD_DIR"

ZLIB_VER=1.3.1
LIBPNG_VER=1.6.43
IMAGEMAGICK_VER=7.1.2-3

# Ensure Emscripten tools are used for all builds
export CC=emcc
export CXX=em++
export AR=emar
export RANLIB=emranlib
unset LIBS

# Fetch and build zlib
if [ ! -d zlib-$ZLIB_VER ]; then
  curl -LO https://zlib.net/zlib-$ZLIB_VER.tar.gz
  tar xf zlib-$ZLIB_VER.tar.gz
fi
cd zlib-$ZLIB_VER
emconfigure ./configure --static --prefix=$PWD/../zlib-install >/dev/null
emmake make install >/dev/null
cd ..

# Fetch and build libpng
if [ ! -d libpng-$LIBPNG_VER ]; then
  curl -LO https://download.sourceforge.net/libpng/libpng-$LIBPNG_VER.tar.gz
  tar xf libpng-$LIBPNG_VER.tar.gz
fi
cd libpng-$LIBPNG_VER
CPPFLAGS="-I$BUILD_DIR/zlib-install/include" LDFLAGS="-L$BUILD_DIR/zlib-install/lib" \
  emconfigure ./configure --host=wasm32-unknown-emscripten --prefix=$PWD/../libpng-install \
  --enable-static --disable-shared >/dev/null
emmake make >/dev/null
emmake make install >/dev/null
cd ..

# Fetch and build ImageMagick
if [ ! -d ImageMagick-$IMAGEMAGICK_VER ]; then
  curl -LO https://download.imagemagick.org/ImageMagick/download/releases/ImageMagick-$IMAGEMAGICK_VER.tar.gz
  tar xf ImageMagick-$IMAGEMAGICK_VER.tar.gz
fi
cd ImageMagick-$IMAGEMAGICK_VER
export CPPFLAGS="-I$BUILD_DIR/zlib-install/include -I$BUILD_DIR/libpng-install/include"
export LDFLAGS="-L$BUILD_DIR/zlib-install/lib -L$BUILD_DIR/libpng-install/lib"
export LIBS="-lz -lpng16"
# Remove stray configure lines that break under Emscripten
sed -i "/^='-fPIC'/d" configure
emconfigure ./configure \
  --disable-shared \
  --enable-static \
  --without-magick-plus-plus \
  --without-perl \
  --without-x \
  --without-jpeg \
  --host=wasm32-unknown-emscripten >/dev/null
emmake make >/dev/null

# Link to wasm
emcc MagickWand/.libs/libMagickWand-7.Q16HDRI.a MagickCore/.libs/libMagickCore-7.Q16HDRI.a \
  $LDFLAGS $LIBS --no-entry -sSTANDALONE_WASM=1 -o "$DIST_DIR/imagemagick.wasm"

printf "Built wasm module at %s/imagemagick.wasm\n" "$DIST_DIR"
