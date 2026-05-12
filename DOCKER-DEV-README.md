# Firefly III Data Importer - Docker Development Setup

This directory contains a Docker development environment for the Firefly III Data Importer on macOS.

## Prerequisites

- **Docker Desktop for Mac**: [Download and install](https://www.docker.com/products/docker-desktop)
- **macOS**: 10.15 (Catalina) or later recommended

## Quick Start

1. **Initial Setup**
   ```bash
   ./dev-docker.sh setup
   ```
   This will:
   - Check if Docker is installed and running
   - Create a `.env` file if it doesn't exist
   - Build the Docker image
   - Start the containers
   - Build frontend assets inside the running container if they are missing

2. **Configure Environment**
   Edit `.env` and set at minimum:
   ```bash
   FIREFLY_III_URL=http://your-firefly-instance
   FIREFLY_III_ACCESS_TOKEN=your-token-here
   ```

3. **Access the Application**
   Open your browser to: http://localhost:8081

## Available Commands

The `dev-docker.sh` script provides convenient commands for development:

### Container Management
```bash
./dev-docker.sh start      # Start containers
./dev-docker.sh stop       # Stop containers
./dev-docker.sh restart    # Restart containers
./dev-docker.sh logs       # View logs (follow mode)
./dev-docker.sh status     # Show container status
./dev-docker.sh shell      # Open shell in container
```

### Development Tools
```bash
./dev-docker.sh composer install       # Install PHP dependencies
./dev-docker.sh npm install           # Install Node dependencies
./dev-docker.sh artisan migrate       # Run migrations
./dev-docker.sh test                  # Run PHPUnit tests
./dev-docker.sh quality               # Run all code quality checks
```

### Specific Examples
```bash
# Check importer version
./dev-docker.sh artisan importer:version

# Run specific test file
./dev-docker.sh test tests/Unit/SomeTest.php

# Run PHP CS Fixer
./dev-docker.sh composer exec ./.ci/phpcs.sh

# Run PHPStan
./dev-docker.sh composer exec ./.ci/phpstan.sh

# Install a new composer package
./dev-docker.sh composer require vendor/package
```

### Cleanup
```bash
./dev-docker.sh clean      # Remove all containers, volumes, and images
```

## Architecture

### Files Created

- **Dockerfile.dev** - Development Dockerfile with PHP 8.5, Nginx, and all dependencies
- **docker-compose.dev.yml** - Docker Compose configuration
- **docker/** - Configuration files for Nginx and Supervisor
  - `nginx.conf` - Main Nginx configuration
  - `default.conf` - Site configuration for the Laravel app
  - `supervisord.conf` - Supervisor configuration to run PHP-FPM and Nginx
- **dev-docker.sh** - Convenience script for managing the environment
- **.dockerignore** - Files to exclude from Docker build context

### How It Works

1. **PHP-FPM**: Runs on port 9000 inside the container
2. **Nginx**: Proxies requests to PHP-FPM, serves static files, listens on port 8080 inside container
3. **Supervisor**: Manages both PHP-FPM and Nginx processes
4. **Volume Mounts**: Your local code is mounted into the container for live editing
5. **Anonymous Volumes**: `vendor/`, frontend `node_modules/`, and `public/build/` stay inside the container so dependency installs and Vite assets are not overwritten by the bind mount
5. **Port Mapping**: Container port 8080 is mapped to host port 8081

### Environment Variables

The `docker-compose.dev.yml` file reads environment variables from your `.env` file. Key variables:

- `FIREFLY_III_URL` - URL to your Firefly III instance
- `FIREFLY_III_ACCESS_TOKEN` - Personal Access Token from Firefly III
- `APP_DEBUG=true` - Enabled for development
- `LOG_LEVEL=debug` - Detailed logging
- `AKAHU_APP_TOKEN` - Default Akahu app token
- `AKAHU_USER_TOKEN` - Default Akahu user token
- `AKAHU_INTERNAL_ACCOUNT_PREFIX` - Prefix used to classify internal transfer accounts
- `AKAHU_MORTGAGE_PAYMENT_PATTERN` - Regex for mortgage transaction matching

## Development Workflow

### Making Code Changes

1. Edit files on your macOS host (they're mounted into the container)
2. Changes are immediately reflected in the container
3. For PHP changes, they're picked up automatically
4. For Nginx/config changes, restart the container:
   ```bash
   ./dev-docker.sh restart
   ```
5. For frontend dependency changes, rerun the asset build inside the container:
   ```bash
   ./dev-docker.sh exec sh -lc 'npm install && cd resources/js/v2 && npm install && npm run build'
   ```

### Running Tests

```bash
# Run all tests
./dev-docker.sh test

# Run specific test suite
./dev-docker.sh test --testsuite Unit

# Run with coverage
./dev-docker.sh test --coverage-html coverage/
```

### Code Quality

```bash
# Run all quality checks
./dev-docker.sh quality

# Or run individually
./dev-docker.sh composer exec ./.ci/phpcs.sh
./dev-docker.sh composer exec ./.ci/phpstan.sh
./dev-docker.sh composer exec ./.ci/rector.sh
```

### Debugging

View logs in real-time:
```bash
./dev-docker.sh logs
```

Access container shell for debugging:
```bash
./dev-docker.sh shell
# Then run commands like:
php artisan route:list
php artisan config:cache
tail -f storage/logs/laravel.log
```

## Connecting to Firefly III

### Local Firefly III Instance

If your Firefly III is also running in Docker:

1. Create a shared network or use `host.docker.internal`:
   ```bash
   FIREFLY_III_URL=http://host.docker.internal:8080
   ```

2. Or get the container IP:
   ```bash
   docker inspect firefly-iii-container | grep IPAddress
   ```

### Remote Firefly III Instance

Just use the public URL:
```bash
FIREFLY_III_URL=https://your-firefly-instance.com
```

## Troubleshooting

### Container won't start
```bash
# Check Docker is running
docker info

# View detailed logs
./dev-docker.sh logs

# Rebuild from scratch
./dev-docker.sh clean
./dev-docker.sh setup
```

### Vite manifest not found
If Laravel reports `public/build/manifest.json` is missing, rebuild frontend assets inside the container:
```bash
./dev-docker.sh exec sh -lc 'npm install && cd resources/js/v2 && npm install && npm run build'
```
`setup` and `start` already do this automatically when the manifest is missing, so you should only need this after manually clearing Docker volumes.

### Permission issues
```bash
# Fix storage permissions
./dev-docker.sh shell
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Port already in use
Edit `docker-compose.dev.yml` and change the port mapping:
```yaml
ports:
  - "8082:8080"  # Use 8082 instead of 8081
```

### Dependencies out of sync
```bash
./dev-docker.sh composer install
./dev-docker.sh exec sh -lc 'npm install && cd resources/js/v2 && npm install'
```

## Performance Tips for macOS

### Using Docker Volumes for Dependencies

The docker-compose file keeps `vendor/`, frontend `node_modules/`, and `public/build/` inside Docker volumes to improve performance and avoid bind-mounting over generated files.

### File Watching

If you're using file watchers (npm watch, etc.), they work but may be slow. Consider running them on your host instead:

```bash
# On host
npm run watch

# Container still serves the compiled assets
```

## Stopping and Cleaning Up

### Stop containers
```bash
./dev-docker.sh stop
```

### Remove everything (containers, volumes, images)
```bash
./dev-docker.sh clean
```

## Next Steps

1. Configure your `.env` file with Firefly III credentials
2. Set up any required API credentials (Nordigen, Spectre, SimpleFIN)
3. Start importing data!

For more information, see the main [CLAUDE.md](CLAUDE.md) file.
