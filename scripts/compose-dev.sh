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

docker-compose up -d laravel-app laravel-vite queue-worker ocr-service redis mongo elasticsearch

echo "✅ Dev stack is running"
echo "   - Frontend HMR: http://localhost:5173"
echo "   - Backend:      http://localhost:8000"
echo "   - OCR Service:  http://localhost:8010"
echo "   - MongoDB:      mongodb://localhost:27017/poc"
echo "   - Elasticsearch: http://localhost:9200"
echo ""
echo "💡 Edit Vue/CSS files and Vite will hot reload automatically."
