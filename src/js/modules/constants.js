/**
 * Constants Module
 * Extracted from glpichat.js for testing and reuse
 */

export const POLL_FOCUSED_MS = 2500;
export const POLL_IDLE_MS = 9000;
export const POLL_BG_MS = 18000;
export const META_SYNC_MS = 15000;
export const DESKTOP_MAX_OPEN = 3;
export const MOBILE_MAX_OPEN = 1;
export const VALID_POSITIONS = ["bottom-right", "bottom-left", "top-left", "top-right"];
export const SAFE_BOTTOM_MARGIN_PX = 8;
export const TERMINAL_TICKET_STATUSES = new Set([5, 6]);
export const pluginBase = "/plugins/glpichat";

/**
 * Validate if room type is valid
 * @param {string} roomType - The room type to validate ("public" or "internal")
 * @returns {boolean} True if valid
 */
export const isValidRoomType = (roomType) => roomType === "public" || roomType === "internal";
