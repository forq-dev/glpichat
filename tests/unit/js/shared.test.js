import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  assignSingleFileToInput,
  firstFileFromTransfer,
  hasSelectionInsideNode,
  isNodeNearBottom,
  normalizeEventCursor
} from '../../../src/js/modules/shared.js';

const createFileList = (file) => {
  const list = [file];
  if (typeof FileList === 'function' && FileList.prototype) {
    Object.setPrototypeOf(list, FileList.prototype);
  }
  return list;
};

describe('Shared Module', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('normalizes event cursors deterministically', () => {
    expect(normalizeEventCursor('42:cursor')).toBe('42:cursor');
    expect(normalizeEventCursor(12)).toBe('12');
    expect(normalizeEventCursor(3.5)).toBe('3.5');
    expect(normalizeEventCursor(0)).toBe('');
    expect(normalizeEventCursor(-1)).toBe('');
    expect(normalizeEventCursor(Number.POSITIVE_INFINITY)).toBe('');
    expect(normalizeEventCursor(null)).toBe('');
    expect(normalizeEventCursor(undefined)).toBe('');
  });

  it('detects proximity to the bottom of a scroll container', () => {
    const node = document.createElement('div');

    Object.defineProperties(node, {
      scrollHeight: { configurable: true, value: 600 },
      scrollTop: { configurable: true, value: 420 },
      clientHeight: { configurable: true, value: 140 }
    });

    expect(isNodeNearBottom(node, 40)).toBe(true);
    expect(isNodeNearBottom(node, 39)).toBe(false);
    expect(isNodeNearBottom(null)).toBe(true);
  });

  it('detects selection anchors and focus points inside the node', () => {
    const node = document.createElement('div');
    const child = document.createTextNode('hello');
    node.appendChild(child);
    const outside = document.createTextNode('outside');

    vi.spyOn(window, 'getSelection').mockReturnValue({
      rangeCount: 1,
      isCollapsed: false,
      anchorNode: child,
      focusNode: null
    });
    expect(hasSelectionInsideNode(node)).toBe(true);

    vi.spyOn(window, 'getSelection').mockReturnValue({
      rangeCount: 1,
      isCollapsed: false,
      anchorNode: outside,
      focusNode: child
    });
    expect(hasSelectionInsideNode(node)).toBe(true);

    vi.spyOn(window, 'getSelection').mockReturnValue({
      rangeCount: 1,
      isCollapsed: false,
      anchorNode: outside,
      focusNode: outside
    });
    expect(hasSelectionInsideNode(node)).toBe(false);

    vi.spyOn(window, 'getSelection').mockReturnValue({
      rangeCount: 1,
      isCollapsed: true,
      anchorNode: child,
      focusNode: child
    });
    expect(hasSelectionInsideNode(node)).toBe(false);
  });

  it('reads the first file from transfer.files when available', () => {
    const file = new File(['fixture'], 'fixture.txt', { type: 'text/plain' });
    const transfer = {
      files: createFileList(file),
    };

    expect(firstFileFromTransfer(transfer)).toBe(file);
  });

  it('falls back to transfer.items when transfer.files is not present', () => {
    const file = new File(['fixture'], 'fixture.txt', { type: 'text/plain' });
    const transfer = {
      items: [
        { kind: 'string', getAsFile: () => null },
        { kind: 'file', getAsFile: () => file }
      ]
    };

    expect(firstFileFromTransfer(transfer)).toBe(file);
    expect(firstFileFromTransfer(null)).toBeNull();
    expect(firstFileFromTransfer({})).toBeNull();
  });

  it('assigns a single file to the input when DataTransfer is available', () => {
    const previousDataTransfer = globalThis.DataTransfer;

    class MockDataTransfer {
      constructor() {
        this.file = null;
        this.items = {
          add: (addedFile) => {
            this.file = addedFile;
          }
        };
      }

      get files() {
        return this.file ? createFileList(this.file) : createFileList(undefined);
      }
    }

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    let assignedFiles = null;
    Object.defineProperty(fileInput, 'files', {
      configurable: true,
      get: () => assignedFiles,
      set: (value) => {
        assignedFiles = value;
      }
    });

    const file = new File(['fixture'], 'fixture.txt', { type: 'text/plain' });
    vi.stubGlobal('DataTransfer', MockDataTransfer);

    try {
      expect(assignSingleFileToInput(fileInput, file)).toBe(true);
      expect(assignedFiles).toBeInstanceOf(FileList);
      expect(assignedFiles.length).toBe(1);
      expect(assignedFiles[0]).toBe(file);
    } finally {
      globalThis.DataTransfer = previousDataTransfer;
    }
  });

  it('returns false for invalid input combinations', () => {
    expect(assignSingleFileToInput(null, null)).toBe(false);
    expect(assignSingleFileToInput(document.createElement('div'), new File(['x'], 'x.txt'))).toBe(false);
  });
});
