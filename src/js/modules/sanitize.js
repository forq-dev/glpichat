/**
 * Sanitize Module
 * Extracted from glpichat.js for HTML sanitization
 * Mitigates Stored XSS by escaping HTML special characters
 */

/**
 * Sanitize text by escaping HTML special characters
 * Prevents Stored XSS attacks by converting dangerous characters to HTML entities
 * @param {*} text - Text to sanitize (converted to string)
 * @returns {string} Sanitized text with HTML entities
 */
export const sanitize = (text) => String(text || "")
  .replace(/&/g, "&amp;")
  .replace(/</g, "&lt;")
  .replace(/>/g, "&gt;")
  .replace(/"/g, "&quot;")
  .replace(/'/g, "&#039;");
