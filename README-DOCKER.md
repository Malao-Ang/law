# Docker Development & Deployment Setup

This setup provides both development and production Docker configurations for your Laravel Word2HTML application.

## Development Setup (Hot Reload)

### Prerequisites
- Docker & Docker Compose installed
- Git

### Quick Start
```bash
# Copy development environment file
cp .env.dev .env

# Generate application key
docker compose -f docker-compose.dev.yml run --rm artisan php artisan key:generate

# Start development environment
docker compose -f docker-compose.dev.yml up -d

# Install dependencies
docker compose -f docker-compose.dev.yml run --rm composer install
docker compose -f docker-compose.dev.yml run --rm node npm install

# Run migrations
docker compose -f docker-compose.dev.yml run --rm artisan php artisan migrate

# Access the application
# Main app: http://localhost:8080
# Laravel dev server: http://localhost:8000
# MySQL: localhost:3306
# Redis: localhost:6379
```

### Development Services
- **app**: PHP-FPM with Laravel (port 9000)
- **web**: Nginx web server (port 8080)
- **artisan**: Laravel development server with hot reload (port 8000)
- **mysql**: MySQL 8.0 database (port 3306)
- **redis**: Redis cache (port 6379)
- **queue**: Laravel queue worker
- **node**: Node.js for frontend assets

### Hot Reload Features
- Code changes in `src/` are automatically reflected
- Laravel dev server provides hot reload for PHP changes
- Node.js service watches for frontend asset changes

### Common Development Commands
```bash
# View logs
docker compose -f docker-compose.dev.yml logs -f app
docker compose -f docker-compose.dev.yml logs -f web

# Run artisan commands
docker compose -f docker-compose.dev.yml run --rm artisan php artisan migrate:fresh --seed
docker compose -f docker-compose.dev.yml run --rm artisan php artisan tinker

# Clear cache
docker compose -f docker-compose.dev.yml run --rm artisan php artisan cache:clear
docker compose -f docker-compose.dev.yml run --rm artisan php artisan config:clear
```

## Production Deployment

### Setup
```bash
# Create production environment file
cp .env.prod .env

# Update production variables
# Edit .env and set:
# - APP_KEY (generate with: php artisan key:generate)
# - DB_PASSWORD
# - DB_ROOT_PASSWORD

# Build and start production environment
docker compose -f docker-compose.prod.yml up -d --build

# Run production optimizations
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

### Production Services
- **app**: PHP-FPM + Nginx (supervisor) (ports 80, 443)
- **web**: Nginx reverse proxy (ports 80, 443)
- **mysql**: MySQL 8.0 database
- **redis**: Redis cache and session storage
- **queue**: Laravel queue worker (daemon mode)
- **scheduler**: Cron job scheduler

### Production Features
- Optimized Docker image (Alpine Linux)
- Supervisor process management
- SSL support (configure certificates in `./ssl/`)
- Security headers and optimizations
- Automatic restart policies
- Volume persistence for data

## Environment Variables

### Development (.env.dev)
- `APP_ENV=development`
- `APP_DEBUG=true`
- `DB_DATABASE=laravel_dev`
- `DB_USERNAME=root`
- `DB_PASSWORD=root`

### Production (.env.prod)
- `APP_ENV=production`
- `APP_DEBUG=false`
- `DB_DATABASE=laravel_prod`
- `DB_USERNAME=laravel`
- `DB_PASSWORD=CHANGE_ME_IN_PRODUCTION`

## Useful Commands

### Stop Services
```bash
# Development
docker compose -f docker-compose.dev.yml down

# Production
docker compose -f docker-compose.prod.yml down
```

### Rebuild Images
```bash
# Development
docker compose -f docker-compose.dev.yml build --no-cache

# Production
docker compose -f docker-compose.prod.yml build --no-cache
```

### Database Management
```bash
# Backup database
docker compose -f docker-compose.dev.yml exec mysql mysqldump -u root -proot laravel_dev > backup.sql

# Restore database
docker compose -f docker-compose.dev.yml exec -T mysql mysql -u root -proot laravel_dev < backup.sql
```

### Monitoring
```bash
# Resource usage
docker stats

# Container status
docker compose -f docker-compose.dev.yml ps
```

## Troubleshooting

### Permission Issues
```bash
# Fix storage permissions
docker compose -f docker-compose.dev.yml exec app chown -R www-data:www-data storage bootstrap/cache
docker compose -f docker-compose.dev.yml exec app chmod -R 755 storage bootstrap/cache
```

### Clear All Caches
```bash
docker compose -f docker-compose.dev.yml run --rm artisan php artisan cache:clear
docker compose -f docker-compose.dev.yml run --rm artisan php artisan config:clear
docker compose -f docker-compose.dev.yml run --rm artisan php artisan route:clear
docker compose -f docker-compose.dev.yml run --rm artisan php artisan view:clear
```

### View Logs
```bash
# All services
docker compose -f docker-compose.dev.yml logs

# Specific service
docker compose -f docker-compose.dev.yml logs app
docker compose -f docker-compose.dev.yml logs web
docker compose -f docker-compose.dev.yml logs mysql
```

## File Structure
```
├── docker-compose.dev.yml      # Development configuration
├── docker-compose.prod.yml     # Production configuration
├── Dockerfile                  # Development Docker image
├── Dockerfile.prod             # Production Docker image
├── nginx.dev.conf              # Development Nginx config
├── nginx.prod.conf              # Production Nginx config
├── supervisord.conf            # Supervisor config for production
├── .env.dev                    # Development environment variables
├── .env.prod                   # Production environment variables
└── README-DOCKER.md           # This file
```

## MongoDB

This repository's `docker-compose.yml` includes a MongoDB service (`mongo`) and an optional UI (`mongo-express`).

### Connection info (from inside containers)

- **Host**: `mongo`
- **Port**: `27017`
- **Username**: `root`
- **Password**: `root`
- **Example URI**: `mongodb://root:root@mongo:27017`

### Quick verify

```bash
# Rebuild because we added the PHP mongodb extension
docker compose up -d --build

# Verify PHP has mongodb extension enabled
docker compose exec app php -m | grep -i mongodb

# Open mongo-express UI (optional)
# http://localhost:8081
```
