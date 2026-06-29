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
// Raiz de instalacao do GLPI (CFG_GLPI.root_doc), sem o caminho do plugin.
// Necessario para montar URLs do core (ex.: /front/document.send.php) que devem
// respeitar o subdiretorio de instalacao (ex.: /glpi). Em paginas onde CFG_GLPI
// nao existe (convidado), cai em "" preservando o comportamento relativo a raiz.
export const rootDoc =
  typeof window !== "undefined" && window.CFG_GLPI && window.CFG_GLPI.root_doc
    ? window.CFG_GLPI.root_doc
    : "";

// Prefixo de todas as URLs do plugin (assets e endpoints).
// Reutiliza rootDoc para funcionar quando o GLPI esta instalado em subdiretorio
// (ex.: /glpi). O valor final permanece identico ao anterior: root_doc + "/plugins/glpichat".
export const pluginBase = rootDoc + "/plugins/glpichat";

/**
 * Validate if room type is valid
 * @param {string} roomType - The room type to validate ("public" or "internal")
 * @returns {boolean} True if valid
 */
export const isValidRoomType = (roomType) => roomType === "public" || roomType === "internal";
