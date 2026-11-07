#!/bin/sh
# Builds the installable package: dist/mod_pdfflipbook-<version>.zip
set -e
cd "$(dirname "$0")"
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' src/mod_pdfflipbook.xml | head -1)
mkdir -p dist
rm -f "dist/mod_pdfflipbook-$VERSION.zip"
(cd src && zip -rqX "../dist/mod_pdfflipbook-$VERSION.zip" . -x '*.DS_Store' -x '__MACOSX/*')
echo "dist/mod_pdfflipbook-$VERSION.zip"
shasum -a 256 "dist/mod_pdfflipbook-$VERSION.zip" 2>/dev/null || sha256sum "dist/mod_pdfflipbook-$VERSION.zip"
