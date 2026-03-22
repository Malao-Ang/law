@echo off
REM Fast rebuild script for development (Windows)
REM Usage: scripts\fast-rebuild.bat [service]

set SERVICE=%1
if "%SERVICE%"=="" set SERVICE=all

echo 🚀 Fast rebuild script for Thai OCR POC
echo ======================================

if "%SERVICE%"=="all" if "%SERVICE%"=="ocr" (
    echo 📦 Building OCR service with multi-stage cache...
    docker-compose build ocr-service --parallel
)

if "%SERVICE%"=="all" if "%SERVICE%"=="laravel" (
    echo 📦 Building Laravel app with dependency cache...
    docker-compose build laravel-app --parallel
)

if "%SERVICE%"=="all" if "%SERVICE%"=="vite" (
    echo 📦 Restarting Vite (no rebuild needed for Node image)...
    docker-compose restart laravel-vite
)

echo ✅ Build complete! Starting services...
docker-compose up -d

echo 🔥 Hot reload is now active!
echo    - Frontend: http://localhost:5173
echo    - Backend: http://localhost:8000
echo    - OCR Service: http://localhost:8010
echo.
echo 💡 Tips:
echo    - File changes will trigger automatic reloads
echo    - First build after changes may take 2-3 minutes
echo    - Subsequent builds will be much faster with multi-stage cache
