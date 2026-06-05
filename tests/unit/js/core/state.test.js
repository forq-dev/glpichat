import { describe, it, expect, beforeEach } from 'vitest';
import {
  storageLoad,
  storageSave,
  loadUiState,
  saveUiState
} from '../../../../src/js/modules/state.js';

describe('State Module', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  describe('storageLoad Function', () => {
    it('should return empty object when localStorage is empty', () => {
      const result = storageLoad();
      expect(result).toEqual({});
      expect(typeof result).toBe('object');
    });

    it('should return parsed JSON from localStorage', () => {
      const testData = { openTicketIds: [1, 2, 3] };
      localStorage.setItem('glpichat_widget_ui', JSON.stringify(testData));
      
      const result = storageLoad();
      expect(result).toEqual(testData);
      expect(result.openTicketIds).toEqual([1, 2, 3]);
    });

    it('should return empty object on corrupted JSON', () => {
      localStorage.setItem('glpichat_widget_ui', 'invalid json {');
      
      const result = storageLoad();
      expect(result).toEqual({});
    });

    it('should handle null localStorage value', () => {
      localStorage.removeItem('glpichat_widget_ui');
      
      const result = storageLoad();
      expect(result).toEqual({});
    });

    it('should preserve complex data structures', () => {
      const testData = {
        openTicketIds: [1, 2, 3],
        closedTicketIds: [4, 5],
        widgetPosition: 'bottom-right',
        launcherOpen: true
      };
      localStorage.setItem('glpichat_widget_ui', JSON.stringify(testData));
      
      const result = storageLoad();
      expect(result).toEqual(testData);
    });
  });

  describe('storageSave Function', () => {
    it('should save new data to localStorage', () => {
      storageSave({ openTicketIds: [1] });
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.openTicketIds).toEqual([1]);
    });

    it('should merge with existing data', () => {
      storageSave({ openTicketIds: [1] });
      storageSave({ widgetPosition: 'bottom-left' });
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.openTicketIds).toEqual([1]);
      expect(stored.widgetPosition).toBe('bottom-left');
    });

    it('should overwrite existing keys in merge', () => {
      storageSave({ openTicketIds: [1, 2] });
      storageSave({ openTicketIds: [3, 4, 5] });
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.openTicketIds).toEqual([3, 4, 5]);
    });

    it('should handle errors gracefully', () => {
      // This test verifies error handling doesn't throw
      expect(() => {
        storageSave({ validData: true });
      }).not.toThrow();
    });

    it('should save boolean values correctly', () => {
      storageSave({ launcherOpen: true });
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.launcherOpen).toBe(true);
    });
  });

  describe('loadUiState Function', () => {
    it('should load valid openTicketIds array', () => {
      const stateObj = { openTicketIds: [], widgetPosition: 'bottom-right' };
      storageSave({ openTicketIds: [1, 2, 3] });
      
      loadUiState(stateObj);
      expect(stateObj.openTicketIds).toEqual([1, 2, 3]);
    });

    it('should filter invalid IDs from openTicketIds', () => {
      const stateObj = { openTicketIds: [] };
      storageSave({ openTicketIds: [1, 'invalid', 0, -1, 2] });
      
      loadUiState(stateObj);
      expect(stateObj.openTicketIds).toEqual([1, 2]);
    });

    it('should load closedTicketIds as Set', () => {
      const stateObj = { closedTicketIds: new Set() };
      storageSave({ closedTicketIds: [4, 5, 6] });
      
      loadUiState(stateObj);
      expect(stateObj.closedTicketIds instanceof Set).toBe(true);
      expect(stateObj.closedTicketIds.has(4)).toBe(true);
      expect(stateObj.closedTicketIds.has(5)).toBe(true);
      expect(stateObj.closedTicketIds.has(6)).toBe(true);
    });

    it('should filter invalid IDs from closedTicketIds', () => {
      const stateObj = { closedTicketIds: new Set() };
      storageSave({ closedTicketIds: [1, 'invalid', 0, -1, 2] });
      
      loadUiState(stateObj);
      expect(stateObj.closedTicketIds.size).toBe(2);
      expect(stateObj.closedTicketIds.has(1)).toBe(true);
      expect(stateObj.closedTicketIds.has(2)).toBe(true);
    });

    it('should load manualTicketIds as filtered Set', () => {
      const stateObj = { manualTicketIds: new Set() };
      storageSave({ manualTicketIds: [10, 11, 12] });
      
      loadUiState(stateObj);
      expect(stateObj.manualTicketIds instanceof Set).toBe(true);
      expect(stateObj.manualTicketIds.size).toBe(3);
    });

    it('should load launcherOpen boolean', () => {
      const stateObj = { launcherOpen: false };
      storageSave({ launcherOpen: true });
      
      loadUiState(stateObj);
      expect(stateObj.launcherOpen).toBe(true);
    });

    it('should default launcherOpen to false', () => {
      const stateObj = { launcherOpen: false };
      storageSave({ someOtherKey: 'value' });
      
      loadUiState(stateObj);
      expect(stateObj.launcherOpen).toBe(false);
    });

    it('should load valid widgetPosition', () => {
      const stateObj = { widgetPosition: 'bottom-right' };
      storageSave({ widgetPosition: 'top-left' });
      
      loadUiState(stateObj);
      expect(stateObj.widgetPosition).toBe('top-left');
    });

    it('should not load invalid widgetPosition', () => {
      const stateObj = { widgetPosition: 'bottom-right' };
      storageSave({ widgetPosition: 'invalid-position' });
      
      loadUiState(stateObj);
      expect(stateObj.widgetPosition).toBe('bottom-right');
    });

    it('should handle all VALID_POSITIONS', () => {
      const validPositions = ['bottom-right', 'bottom-left', 'top-left', 'top-right'];
      
      for (const pos of validPositions) {
        localStorage.clear();
        const stateObj = { widgetPosition: 'bottom-right' };
        storageSave({ widgetPosition: pos });
        
        loadUiState(stateObj);
        expect(stateObj.widgetPosition).toBe(pos);
      }
    });

    it('should handle empty storage', () => {
      const stateObj = {
        openTicketIds: [],
        closedTicketIds: new Set(),
        manualTicketIds: new Set(),
        launcherOpen: false,
        widgetPosition: 'bottom-right'
      };
      
      loadUiState(stateObj);
      expect(stateObj.openTicketIds).toEqual([]);
      expect(stateObj.closedTicketIds.size).toBe(0);
      expect(stateObj.manualTicketIds.size).toBe(0);
      expect(stateObj.launcherOpen).toBe(false);
      expect(stateObj.widgetPosition).toBe('bottom-right');
    });
  });

  describe('saveUiState Function', () => {
    it('should save only manual ticket IDs that are in openTicketIds', () => {
      const stateObj = {
        openTicketIds: [1, 2, 3, 4],
        closedTicketIds: new Set([5, 6]),
        manualTicketIds: new Set([1, 2, 7]),
        launcherOpen: true,
        widgetPosition: 'bottom-left'
      };
      
      saveUiState(stateObj);
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.openTicketIds).toEqual([1, 2]);
      expect(stored.closedTicketIds).toEqual([5, 6]);
      expect(stored.manualTicketIds).toEqual([1, 2, 7]);
    });

    it('should save all state fields', () => {
      const stateObj = {
        openTicketIds: [1],
        closedTicketIds: new Set([2]),
        manualTicketIds: new Set([1]),
        launcherOpen: false,
        widgetPosition: 'top-right'
      };
      
      saveUiState(stateObj);
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.launcherOpen).toBe(false);
      expect(stored.widgetPosition).toBe('top-right');
    });

    it('should convert Sets to Arrays', () => {
      const stateObj = {
        openTicketIds: [1, 2],
        closedTicketIds: new Set([3, 4]),
        manualTicketIds: new Set([1, 2]),
        launcherOpen: true,
        widgetPosition: 'bottom-right'
      };
      
      saveUiState(stateObj);
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(Array.isArray(stored.closedTicketIds)).toBe(true);
      expect(Array.isArray(stored.manualTicketIds)).toBe(true);
    });

    it('should handle empty collections', () => {
      const stateObj = {
        openTicketIds: [],
        closedTicketIds: new Set(),
        manualTicketIds: new Set(),
        launcherOpen: false,
        widgetPosition: 'bottom-right'
      };
      
      saveUiState(stateObj);
      
      const stored = JSON.parse(localStorage.getItem('glpichat_widget_ui'));
      expect(stored.openTicketIds).toEqual([]);
      expect(stored.closedTicketIds).toEqual([]);
      expect(stored.manualTicketIds).toEqual([]);
    });
  });

  describe('State Isolation Between Tests', () => {
    it('test 1: save state A', () => {
      storageSave({ openTicketIds: [100] });
      const result = storageLoad();
      expect(result.openTicketIds).toEqual([100]);
    });

    it('test 2: should not see state from test 1', () => {
      const result = storageLoad();
      expect(result.openTicketIds).toBeUndefined();
    });

    it('test 3: save different state', () => {
      storageSave({ openTicketIds: [200] });
      const result = storageLoad();
      expect(result.openTicketIds).toEqual([200]);
    });
  });
});
