#!/bin/bash

# Firefly III Data Importer - Development Docker Script for macOS
# This script helps manage the development Docker environment

set -e

COMPOSE_FILE="docker-compose.dev.yml"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_info() {
    echo -e "${BLUE}ℹ ${1}${NC}"
}

print_success() {
    echo -e "${GREEN}✓ ${1}${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ ${1}${NC}"
}

print_error() {
    echo -e "${RED}✗ ${1}${NC}"
}

docker_compose() {
    if docker compose version > /dev/null 2>&1; then
        docker compose -f "${COMPOSE_FILE}" "$@"
        return
    fi

    if command -v docker-compose > /dev/null 2>&1; then
        docker-compose -f "${COMPOSE_FILE}" "$@"
        return
    fi

    print_error "Docker Compose is not available. Install Docker Desktop or docker-compose."
    exit 1
}

# Check if Docker is installed and running
check_docker() {
    if ! command -v docker &> /dev/null; then
        print_error "Docker is not installed. Please install Docker Desktop for macOS."
        exit 1
    fi

    if ! docker info &> /dev/null; then
        print_error "Docker daemon is not running. Please start Docker Desktop."
        exit 1
    fi
}

# Check if .env file exists
check_env() {
    if [ ! -f .env ]; then
        print_warning ".env file not found. Creating from .env.example..."
        cp .env.example .env
        print_success ".env file created. Please configure it with your settings."
        print_info "At minimum, set FIREFLY_III_URL and FIREFLY_III_ACCESS_TOKEN"
    else
        print_success ".env file exists"
    fi
}

# Build the Docker image
build() {
    print_info "Building Docker image..."
    docker_compose build
    print_success "Docker image built successfully"
}

# Start the containers
start() {
    print_info "Starting containers..."
    docker_compose up -d
    ensure_assets
    print_success "Containers started successfully"
    print_info "Access the application at: http://localhost:8081"
}

# Stop the containers
stop() {
    print_info "Stopping containers..."
    docker_compose down
    print_success "Containers stopped"
}

# Restart the containers
restart() {
    stop
    start
}

# Rebuild the containers
rebuild() {
    stop
    build
    start
}

# View logs
logs() {
    docker_compose logs -f
}

# Execute a command in the container
exec_container() {
    docker_compose exec importer "$@"
}

# Build frontend assets when the bind mount or a clean volume removed them.
ensure_assets() {
    if exec_container test -f /var/www/html/public/build/manifest.json; then
        print_success "Frontend assets are available"
        return
    fi

    print_info "Frontend assets missing, installing dependencies and building them..."
    exec_container sh -lc 'npm install && cd resources/js/v2 && npm install && npm run build'
    print_success "Frontend assets built successfully"
}

# Open a shell in the container
shell() {
    print_info "Opening shell in container..."
    exec_container /bin/sh
}

# Run composer commands
composer() {
    print_info "Running composer $*..."
    exec_container composer "$@"
}

# Run artisan commands
artisan() {
    print_info "Running artisan $*..."
    exec_container php artisan "$@"
}

# Run npm commands
npm() {
    print_info "Running npm $*..."
    exec_container npm "$@"
}

# Run tests
test() {
    print_info "Running tests..."
    exec_container ./vendor/bin/phpunit "$@"
}

# Run code quality checks
quality() {
    print_info "Running code quality checks..."
    exec_container ./.ci/all.sh
}

# Show status
status() {
    docker_compose ps
}

# Clean up everything
clean() {
    print_warning "This will remove all containers, volumes, and built images."
    read -p "Are you sure? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        print_info "Cleaning up..."
        docker_compose down -v --rmi local
        print_success "Cleanup complete"
    else
        print_info "Cleanup cancelled"
    fi
}

# Full setup (build + start)
setup() {
    check_docker
    check_env
    build
    start
    print_success "Development environment is ready!"
    print_info "Access the application at: http://localhost:8081"
}

# Show help
show_help() {
    cat << EOF
Firefly III Data Importer - Development Docker Script

Usage: ./dev-docker.sh [command]

Commands:
  setup         Full setup: check requirements, build, and start
  build         Build the Docker image
  start         Start the containers
  stop          Stop the containers
  restart       Restart the containers
  rebuild       Rebuild and restart the containers
  logs          View container logs (follow mode)
  status        Show container status
  shell         Open a shell in the container
  exec          Run a command in the importer container

  composer      Run composer commands (e.g., ./dev-docker.sh composer install)
  artisan       Run artisan commands (e.g., ./dev-docker.sh artisan migrate)
  npm           Run npm commands (e.g., ./dev-docker.sh npm install)
  test          Run PHPUnit tests
  quality       Run all code quality checks

  clean         Remove all containers, volumes, and images
  help          Show this help message

Examples:
  ./dev-docker.sh setup                    # Initial setup
  ./dev-docker.sh start                    # Start containers
  ./dev-docker.sh logs                     # View logs
  ./dev-docker.sh artisan importer:version # Check version
  ./dev-docker.sh test                     # Run tests
  ./dev-docker.sh composer install         # Install dependencies
  ./dev-docker.sh exec php -v              # Run a one-off command in the container

EOF
}

# Main script logic
case "${1:-}" in
    setup)
        setup
        ;;
    build)
        check_docker
        build
        ;;
    rebuild)
        check_docker
        rebuild
        ;;
    start)
        check_docker
        check_env
        start
        ;;
    stop)
        check_docker
        stop
        ;;
    restart)
        check_docker
        restart
        ;;
    logs)
        check_docker
        logs
        ;;
    status)
        check_docker
        status
        ;;
    shell)
        check_docker
        shell
        ;;
    exec)
        check_docker
        shift
        exec_container "$@"
        ;;
    composer)
        check_docker
        shift
        composer "$@"
        ;;
    artisan)
        check_docker
        shift
        artisan "$@"
        ;;
    npm)
        check_docker
        shift
        npm "$@"
        ;;
    test)
        check_docker
        shift
        test "$@"
        ;;
    quality)
        check_docker
        quality
        ;;
    clean)
        check_docker
        clean
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        if [ -z "${1:-}" ]; then
            show_help
        else
            print_error "Unknown command: $1"
            echo
            show_help
            exit 1
        fi
        ;;
esac
