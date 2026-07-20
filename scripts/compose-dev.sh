#!/bin/bash

# Start the full development stack with Vite hot reload enabled.
# Usage: ./scripts/compose-dev.sh

set -euo pipefail

echo "🚀 Starting dev stack with Vite hot reload"
echo "========================================="

if [ ! -x "apps/app-laravel/node_modules/.bin/vite" ]; then
  echo "❌ Missing apps/app-laravel/node_modules/.bin/vite"
  echo "   Run: cd apps/app-laravel && npm install"
  exit 1
fi

export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build \
  laravel-app laravel-vite queue-worker ocr-service pdf-service redis mongo elasticsearch

echo "✅ Dev stack is running"
echo "   - Frontend HMR: http://${VITE_DEV_SERVER_HOST:-localhost}:${VITE_HOST_PORT:-5173}"
echo "   - Backend:      http://localhost:${APP_HOST_PORT:-8500}"
echo "   - OCR Service:  http://localhost:${OCR_HOST_PORT:-8010}"
echo "   - MongoDB:      mongodb://localhost:${MONGO_HOST_PORT:-27017}/poc"
echo "   - Elasticsearch: http://localhost:${ELASTIC_HOST_PORT:-9200}"
echo ""
echo "💡 Edit Vue/CSS files and Vite will hot reload automatically."
