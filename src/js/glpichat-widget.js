import {
  pluginBase,
  POLL_FOCUSED_MS,
  POLL_IDLE_MS,
  POLL_BG_MS,
  META_SYNC_MS,
  DESKTOP_MAX_OPEN,
  MOBILE_MAX_OPEN,
  VALID_POSITIONS,
  SAFE_BOTTOM_MARGIN_PX,
  TERMINAL_TICKET_STATUSES,
  isValidRoomType
} from './modules/constants.js';

import {
  storageLoad,
  storageSave,
  loadUiState,
  saveUiState
} from './modules/state.js';

import {
  isVisibleElement,
  detectBottomObstruction,
  detectTopObstruction,
  updateSafeBottom,
  submitForm,
  channelKey,
  isLeftPosition
} from './modules/dom.js';

import { sanitize } from './modules/sanitize.js';

import {
  fetchJson,
  postForm,
  setCsrfToken,
  getCsrfToken
} from './modules/http.js';

import {
  isVisibleSystemEvent,
  formatSystemEventMessage,
  formatTypingMessage
} from './modules/format.js';

"use strict";

  const AUTO_SCROLL_THRESHOLD_PX = 160;
  const INVITE_REFRESH_MS = 5000;

  const throttle = (func, limit) => {
    let inThrottle;
    return function() {
      const args = arguments;
      const context = this;
      if (!inThrottle) {
        func.apply(context, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  };

  const normalizeEventCursor = (value) => {
    if (typeof value === "string") {
      return value;
    }
    if (typeof value === "number" && Number.isFinite(value) && value > 0) {
      return String(value);
    }
    return "";
  };

  const pickLatestEventCursor = (currentValue, nextValue) => {
    const currentCursor = normalizeEventCursor(currentValue);
    const nextCursor = normalizeEventCursor(nextValue);
    if (currentCursor === "") {
      return nextCursor;
    }
    if (nextCursor === "") {
      return currentCursor;
    }
    return currentCursor.localeCompare(nextCursor) >= 0 ? currentCursor : nextCursor;
  };

  const messageSortKey = (message) => {
    const dateValue = String(message?.date_creation || "");
    const messageId = String(Number(message?.id || 0)).padStart(12, "0");

    return `${dateValue}|1|${messageId}`;
  };

  const eventSortKey = (event) => {
    const dateValue = String(event?.date_creation || "");
    const eventId = normalizeEventCursor(event?.id);

    return `${dateValue}|0|${eventId}`;
  };

  const isNodeNearBottom = (node) => {
    if (!(node instanceof HTMLElement)) {
      return true;
    }

    return (node.scrollHeight - (node.scrollTop + node.clientHeight)) <= AUTO_SCROLL_THRESHOLD_PX;
  };

  const hasSelectionInsideNode = (node) => {
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

  const firstFileFromTransfer = (transfer) => {
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

  const assignSingleFileToInput = (fileInput, file) => {
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


  const state = {
    get csrf() {
      return getCsrfToken();
    },
    set csrf(value) {
      setCsrfToken(value);
    },
    currentUserId: 0,
    activeTicketId: 0,
    channels: new Map(),
    tickets: new Map(),
    openTicketIds: [],
    pollTimer: null,
    metaTimer: null,
    launcherOpen: false,
    statusFilter: "new",
    closedTicketIds: new Set(),
    manualTicketIds: new Set(),
    transientTicketIds: new Set(),
    minimizedSnapshot: null,
    pendingMutations: 0,
    widgetPosition: "bottom-right",
    isDragging: false,
    inviteRefreshTimer: null,
  };

  const ui = {
    root: null,
    launcher: null,
    launcherPanel: null,
    launcherNotifications: null,
    launcherInvites: null,
    dock: null,
  };

  let obstructionElements = [];
  const updateObstructionElementsCache = () => {
    if (!document.body) return;
    obstructionElements = Array.from(document.body.querySelectorAll("header, footer, nav, .navbar, .fixed-top, .fixed-bottom, .sticky-top"));
  };

  const maxOpenChats = () => (window.innerWidth <= 980 ? MOBILE_MAX_OPEN : DESKTOP_MAX_OPEN);

  const insertBoxInDock = (box) => {
    if (isLeftPosition(state.widgetPosition)) {
      // Left positions: launcher is at the start (visually left due to row-reverse),
      // insert box before the launcher so it appears to its right
      ui.dock.insertBefore(box, ui.launcher);
    } else {
      // Right positions: launcher is at the end, insert box before launcher
      ui.dock.insertBefore(box, ui.launcher);
    }
    // Force layout reflow so the browser registers the box placement in the DOM
    void box.offsetHeight;
  };

  const applyWidgetPosition = (position) => {
    if (!VALID_POSITIONS.includes(position)) {
      position = "bottom-right";
    }
    state.widgetPosition = position;
    if (ui.root) {
      for (const pos of VALID_POSITIONS) {
        ui.root.classList.remove(`pos-${pos}`);
      }
      ui.root.classList.add(`pos-${position}`);
    }
  };

  const cycleWidgetPosition = () => {
    // Deprecated. Function removed.
  };

  const animatePanelClose = (panel, callback) => {
    if (!panel || panel.hidden) {
      callback();
      return;
    }
    
    if (panel._cancelClose) {
      panel._cancelClose();
    }
    
    panel.classList.add("is-closing");
    
    let called = false;
    let timeoutId = null;
    
    const onEnd = (e) => {
      if (e && e.target !== panel) return; // Prevent bubbling events from children
      if (called) return;
      called = true;
      cleanup();
      callback();
    };
    
    const cleanup = () => {
      if (timeoutId) clearTimeout(timeoutId);
      panel.classList.remove("is-closing");
      panel.removeEventListener("animationend", onEnd);
      delete panel._cancelClose;
    };
    
    panel._cancelClose = cleanup;
    
    panel.addEventListener("animationend", onEnd);
    timeoutId = setTimeout(onEnd, 150);
  };



  const activeTicketIdFromPage = () => {
    try {
      const url = new URL(window.location.href);
      const id = Number(url.searchParams.get("id") || "0");
      if (Number.isInteger(id) && id > 0 && window.location.pathname.includes("ticket")) {
        return id;
      }
    } catch (_err) {
      // no-op
    }
    return 0;
  };

  const ensureTicket = (ticketsId) => {
    if (!state.tickets.has(ticketsId)) {
      state.tickets.set(ticketsId, {
        id: ticketsId,
        channels: {},
        activeTab: "public",
        unreadTotal: 0,
        status: 0,
      });
    }
    return state.tickets.get(ticketsId);
  };

  const setChannel = (payload) => {
    if (!payload || !isValidRoomType(payload.room_type)) {
      return;
    }
    const key = channelKey(payload.tickets_id, payload.room_type);
    const prev = state.channels.get(key) || {};
    const previousMessages = Array.isArray(prev.messages) ? prev.messages : [];
    const previousLastLoadedMessageId = previousMessages.length > 0
      ? Number(previousMessages.at(-1).id || prev.last_message_id || 0)
      : Number(prev.last_message_id || 0);
    const merged = {
      ...prev,
      ...payload,
      messages: previousMessages,
      events: Array.isArray(prev.events) ? prev.events : [],
      messages_loaded: Boolean(prev.messages_loaded || false),
      history_loading: false,
      older_loading: false,
      history_failed: false,
      last_message_id: previousLastLoadedMessageId,
      last_event_id: pickLatestEventCursor(prev.last_event_id, payload.last_event_id),
      unread_count: Number(prev.unread_count || payload.unread_count || 0),
    };
    if (Object.prototype.hasOwnProperty.call(payload, "unread_count")) {
      merged.unread_count = Number(payload.unread_count || 0);
    }
    state.channels.set(key, merged);

    const ticket = ensureTicket(payload.tickets_id);
    if (isValidRoomType(payload.room_type)) {
      ticket.channels[payload.room_type] = key;
    }
    updateTicketStatus(payload.tickets_id, payload.ticket_status || ticket.status || 0);
    if (!ticket.activeTab || !isValidRoomType(ticket.activeTab) || !ticket.channels[ticket.activeTab]) {
      ticket.activeTab = payload.room_type;
    }
  };

  const recalcUnread = (ticket) => {
    let total = 0;
    for (const key of Object.values(ticket.channels)) {
      total += Number(state.channels.get(key)?.unread_count || 0);
    }
    ticket.unreadTotal = total;
  };

  const applyChannelUnread = (channel, payload) => {
    if (!Object.prototype.hasOwnProperty.call(payload, "unread_count")) {
      return false;
    }

    const nextUnread = Number(payload.unread_count || 0);
    const changed = Number(channel.unread_count || 0) !== nextUnread;
    channel.unread_count = nextUnread;
    return changed;
  };

  const tabLabel = (roomType) => (roomType === "internal" ? "Discussão técnica" : "Solicitante");
  const statusKeyFromCode = (status) => {
    const code = Number(status || 0);
    if (code === 1) {
      return "new";
    }
    if (code === 2 || code === 3) {
      return "processing";
    }
    if (code === 4) {
      return "pending";
    }
    return "other";
  };
  const statusLabel = (statusKey) => {
    if (statusKey === "new") {
      return "Novo";
    }
    if (statusKey === "processing") {
      return "Processando";
    }
    if (statusKey === "pending") {
      return "Pendente";
    }
    return "Outros";
  };
  const ticketStatusLabel = (statusCode) => {
    const code = Number(statusCode || 0);
    if (code === 5) {
      return "Solucionado";
    }
    if (code === 6) {
      return "Fechado";
    }
    return statusLabel(statusKeyFromCode(code));
  };

  const normalizedInviteText = (value) => String(value || "").trim().toLowerCase();
  const inviteFlagEnabled = (value) => value === true || value === 1 || value === "1";

  const inviteBadgeMeta = (status) => {
    if (status === "active") {
      return { label: "Convite ativo", badgeClass: "glpichat-badge-active" };
    }
    if (status === "expired") {
      return { label: "Convite expirado", badgeClass: "glpichat-badge-expired" };
    }
    if (status === "revoked") {
      return { label: "Convite revogado", badgeClass: "glpichat-badge-revoked" };
    }
    if (status === "accepted") {
      return { label: "Link consumido", badgeClass: "glpichat-badge-accepted" };
    }
    return { label: "Status desconhecido", badgeClass: "glpichat-badge-neutral" };
  };

  const inviteSessionMeta = (status) => {
    if (status === "active") {
      return {
        label: "Sessão ativa",
        badgeClass: "glpichat-badge-session-active",
        note: "Chat em andamento"
      };
    }
    if (status === "timeout" || status === "expired") {
      return {
        label: "Sessão expirada",
        badgeClass: "glpichat-badge-session-timeout",
        note: "Sessão encerrada por inatividade"
      };
    }
    if (status === "revoked" || status === "disconnected" || status === "cancelled") {
      return {
        label: "Sessão encerrada",
        badgeClass: "glpichat-badge-session-ended",
        note: "Chat encerrado manualmente"
      };
    }
    if (status === "accepted") {
      return {
        label: "Link consumido",
        badgeClass: "glpichat-badge-accepted",
        note: "Aguardando estado detalhado da sessão"
      };
    }
    return {
      label: "Sem sessão",
      badgeClass: "glpichat-badge-neutral",
      note: "Convidado ainda não entrou no chat"
    };
  };

  const deriveInviteState = (invite) => {
    const inviteStatus = normalizedInviteText(invite.invite_status || invite.status);
    const leftAt = String(invite.left_at || invite.session_left_at || invite.session_ended_at || "").trim();
    const explicitSessionStatus = normalizedInviteText(invite.session_status);
    const fallbackSessionStatus = inviteFlagEnabled(invite.is_session_active)
      ? "active"
      : (leftAt !== "" ? normalizedInviteText(invite.session_end_reason || invite.session_reason || "disconnected") : "");
    const sessionStatus = explicitSessionStatus || fallbackSessionStatus;
    const hasSessionTelemetry = sessionStatus !== ""
      || leftAt !== ""
      || inviteFlagEnabled(invite.is_session_active);

    return {
      inviteMeta: inviteBadgeMeta(inviteStatus),
      sessionMeta: inviteSessionMeta(
        sessionStatus !== "" ? sessionStatus : (inviteStatus === "accepted" ? "accepted" : "")
      ),
      canDisconnect: sessionStatus === "active" || (inviteStatus === "accepted" && !hasSessionTelemetry),
      canRevoke: inviteStatus === "active",
      canReissue: inviteStatus === "active" || inviteStatus === "expired" || inviteStatus === "revoked",
      accessLabel: invite.was_accessed ? "Sim" : "Não"
    };
  };

  const ticketIsArchived = (ticket) => TERMINAL_TICKET_STATUSES.has(Number(ticket?.status || 0));
  const archivedChatMessage = (ticket) => `Este chamado está ${ticketStatusLabel(ticket?.status || 0).toLowerCase()}. O chat foi arquivado.`;

  const updateTicketStatus = (ticketId, nextStatus) => {
    const ticket = state.tickets.get(ticketId);
    if (!ticket) {
      return false;
    }

    const parsedStatus = Number(nextStatus || 0);
    if (parsedStatus > 0) {
      const changed = ticket.status !== parsedStatus;
      ticket.status = parsedStatus;
      return changed;
    }

    return false;
  };



  const buildTimelineItems = (channel) => {
    const messages = Array.isArray(channel?.messages) ? channel.messages : [];
    const events = Array.isArray(channel?.events) ? channel.events : [];
    const timeline = [];

    for (const msg of messages) {
      timeline.push({
        kind: "message",
        sortKey: messageSortKey(msg),
        data: msg,
      });
    }

    for (const event of events) {
      if (!isVisibleSystemEvent(event)) {
        continue;
      }

      timeline.push({
        kind: "event",
        sortKey: eventSortKey(event),
        data: event,
      });
    }

    timeline.sort((left, right) => {
      return left.sortKey.localeCompare(right.sortKey);
    });
    return timeline;
  };

  const applyComposerState = (ticketId) => {
    const ticket = state.tickets.get(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    if (!ticket || !box) {
      return;
    }

    const archived = ticketIsArchived(ticket);
    const form = box.querySelector("[data-role='send-form']");
    const empty = box.querySelector("[data-role='empty']");
    if (!(form instanceof HTMLElement) || !(empty instanceof HTMLElement)) {
      return;
    }

    const textarea = form.querySelector("textarea");
    const fileInput = form.querySelector("input[type='file']");
    const submitButton = form.querySelector("button[type='submit']");
    const attachButton = form.querySelector(".glpichat-attach");

    form.hidden = archived;
    if (textarea instanceof HTMLTextAreaElement) {
      textarea.disabled = archived;
      textarea.readOnly = archived;
      textarea.placeholder = archived ? archivedChatMessage(ticket) : "Escreva uma mensagem...";
    }
    if (fileInput instanceof HTMLInputElement) {
      fileInput.disabled = archived;
    }
    if (submitButton instanceof HTMLButtonElement) {
      submitButton.disabled = archived;
    }
    if (attachButton instanceof HTMLElement) {
      attachButton.style.pointerEvents = archived ? "none" : "";
      attachButton.style.opacity = archived ? "0.5" : "";
    }

    if (archived) {
      empty.hidden = false;
      empty.textContent = archivedChatMessage(ticket);
    }
  };



  const createShell = () => {
    const root = document.createElement("div");
    root.className = "glpichat-widget";
    root.innerHTML = `
      <div class="glpichat-dock" data-role="dock">
        <div class="glpichat-chatbox glpichat-launcher" data-role="launcher">
          <div class="glpichat-chatbox-panel" data-role="launcher-panel" hidden>
            <div class="glpichat-panel-head">
              <strong>Conversas</strong>
            </div>
          <div class="glpichat-notification-view" data-role="notifications"></div>
          </div>
          <button type="button" class="glpichat-chatbox-tab" data-role="launcher-tab" aria-label="Abrir conversas">
            <img class="glpichat-launcher-icon" src="${pluginBase}/public/img/launcher-placeholder.png" alt="Chat">
            <em class="glpichat-launcher-badge" data-role="launcher-badge"></em>
          </button>
        </div>
      </div>
      <div class="glpichat-lightbox" data-role="lightbox">
        <button type="button" class="glpichat-lightbox-close" data-role="lightbox-close">×</button>
        <img class="glpichat-lightbox-img" data-role="lightbox-img" src="" alt="Preview">
      </div>
    `;

    document.body.appendChild(root);
    ui.root = root;
    ui.dock = root.querySelector("[data-role='dock']");
    ui.launcher = root.querySelector("[data-role='launcher']");
    ui.launcherPanel = root.querySelector("[data-role='launcher-panel']");
    ui.launcherNotifications = root.querySelector("[data-role='notifications']");
    ui.launcherInvites = null;
    
    ui.lightbox = root.querySelector("[data-role='lightbox']");
    ui.lightboxImg = root.querySelector("[data-role='lightbox-img']");

    // Apply saved position
    applyWidgetPosition(state.widgetPosition);


    root.querySelector(".glpichat-panel-head").addEventListener("click", (event) => {
      if (event.target.closest("button")) {
        return;
      }
      minimizeLauncher();
    });

    const closeLightbox = () => {
      ui.lightbox.classList.remove("is-open");
    };
    root.querySelector("[data-role='lightbox-close']").addEventListener("click", closeLightbox);
    ui.lightbox.addEventListener("click", (e) => {
      if (e.target === ui.lightbox) {
        closeLightbox();
      }
    });

    root.addEventListener("click", (event) => {
      const zoomImg = event.target.closest(".glpichat-zoomable-img");
      if (zoomImg) {
        ui.lightboxImg.src = zoomImg.src;
        ui.lightbox.classList.add("is-open");
      }
    });

    initWidgetDragAndDrop();
    updateSafeBottom();
  };

  const initWidgetDragAndDrop = () => {
    const tab = ui.root.querySelector("[data-role='launcher-tab']");
    let isDragging = false;
    let hasMoved = false;
    let startX = 0, startY = 0;
    let initialRect = null;
    let dragTimeout = null;

    const onMove = (e) => {
      if (!isDragging) return;
      
      const clientX = e.touches ? e.touches[0].clientX : e.clientX;
      const clientY = e.touches ? e.touches[0].clientY : e.clientY;
      
      const deltaX = clientX - startX;
      const deltaY = clientY - startY;

      // Only consider it a drag if moved more than 5px to avoid preventing normal clicks
      if (!hasMoved && (Math.abs(deltaX) > 5 || Math.abs(deltaY) > 5)) {
        hasMoved = true;
        state.isDragging = true;
        ui.root.classList.add("is-dragging");
      }

      if (hasMoved) {
        ui.root.style.transform = `translate(${deltaX}px, ${deltaY}px)`;
        e.preventDefault(); // Prevent scrolling on touch
      }
    };

    const onEnd = (e) => {
      if (!isDragging) return;
      isDragging = false;
      clearTimeout(dragTimeout);
      
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseup", onEnd);
      window.removeEventListener("touchmove", onMove, { passive: false });
      window.removeEventListener("touchend", onEnd);

      if (!hasMoved) {
        // It was a click
        toggleLauncher();
        return;
      }
      
      // Calculate closest corner (FLIP animation)
      const currentRect = ui.root.getBoundingClientRect();
      const centerX = currentRect.left + currentRect.width / 2;
      const centerY = currentRect.top + currentRect.height / 2;
      const isTop = centerY < window.innerHeight / 2;
      const isLeft = centerX < window.innerWidth / 2;
      
      const newPosition = `${isTop ? 'top' : 'bottom'}-${isLeft ? 'left' : 'right'}`;

      // First: get bounds before position change (with the current transform)
      const firstRect = ui.root.getBoundingClientRect();
      
      // Remove temporary drag transform and state
      ui.root.style.transform = '';
      ui.root.classList.remove("is-dragging");
      
      // Change position and save
      applyWidgetPosition(newPosition);
      storageSave({ widgetPosition: newPosition });
      
      // We must reflow to get the 'Last' position accurately
      // Ensure we re-run layout rules like dock flex-direction
      ui.root.offsetHeight; 
      
      // Last: get bounds after position change
      const lastRect = ui.root.getBoundingClientRect();
      
      // Invert: calculate delta
      const deltaX = firstRect.left - lastRect.left;
      const deltaY = firstRect.top - lastRect.top;
      
      // Short-circuit: if the widget didn't move far enough to change corners or position,
      // clean up immediately to avoid transition delays
      if (Math.abs(deltaX) < 1 && Math.abs(deltaY) < 1) {
        ui.root.style.transform = '';
        state.isDragging = false;
        renderDock();
        hasMoved = false;
        return;
      }
      
      // Apply inverted transform immediately (start position for FLIP)
      ui.root.style.transform = `translate(${deltaX}px, ${deltaY}px)`;
      
      // Force repaint of the start position
      ui.root.offsetHeight;
      
      // Now add transition class and animate to translate(0, 0)
      ui.root.classList.add("glpichat-animating-position");
      
      // Play: animate to translate(0, 0)
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          ui.root.style.transform = 'translate(0, 0)';
          
          let called = false;
          let timeoutId = null;
          
          const cleanup = (e) => {
            if (e && e.target !== ui.root) return; // Prevent bubbling events from children
            if (e && e.propertyName !== 'transform') return; // Trigger only for the transform transition
            if (called) return;
            called = true;
            
            if (timeoutId) clearTimeout(timeoutId);
            ui.root.classList.remove("glpichat-animating-position");
            ui.root.style.transform = '';
            ui.root.removeEventListener("transitionend", cleanup);
            state.isDragging = false;
            renderDock();
          };
          
          ui.root.addEventListener("transitionend", cleanup);
          timeoutId = setTimeout(cleanup, 400); // Safety cleanup fallback
        });
      });
      
      hasMoved = false;
    };

    const onStart = (e) => {
      // Don't drag if clicking buttons inside the tab
      if (e.target.closest("button") && e.target !== tab && e.target.closest("button") !== tab) return;
      if (e.button !== 0 && e.type === "mousedown") return; // Only left click

      isDragging = true;
      hasMoved = false;
      startX = e.touches ? e.touches[0].clientX : e.clientX;
      startY = e.touches ? e.touches[0].clientY : e.clientY;
      initialRect = ui.root.getBoundingClientRect();

      window.addEventListener("mousemove", onMove);
      window.addEventListener("mouseup", onEnd);
      window.addEventListener("touchmove", onMove, { passive: false });
      window.addEventListener("touchend", onEnd);
    };

    tab.addEventListener("mousedown", onStart);
    tab.addEventListener("touchstart", onStart, { passive: true });
    
    // Prevent default browser drag on the tab and its images
    tab.addEventListener("dragstart", (e) => e.preventDefault());

    // Remove the old toggle click listener from createShell
    tab.removeEventListener("click", toggleLauncher);
  };

  const minimizeLauncher = () => {
    if (ui.launcher) {
      ui.launcher.classList.add("is-closing");
    }
    state.launcherOpen = false;
    saveUiState(state);

    const performMinimize = () => {
      if (ui.launcherPanel) ui.launcherPanel.hidden = true;
      if (ui.launcher) ui.launcher.classList.remove("is-open", "is-closing");
    };
    animatePanelClose(ui.launcherPanel, performMinimize);
  };

  const openLauncher = () => {
    state.launcherOpen = true;
    if (ui.launcherPanel && ui.launcherPanel._cancelClose) {
      ui.launcherPanel._cancelClose();
    }
    if (ui.launcherPanel) {
      ui.launcherPanel.hidden = false;
      ui.launcherPanel.classList.remove("is-closing");
    }
    if (ui.launcher) {
      ui.launcher.classList.add("is-open");
      ui.launcher.classList.remove("is-closing");
    }
    saveUiState(state);
    renderLauncherNotifications();
    if (state.activeTicketId > 0) {
      refreshInvites(state.activeTicketId).catch(console.error);
    }
  };

  const minimizeAll = () => {
    state.minimizedSnapshot = {
      launcherOpen: state.launcherOpen,
      openTicketIds: state.openTicketIds.slice(),
    };

    if (!ui.launcherPanel.hidden) {
      if (ui.launcher) {
        ui.launcher.classList.add("is-closing");
      }
      if (ui.launcherPanel) {
        ui.launcherPanel.classList.add("is-closing");
      }
    }
    for (const ticketId of state.minimizedSnapshot.openTicketIds) {
      const box = document.getElementById(ticketBoxId(ticketId));
      const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
      if (panel && !panel.hidden) {
        if (box) {
          box.classList.add("is-closing");
        }
        panel.classList.add("is-closing");
      }
    }

    state.launcherOpen = false;
    state.openTicketIds = [];
    saveUiState(state);
    renderDock();

    if (!ui.launcherPanel.hidden) {
      animatePanelClose(ui.launcherPanel, () => {
        ui.launcherPanel.hidden = true;
        if (ui.launcher) {
          ui.launcher.classList.remove("is-open", "is-closing");
        }
      });
    }
    for (const ticketId of state.minimizedSnapshot.openTicketIds) {
      const box = document.getElementById(ticketBoxId(ticketId));
      const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
      if (panel && !panel.hidden) {
        animatePanelClose(panel, () => {
          if (box) box.remove();
        });
      }
    }
  };

  const restoreMinimizedSnapshot = async () => {
    const snapshot = state.minimizedSnapshot;
    state.minimizedSnapshot = null;
    if (!snapshot) {
      openLauncher();
      return;
    }

    state.openTicketIds = [];
    for (const ticketId of snapshot.openTicketIds) {
      if (!state.tickets.has(ticketId) || state.closedTicketIds.has(ticketId)) {
        continue;
      }
      if (!state.openTicketIds.includes(ticketId)) {
        state.openTicketIds.push(ticketId);
      }
    }
    enforceOpenLimit();
    renderDock();
    for (const ticketId of state.openTicketIds.slice()) {
      const source = state.manualTicketIds.has(ticketId) ? "manual" : "auto";
      await openTicket(ticketId, source);
    }
    if (snapshot.launcherOpen) {
      openLauncher();
      return;
    }
    minimizeLauncher();
  };

  const toggleLauncher = () => {
    if (state.launcherOpen) {
      minimizeAll();
      return;
    }
    if (state.openTicketIds.length > 0) {
      openLauncher();
      return;
    }
    if (state.minimizedSnapshot) {
      restoreMinimizedSnapshot().catch(console.error);
      return;
    }
    openLauncher();
  };

  const ticketBoxId = (ticketId) => `glpichat-box-${ticketId}`;  const ensureTicketBox = (ticketId) => {
    if (document.getElementById(ticketBoxId(ticketId))) {
      return;
    }

    const box = document.createElement("div");
    box.id = ticketBoxId(ticketId);
    box.className = "glpichat-chatbox";
    box.dataset.ticketId = String(ticketId);
    box.innerHTML = `
      <div class="glpichat-chatbox-panel" hidden>
        <div class="glpichat-dropzone-overlay">
          <span class="glpichat-dropzone-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          </span>
          <span>Solte o arquivo para enviar</span>
        </div>
        <div class="glpichat-window-head">
          <strong>Chamado #${ticketId}</strong>
          <button type="button" data-role="close">×</button>
        </div>
        <div class="glpichat-tabs" data-role="tabs"></div>
        <div class="glpichat-empty" data-role="empty" hidden></div>
        <div class="glpichat-messages" data-role="messages"></div>
        <div class="glpichat-typing-indicator" data-role="typing-indicator" hidden></div>
        <form class="glpichat-send" data-role="send-form">
          <input id="attach-${ticketId}" class="glpichat-file-input" type="file" name="file">
          <label for="attach-${ticketId}" class="glpichat-attach" title="Anexar arquivo">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          </label>
          <textarea name="content" placeholder="Escreva uma mensagem..."></textarea>
          <button type="submit" class="glpichat-send-btn" title="Enviar mensagem">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
          </button>
        </form>
      </div>
      <button type="button" class="glpichat-chatbox-tab" data-role="tab"></button>
    `;

    insertBoxInDock(box);

    box.querySelector("[data-role='tab']").addEventListener("click", () => {
      const isOpen = state.openTicketIds.includes(ticketId);
      if (!isOpen) {
        openTicket(ticketId);
      }
    });

    box.querySelector("[data-role='close']").addEventListener("click", (event) => {
      event.stopPropagation();
      closeTicket(ticketId);
    });

    const sendForm = box.querySelector("[data-role='send-form']");
    const panel = box.querySelector(".glpichat-chatbox-panel");
    const fileInput = box.querySelector(".glpichat-file-input");
    const textarea = box.querySelector("textarea");

    panel.addEventListener("dragover", (e) => {
      e.preventDefault();
      panel.classList.add("drag-over");
    });

    panel.addEventListener("dragleave", (e) => {
      if (e.relatedTarget && panel.contains(e.relatedTarget)) {
        return;
      }
      panel.classList.remove("drag-over");
    });

    panel.addEventListener("drop", (e) => {
      e.preventDefault();
      panel.classList.remove("drag-over");
      const droppedFile = firstFileFromTransfer(e.dataTransfer);
      if (assignSingleFileToInput(fileInput, droppedFile)) {
        submitForm(sendForm);
      }
    });

    // Ctrl+V paste support for clipboard files
    textarea.addEventListener("paste", (e) => {
      const pastedFile = firstFileFromTransfer(e.clipboardData);
      if (assignSingleFileToInput(fileInput, pastedFile)) {
        submitForm(sendForm);
        e.preventDefault();
      }
    });

    fileInput.addEventListener("change", () => {
      if (fileInput.files instanceof FileList && fileInput.files.length > 0) {
        submitForm(sendForm);
      }
    });

    // Enter to send, Shift+Enter for newline
    textarea.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        submitForm(sendForm);
      }
    });

    let typingTimeout = null;
    textarea.addEventListener("input", () => {
      const ticket = state.tickets.get(ticketId);
      if (!ticket) return;
      const activeTab = isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
      const key = ticket.channels[activeTab];
      const channel = state.channels.get(key);
      if (!channel) return;

      if (!channel.isTyping) {
        channel.isTyping = true;
        pollChannels().catch(console.error);
      }

      if (typingTimeout) clearTimeout(typingTimeout);
      typingTimeout = setTimeout(() => {
        channel.isTyping = false;
        pollChannels().catch(console.error);
      }, 3000);
    });

    sendForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (sendForm.hidden) {
        return;
      }
      const ticket = state.tickets.get(ticketId);
      if (!ticket) {
        return;
      }
      const activeRoom = isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
      const activeKey = ticket.channels[activeRoom];
      const activeChannel = activeKey ? state.channels.get(activeKey) : null;
      if (activeChannel) {
        activeChannel.isTyping = false;
        if (typingTimeout) {
          clearTimeout(typingTimeout);
          typingTimeout = null;
        }
      }
      const fd = new FormData(sendForm);
      const content = String(fd.get("content") || "").trim();
      const file = fd.get("file");
      fd.set("tickets_id", String(ticketId));
      fd.set("room_type", activeRoom);
      fd.set("_glpi_csrf_token", state.csrf);

      const hasFile = file instanceof File && file.size > 0;
      if (content === "" && !hasFile) {
        return;
      }

      const submitButton = event.currentTarget.querySelector("button[type='submit']");
      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
      }
      state.pendingMutations += 1;

      // Optimistic message placeholder (for text and files)
      let tempMsg = null;
      const key = ticket.channels[activeRoom];
      const channel = key ? state.channels.get(key) : null;

      if (channel && (content !== "" || hasFile)) {
        const displayContent = hasFile 
          ? `Enviando arquivo: ${file.name}...` 
          : content;

        tempMsg = {
          id: -Date.now(),
          actor_type: "user",
          users_id: state.currentUserId,
          sender_name: "Você",
          content: displayContent,
          date_creation: "Enviando...",
          is_optimistic: true,
        };
        if (!Array.isArray(channel.messages)) {
          channel.messages = [];
        }
        channel.messages.push(tempMsg);
        renderTicketMessages(ticketId, { forceScroll: true });
      }

      if (textarea) {
        textarea.value = "";
      }
      if (fileInput) {
        fileInput.value = "";
      }

      try {
        if (content !== "") {
          state.manualTicketIds.add(ticketId);
          state.transientTicketIds.delete(ticketId);
          state.closedTicketIds.delete(ticketId);
          // keepalive improves odds of completing small POSTs during accidental refresh/navigation.
          const sendResult = await postForm(`${pluginBase}/Send`, fd, { keepalive: true });
          if (channel && reconcileConfirmedMessage(channel, tempMsg, sendResult.message || null)) {
            renderTicketMessages(ticketId, { forceScroll: true });
          }
        }
        if (hasFile) {
          state.manualTicketIds.add(ticketId);
          state.transientTicketIds.delete(ticketId);
          state.closedTicketIds.delete(ticketId);
          const uploadResult = await postForm(`${pluginBase}/Upload`, fd);
          if (channel && reconcileConfirmedMessage(channel, tempMsg, uploadResult.message || null)) {
            renderTicketMessages(ticketId, { forceScroll: true });
          }
        }
      } catch (err) {
        // Rollback optimistic message on error
        if (channel && tempMsg) {
          channel.messages = channel.messages.filter((m) => m.id !== tempMsg.id);
          renderTicketMessages(ticketId);
        }
        if (textarea && content !== "") {
          textarea.value = content;
        }
        alert("Erro ao enviar: " + err.message);
      } finally {
        state.pendingMutations = Math.max(0, state.pendingMutations - 1);
        if (submitButton instanceof HTMLButtonElement) {
          submitButton.disabled = false;
        }
      }

      saveUiState(state);

      if (channel && tempMsg) {
        setTimeout(() => {
          channel.messages = channel.messages.filter((m) => m.id !== tempMsg.id);
          renderTicketMessages(ticketId);
        }, 5000);
      }

      await pollChannels();
    });

  };

  const renderTicketTabs = (ticketId) => {
    const ticket = state.tickets.get(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    if (!ticket || !box) {
      return;
    }

    const tabs = box.querySelector("[data-role='tabs']");
    tabs.innerHTML = "";
    for (const roomType of ["public", "internal"]) {
      const key = ticket.channels[roomType];
      if (!key) {
        continue;
      }
      const channel = state.channels.get(key);
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = `glpichat-tab ${ticket.activeTab === roomType ? "is-active" : ""}`;
      btn.textContent = tabLabel(roomType);
      const unread = Number(channel?.unread_count || 0);
      if (unread > 0) {
        const badge = document.createElement("em");
        badge.textContent = String(unread);
        btn.appendChild(badge);
      }
      btn.addEventListener("click", () => {
        ticket.activeTab = roomType;
        renderTicketTabs(ticketId);
        const activeChannel = state.channels.get(key);
        loadChannelHistory(activeChannel).then(() => {
          renderTicketTabs(ticketId);
          renderTicketMessages(ticketId);
        }).catch(console.error);
      });
      tabs.appendChild(btn);
    }

    applyComposerState(ticketId);
  };

  const renderTicketMessages = (ticketId, options = {}) => {
    const preservePosition = options.preservePosition === true;
    const forceScroll = options.forceScroll === true;
    const ticket = state.tickets.get(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    if (!ticket || !box) {
      return;
    }
    const activeTab = isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
    const key = ticket.channels[activeTab];
    const channel = key ? state.channels.get(key) : null;
    const empty = box.querySelector("[data-role='empty']");
    const messagesNode = box.querySelector("[data-role='messages']");
    const previousHeight = messagesNode.scrollHeight;
    const previousTop = messagesNode.scrollTop;
    const wasNearBottom = isNodeNearBottom(messagesNode);
    const shouldPreserveSelection = hasSelectionInsideNode(messagesNode);

    // Render skeleton loading screen if loading
    if (channel && channel.history_loading === true) {
      empty.hidden = true;
      messagesNode.innerHTML = `
        <div class="glpichat-skeleton-container">
          <div class="glpichat-skeleton-message is-other">
            <div class="glpichat-skeleton-line head"></div>
            <div class="glpichat-skeleton-line medium"></div>
            <div class="glpichat-skeleton-line short"></div>
          </div>
          <div class="glpichat-skeleton-message is-own">
            <div class="glpichat-skeleton-line head"></div>
            <div class="glpichat-skeleton-line long"></div>
            <div class="glpichat-skeleton-line medium"></div>
          </div>
          <div class="glpichat-skeleton-message is-other">
            <div class="glpichat-skeleton-line head"></div>
            <div class="glpichat-skeleton-line short"></div>
          </div>
        </div>
      `;
      messagesNode.scrollTop = messagesNode.scrollHeight;
      delete messagesNode.dataset.renderedSignature;
      return;
    }

    const signature = channel
      ? `${channel.messages?.length || 0}_${channel.messages?.at(-1)?.id || 0}_${channel.events?.length || 0}_${ticket.activeTab}`
      : '';
    const isSameSignature = messagesNode.dataset.renderedSignature === signature;

    if (isSameSignature) {
      renderTypingIndicator(ticketId);
      applyComposerState(ticketId);
      recalcUnread(ticket);
      renderDock();
      if (forceScroll) {
        messagesNode.scrollTop = messagesNode.scrollHeight;
      }
      return;
    }
    messagesNode.dataset.renderedSignature = signature;

    const timeline = buildTimelineItems(channel);
    messagesNode.innerHTML = "";

    if (timeline.length === 0) {
      if (!ticketIsArchived(ticket)) {
        empty.hidden = false;
        empty.textContent = ticket.activeTab === "internal"
          ? "Nenhuma mensagem na discussão técnica ainda."
          : "Nenhuma mensagem com o solicitante ainda.";
      }
    } else {
      empty.hidden = true;
      if (channel && !channel.renderedMessageIds) {
        channel.renderedMessageIds = new Set();
      }
      for (const entry of timeline) {
        if (entry.kind === "event") {
          const event = entry.data;
          const item = document.createElement("div");
          item.className = "glpichat-message is-system";
          item.innerHTML = `
            <div class="glpichat-message-head">Sistema</div>
            <div class="glpichat-message-body">${sanitize(formatSystemEventMessage(event))}</div>
            <div class="glpichat-message-time">${sanitize(event.date_creation || "")}</div>
          `;
          messagesNode.appendChild(item);
          continue;
        }

        const msg = entry.data;
        const own = msg.actor_type === "user" && Number(msg.users_id || 0) === Number(state.currentUserId || 0);
        const system = msg.actor_type !== "user" && msg.actor_type !== "guest";
        const item = document.createElement("div");
        
        let extraClasses = "";
        if (msg.is_optimistic) {
          extraClasses += " is-optimistic";
        } else {
          const msgId = Number(msg.id || 0);
          if (msgId > 0 && channel) {
            if (!channel.renderedMessageIds.has(msgId)) {
              extraClasses += " is-new";
              channel.renderedMessageIds.add(msgId);
            }
          }
        }
        
        item.className = `glpichat-message ${own ? "is-own" : "is-other"} ${system ? "is-system" : ""}${extraClasses}`;
        
        let bodyHtml = "";
        if (Number(msg.documents_id || 0) > 0) {
          const contentStr = String(msg.content || "");
          const filename = contentStr.includes(":") 
            ? contentStr.split(":").slice(1).join(":").trim() 
            : contentStr;
          
          const isImg = /\.(apng|avif|gif|jpg|jpeg|jfif|pjpeg|pjpg|png|svg|webp)$/i.test(filename);
          if (isImg) {
            bodyHtml = `
              <div class="glpichat-attachment-title">${sanitize(contentStr)}</div>
              <div class="glpichat-attachment-preview">
                <img src="/front/document.send.php?docid=${msg.documents_id}" alt="${sanitize(filename)}" class="glpichat-zoomable-img" />
              </div>
            `;
          } else {
            bodyHtml = `
              <a class="glpichat-attachment-link" href="/front/document.send.php?docid=${msg.documents_id}" target="_blank" download="${sanitize(filename)}">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:4px;"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                ${sanitize(filename)}
              </a>
            `;
          }
        } else {
          bodyHtml = sanitize(msg.content);
        }

        item.innerHTML = `
          <div class="glpichat-message-head">${sanitize(msg.sender_name || "Sistema")}</div>
          <div class="glpichat-message-body">${bodyHtml}</div>
          <div class="glpichat-message-time">${sanitize(msg.date_creation || "")}</div>
        `;
        messagesNode.appendChild(item);
      }
      const messages = Array.isArray(channel?.messages) ? channel.messages : [];
      const lastId = Number(messages.at(-1).id || 0);
      if (channel && lastId > 0) {
        markRead(channel.rooms_id, lastId).catch(console.error);
      }
    }

    renderTypingIndicator(ticketId);

    applyComposerState(ticketId);
    recalcUnread(ticket);
    renderDock();
    if (preservePosition) {
      const delta = messagesNode.scrollHeight - previousHeight;
      messagesNode.scrollTop = Math.max(0, previousTop + delta);
      return;
    }

    if (forceScroll || (!shouldPreserveSelection && (wasNearBottom || previousHeight === 0))) {
      messagesNode.scrollTop = messagesNode.scrollHeight;
      return;
    }

    const maxTop = Math.max(0, messagesNode.scrollHeight - messagesNode.clientHeight);
    messagesNode.scrollTop = Math.min(previousTop, maxTop);
  };

  const renderTypingIndicator = (ticketId) => {
    const ticket = state.tickets.get(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    if (!ticket || !box) {
      return;
    }

    const activeTab = isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
    const key = ticket.channels[activeTab];
    const channel = key ? state.channels.get(key) : null;
    const typingIndicator = box.querySelector("[data-role='typing-indicator']");
    if (!(typingIndicator instanceof HTMLElement)) {
      return;
    }

    if (channel && Array.isArray(channel.typing_names) && channel.typing_names.length > 0) {
      typingIndicator.textContent = formatTypingMessage(channel.typing_names);
      typingIndicator.hidden = false;
      return;
    }

    typingIndicator.hidden = true;
  };

  const enforceOpenLimit = () => {
    const limit = maxOpenChats();
    while (state.openTicketIds.length > limit) {
      const id = state.openTicketIds.shift();
      if (id) {
        const box = document.getElementById(ticketBoxId(id));
        if (box) {
          const panel = box.querySelector(".glpichat-chatbox-panel");
          const cleanup = () => {
            box.classList.remove("is-open");
            if (panel) panel.hidden = true;
          };
          animatePanelClose(panel, cleanup);
        }
      }
    }
  };

  const openTicket = async (ticketId, source) => {
    if (!state.tickets.has(ticketId)) {
      return;
    }
    const openSource = source === "manual" ? "manual" : "auto";
    if (openSource === "manual") {
      state.manualTicketIds.add(ticketId);
      state.transientTicketIds.delete(ticketId);
    }
    state.closedTicketIds.delete(ticketId);
    ensureTicketBox(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    if (box && ui.dock && ui.launcher) {
      insertBoxInDock(box);
      // Force layout reflow so the browser registers the box placement in the DOM
      void box.offsetHeight;
    }
    state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
    state.openTicketIds.push(ticketId);
    enforceOpenLimit();

    const openedBox = document.getElementById(ticketBoxId(ticketId));
    openedBox.classList.add("is-open");
    openedBox.classList.remove("is-closing");
    const panel = openedBox.querySelector(".glpichat-chatbox-panel");
    if (panel) {
      if (panel._cancelClose) {
        panel._cancelClose();
      }
      panel.hidden = false;
      panel.classList.remove("is-closing");
      // Force layout reflow so the browser registers the transition target states correctly
      void panel.offsetHeight;
    }
    const ticket = state.tickets.get(ticketId);
    const activeTab = ticket && isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
    const activeKey = ticket ? ticket.channels[activeTab] : null;
    if (activeKey) {
      try {
        await loadChannelHistory(state.channels.get(activeKey));
      } catch (_error) {
        // keep channel as not loaded to allow a retry path on next poll/open.
      }
    }
    renderTicketTabs(ticketId);
    renderTicketMessages(ticketId);
    saveUiState(state);
    schedulePolling();
  };

  const notifyLeaveRoom = (ticketId) => {
    const ticket = state.tickets.get(ticketId);
    const activeTab = ticket && isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
    const key = ticket ? ticket.channels[activeTab] : null;
    const channel = key ? state.channels.get(key) : null;
    if (channel && channel.rooms_id) {
      const fd = new FormData();
      fd.set("rooms_id", String(channel.rooms_id));
      fd.set("_glpi_csrf_token", state.csrf);
      postForm(`${pluginBase}/Widget/Leave`, fd).catch(console.error);
    }
  };

  const minimizeTicket = (ticketId) => {
    notifyLeaveRoom(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
    if (box) {
      box.classList.add("is-closing");
    }
    if (panel) {
      panel.classList.add("is-closing");
    }
    state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
    saveUiState(state);
    renderDock();

    const performMinimize = () => {
      if (box) {
        box.classList.remove("is-open", "is-closing");
        if (panel) {
          panel.hidden = true;
          panel.classList.remove("is-closing");
        }
      }
      renderDock();
    };

    animatePanelClose(panel, performMinimize);
  };

  const closeTicket = (ticketId) => {
    notifyLeaveRoom(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
    if (box) {
      box.classList.add("is-closing");
    }
    if (panel) {
      panel.classList.add("is-closing");
    }
    state.closedTicketIds.add(ticketId);
    state.manualTicketIds.delete(ticketId);
    state.transientTicketIds.delete(ticketId);
    state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
    saveUiState(state);
    renderDock();

    const performClose = () => {
      if (box) box.remove();
    };

    animatePanelClose(panel, performClose);
  };

  const renderDock = () => {
    if (state.isDragging || (ui.root && ui.root.classList.contains("glpichat-animating-position"))) {
      return;
    }
    const allTicketIds = Array.from(state.tickets.keys()).sort((a, b) => a - b);
    let dirtyUiState = false;
    const visibleSet = new Set();
    for (const ticketId of state.manualTicketIds) {
      if (state.openTicketIds.includes(ticketId) && state.tickets.has(ticketId) && !state.closedTicketIds.has(ticketId)) {
        visibleSet.add(ticketId);
      }
    }
    for (const ticketId of state.transientTicketIds) {
      if (state.openTicketIds.includes(ticketId) && state.tickets.has(ticketId) && !state.closedTicketIds.has(ticketId)) {
        visibleSet.add(ticketId);
      }
    }
    const ticketIds = Array.from(visibleSet).sort((a, b) => a - b);

    for (const ticketId of allTicketIds) {
      if (visibleSet.has(ticketId)) {
        continue;
      }
      const existingBox = document.getElementById(ticketBoxId(ticketId));
      if (existingBox) {
        const panel = existingBox.querySelector(".glpichat-chatbox-panel");
        if (panel && panel.classList.contains("is-closing")) {
          continue;
        }
        existingBox.remove();
      }
      const hadOpenState = state.openTicketIds.includes(ticketId);
      state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
      if (hadOpenState) {
        dirtyUiState = true;
      }
    }

    for (const ticketId of ticketIds) {
      ensureTicketBox(ticketId);
      const ticket = state.tickets.get(ticketId);
      recalcUnread(ticket);
      const box = document.getElementById(ticketBoxId(ticketId));
      const tab = box.querySelector("[data-role='tab']");
      const isOpen = state.openTicketIds.includes(ticketId);
      const panel = box.querySelector(".glpichat-chatbox-panel");

      if (panel && panel.classList.contains("is-closing")) {
        continue;
      }

      box.classList.toggle("is-open", isOpen);
      if (panel) {
        panel.hidden = !isOpen;
      }

      const label = `Chamado #${sanitize(ticketId)}`;
      tab.innerHTML = `<span>${label}</span>${ticket.unreadTotal > 0 ? `<em>${sanitize(ticket.unreadTotal)}</em>` : ""}`;
      if (isOpen) {
        renderTicketTabs(ticketId);
      }
    }

    let totalUnread = 0;
    for (const ticket of state.tickets.values()) {
      recalcUnread(ticket);
      totalUnread += ticket.unreadTotal;
    }
    const launcherBadge = ui.launcher.querySelector("[data-role='launcher-badge']");
    launcherBadge.textContent = totalUnread > 0 ? String(totalUnread) : "";
    launcherBadge.style.display = totalUnread > 0 ? "inline-flex" : "none";
    if (dirtyUiState) {
      saveUiState(state);
    }
    renderLauncherNotifications();
  };

  const renderLauncherNotifications = () => {
    if (!state.launcherOpen) {
      return;
    }
    const statusFilters = `
      <div class="glpichat-status-filters">
        <button type="button" class="glpichat-status-filter ${state.statusFilter === "new" ? "is-active" : ""}" data-filter="new">Novo</button>
        <button type="button" class="glpichat-status-filter ${state.statusFilter === "processing" ? "is-active" : ""}" data-filter="processing">Processando</button>
        <button type="button" class="glpichat-status-filter ${state.statusFilter === "pending" ? "is-active" : ""}" data-filter="pending">Pendente</button>
      </div>
    `;

    const allTicketsByStatus = {
      new: Array.from(state.tickets.values()).filter((ticket) => statusKeyFromCode(ticket.status) === "new"),
      processing: Array.from(state.tickets.values()).filter((ticket) => statusKeyFromCode(ticket.status) === "processing"),
      pending: Array.from(state.tickets.values()).filter((ticket) => statusKeyFromCode(ticket.status) === "pending"),
    };
    if (allTicketsByStatus[state.statusFilter].length === 0) {
      if (allTicketsByStatus.processing.length > 0) {
        state.statusFilter = "processing";
      } else if (allTicketsByStatus.new.length > 0) {
        state.statusFilter = "new";
      } else if (allTicketsByStatus.pending.length > 0) {
        state.statusFilter = "pending";
      }
    }

    const filteredTickets = Array.from(state.tickets.values())
      .filter((ticket) => statusKeyFromCode(ticket.status) === state.statusFilter)
      .sort((a, b) => b.id - a.id);

    const ticketRows = filteredTickets.map((ticket) => {
      const badges = [];
      if (ticket.channels.public) {
        const publicUnread = Number(state.channels.get(ticket.channels.public)?.unread_count || 0);
        badges.push(`<i class="glpichat-notification-badge glpichat-notification-badge-public" title="Solicitante">${sanitize(publicUnread)}</i>`);
      }
      if (ticket.channels.internal) {
        const internalUnread = Number(state.channels.get(ticket.channels.internal)?.unread_count || 0);
        badges.push(`<i class="glpichat-notification-badge glpichat-notification-badge-internal" title="Discussão técnica">${sanitize(internalUnread)}</i>`);
      }
      const badgesHtml = badges.length > 0
        ? `<span class="glpichat-notification-badges">${badges.join("")}</span>`
        : "";
      return `<button type="button" class="glpichat-ticket-filter-item" data-ticket-id="${sanitize(ticket.id)}">
        <span class="glpichat-notification-label">Chamado #${sanitize(ticket.id)} <small>${statusLabel(statusKeyFromCode(ticket.status))}</small></span>
        ${badgesHtml}
      </button>`;
    }).join("");

    const ticketList = ticketRows !== ""
      ? `<div class="glpichat-ticket-filter-list">${ticketRows}</div>`
      : `<div class="glpichat-notification-empty">Nenhum chamado em ${statusLabel(state.statusFilter).toLowerCase()}.</div>`;

    ui.launcherNotifications.innerHTML = `${statusFilters}${ticketList}`;
    ui.launcherNotifications.querySelectorAll(".glpichat-status-filter").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.statusFilter = String(btn.getAttribute("data-filter") || "new");
        renderLauncherNotifications();
      });
    });
    ui.launcherNotifications.querySelectorAll(".glpichat-ticket-filter-item").forEach((btn) => {
      btn.addEventListener("click", () => {
        const ticketId = Number(btn.getAttribute("data-ticket-id") || "0");
        if (ticketId > 0) {
          openTicket(ticketId, "manual");
        }
      });
    });
  };

  const clearInviteRefreshTimer = () => {
    if (state.inviteRefreshTimer !== null) {
      window.clearTimeout(state.inviteRefreshTimer);
      state.inviteRefreshTimer = null;
    }
  };

  const copyInviteLink = (copyBtn, inviteUrl) => {
    navigator.clipboard.writeText(inviteUrl).then(() => {
      copyBtn.textContent = "Copiado!";
      copyBtn.classList.add("copied");
      window.setTimeout(() => {
        copyBtn.textContent = "Copiar";
        copyBtn.classList.remove("copied");
      }, 2000);
    }).catch((err) => {
      console.error("Falha ao copiar:", err);
      alert("Por favor, copie o link manualmente.");
    });
  };

  const renderInviteSuccessBanner = (inviteUrl, title) => {
    const successBanner = document.createElement("div");
    successBanner.className = "glpichat-invite-success";
    successBanner.innerHTML = `
      <div class="glpichat-invite-success-text">${sanitize(title)}</div>
      <div class="glpichat-invite-success-row">
        <input type="text" readonly value="${sanitize(inviteUrl)}">
        <button type="button" class="glpichat-copy-btn">Copiar</button>
      </div>
    `;
    const copyBtn = successBanner.querySelector(".glpichat-copy-btn");
    copyBtn.addEventListener("click", () => copyInviteLink(copyBtn, inviteUrl));
    return successBanner;
  };

  const inviteStatusLabel = (status) => {
    if (status === "accepted") {
      return "Aceito";
    }
    if (status === "revoked") {
      return "Revogado";
    }
    if (status === "expired") {
      return "Expirado";
    }
    if (status === "active") {
      return "Ativo";
    }
    return "Nao iniciado";
  };

  const sessionStatusLabel = (status) => {
    if (status === "active") {
      return "Ativa";
    }
    if (status === "ended") {
      return "Encerrada";
    }
    return "Sem sessão";
  };

  const scheduleInviteRefresh = (ticketsId, targetContainer) => {
    clearInviteRefreshTimer();
    state.inviteRefreshTimer = window.setTimeout(async () => {
      const currentContainer = targetContainer || ui.launcherInvites;
      if (!currentContainer || ui.launcherInvites !== currentContainer) {
        clearInviteRefreshTimer();
        return;
      }

      const inviteForm = currentContainer.querySelector(".glpichat-invite");
      const focusedElement = document.activeElement;
      const isEditingForm = focusedElement instanceof HTMLElement
        && inviteForm instanceof HTMLElement
        && inviteForm.contains(focusedElement);

      if (!isEditingForm) {
        try {
          await refreshInvites(ticketsId, null, null, currentContainer);
        } catch (error) {
          console.error("Falha ao atualizar gestão de invites:", error);
        }
      }

      if (ui.launcherInvites === currentContainer) {
        scheduleInviteRefresh(ticketsId, currentContainer);
      }
    }, INVITE_REFRESH_MS);
  };

  const refreshInvites = async (ticketsId, lastCreatedUrl = null, reissuedInviteId = null, targetContainer = ui.launcherInvites) => {
    if (!targetContainer) {
      return;
    }
    clearInviteRefreshTimer();

    const canCreate = targetContainer.dataset.canCreate === "1";
    const canUpdate = targetContainer.dataset.canUpdate === "1";

    let isInitialized = targetContainer.querySelector(".glpichat-invite-head") !== null;
    let resultDiv = targetContainer.querySelector("[data-role='result']");

    if (!isInitialized) {
      targetContainer.innerHTML = "";

      const head = document.createElement("div");
      head.className = "glpichat-invite-head";
      head.innerHTML = `<strong>Convites para Chamado #${ticketsId}</strong>`;

      targetContainer.appendChild(head);

    if (canCreate) {
      const form = document.createElement("div");
      form.className = "glpichat-invite";
      form.innerHTML = `
        <div class="glpichat-invite-field">
          <input type="text" name="guest_name" placeholder="Nome do convidado" required>
          <span class="glpichat-field-error" data-error-for="guest_name"></span>
        </div>
        <div class="glpichat-invite-field">
          <input type="email" name="guest_email" placeholder="Email do convidado (opcional)">
          <span class="glpichat-field-error" data-error-for="guest_email"></span>
        </div>
        <button type="button" data-role="submit-btn">Criar invite</button>
        <div data-role="result"></div>
      `;

      const nameInput = form.querySelector("[name='guest_name']");
      const emailInput = form.querySelector("[name='guest_email']");
      const nameError = form.querySelector("[data-error-for='guest_name']");
      const emailError = form.querySelector("[data-error-for='guest_email']");
      resultDiv = form.querySelector("[data-role='result']");

      const checkName = () => {
        if (nameInput.value.trim() === "") {
          nameError.textContent = "Por favor, informe o nome do convidado.";
          nameInput.classList.add("is-invalid");
          return false;
        }

        nameError.textContent = "";
        nameInput.classList.remove("is-invalid");
        return true;
      };

      const checkEmail = () => {
        const email = emailInput.value.trim();
        if (email === "") {
          emailError.textContent = "";
          emailInput.classList.remove("is-invalid");
          return true;
        }

        // Limit maximum email length to prevent ReDoS/excessive backtracking
        if (email.length > 254) {
          emailError.textContent = "Por favor, insira um e-mail válido (ex: nome@dominio.com).";
          emailInput.classList.add("is-invalid");
          return false;
        }

        const parts = email.split("@");
        if (parts.length !== 2) {
          emailError.textContent = "Por favor, insira um e-mail válido (ex: nome@dominio.com).";
          emailInput.classList.add("is-invalid");
          return false;
        }

        const [local, domain] = parts;
        if (local.length === 0 || domain.length === 0) {
          emailError.textContent = "Por favor, insira um e-mail válido (ex: nome@dominio.com).";
          emailInput.classList.add("is-invalid");
          return false;
        }

        // Ensure no whitespace is present in local or domain parts
        if (/\s/.test(local) || /\s/.test(domain)) {
          emailError.textContent = "Por favor, insira um e-mail válido (ex: nome@dominio.com).";
          emailInput.classList.add("is-invalid");
          return false;
        }

        // Ensure domain part has at least one dot, and it's not the first or last character
        const firstDot = domain.indexOf(".");
        const lastDot = domain.lastIndexOf(".");
        if (firstDot <= 0 || lastDot === domain.length - 1) {
          emailError.textContent = "Por favor, insira um e-mail válido (ex: nome@dominio.com).";
          emailInput.classList.add("is-invalid");
          return false;
        }

        emailError.textContent = "";
        emailInput.classList.remove("is-invalid");
        return true;
      };

      const submitInvite = async () => {
        const isNameValid = checkName();
        const isEmailValid = checkEmail();
        if (!isNameValid || !isEmailValid) {
          return;
        }

        const submitBtn = form.querySelector("[data-role='submit-btn']");
        submitBtn.disabled = true;
        submitBtn.textContent = "Criando...";

        try {
          const fd = new FormData();
          fd.set("guest_name", nameInput.value.trim());
          fd.set("guest_email", emailInput.value.trim());
          fd.set("tickets_id", String(ticketsId));
          fd.set("_glpi_csrf_token", state.csrf);
          const created = await postForm(`${pluginBase}/Invite`, fd);
          await refreshInvites(ticketsId, created.url, null, targetContainer);
        } catch (err) {
          alert("Erro ao criar convite: " + err.message);
          submitBtn.disabled = false;
          submitBtn.textContent = "Criar invite";
        }
      };

      nameInput.addEventListener("input", checkName);
      nameInput.addEventListener("blur", checkName);
      emailInput.addEventListener("input", checkEmail);
      emailInput.addEventListener("blur", checkEmail);
      form.querySelector("[data-role='submit-btn']").addEventListener("click", submitInvite);

      const handleKeyPress = (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          submitInvite();
        }
      };
      nameInput.addEventListener("keydown", handleKeyPress);
      emailInput.addEventListener("keydown", handleKeyPress);

      targetContainer.appendChild(form);
      resultDiv = form.querySelector("[data-role='result']");
    }
    }

    if (resultDiv instanceof HTMLElement && lastCreatedUrl && !reissuedInviteId) {
      resultDiv.innerHTML = "";
      resultDiv.appendChild(renderInviteSuccessBanner(lastCreatedUrl, "Convite criado com sucesso!"));
    }

    const listData = await fetchJson(`${pluginBase}/Invite/List?tickets_id=${ticketsId}`, { credentials: "same-origin" });
    
    let listContainer = targetContainer.querySelector(".glpichat-invite-list");
    if (!listContainer) {
      listContainer = document.createElement("div");
      listContainer.className = "glpichat-invite-list";
      targetContainer.appendChild(listContainer);
    } else {
      listContainer.innerHTML = "";
    }

    for (const invite of listData.invites || []) {
      const card = document.createElement("div");
      card.className = "glpichat-invite-card";

      const initials = String(invite.guest_name || "G").trim().substring(0, 2).toUpperCase();
      const inviteStatus = normalizedInviteText(invite.invite_status || invite.status);
      const inviteState = deriveInviteState(invite);

      card.innerHTML = `
        <div class="glpichat-invite-avatar">${sanitize(initials)}</div>
        <div class="glpichat-invite-info">
          <span class="glpichat-invite-name" title="${sanitize(invite.guest_name || "")}">${sanitize(invite.guest_name || "")}</span>
          <span class="glpichat-invite-email" title="${sanitize(invite.guest_email || "Sem e-mail")}">${sanitize(invite.guest_email || "Sem e-mail")}</span>
        </div>
        <div class="glpichat-invite-meta">
          <div class="glpichat-invite-statuses">
            <span class="glpichat-badge ${inviteState.inviteMeta.badgeClass}">${sanitize(inviteState.inviteMeta.label)}</span>
            <span class="glpichat-badge ${inviteState.sessionMeta.badgeClass}">${sanitize(inviteState.sessionMeta.label)}</span>
          </div>
          <span class="glpichat-invite-status-note">${sanitize(inviteState.sessionMeta.note)}</span>
          <span class="glpichat-invite-access">Acessou: ${inviteState.accessLabel}</span>
        </div>
        <div class="glpichat-invite-actions"></div>
      `;

      const actionsDiv = card.querySelector(".glpichat-invite-actions");

      // Check if this invite was just reissued
      if (Number(invite.id) === Number(reissuedInviteId) && lastCreatedUrl) {
        const reissueSuccess = document.createElement("div");
        reissueSuccess.className = "glpichat-invite-success";
        reissueSuccess.innerHTML = `
          <div class="glpichat-invite-success-text">Novo link gerado!</div>
          <div class="glpichat-invite-success-row">
            <input type="text" readonly value="${sanitize(lastCreatedUrl)}">
            <button type="button" class="glpichat-copy-btn">Copiar</button>
          </div>
        `;
        const copyBtn = reissueSuccess.querySelector(".glpichat-copy-btn");
        copyBtn.addEventListener("click", () => {
          navigator.clipboard.writeText(lastCreatedUrl).then(() => {
            copyBtn.textContent = "Copiado!";
            copyBtn.classList.add("copied");
            setTimeout(() => {
              copyBtn.textContent = "Copiar";
              copyBtn.classList.remove("copied");
            }, 2000);
          }).catch((err) => {
            console.error("Falha ao copiar:", err);
            alert("Por favor, copie o link manualmente.");
          });
        });
        card.appendChild(reissueSuccess);
      }

      if (inviteState.canReissue) {
        const reissueBtn = document.createElement("button");
        reissueBtn.type = "button";
        reissueBtn.textContent = "Reemitir";
        reissueBtn.addEventListener("click", async () => {
          const fd = new FormData();
          fd.set("invite_id", String(invite.id));
          fd.set("_glpi_csrf_token", state.csrf);
          try {
            const result = await postForm(`${pluginBase}/Invite/Reissue`, fd);
            await refreshInvites(ticketsId, result.url, invite.id, targetContainer);
          } catch (err) {
            alert("Erro ao reemitir convite: " + err.message);
          }
        });
        actionsDiv.appendChild(reissueBtn);
      }

      if (inviteState.canRevoke) {
        const revokeBtn = document.createElement("button");
        revokeBtn.type = "button";
        revokeBtn.className = "action-revoke";
        revokeBtn.textContent = "Cancelar Convite";
        revokeBtn.addEventListener("click", async () => {
          if (!confirm("Deseja cancelar o acesso deste convidado?")) {
            return;
          }
          const fd = new FormData();
          fd.set("invite_id", String(invite.id));
          fd.set("_glpi_csrf_token", state.csrf);
          try {
            await postForm(`${pluginBase}/Invite/Revoke`, fd);
            await refreshInvites(ticketsId, null, null, targetContainer);
          } catch (err) {
            alert("Erro ao cancelar convite: " + err.message);
          }
        });
        actionsDiv.appendChild(revokeBtn);
      }

      if (inviteState.canDisconnect) {
        const disconnectBtn = document.createElement("button");
        disconnectBtn.type = "button";
        disconnectBtn.className = "action-revoke";
        disconnectBtn.textContent = "Encerrar Chat";
        disconnectBtn.addEventListener("click", async () => {
          if (!confirm("Deseja encerrar a sessao atual deste convidado?")) {
            return;
          }
          const fd = new FormData();
          fd.set("invite_id", String(invite.id));
          fd.set("_glpi_csrf_token", state.csrf);
          try {
            await postForm(`${pluginBase}/Invite/Revoke`, fd);
            await refreshInvites(ticketsId, null, null, targetContainer);
          } catch (err) {
            alert("Erro ao encerrar chat: " + err.message);
          }
        });
        actionsDiv.appendChild(disconnectBtn);
      }

      listContainer.appendChild(card);
    }
  };

  const mountInviteManagement = async (container) => {
    const ticketsId = Number(container.dataset.ticketId || "0");
    const csrf = String(container.dataset.csrf || "");
    if (ticketsId <= 0) {
      return;
    }
    if (csrf) {
      state.csrf = csrf;
    }
    if (typeof container._glpiChatInviteTimer === "number") {
      window.clearInterval(container._glpiChatInviteTimer);
    }
    ui.launcherInvites = container;
    await refreshInvites(ticketsId, null, null, container);

    let isVisible = true;
    if ("IntersectionObserver" in window) {
      isVisible = false;
      const observer = new IntersectionObserver((entries) => {
        isVisible = entries[0].isIntersecting;
        if (isVisible) {
          refreshInvites(ticketsId, null, null, container).catch(console.error);
        }
      }, { root: null, threshold: 0 });
      observer.observe(container);
    }

    container._glpiChatInviteTimer = window.setInterval(() => {
      if (!document.body.contains(container)) {
        window.clearInterval(container._glpiChatInviteTimer);
        delete container._glpiChatInviteTimer;
        return;
      }
      if (document.hidden || !isVisible) {
        return;
      }
      const tabPane = container.closest(".tab-pane");
      if (tabPane && !tabPane.classList.contains("active")) {
        return;
      }
      if (!isVisibleElement(container)) {
        return;
      }
      refreshInvites(ticketsId, null, null, container).catch(console.error);
    }, 10000);
  };

  const markRead = async (roomsId, lastReadMessageId) => {
    const fd = new FormData();
    fd.set("rooms_id", String(roomsId));
    fd.set("last_read_message_id", String(lastReadMessageId));
    fd.set("_glpi_csrf_token", state.csrf);
    const data = await postForm(`${pluginBase}/Widget/MarkRead`, fd);
    for (const channel of state.channels.values()) {
      if (Number(channel.rooms_id || 0) !== Number(roomsId || 0)) {
        continue;
      }
      applyChannelUnread(channel, data);
      channel.last_read_message_id = Number(data.last_read_message_id || channel.last_read_message_id || 0);
      const ticket = state.tickets.get(channel.tickets_id);
      if (ticket) {
        recalcUnread(ticket);
      }
      renderDock();
      break;
    }
    return data;
  };

  const mergeChannelMessages = (channel, incomingMessages) => {
    if (!Array.isArray(incomingMessages) || incomingMessages.length === 0) {
      return;
    }

    // Filter out optimistic messages if we see an incoming message from the current user
    // with the same content, or if the optimistic message is older than 15 seconds.
    const now = Date.now();
    channel.messages = (channel.messages || []).filter((msg) => {
      if (msg.is_optimistic) {
        if (now - Math.abs(msg.id) > 15000) {
          return false;
        }
        const match = incomingMessages.some(
          (m) =>
            m.actor_type === "user" &&
            Number(m.users_id || 0) === Number(state.currentUserId || 0) &&
            String(m.content || "").trim() === String(msg.content || "").trim()
        );
        if (match) {
          return false;
        }
      }
      return true;
    });

    const seen = new Set((channel.messages || []).map((item) => Number(item.id || 0)));
    const merged = Array.isArray(channel.messages) ? channel.messages.slice() : [];
    for (const item of incomingMessages) {
      const messageId = Number(item.id || 0);
      if (messageId <= 0 || seen.has(messageId)) {
        continue;
      }
      seen.add(messageId);
      merged.push(item);
    }
    merged.sort((a, b) => Number(a.id || 0) - Number(b.id || 0));
    channel.messages = merged;
  };

  const mergeChannelEvents = (channel, incomingEvents) => {
    if (!Array.isArray(incomingEvents) || incomingEvents.length === 0) {
      return;
    }
    const seen = new Set((channel.events || []).map((item) => String(item.id || "")));
    const merged = Array.isArray(channel.events) ? channel.events.slice() : [];
    for (const item of incomingEvents) {
      const eventId = String(item.id || "");
      if (eventId === "" || seen.has(eventId)) {
        continue;
      }
      seen.add(eventId);
      merged.push(item);
    }
    channel.events = merged;
  };

  const reconcileConfirmedMessage = (channel, optimisticMessage, confirmedMessage) => {
    if (!channel || !confirmedMessage || Number(confirmedMessage.id || 0) <= 0) {
      return false;
    }

    const optimisticId = Number(optimisticMessage?.id || 0);
    const optimisticContent = String(optimisticMessage?.content || "").trim();
    const nextMessages = (channel.messages || []).filter((message) => {
      const messageId = Number(message.id || 0);
      if (optimisticId !== 0 && messageId === optimisticId) {
        return false;
      }

      if (message.is_optimistic !== true) {
        return true;
      }

      return String(message.content || "").trim() !== optimisticContent;
    });

    const confirmedId = Number(confirmedMessage.id || 0);
    if (!nextMessages.some((message) => Number(message.id || 0) === confirmedId)) {
      nextMessages.push(confirmedMessage);
    }

    nextMessages.sort((left, right) => Number(left.id || 0) - Number(right.id || 0));
    channel.messages = nextMessages;
    channel.last_message_id = Math.max(Number(channel.last_message_id || 0), confirmedId);
    channel.messages_loaded = true;
    channel.history_failed = false;
    return true;
  };

  const loadChannelHistory = async (channel) => {
    if (!channel || channel.messages_loaded === true || channel.history_loading === true) {
      return;
    }
    channel.history_loading = true;
    renderTicketMessages(channel.tickets_id);
    try {
      try {
        const data = await fetchJson(`${pluginBase}/Widget/History?rooms_id=${channel.rooms_id}&limit=50`, { credentials: "same-origin" });
        channel.messages = Array.isArray(data.messages) ? data.messages : [];
        channel.events = Array.isArray(data.events) ? data.events : [];
        channel.last_message_id = channel.messages.length > 0
          ? Number(channel.messages.at(-1).id || channel.last_message_id || 0)
          : Number(channel.last_message_id || 0);
        channel.last_event_id = channel.events.length > 0
          ? pickLatestEventCursor(channel.last_event_id, channel.events.at(-1).id)
          : normalizeEventCursor(channel.last_event_id);
        channel.renderedMessageIds = new Set(channel.messages.map((m) => Number(m.id || 0)));
        channel.messages_loaded = true;
        channel.history_failed = false;
        return;
      } catch (_historyError) {
        // Fallback: fetch initial timeline using poll endpoint from after_id=0.
      }

      const fd = new FormData();
      fd.set("_glpi_csrf_token", state.csrf);
      fd.set("channels[0][tickets_id]", String(channel.tickets_id));
      fd.set("channels[0][room_type]", String(channel.room_type));
      fd.set("channels[0][after_id]", "0");
      fd.set("channels[0][after_event_id]", normalizeEventCursor(channel.last_event_id));
      const pollData = await postForm(`${pluginBase}/Widget/Poll`, fd);
      const key = channelKey(channel.tickets_id, channel.room_type);
      if (!/^\d+:(public|internal)$/.test(key)) {
        channel.history_failed = true;
        return;
      }
      const patch = pollData.channels && pollData.channels[key] ? pollData.channels[key] : null;
      if (!patch || !Array.isArray(patch.messages)) {
        channel.history_failed = true;
        return;
      }

      channel.messages = patch.messages;
      channel.events = Array.isArray(patch.events) ? patch.events : [];
      channel.last_message_id = Number(
        patch.last_message_id
        || (patch.messages.length > 0 ? patch.messages.at(-1).id : channel.last_message_id || 0)
      );
      channel.last_event_id = pickLatestEventCursor(channel.last_event_id, patch.last_event_id);
      applyChannelUnread(channel, patch);
      updateTicketStatus(channel.tickets_id, patch.ticket_status || 0);
      channel.renderedMessageIds = new Set(channel.messages.map((m) => Number(m.id || 0)));
      channel.messages_loaded = true;
      channel.history_failed = false;
    } finally {
      channel.history_loading = false;
      renderTicketMessages(channel.tickets_id);
    }
  };

  const loadOlderMessages = async (ticketId, channel) => {
    if (!channel || !Array.isArray(channel.messages) || channel.messages.length === 0 || channel.older_loading === true) {
      return;
    }
    const oldestId = Number(channel.messages[0].id || 0);
    if (oldestId <= 0) {
      return;
    }
    channel.older_loading = true;
    try {
      const data = await fetchJson(`${pluginBase}/Widget/History?rooms_id=${channel.rooms_id}&before_id=${oldestId}&limit=50`, { credentials: "same-origin" });
      const olderMessages = Array.isArray(data.messages) ? data.messages : [];
      if (olderMessages.length === 0) {
        return;
      }
      channel.messages = [...olderMessages, ...channel.messages];
      renderTicketMessages(ticketId, { preservePosition: true });
    } finally {
      channel.older_loading = false;
    }
  };

  const syncChannelMetadata = async () => {
    const endpoint = `${pluginBase}/Widget/Channels${state.activeTicketId > 0 ? `?tickets_id=${state.activeTicketId}` : ""}`;
    const data = await fetchJson(endpoint, { credentials: "same-origin" });
    state.currentUserId = Number(data.current_user_id || state.currentUserId || 0);
    for (const ch of data.channels || []) {
      setChannel(ch);
    }
    renderDock();
  };

  const pollChannels = async () => {
    const payload = [];
    const openTickets = new Set(state.openTicketIds);
    for (const channel of state.channels.values()) {
      if (!openTickets.has(Number(channel.tickets_id || 0))) {
        continue;
      }
      if (channel.messages_loaded !== true) {
        continue;
      }
      payload.push({
        tickets_id: channel.tickets_id,
        room_type: channel.room_type,
        after_id: channel.last_message_id || 0,
        after_event_id: normalizeEventCursor(channel.last_event_id),
        is_typing: Boolean(channel.isTyping || false),
      });
    }
    if (payload.length === 0) {
      await syncChannelMetadata();
      return;
    }

    const fd = new FormData();
    fd.set("_glpi_csrf_token", state.csrf);
    payload.forEach((item, index) => {
      fd.set(`channels[${index}][tickets_id]`, String(item.tickets_id));
      fd.set(`channels[${index}][room_type]`, item.room_type);
      fd.set(`channels[${index}][after_id]`, String(item.after_id));
      fd.set(`channels[${index}][after_event_id]`, String(item.after_event_id));
      fd.set(`channels[${index}][is_typing]`, item.is_typing ? "1" : "0");
    });

    const data = await postForm(`${pluginBase}/Widget/Poll`, fd);
    let hasDockChanges = false;
    const ticketsNeedingTabRefresh = new Set();
    const ticketsNeedingMessageRefresh = new Set();
    const ticketsNeedingTypingRefresh = new Set();
    const channelsNeedingReload = [];

    for (const key of Object.keys(data.channels || {})) {
      if (!/^\d+:(public|internal)$/.test(key)) {
        continue;
      }
      const patch = data.channels[key];
      const channel = state.channels.get(key);
      if (!channel) {
        continue;
      }

      const serverLastMessageId = Number(patch.last_message_id || 0);
      const localLastMessageId = Number(channel.last_message_id || 0);
      const hasIncomingMessages = Array.isArray(patch.messages) && patch.messages.length > 0;

      if (hasIncomingMessages) {
        mergeChannelMessages(channel, patch.messages);
        channel.last_message_id = serverLastMessageId;
        channel.messages_loaded = true;
        channel.history_failed = false;
        ticketsNeedingMessageRefresh.add(Number(channel.tickets_id || 0));
        ticketsNeedingTabRefresh.add(Number(channel.tickets_id || 0));
        hasDockChanges = true;
      } else if (serverLastMessageId > 0 && serverLastMessageId !== localLastMessageId) {
        channel.last_message_id = serverLastMessageId;
        channel.messages_loaded = false;
        channel.history_failed = false;
        channelsNeedingReload.push(channel);
        ticketsNeedingMessageRefresh.add(Number(channel.tickets_id || 0));
      }

      if (Object.prototype.hasOwnProperty.call(patch, "ticket_status")) {
        if (updateTicketStatus(channel.tickets_id, patch.ticket_status || 0)) {
          ticketsNeedingMessageRefresh.add(Number(channel.tickets_id || 0));
          ticketsNeedingTabRefresh.add(Number(channel.tickets_id || 0));
        }
      }

      if (applyChannelUnread(channel, patch)) {
        ticketsNeedingTabRefresh.add(Number(channel.tickets_id || 0));
        hasDockChanges = true;
      }

      if (Array.isArray(patch.events) && patch.events.length > 0) {
        mergeChannelEvents(channel, patch.events);
        channel.last_event_id = pickLatestEventCursor(channel.last_event_id, patch.last_event_id);
        ticketsNeedingMessageRefresh.add(Number(channel.tickets_id || 0));
        ticketsNeedingTabRefresh.add(Number(channel.tickets_id || 0));
        hasDockChanges = true;
      }

      if (Object.prototype.hasOwnProperty.call(patch, "typing_names")) {
        const prevTyping = channel.typing_names || [];
        const nextTyping = patch.typing_names || [];
        if (JSON.stringify(prevTyping) !== JSON.stringify(nextTyping)) {
          channel.typing_names = nextTyping;
          ticketsNeedingTypingRefresh.add(Number(channel.tickets_id || 0));
        }
      }
    }

    for (const ticketId of state.openTicketIds) {
      if (ticketsNeedingTabRefresh.has(ticketId)) {
        renderTicketTabs(ticketId);
      }
      if (ticketsNeedingMessageRefresh.has(ticketId)) {
        renderTicketMessages(ticketId);
      } else if (ticketsNeedingTypingRefresh.has(ticketId)) {
        renderTypingIndicator(ticketId);
      }
    }

    for (const channel of channelsNeedingReload) {
      await loadChannelHistory(channel);
      ticketsNeedingMessageRefresh.add(Number(channel.tickets_id || 0));
      ticketsNeedingTabRefresh.add(Number(channel.tickets_id || 0));
    }

    for (const ticketId of state.openTicketIds) {
      if (ticketsNeedingTabRefresh.has(ticketId)) {
        renderTicketTabs(ticketId);
      }
      if (ticketsNeedingMessageRefresh.has(ticketId)) {
        renderTicketMessages(ticketId);
      } else if (ticketsNeedingTypingRefresh.has(ticketId)) {
        renderTypingIndicator(ticketId);
      }
    }

    if (hasDockChanges) {
      renderDock();
    }
  };

  const schedulePolling = () => {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
    }
    if (state.metaTimer) {
      clearInterval(state.metaTimer);
    }
    const interval = document.visibilityState === "visible"
      ? (state.openTicketIds.length > 0 ? POLL_FOCUSED_MS : POLL_IDLE_MS)
      : POLL_BG_MS;

    state.pollTimer = window.setInterval(() => {
      pollChannels().catch(console.error);
    }, interval);
    state.metaTimer = window.setInterval(() => {
      if (document.visibilityState === "visible") {
        syncChannelMetadata().catch(console.error);
      }
    }, META_SYNC_MS);
  };



  const bootstrap = async () => {


    if (!document.body || window.location.pathname.includes("/install/")) {
      return;
    }

    updateObstructionElementsCache();
    // Refresh cache periodically in case other plugins dynamically inject elements
    window.setInterval(updateObstructionElementsCache, 8000);

    state.activeTicketId = activeTicketIdFromPage();
    loadUiState(state);
    createShell();

    const endpoint = `${pluginBase}/Widget/Channels${state.activeTicketId > 0 ? `?tickets_id=${state.activeTicketId}` : ""}`;
    const data = await fetchJson(endpoint, { credentials: "same-origin" });
    state.currentUserId = Number(data.current_user_id || 0);

    for (const ch of data.channels || []) {
      setChannel(ch);
    }

    if (state.activeTicketId > 0 && state.tickets.has(state.activeTicketId)) {
      state.closedTicketIds.delete(state.activeTicketId);
      state.transientTicketIds.add(state.activeTicketId);
      if (!state.openTicketIds.includes(state.activeTicketId)) {
        state.openTicketIds.push(state.activeTicketId);
      }
    }

    enforceOpenLimit();
    renderDock();

    for (const ticketId of state.openTicketIds.slice()) {
      const source = state.manualTicketIds.has(ticketId) ? "manual" : "auto";
      openTicket(ticketId, source);
    }

    if (state.launcherOpen) {
      openLauncher();
    } else {
      minimizeLauncher();
    }

    if (state.activeTicketId > 0) {
      refreshInvites(state.activeTicketId).catch(console.error);
    }

    schedulePolling();

    const throttledUpdateSafeBottom = throttle(updateSafeBottom, 150);

    window.addEventListener("resize", () => {
      enforceOpenLimit();
      renderDock();
      throttledUpdateSafeBottom();
    });

    window.addEventListener("scroll", throttledUpdateSafeBottom, { passive: true });
    document.addEventListener("visibilitychange", schedulePolling);
    window.setInterval(throttledUpdateSafeBottom, 2000);
  };

  const bootWidget = () => {
    bootstrap().catch(console.error);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootWidget, { once: true });
  } else {
    bootWidget();
  }

  document.addEventListener("scroll", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !target.classList.contains("glpichat-messages")) {
      return;
    }
    if (target.scrollTop > 20) {
      return;
    }
    const box = target.closest(".glpichat-chatbox");
    if (!(box instanceof HTMLElement)) {
      return;
    }
    const ticketId = Number(box.dataset.ticketId || "0");
    if (!Number.isInteger(ticketId) || ticketId <= 0) {
      return;
    }
    const ticket = state.tickets.get(ticketId);
    if (!ticket) {
      return;
    }
    const activeTab = isValidRoomType(ticket.activeTab) ? ticket.activeTab : "public";
    const activeKey = ticket.channels[activeTab];
    if (!activeKey) {
      return;
    }
    const channel = state.channels.get(activeKey);
    if (!channel) {
      return;
    }
    loadOlderMessages(ticketId, channel).catch(console.error);
  }, true);

  window.addEventListener("beforeunload", (event) => {
    if (state.pendingMutations <= 0) {
      return;
    }
    event.preventDefault();
    event.returnValue = "";
  });

  window.glpiChatMountInviteManagement = (container) => {
    mountInviteManagement(container).catch(console.error);
  };
