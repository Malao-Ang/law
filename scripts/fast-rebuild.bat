@echo off
REM Fast rebuild script for development (Windows)
REM Usage: scripts\fast-rebuild.bat [service]

set SERVICE=%1
if "%SERVICE%"=="" set SERVICE=all

echo 🚀 Fast rebuild script for Thai OCR POC
echo ======================================

if "%SERVICE%"=="all" goto :build_all
if "%SERVICE%"=="ocr" goto :build_ocr
if "%SERVICE%"=="laravel" goto :build_laravel
if "%SERVICE%"=="vite" goto :restart_vite

echo ❌ Unknown service: %SERVICE%
echo    Usage: %~nx0 [all|ocr|laravel|vite]
goto :end

:build_all
echo 📦 Building all services with BuildKit cache...
set DOCKER_BUILDKIT=1
set COMPOSE_DOCKER_CLI_BUILD=1
docker compose -f docker-compose.yml -f docker-compose.dev.yml build --parallel
goto :start_services

:build_ocr
echo 📦 Building OCR service with BuildKit cache...
set DOCKER_BUILDKIT=1
set COMPOSE_DOCKER_CLI_BUILD=1
docker compose -f docker-compose.yml -f docker-compose.dev.yml build ocr-service
goto :start_services

:build_laravel
echo 📦 Building Laravel app with BuildKit cache...
set DOCKER_BUILDKIT=1
set COMPOSE_DOCKER_CLI_BUILD=1
docker compose -f docker-compose.yml -f docker-compose.dev.yml build laravel-app
goto :start_services

:restart_vite
echo 📦 Restarting Vite (no rebuild needed for Node image)...
docker compose -f docker-compose.yml -f docker-compose.dev.yml restart laravel-vite
goto :start_services

:start_services
echo ✅ Build complete! Starting services...
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

echo 🔥 Hot reload is now active!
echo    - Frontend: http://localhost:5173
echo    - Backend: http://localhost:8500
echo    - OCR Service: http://localhost:8010
echo.
echo 💡 Tips:
echo    - File changes will trigger automatic reloads
echo    - First build after changes may take 2-3 minutes
echo    - Subsequent builds will be much faster with BuildKit cache

:end
