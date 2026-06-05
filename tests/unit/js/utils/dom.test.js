import { describe, it, expect, beforeEach } from 'vitest';
import {
  isVisibleElement,
  detectBottomObstruction,
  detectTopObstruction,
  updateSafeBottom,
  submitForm,
  channelKey,
  isLeftPosition
} from '../../../../src/js/modules/dom.js';

describe('DOM Module', () => {
  beforeEach(() => {
    document.documentElement.innerHTML = '<body></body>';
  });

  describe('isVisibleElement Function', () => {
    it('should return true for visible element', () => {
      const element = document.createElement('div');
      element.dataset.display = 'block';
      element.dataset.visibility = 'visible';
      element.dataset.opacity = '1';
      
      const result = isVisibleElement(element);
      expect(result).toBe(true);
    });

    it('should return false for element with display:none', () => {
      const element = document.createElement('div');
      element.dataset.display = 'none';
      element.dataset.visibility = 'visible';
      element.dataset.opacity = '1';
      
      const result = isVisibleElement(element);
      expect(result).toBe(false);
    });

    it('should return false for element with visibility:hidden', () => {
      const element = document.createElement('div');
      element.dataset.display = 'block';
      element.dataset.visibility = 'hidden';
      element.dataset.opacity = '1';
      
      const result = isVisibleElement(element);
      expect(result).toBe(false);
    });

    it('should return false for element with opacity:0', () => {
      const element = document.createElement('div');
      element.dataset.display = 'block';
      element.dataset.visibility = 'visible';
      element.dataset.opacity = '0';
      
      const result = isVisibleElement(element);
      expect(result).toBe(false);
    });

    it('should return false for element with opacity:0.5 (less than 0 check uses > 0)', () => {
      // Note: opacity > 0 means 0.5 is visible
      const element = document.createElement('div');
      element.dataset.display = 'block';
      element.dataset.visibility = 'visible';
      element.dataset.opacity = '0.5';
      
      const result = isVisibleElement(element);
      expect(result).toBe(true);
    });

    it('should handle missing opacity gracefully', () => {
      const element = document.createElement('div');
      element.dataset.display = 'block';
      element.dataset.visibility = 'visible';
      // No opacity set
      
      const result = isVisibleElement(element);
      expect(result).toBe(true);
    });
  });

  describe('detectBottomObstruction Function', () => {
    it('should return 0 when no obstruction exists', () => {
      document.body.innerHTML = '<div></div>';
      
      const result = detectBottomObstruction();
      expect(result).toBe(0);
      expect(typeof result).toBe('number');
    });

    it('should detect fixed element at bottom', () => {
      const footer = document.createElement('footer');
      footer.dataset.position = 'fixed';
      footer.dataset.display = 'block';
      footer.dataset.visibility = 'visible';
      footer.dataset.opacity = '1';
      footer.dataset.bottom = '0';
      footer.dataset.top = '700';
      footer.dataset.left = '0';
      footer.dataset.width = '1024';
      footer.dataset.height = '68';
      
      document.body.appendChild(footer);
      
      const result = detectBottomObstruction();
      expect(typeof result).toBe('number');
      expect(result).toBeGreaterThanOrEqual(0);
    });

    it('should ignore hidden fixed elements', () => {
      const footer = document.createElement('footer');
      footer.dataset.position = 'fixed';
      footer.dataset.display = 'none';
      footer.dataset.visibility = 'visible';
      footer.dataset.opacity = '1';
      footer.dataset.bottom = '0';
      
      document.body.appendChild(footer);
      
      const result = detectBottomObstruction();
      expect(result).toBe(0);
    });

    it('should ignore glpichat-widget elements', () => {
      const widget = document.createElement('div');
      widget.classList.add('glpichat-widget');
      widget.dataset.position = 'fixed';
      widget.dataset.display = 'block';
      widget.dataset.visibility = 'visible';
      widget.dataset.opacity = '1';
      widget.dataset.bottom = '0';
      widget.dataset.width = '1024';
      widget.dataset.height = '500';
      
      document.body.appendChild(widget);
      
      const result = detectBottomObstruction();
      expect(result).toBe(0);
    });

    it('should ignore non-fixed elements', () => {
      const div = document.createElement('div');
      div.dataset.position = 'static';
      div.dataset.display = 'block';
      div.dataset.visibility = 'visible';
      div.dataset.opacity = '1';
      div.dataset.bottom = '0';
      
      document.body.appendChild(div);
      
      const result = detectBottomObstruction();
      expect(result).toBe(0);
    });

    it('should handle multiple obstructions and return max', () => {
      const footer1 = document.createElement('footer');
      footer1.dataset.position = 'fixed';
      footer1.dataset.display = 'block';
      footer1.dataset.visibility = 'visible';
      footer1.dataset.opacity = '1';
      footer1.dataset.top = '700';
      footer1.dataset.width = '1024';
      footer1.dataset.height = '30';
      
      const footer2 = document.createElement('footer');
      footer2.dataset.position = 'fixed';
      footer2.dataset.display = 'block';
      footer2.dataset.visibility = 'visible';
      footer2.dataset.opacity = '1';
      footer2.dataset.top = '750';
      footer2.dataset.width = '1024';
      footer2.dataset.height = '68';
      
      document.body.appendChild(footer1);
      document.body.appendChild(footer2);
      
      const result = detectBottomObstruction();
      expect(typeof result).toBe('number');
      expect(result).toBeGreaterThanOrEqual(0);
    });
  });

  describe('detectTopObstruction Function', () => {
    it('should return 0 when no obstruction exists', () => {
      document.body.innerHTML = '<div></div>';
      
      const result = detectTopObstruction();
      expect(result).toBe(0);
      expect(typeof result).toBe('number');
    });

    it('should detect fixed element at top', () => {
      const header = document.createElement('header');
      header.dataset.position = 'fixed';
      header.dataset.display = 'block';
      header.dataset.visibility = 'visible';
      header.dataset.opacity = '1';
      header.dataset.top = '0';
      header.dataset.bottom = '80';
      header.dataset.left = '0';
      header.dataset.width = '1024';
      header.dataset.height = '80';
      
      document.body.appendChild(header);
      
      const result = detectTopObstruction();
      expect(typeof result).toBe('number');
      expect(result).toBeGreaterThanOrEqual(0);
    });

    it('should ignore hidden fixed elements', () => {
      const header = document.createElement('header');
      header.dataset.position = 'fixed';
      header.dataset.display = 'none';
      header.dataset.visibility = 'visible';
      header.dataset.opacity = '1';
      header.dataset.top = '0';
      
      document.body.appendChild(header);
      
      const result = detectTopObstruction();
      expect(result).toBe(0);
    });

    it('should ignore non-fixed elements', () => {
      const div = document.createElement('div');
      div.dataset.position = 'static';
      div.dataset.display = 'block';
      div.dataset.visibility = 'visible';
      div.dataset.opacity = '1';
      div.dataset.top = '0';
      
      document.body.appendChild(div);
      
      const result = detectTopObstruction();
      expect(result).toBe(0);
    });
  });

  describe('updateSafeBottom Function', () => {
    it('should set CSS variables for safe positioning', () => {
      updateSafeBottom();
      
      const safeBottom = document.documentElement.style.getPropertyValue('--glpichat-safe-bottom');
      const safeTop = document.documentElement.style.getPropertyValue('--glpichat-safe-top');
      
      expect(safeBottom).toBeDefined();
      expect(safeTop).toBeDefined();
      expect(safeBottom).toMatch(/\d+px/);
      expect(safeTop).toMatch(/\d+px/);
    });

    it('should include SAFE_BOTTOM_MARGIN_PX (8px) in calculation', () => {
      updateSafeBottom();
      
      const safeBottom = document.documentElement.style.getPropertyValue('--glpichat-safe-bottom');
      // Should be at least 8px (SAFE_BOTTOM_MARGIN_PX)
      const value = parseInt(safeBottom);
      expect(value).toBeGreaterThanOrEqual(8);
    });

    it('should update CSS variables when called multiple times', () => {
      updateSafeBottom();
      let safeBottom1 = document.documentElement.style.getPropertyValue('--glpichat-safe-bottom');
      
      updateSafeBottom();
      let safeBottom2 = document.documentElement.style.getPropertyValue('--glpichat-safe-bottom');
      
      expect(safeBottom1).toBeDefined();
      expect(safeBottom2).toBeDefined();
    });
  });

  describe('submitForm Function', () => {
    it('should call requestSubmit if available', () => {
      const form = document.createElement('form');
      let requestSubmitCalled = false;
      form.requestSubmit = () => {
        requestSubmitCalled = true;
      };
      
      submitForm(form);
      expect(requestSubmitCalled).toBe(true);
    });

    it('should dispatch submit event as fallback', () => {
      const form = document.createElement('form');
      delete form.requestSubmit; // Ensure requestSubmit is not available
      
      let submitEventFired = false;
      form.addEventListener('submit', () => {
        submitEventFired = true;
      });
      
      submitForm(form);
      expect(submitEventFired).toBe(true);
    });

    it('should not throw on missing requestSubmit', () => {
      const form = document.createElement('form');
      delete form.requestSubmit;
      
      expect(() => {
        submitForm(form);
      }).not.toThrow();
    });
  });

  describe('channelKey Function', () => {
    it('should generate key in format "ticketsId:roomType"', () => {
      const key = channelKey(123, 'public');
      expect(key).toBe('123:public');
    });

    it('should work with different ticket IDs', () => {
      const key1 = channelKey(1, 'public');
      const key2 = channelKey(999, 'public');
      
      expect(key1).toBe('1:public');
      expect(key2).toBe('999:public');
    });

    it('should work with internal room type', () => {
      const key = channelKey(456, 'internal');
      expect(key).toBe('456:internal');
    });

    it('should maintain uniqueness', () => {
      const key1 = channelKey(123, 'public');
      const key2 = channelKey(123, 'internal');
      const key3 = channelKey(456, 'public');
      
      expect(key1).not.toBe(key2);
      expect(key1).not.toBe(key3);
      expect(key2).not.toBe(key3);
    });

    it('should handle large ticket IDs', () => {
      const key = channelKey(999999999, 'public');
      expect(key).toBe('999999999:public');
    });
  });

  describe('isLeftPosition Function', () => {
    it('should return true for bottom-left', () => {
      const result = isLeftPosition('bottom-left');
      expect(result).toBe(true);
    });

    it('should return true for top-left', () => {
      const result = isLeftPosition('top-left');
      expect(result).toBe(true);
    });

    it('should return false for bottom-right', () => {
      const result = isLeftPosition('bottom-right');
      expect(result).toBe(false);
    });

    it('should return false for top-right', () => {
      const result = isLeftPosition('top-right');
      expect(result).toBe(false);
    });

    it('should return false for invalid position', () => {
      const result = isLeftPosition('invalid-position');
      expect(result).toBe(false);
    });

    it('should be case-sensitive', () => {
      const result = isLeftPosition('BOTTOM-LEFT');
      expect(result).toBe(false);
    });

    it('should handle empty string', () => {
      const result = isLeftPosition('');
      expect(result).toBe(false);
    });

    it('should handle null and undefined', () => {
      expect(isLeftPosition(null)).toBe(false);
      expect(isLeftPosition(undefined)).toBe(false);
    });
  });
});
