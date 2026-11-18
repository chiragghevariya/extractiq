# Laravel Forge (AWS EC2)

1. **Server.** Create an EC2 server from Forge (Ubuntu 24.04, PHP 8.3, PostgreSQL 16, Redis). Add
   `poppler-utils tesseract-ocr tesseract-ocr-deu` with a Forge recipe (`apt-get install -y …`).
2. **Site.** `api.example.com`, repository root, web directory `/api/public`, PHP 8.3. Enable
   Let's Encrypt. Environment: copy `api/.env.example` and fill it in (see `docs/DEPLOY.md`).
3. **Deploy script.** Paste `deploy/forge/deploy.sh`; enable quick deploy on `main`.
4. **Daemons** (Server → Daemons), directory `/home/forge/api.example.com/api`:
   | Command | Purpose |
   |---|---|
   | `php8.3 artisan horizon` | queue workers (OCR, extraction, matching, ERP push) |
   | `php8.3 artisan reverb:start --host=0.0.0.0 --port=8031` | WebSockets (proxy `ws.example.com` → 8031 with an Nginx site) |
5. **Scheduler.** Server → Scheduler: `php8.3 /home/forge/api.example.com/api/artisan schedule:run` every minute.
6. **Health.** Point the load balancer / uptime check at `https://api.example.com/api/health`.
