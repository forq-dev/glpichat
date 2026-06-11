#!/bin/bash
set -e

cd "$(dirname "$0")/../../.."

echo "=== Running npm test ==="
npm test

echo ""
echo "=== Running npm test:coverage ==="
npm run test:coverage

echo ""
echo "=== Test run complete ==="
