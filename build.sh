#!/usr/bin/env bash

set -e

rm -f ./steadfast-api.zip

zip -r steadfast-api.zip . -x "build.sh" ".gitignore" ".git/*"

echo "archive created: steadfast-api.zip"
