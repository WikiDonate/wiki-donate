#!/bin/bash

# Exit immediately if a command exits with a non-zero status
set -e

# Get the root directory of the repository
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== Starting Formatting ==="

# 1. Format Backend (Laravel Pint)
if [ -d "$ROOT_DIR/backend" ]; then
    echo "-> Formatting Backend (Laravel)..."
    cd "$ROOT_DIR/backend"
    if [ -f "./vendor/bin/pint" ]; then
        ./vendor/bin/pint
    else
        echo "Warning: Laravel Pint not found. Run 'composer install' in the backend directory."
    fi
else
    echo "Warning: backend directory not found."
fi

# 2. Format Frontend (Nuxt.js Lint / Prettier)
if [ -d "$ROOT_DIR/frontend" ]; then
    echo "-> Formatting Frontend (Nuxt)..."
    cd "$ROOT_DIR/frontend"
    if [ -f "yarn.lock" ] || [ -f "package.json" ]; then
        # Check if yarn is installed, otherwise fallback to npm
        if command -v yarn >/dev/null 2>&1; then
            yarn lint:fix
        else
            npm run lint:fix
        fi
    else
        echo "Warning: frontend dependencies not initialized."
    fi
else
    echo "Warning: frontend directory not found."
fi

echo "=== Formatting Completed Successfully! ==="
