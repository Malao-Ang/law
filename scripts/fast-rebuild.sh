#!/bin/bash

# Fast rebuild script for development
# Usage: ./scripts/fast-rebuild.sh [service]

SERVICE=${1:-all}

echo "🚀 Fast rebuild script for Thai OCR POC"
echo "======================================"

if [ "$SERVICE" = "all" ] || [ "$SERVICE" = "ocr" ]; then
    echo "📦 Building OCR service with multi-stage cache..."
    docker-compose build ocr-service --parallel
fi

if [ "$SERVICE" = "all" ] || [ "$SERVICE" = "laravel" ]; then
    echo "📦 Building Laravel app with dependency cache..."
    docker-compose build laravel-app --parallel
fi

if [ "$SERVICE" = "all" ] || [ "$SERVICE" = "vite" ]; then
    echo "📦 Restarting Vite (no rebuild needed for Node image)..."
    docker-compose restart laravel-vite
fi

echo "✅ Build complete! Starting services..."
docker-compose up -d

echo "🔥 Hot reload is now active!"
echo "   - Frontend: http://localhost:5173"
echo "   - Backend: http://localhost:8000"
echo "   - OCR Service: http://localhost:8010"
echo ""
echo "💡 Tips:"
echo "   - File changes will trigger automatic reloads"
echo "   - First build after changes may take 2-3 minutes"
echo "   - Subsequent builds will be much faster with multi-stage cache"
