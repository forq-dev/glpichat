/**
 * State Module
 * Extracted from glpichat.js for localStorage and UI state management
 */

import { VALID_POSITIONS } from './constants.js';

/**
 * Load UI state from localStorage
 * @returns {Object} Parsed state object or empty object on error
 */
export const storageLoad = () => {
  try {
    return JSON.parse(localStorage.getItem("glpichat_widget_ui") || "{}");
  } catch (_err) {
    return {};
  }
};

/**
 * Save UI state to localStorage with merge
 * @param {Object} patch - State patch to merge and save
 */
export const storageSave = (patch) => {
  try {
    const merged = { ...storageLoad(), ...patch };
    localStorage.setItem("glpichat_widget_ui", JSON.stringify(merged));
  } catch (_err) {
    // Silently handle storage errors
  }
};

/**
 * Load and validate UI state from localStorage
 * Filters out invalid IDs and validates types
 * @param {Object} stateObj - State object to populate (external state)
 * @returns {void}
 */
export const loadUiState = (stateObj) => {
  const data = storageLoad();
  
  // Load and validate openTicketIds
  stateObj.openTicketIds = Array.isArray(data.openTicketIds)
    ? data.openTicketIds.filter((id) => Number.isInteger(id) && id > 0)
    : [];
  
  // Load and validate closedTicketIds
  stateObj.closedTicketIds = new Set(
    Array.isArray(data.closedTicketIds)
      ? data.closedTicketIds.filter((id) => Number.isInteger(id) && id > 0)
      : []
  );
  
  // Load and validate manualTicketIds
  stateObj.manualTicketIds = new Set(
    Array.isArray(data.manualTicketIds)
      ? data.manualTicketIds.filter((id) => Number.isInteger(id) && id > 0)
      : []
  );
  
  // Load launcher open state
  stateObj.launcherOpen = data.launcherOpen === true;
  
  // Load and validate widgetPosition
  if (typeof data.widgetPosition === "string" && VALID_POSITIONS.includes(data.widgetPosition)) {
    stateObj.widgetPosition = data.widgetPosition;
  }
};

/**
 * Save UI state to localStorage
 * Only saves manualTicketIds for persistence
 * @param {Object} stateObj - State object to save
 */
export const saveUiState = (stateObj) => {
  const persistentOpenTicketIds = (stateObj.openTicketIds || []).filter(
    (id) => stateObj.manualTicketIds && stateObj.manualTicketIds.has(id)
  );
  
  storageSave({
    openTicketIds: persistentOpenTicketIds,
    closedTicketIds: stateObj.closedTicketIds ? Array.from(stateObj.closedTicketIds) : [],
    manualTicketIds: stateObj.manualTicketIds ? Array.from(stateObj.manualTicketIds) : [],
    launcherOpen: stateObj.launcherOpen || false,
    widgetPosition: stateObj.widgetPosition || "bottom-right",
  });
};
