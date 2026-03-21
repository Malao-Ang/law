You are generating a clean monorepo for a Thai legal document OCR POC.

Requirements:
- Monorepo structure exactly as described in README.md
- Services:
  - Laravel 12 API
  - Vue 3 + Vite + TypeScript frontend
  - FastAPI Python OCR service
- Docker Compose must run all services locally
- Use clean architecture principles
- Keep controllers/routes thin
- Put business logic in service classes
- Add health endpoints for every service
- Add placeholder tests
- Do not add database integration yet
- Use local/shared storage only
- Output should be production-minded, readable, and minimal

Tasks:
1. Create directory structure
2. Create Dockerfiles
3. Create docker-compose.yml
4. Create .env.example
5. Add minimal bootable apps for each service
6. Add README references where needed
