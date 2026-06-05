/**
 * DOM Module
 * Extracted from glpichat.js for DOM manipulation utilities
 */

import { SAFE_BOTTOM_MARGIN_PX } from './constants.js';

/**
 * Check if an element is visible based on CSS properties
 * @param {HTMLElement} element - Element to check
 * @returns {boolean} True if element is visible
 */
export const isVisibleElement = (element) => {
  if (!element) {
    return false;
  }
  let current = element;
  while (current && current !== document.documentElement) {
    const style = window.getComputedStyle(current);
    if (style.display === "none" || style.visibility === "hidden") {
      return false;
    }
    if (Number(style.opacity || "1") === 0) {
      return false;
    }
    current = current.parentElement;
  }
  return true;
};

/**
 * Detect obstruction at bottom of viewport
 * @returns {number} Height of obstruction in pixels
 */
export const detectBottomObstruction = () => {
  const viewportHeight = window.innerHeight || 0;
  const viewportWidth = window.innerWidth || 0;
  let maxObstruction = 0;

  const obstructionElements = Array.from(document.body?.querySelectorAll("header, footer, nav, .navbar, .fixed-top, .fixed-bottom, .sticky-top") || []);

  for (const element of obstructionElements) {
    if (!(element instanceof HTMLElement)) {
      continue;
    }
    if (element.classList.contains("glpichat-widget") || element.closest(".glpichat-widget")) {
      continue;
    }
    const style = window.getComputedStyle(element);
    if (style.position !== "fixed" && style.position !== "sticky") {
      continue;
    }
    if (!isVisibleElement(element)) {
      continue;
    }
    const rect = element.getBoundingClientRect();
    if (rect.height <= 0 || rect.width <= 0) {
      continue;
    }
    if (rect.bottom < viewportHeight - 2) {
      continue;
    }
    if (rect.width < Math.min(220, viewportWidth * 0.2)) {
      continue;
    }
    if (rect.left > viewportWidth * 0.8) {
      continue;
    }
    const obstruction = Math.max(0, viewportHeight - rect.top);
    if (obstruction > maxObstruction) {
      maxObstruction = obstruction;
    }
  }

  return maxObstruction;
};

/**
 * Detect obstruction at top of viewport
 * @returns {number} Height of obstruction in pixels
 */
export const detectTopObstruction = () => {
  const viewportWidth = window.innerWidth || 0;
  let maxObstruction = 0;

  const obstructionElements = Array.from(document.body?.querySelectorAll("header, footer, nav, .navbar, .fixed-top, .fixed-bottom, .sticky-top") || []);

  for (const element of obstructionElements) {
    if (!(element instanceof HTMLElement)) {
      continue;
    }
    if (element.classList.contains("glpichat-widget") || element.closest(".glpichat-widget")) {
      continue;
    }
    const style = window.getComputedStyle(element);
    if (style.position !== "fixed" && style.position !== "sticky") {
      continue;
    }
    if (!isVisibleElement(element)) {
      continue;
    }
    const rect = element.getBoundingClientRect();
    if (rect.height <= 0 || rect.width <= 0) {
      continue;
    }
    if (rect.top > 2) {
      continue;
    }
    if (rect.width < Math.min(220, viewportWidth * 0.2)) {
      continue;
    }
    const obstruction = Math.max(0, rect.bottom);
    if (obstruction > maxObstruction) {
      maxObstruction = obstruction;
    }
  }

  return maxObstruction;
};

/**
 * Update CSS variables for safe widget positioning
 * @returns {void}
 */
export const updateSafeBottom = () => {
  const obstruction = detectBottomObstruction();
  const safeBottom = Math.max(0, obstruction) + SAFE_BOTTOM_MARGIN_PX;
  document.documentElement.style.setProperty("--glpichat-safe-bottom", `${safeBottom}px`);

  const topObstruction = detectTopObstruction();
  const safeTop = Math.max(0, topObstruction) + SAFE_BOTTOM_MARGIN_PX;
  document.documentElement.style.setProperty("--glpichat-safe-top", `${safeTop}px`);
};

/**
 * Submit a form programmatically
 * @param {HTMLFormElement} form - Form element to submit
 */
export const submitForm = (form) => {
  if (typeof form.requestSubmit === "function") {
    form.requestSubmit();
  } else {
    form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
  }
};

/**
 * Generate channel key from ticket ID and room type
 * @param {number} ticketsId - Ticket ID
 * @param {string} roomType - Room type ("public" or "internal")
 * @returns {string} Channel key in format "ticketsId:roomType"
 */
export const channelKey = (ticketsId, roomType) => `${ticketsId}:${roomType}`;

/**
 * Check if widget position is on the left side
 * @param {string} widgetPosition - Current widget position
 * @returns {boolean} True if position is left-based
 */
export const isLeftPosition = (widgetPosition) => 
  widgetPosition === "bottom-left" || widgetPosition === "top-left";
