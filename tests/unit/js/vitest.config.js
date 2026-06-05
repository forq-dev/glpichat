import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/unit/js/setup.js'],
    include: ['./tests/unit/js/**/*.test.js'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'json'],
      lines: 80,
      functions: 80,
      branches: 80,
      statements: 80,
      exclude: [
        'node_modules/',
        'tests/',
        'src/js/modules/**/*.test.js'
      ]
    }
  }
});
