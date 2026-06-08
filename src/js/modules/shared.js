"use strict";

export const normalizeEventCursor = (value) => {
  if (typeof value === "string") {
    return value;
  }
  if (typeof value === "number" && Number.isFinite(value) && value > 0) {
    return String(value);
  }
  return "";
};

export const isNodeNearBottom = (node, threshold = 160) => {
  if (!(node instanceof HTMLElement)) {
    return true;
  }
  return (node.scrollHeight - (node.scrollTop + node.clientHeight)) <= threshold;
};

export const hasSelectionInsideNode = (node) => {
  if (!(node instanceof HTMLElement)) {
    return false;
  }
  const selection = window.getSelection();
  if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
    return false;
  }
  const anchorNode = selection.anchorNode;
  const focusNode = selection.focusNode;
  return Boolean(anchorNode && node.contains(anchorNode))
    || Boolean(focusNode && node.contains(focusNode));
};

export const firstFileFromTransfer = (transfer) => {
  if (!transfer) {
    return null;
  }
  if (transfer.files instanceof FileList && transfer.files.length > 0) {
    const directFile = transfer.files[0];
    return directFile instanceof File ? directFile : null;
  }
  if (!transfer.items) {
    return null;
  }
  for (const item of Array.from(transfer.items)) {
    if (!item || item.kind !== "file") {
      continue;
    }
    const file = item.getAsFile();
    if (file instanceof File) {
      return file;
    }
  }
  return null;
};

export const assignSingleFileToInput = (fileInput, file) => {
  if (!(fileInput instanceof HTMLInputElement) || !(file instanceof File)) {
    return false;
  }
  if (typeof DataTransfer !== "function") {
    return false;
  }
  const transfer = new DataTransfer();
  transfer.items.add(file);
  fileInput.files = transfer.files;
  return fileInput.files instanceof FileList && fileInput.files.length > 0;
};
