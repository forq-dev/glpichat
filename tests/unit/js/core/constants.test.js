import { describe, it, expect } from 'vitest';
import {
  POLL_FOCUSED_MS,
  POLL_IDLE_MS,
  POLL_BG_MS,
  META_SYNC_MS,
  DESKTOP_MAX_OPEN,
  MOBILE_MAX_OPEN,
  VALID_POSITIONS,
  SAFE_BOTTOM_MARGIN_PX,
  TERMINAL_TICKET_STATUSES,
  pluginBase,
  isValidRoomType
} from '../../../../src/js/modules/constants.js';

describe('Constants Module', () => {
  // Test numeric constants
  describe('Numeric Constants', () => {
    it('POLL_FOCUSED_MS should be 2500', () => {
      expect(POLL_FOCUSED_MS).toBe(2500);
      expect(typeof POLL_FOCUSED_MS).toBe('number');
    });

    it('POLL_IDLE_MS should be 9000', () => {
      expect(POLL_IDLE_MS).toBe(9000);
      expect(typeof POLL_IDLE_MS).toBe('number');
    });

    it('POLL_BG_MS should be 18000', () => {
      expect(POLL_BG_MS).toBe(18000);
      expect(typeof POLL_BG_MS).toBe('number');
    });

    it('META_SYNC_MS should be 15000', () => {
      expect(META_SYNC_MS).toBe(15000);
      expect(typeof META_SYNC_MS).toBe('number');
    });

    it('DESKTOP_MAX_OPEN should be 3', () => {
      expect(DESKTOP_MAX_OPEN).toBe(3);
      expect(typeof DESKTOP_MAX_OPEN).toBe('number');
    });

    it('MOBILE_MAX_OPEN should be 1', () => {
      expect(MOBILE_MAX_OPEN).toBe(1);
      expect(typeof MOBILE_MAX_OPEN).toBe('number');
    });

    it('SAFE_BOTTOM_MARGIN_PX should be 8', () => {
      expect(SAFE_BOTTOM_MARGIN_PX).toBe(8);
      expect(typeof SAFE_BOTTOM_MARGIN_PX).toBe('number');
    });
  });

  // Test VALID_POSITIONS array
  describe('VALID_POSITIONS Array', () => {
    it('should be an array with exactly 4 strings', () => {
      expect(Array.isArray(VALID_POSITIONS)).toBe(true);
      expect(VALID_POSITIONS.length).toBe(4);
    });

    it('should contain all required positions', () => {
      expect(VALID_POSITIONS).toContain('bottom-right');
      expect(VALID_POSITIONS).toContain('bottom-left');
      expect(VALID_POSITIONS).toContain('top-left');
      expect(VALID_POSITIONS).toContain('top-right');
    });

    it('all elements should be strings', () => {
      VALID_POSITIONS.forEach((pos) => {
        expect(typeof pos).toBe('string');
      });
    });
  });

  // Test TERMINAL_TICKET_STATUSES Set
  describe('TERMINAL_TICKET_STATUSES Set', () => {
    it('should be a Set instance', () => {
      expect(TERMINAL_TICKET_STATUSES instanceof Set).toBe(true);
    });

    it('should contain exactly 2 elements', () => {
      expect(TERMINAL_TICKET_STATUSES.size).toBe(2);
    });

    it('should contain statuses 5 and 6', () => {
      expect(TERMINAL_TICKET_STATUSES.has(5)).toBe(true);
      expect(TERMINAL_TICKET_STATUSES.has(6)).toBe(true);
    });

    it('should not contain other statuses', () => {
      expect(TERMINAL_TICKET_STATUSES.has(1)).toBe(false);
      expect(TERMINAL_TICKET_STATUSES.has(4)).toBe(false);
      expect(TERMINAL_TICKET_STATUSES.has(7)).toBe(false);
    });
  });

  // Test pluginBase string
  describe('pluginBase String', () => {
    it('should be a non-empty string', () => {
      expect(typeof pluginBase).toBe('string');
      expect(pluginBase.length).toBeGreaterThan(0);
    });

    it('should equal "/plugins/glpichat"', () => {
      expect(pluginBase).toBe('/plugins/glpichat');
    });
  });

  // Test isValidRoomType function
  describe('isValidRoomType Function', () => {
    it('should accept "public"', () => {
      expect(isValidRoomType('public')).toBe(true);
    });

    it('should accept "internal"', () => {
      expect(isValidRoomType('internal')).toBe(true);
    });

    it('should reject other strings', () => {
      expect(isValidRoomType('other')).toBe(false);
      expect(isValidRoomType('external')).toBe(false);
      expect(isValidRoomType('private')).toBe(false);
    });

    it('should reject empty string', () => {
      expect(isValidRoomType('')).toBe(false);
    });

    it('should reject null and undefined', () => {
      expect(isValidRoomType(null)).toBe(false);
      expect(isValidRoomType(undefined)).toBe(false);
    });

    it('should be case-sensitive', () => {
      expect(isValidRoomType('PUBLIC')).toBe(false);
      expect(isValidRoomType('Public')).toBe(false);
      expect(isValidRoomType('INTERNAL')).toBe(false);
    });
  });
});
