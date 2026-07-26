#!/bin/bash

# Build and start the deployment stack.
# This uses docker-compose.yml only: no Vite dev server and no source bind mounts.
# Usage: ./scripts/compose-deploy.sh

set -euo pipefail

export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

docker compose -f docker-compose.yml up -d --build \
  laravel-app queue-worker ocr-service pdf-service redis mongo elasticsearch

echo "Deploy stack is running"
echo "   - App: http://localhost:${APP_HOST_PORT:-8500}"
echo "   - OCR: http://localhost:${OCR_HOST_PORT:-8010}"
