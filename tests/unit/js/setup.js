import { beforeEach, afterEach, vi } from 'vitest';

// Mock localStorage com isolamento por teste
let mockStorage = {};

beforeEach(() => {
  mockStorage = {};
  
  global.localStorage = {
    getItem: (key) => mockStorage[key] || null,
    setItem: (key, value) => {
      mockStorage[key] = String(value);
    },
    removeItem: (key) => {
      delete mockStorage[key];
    },
    clear: () => {
      mockStorage = {};
    },
    key: (index) => Object.keys(mockStorage)[index] || null,
    length: Object.keys(mockStorage).length
  };
  
  // Mock window.getComputedStyle
  global.getComputedStyle = (element) => ({
    display: element.dataset.display || 'block',
    visibility: element.dataset.visibility || 'visible',
    opacity: element.dataset.opacity || '1',
    position: element.dataset.position || 'static',
    width: element.dataset.width || '100px',
    height: element.dataset.height || '100px',
    top: element.dataset.top || 'auto',
    bottom: element.dataset.bottom || 'auto',
    left: element.dataset.left || 'auto',
    right: element.dataset.right || 'auto'
  });

  // Mock getBoundingClientRect
  if (!Element.prototype.getBoundingClientRect) {
    Element.prototype.getBoundingClientRect = function() {
      return {
        width: Number(this.dataset.width || 100),
        height: Number(this.dataset.height || 100),
        top: Number(this.dataset.top || 0),
        bottom: Number(this.dataset.bottom || 100),
        left: Number(this.dataset.left || 0),
        right: Number(this.dataset.right || 100),
        x: Number(this.dataset.left || 0),
        y: Number(this.dataset.top || 0)
      };
    };
  }

  // Mock window dimensions
  Object.defineProperty(window, 'innerWidth', {
    writable: true,
    configurable: true,
    value: 1024
  });

  Object.defineProperty(window, 'innerHeight', {
    writable: true,
    configurable: true,
    value: 768
  });
});

afterEach(() => {
  mockStorage = {};
  vi.clearAllMocks();
});
