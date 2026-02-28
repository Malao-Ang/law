# Docker Development & Deployment Setup

## สิ่งที่ต้องมี (Prerequisites)
- Docker Desktop (with BuildKit enabled — default ใน Docker Desktop ≥ 4.x)
- Docker Compose v2 (`docker compose` ไม่ใช่ `docker-compose`)

---

## Development Setup (Hot Reload)

### Quick Start (ครั้งแรก)
```bash
# 1. Enable BuildKit (ถ้ายังไม่ได้เปิด)
export DOCKER_BUILDKIT=1

# 2. Build image (ครั้งแรกใช้เวลา ~3-5 นาที, ครั้งถัดไป <30 วินาที เพราะ cache)
docker compose build

# 3. Start core services (app, node, web)
docker compose up -d

# 4. Install PHP dependencies (ครั้งแรก หรือหลัง composer.json เปลี่ยน)
docker compose exec app composer install

# 5. Setup Laravel
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

# 6. เปิดเบราว์เซอร์
# App: http://localhost:8080
# Vite HMR: http://localhost:5173 (internal)
```

### Development Services

| Service | Image | Port | หมายเหตุ |
|---------|-------|------|----------|
| **app** | `Dockerfile.dev` (php:8.4-fpm) | 9000 (internal) | PHP-FPM + poppler-utils |
| **node** | `node:20-slim` | 5173 | Vite HMR |
| **web** | `nginx:1.27-alpine` | **8080** | Reverse proxy |
| **mongo** *(optional)* | `mongo:7` | 27017 | ใช้ profile `mongo` |
| **mongo-express** *(optional)* | `mongo-express:1` | 8081 | ใช้ profile `mongo` |

> **หมายเหตุ:** ระบบใช้ **SQLite** เป็นค่าเริ่มต้น ไม่ต้องรัน mongo หากไม่ใช้

### Common Development Commands
```bash
# เริ่มทุก services
docker compose up -d

# ดู logs
docker compose logs -f app
docker compose logs -f node

# รัน artisan commands
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan tinker

# Clear caches
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear

# Composer commands
docker compose exec app composer install
docker compose exec app composer require some/package

# หยุดและลบ containers
docker compose down

# Rebuild image (หลังแก้ไข Dockerfile.dev)
docker compose build app
```

### รัน MongoDB (optional)
```bash
# เริ่ม MongoDB + Mongo Express
docker compose --profile mongo up -d

# เปิด Mongo Express UI
# http://localhost:8081
```

## Production Deployment

### Build production image (multi-stage)
```bash
# ต้องเปิด BuildKit
export DOCKER_BUILDKIT=1

# Build production image (multi-stage: php-builder → node-builder → runtime)
docker build -t laravel-word2html:prod --target runtime .

# หรือ override docker-compose ด้วย prod Dockerfile
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### Production Optimizations (หลัง deploy)
```bash
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app php artisan event:cache
docker compose exec app php artisan migrate --force
```

### Production Features (Dockerfile)
- ✅ **Multi-stage build**: builder stages ไม่อยู่ใน final image
- ✅ **ไม่มี build tools** (g++, make, pkg-config) ใน runtime
- ✅ **Vendor และ public/build** ถูก copy จาก builder stages
- ✅ **BuildKit cache mounts** สำหรับ apt และ composer

## Environment Variables

### Development (src/.env)
```env
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
```

### Production
```env
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=sqlite        # หรือ mysql
QUEUE_CONNECTION=database
APP_KEY=base64:...          # php artisan key:generate
```

---

## Useful Commands

```bash
# ดู status
docker compose ps

# ดู logs
docker compose logs -f
docker compose logs -f app

# resource usage
docker stats

# แก้ permission
docker compose exec app chown -R www-data:www-data storage bootstrap/cache

# Rebuild ใหม่ทั้งหมด (no cache)
docker compose build --no-cache

# ลบ volumes ด้วย (reset node_modules)
docker compose down -v
```

---

## File Structure
```
├── docker-compose.yml      # Main compose (dev)
├── Dockerfile              # Production multi-stage build
├── Dockerfile.dev          # Development build (เบา ไม่มี mongodb)
├── .dockerignore           # Exclude vendor/, node_modules/, .git/ ฯลฯ
├── nginx.dev.conf          # Nginx dev (proxy Vite HMR)
├── nginx.prod.conf         # Nginx production
├── supervisord.conf        # Process manager (php-fpm + nginx)
└── README-DOCKER.md        # This file
```

---

## 🚀 Build Speed Summary

| สิ่งที่เปลี่ยน | ผลลัพธ์ |
|---|---|
| ลบ `pecl install mongodb` | **ประหยัด 5-10 นาที** |
| ลบ `autoconf g++ make libssl-dev` | image เบาลง ~200MB |
| `--mount=type=cache` (apt + composer) | build ครั้งที่ 2+ เร็วขึ้น **~80%** |
| `.dockerignore` ครอบคลุม | build context เล็กลง ~90% |
| `node:20-slim` แทน `node:20` | image เล็กลง ~400MB |
| `nginx:1.27-alpine` แทน `nginx:latest` | image เล็กลง ~120MB |
| Named volume `node_modules` | ไม่ reinstall ทุก restart |
| mongo เป็น optional profile | ไม่รัน mongo เปล่าๆ |
| Multi-stage build (prod) | runtime image ไม่มี build tools |
