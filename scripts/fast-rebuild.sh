#!/bin/bash

# Fast rebuild script for development
# Usage: ./scripts/fast-rebuild.sh [service]

SERVICE=${1:-all}
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.dev.yml)

echo "🚀 Fast rebuild script for Thai OCR POC"
echo "======================================"

if [ "$SERVICE" = "all" ]; then
    echo "📦 Building all services with BuildKit cache..."
    export DOCKER_BUILDKIT=1
    export COMPOSE_DOCKER_CLI_BUILD=1
    "${COMPOSE[@]}" build --parallel
elif [ "$SERVICE" = "ocr" ]; then
    echo "📦 Building OCR service with BuildKit cache..."
    export DOCKER_BUILDKIT=1
    export COMPOSE_DOCKER_CLI_BUILD=1
    "${COMPOSE[@]}" build ocr-service
elif [ "$SERVICE" = "laravel" ]; then
    echo "📦 Building Laravel app with BuildKit cache..."
    export DOCKER_BUILDKIT=1
    export COMPOSE_DOCKER_CLI_BUILD=1
    "${COMPOSE[@]}" build laravel-app
elif [ "$SERVICE" = "vite" ]; then
    echo "📦 Restarting Vite (no rebuild needed for Node image)..."
    "${COMPOSE[@]}" up -d laravel-vite
else
    echo "❌ Unknown service: $SERVICE"
    echo "   Usage: $0 [all|ocr|laravel|vite]"
    exit 1
fi

echo "✅ Build complete! Starting services..."
"${COMPOSE[@]}" up -d

echo "🔥 Hot reload is now active!"
echo "   - Frontend: http://${VITE_DEV_SERVER_HOST:-localhost}:${VITE_HOST_PORT:-5173}"
echo "   - Backend: http://localhost:${APP_HOST_PORT:-8500}"
echo "   - OCR Service: http://localhost:${OCR_HOST_PORT:-8010}"
echo ""
echo "💡 Tips:"
echo "   - File changes will trigger automatic reloads"
echo "   - First build after changes may take 2-3 minutes"
echo "   - Subsequent builds will be much faster with BuildKit cache"
