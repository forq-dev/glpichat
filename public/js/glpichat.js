(function () {
  "use strict";

  const pluginBase = "/plugins/glpichat";
  const POLL_FOCUSED_MS = 2500;
  const POLL_IDLE_MS = 9000;
  const POLL_BG_MS = 18000;
  const META_SYNC_MS = 15000;
  const DESKTOP_MAX_OPEN = 3;
  const MOBILE_MAX_OPEN = 1;

  const VALID_POSITIONS = ["bottom-right", "bottom-left", "top-left", "top-right"];

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

  const state = {
    csrf: "",
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

  const channelKey = (ticketsId, roomType) => `${ticketsId}:${roomType}`;
  const maxOpenChats = () => (window.innerWidth <= 980 ? MOBILE_MAX_OPEN : DESKTOP_MAX_OPEN);
  const SAFE_BOTTOM_MARGIN_PX = 8;

  const submitForm = (form) => {
    if (typeof form.requestSubmit === "function") {
      form.requestSubmit();
    } else {
      form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    }
  };

  const isVisibleElement = (element) => {
    const style = window.getComputedStyle(element);
    if (style.display === "none" || style.visibility === "hidden") {
      return false;
    }
    return Number(style.opacity || "1") > 0;
  };

  const detectBottomObstruction = () => {
    const viewportHeight = window.innerHeight || 0;
    const viewportWidth = window.innerWidth || 0;
    let maxObstruction = 0;

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

  const detectTopObstruction = () => {
    const viewportWidth = window.innerWidth || 0;
    let maxObstruction = 0;

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

  const updateSafeBottom = () => {
    const obstruction = detectBottomObstruction();
    const safeBottom = Math.max(0, obstruction) + SAFE_BOTTOM_MARGIN_PX;
    document.documentElement.style.setProperty("--glpichat-safe-bottom", `${safeBottom}px`);

    const topObstruction = detectTopObstruction();
    const safeTop = Math.max(0, topObstruction) + SAFE_BOTTOM_MARGIN_PX;
    document.documentElement.style.setProperty("--glpichat-safe-top", `${safeTop}px`);
  };

  const isLeftPosition = () => state.widgetPosition === "bottom-left" || state.widgetPosition === "top-left";

  const insertBoxInDock = (box) => {
    if (isLeftPosition()) {
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
      panel.removeEventListener("transitionend", onEnd);
      delete panel._cancelClose;
    };
    
    panel._cancelClose = cleanup;
    
    panel.addEventListener("animationend", onEnd);
    panel.addEventListener("transitionend", onEnd);
    timeoutId = setTimeout(onEnd, 200);
  };

  const storageLoad = () => {
    try {
      return JSON.parse(localStorage.getItem("glpichat_widget_ui") || "{}");
    } catch (_err) {
      return {};
    }
  };

  const storageSave = (patch) => {
    const merged = { ...storageLoad(), ...patch };
    localStorage.setItem("glpichat_widget_ui", JSON.stringify(merged));
  };

  const loadUiState = () => {
    const data = storageLoad();
    state.openTicketIds = Array.isArray(data.openTicketIds)
      ? data.openTicketIds.filter((id) => Number.isInteger(id) && id > 0)
      : [];
    state.closedTicketIds = new Set(
      Array.isArray(data.closedTicketIds)
        ? data.closedTicketIds.filter((id) => Number.isInteger(id) && id > 0)
        : []
    );
    state.manualTicketIds = new Set(
      Array.isArray(data.manualTicketIds)
        ? data.manualTicketIds.filter((id) => Number.isInteger(id) && id > 0)
        : []
    );
    state.launcherOpen = data.launcherOpen === true;
    if (typeof data.widgetPosition === "string" && VALID_POSITIONS.includes(data.widgetPosition)) {
      state.widgetPosition = data.widgetPosition;
    }
  };

  const saveUiState = () => {
    const persistentOpenTicketIds = state.openTicketIds.filter((id) => state.manualTicketIds.has(id));
    storageSave({
      openTicketIds: persistentOpenTicketIds,
      closedTicketIds: Array.from(state.closedTicketIds),
      manualTicketIds: Array.from(state.manualTicketIds),
      launcherOpen: state.launcherOpen,
      widgetPosition: state.widgetPosition,
    });
  };

  let activeRequestPromise = Promise.resolve();

  const fetchJson = async (url, options) => {
    const nextPromise = activeRequestPromise.then(async () => {
      if (options && options.body instanceof FormData) {
        if (options.body.has("_glpi_csrf_token")) {
          options.body.set("_glpi_csrf_token", state.csrf);
        }
      }
      const response = await fetch(url, options || { credentials: "same-origin" });
      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.error || "Request failed.");
      }
      if (data._glpi_csrf_token) {
        state.csrf = data._glpi_csrf_token;
      }
      return data;
    });

    activeRequestPromise = nextPromise.catch(() => {});
    return nextPromise;
  };

  const postForm = async (url, formData, requestOptions = {}) => fetchJson(url, {
    method: "POST",
    body: formData,
    credentials: "same-origin",
    ...requestOptions,
  });

  const sanitize = (text) => String(text || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");

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
    const key = channelKey(payload.tickets_id, payload.room_type);
    const prev = state.channels.get(key) || {};
    const previousMessages = Array.isArray(prev.messages) ? prev.messages : [];
    const previousLastLoadedMessageId = previousMessages.length > 0
      ? Number(previousMessages[previousMessages.length - 1].id || prev.last_message_id || 0)
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
      last_event_id: Number(prev.last_event_id || payload.last_event_id || 0),
      unread_count: Number(prev.unread_count || payload.unread_count || 0),
    };
    if (Object.prototype.hasOwnProperty.call(payload, "unread_count")) {
      merged.unread_count = Number(payload.unread_count || 0);
    }
    state.channels.set(key, merged);

    const ticket = ensureTicket(payload.tickets_id);
    ticket.channels[payload.room_type] = key;
    ticket.status = Number(payload.ticket_status || ticket.status || 0);
    if (!ticket.activeTab || !ticket.channels[ticket.activeTab]) {
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
    const performMinimize = () => {
      state.launcherOpen = false;
      if (ui.launcherPanel) ui.launcherPanel.hidden = true;
      if (ui.launcher) ui.launcher.classList.remove("is-open", "is-closing");
      saveUiState();
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
    }
    saveUiState();
    if (state.activeTicketId > 0) {
      refreshInvites(state.activeTicketId).catch(console.error);
    }
  };

  const minimizeAll = () => {
    state.minimizedSnapshot = {
      launcherOpen: state.launcherOpen,
      openTicketIds: state.openTicketIds.slice(),
    };

    const panelsToAnimate = [];
    if (!ui.launcherPanel.hidden) {
      if (ui.launcher) {
        ui.launcher.classList.add("is-closing");
      }
      panelsToAnimate.push({
        panel: ui.launcherPanel,
        cleanup: () => {
          ui.launcherPanel.hidden = true;
          if (ui.launcher) {
            ui.launcher.classList.remove("is-open", "is-closing");
          }
        }
      });
    }
    for (const ticketId of state.openTicketIds) {
      const box = document.getElementById(ticketBoxId(ticketId));
      const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
      if (panel && !panel.hidden) {
        if (box) {
          box.classList.add("is-closing");
        }
        panelsToAnimate.push({
          panel,
          cleanup: () => {
            if (box) box.remove();
          }
        });
      }
    }

    const finalize = () => {
      state.launcherOpen = false;
      state.openTicketIds = [];
      saveUiState();
      renderDock();
    };

    if (panelsToAnimate.length > 0) {
      let completed = 0;
      const onPanelCompleted = () => {
        completed++;
        if (completed === panelsToAnimate.length) {
          finalize();
        }
      };
      for (const item of panelsToAnimate) {
        animatePanelClose(item.panel, () => {
          item.cleanup();
          onPanelCompleted();
        });
      }
    } else {
      finalize();
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
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        submitForm(sendForm);
      }
    });

    // Ctrl+V paste support for clipboard files
    textarea.addEventListener("paste", (e) => {
      if (e.clipboardData && e.clipboardData.files && e.clipboardData.files.length > 0) {
        fileInput.files = e.clipboardData.files;
        submitForm(sendForm);
        e.preventDefault();
      }
    });

    // Enter to send, Shift+Enter for newline
    textarea.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        submitForm(sendForm);
      }
    });

    sendForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const ticket = state.tickets.get(ticketId);
      if (!ticket) {
        return;
      }
      const activeRoom = ticket.activeTab;
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
        renderTicketMessages(ticketId);
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
          await postForm(`${pluginBase}/Send`, fd, { keepalive: true });
        }
        if (hasFile) {
          state.manualTicketIds.add(ticketId);
          state.transientTicketIds.delete(ticketId);
          state.closedTicketIds.delete(ticketId);
          await postForm(`${pluginBase}/Upload`, fd);
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

      saveUiState();

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
  };

  const renderTicketMessages = (ticketId, options = {}) => {
    const preservePosition = options.preservePosition === true;
    const ticket = state.tickets.get(ticketId);
    const box = document.getElementById(ticketBoxId(ticketId));
    if (!ticket || !box) {
      return;
    }
    const key = ticket.channels[ticket.activeTab];
    const channel = key ? state.channels.get(key) : null;
    const empty = box.querySelector("[data-role='empty']");
    const messagesNode = box.querySelector("[data-role='messages']");
    const previousHeight = messagesNode.scrollHeight;
    const previousTop = messagesNode.scrollTop;

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
      return;
    }

    const messages = Array.isArray(channel?.messages) ? channel.messages : [];
    messagesNode.innerHTML = "";

    if (messages.length === 0) {
      empty.hidden = false;
      empty.textContent = ticket.activeTab === "internal"
        ? "Nenhuma mensagem na discussão técnica ainda."
        : "Nenhuma mensagem com o solicitante ainda.";
    } else {
      empty.hidden = true;
      if (channel && !channel.renderedMessageIds) {
        channel.renderedMessageIds = new Set();
      }
      for (const msg of messages) {
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
      const lastId = Number(messages[messages.length - 1].id || 0);
      if (channel && lastId > 0) {
        markRead(channel.rooms_id, lastId).catch(console.error);
      }
    }

    recalcUnread(ticket);
    renderDock();
    if (preservePosition) {
      const delta = messagesNode.scrollHeight - previousHeight;
      messagesNode.scrollTop = Math.max(0, previousTop + delta);
      return;
    }
    messagesNode.scrollTop = messagesNode.scrollHeight;
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
    const activeKey = ticket ? ticket.channels[ticket.activeTab] : null;
    if (activeKey) {
      try {
        await loadChannelHistory(state.channels.get(activeKey));
      } catch (_error) {
        // keep channel as not loaded to allow a retry path on next poll/open.
      }
    }
    renderTicketTabs(ticketId);
    renderTicketMessages(ticketId);
    saveUiState();
    schedulePolling();
  };

  const minimizeTicket = (ticketId) => {
    const box = document.getElementById(ticketBoxId(ticketId));
    const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
    if (box) {
      box.classList.add("is-closing");
    }
    const performMinimize = () => {
      if (box) {
        box.classList.remove("is-open", "is-closing");
        if (panel) {
          panel.hidden = true;
          panel.classList.remove("is-closing");
        }
      }
      state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
      saveUiState();
      renderDock();
    };

    animatePanelClose(panel, performMinimize);
  };

  const closeTicket = (ticketId) => {
    const box = document.getElementById(ticketBoxId(ticketId));
    const panel = box ? box.querySelector(".glpichat-chatbox-panel") : null;
    if (box) {
      box.classList.add("is-closing");
    }
    const performClose = () => {
      if (box) box.remove();
      state.closedTicketIds.add(ticketId);
      state.manualTicketIds.delete(ticketId);
      state.transientTicketIds.delete(ticketId);
      state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
      saveUiState();
      renderDock();
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

      const label = `Chamado #${ticketId}`;
      tab.innerHTML = `<span>${label}</span>${ticket.unreadTotal > 0 ? `<em>${ticket.unreadTotal}</em>` : ""}`;
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
      saveUiState();
    }
    renderLauncherNotifications();
  };

  const renderLauncherNotifications = () => {
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
      const publicUnread = Number(state.channels.get(ticket.channels.public || "")?.unread_count || 0);
      const internalUnread = Number(state.channels.get(ticket.channels.internal || "")?.unread_count || 0);
      return `<button type="button" class="glpichat-ticket-filter-item" data-ticket-id="${ticket.id}">
        <span class="glpichat-notification-label">Chamado #${ticket.id} <small>${statusLabel(statusKeyFromCode(ticket.status))}</small></span>
        <span class="glpichat-notification-badges">
          <i class="glpichat-notification-badge glpichat-notification-badge-public" title="Solicitante">${publicUnread}</i>
          <i class="glpichat-notification-badge glpichat-notification-badge-internal" title="Discussão técnica">${internalUnread}</i>
        </span>
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

  const refreshInvites = async (ticketsId, lastCreatedUrl = null, reissuedInviteId = null) => {
    if (!ui.launcherInvites) {
      return;
    }
    ui.launcherInvites.innerHTML = "";

    const head = document.createElement("div");
    head.className = "glpichat-invite-head";
    head.innerHTML = `<strong>Convites para Chamado #${ticketsId}</strong>`;

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

    const checkName = () => {
      if (nameInput.value.trim() === "") {
        nameError.textContent = "Por favor, informe o nome do convidado.";
        nameInput.classList.add("is-invalid");
        return false;
      } else {
        nameError.textContent = "";
        nameInput.classList.remove("is-invalid");
        return true;
      }
    };

    const checkEmail = () => {
      const email = emailInput.value.trim();
      if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        emailError.textContent = "Por favor, insira um e-mail válido (ex: nome@dominio.com).";
        emailInput.classList.add("is-invalid");
        return false;
      } else {
        emailError.textContent = "";
        emailInput.classList.remove("is-invalid");
        return true;
      }
    };

    nameInput.addEventListener("input", checkName);
    nameInput.addEventListener("blur", checkName);
    emailInput.addEventListener("input", checkEmail);
    emailInput.addEventListener("blur", checkEmail);

    const submitInvite = async () => {
      const isNameValid = checkName();
      const isEmailValid = checkEmail();
      if (!isNameValid || !isEmailValid) {
        return;
      }
      const submitBtn = form.querySelector("[data-role='submit-btn']");
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Criando...";
      }
      try {
        const fd = new FormData();
        fd.set("guest_name", nameInput.value.trim());
        fd.set("guest_email", emailInput.value.trim());
        fd.set("tickets_id", String(ticketsId));
        fd.set("_glpi_csrf_token", state.csrf);
        const created = await postForm(`${pluginBase}/Invite`, fd);
        await refreshInvites(ticketsId, created.url, null);
      } catch (err) {
        alert("Erro ao criar convite: " + err.message);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = "Criar invite";
        }
      }
    };

    form.querySelector("[data-role='submit-btn']").addEventListener("click", submitInvite);

    const handleKeyPress = (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        submitInvite();
      }
    };
    nameInput.addEventListener("keydown", handleKeyPress);
    emailInput.addEventListener("keydown", handleKeyPress);

    ui.launcherInvites.append(head, form);

    if (lastCreatedUrl && !reissuedInviteId) {
      const resultDiv = form.querySelector("[data-role='result']");
      const successBanner = document.createElement("div");
      successBanner.className = "glpichat-invite-success";
      successBanner.innerHTML = `
        <div class="glpichat-invite-success-text">Convite criado com sucesso!</div>
        <div class="glpichat-invite-success-row">
          <input type="text" readonly value="${sanitize(lastCreatedUrl)}">
          <button type="button" class="glpichat-copy-btn">Copiar</button>
        </div>
      `;
      const copyBtn = successBanner.querySelector(".glpichat-copy-btn");
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
      resultDiv.appendChild(successBanner);
    }

    const listData = await fetchJson(`${pluginBase}/Invite/List?tickets_id=${ticketsId}`, { credentials: "same-origin" });
    const listContainer = document.createElement("div");
    listContainer.className = "glpichat-invite-list";

    for (const invite of listData.invites || []) {
      const card = document.createElement("div");
      card.className = "glpichat-invite-card";

      const initials = String(invite.guest_name || "G").trim().substring(0, 2).toUpperCase();
      const statusClass = String(invite.status).toLowerCase();
      const isExpired = statusClass === "expired";
      const isRevoked = statusClass === "revoked";
      const isActive = statusClass === "active";
      const isAccepted = statusClass === "accepted";

      let statusLabelStr = invite.status || "";
      let badgeClass = "glpichat-badge-active";
      if (isExpired) {
        statusLabelStr = "Expirado";
        badgeClass = "glpichat-badge-expired";
      } else if (isRevoked) {
        statusLabelStr = "Revogado";
        badgeClass = "glpichat-badge-revoked";
      } else if (isActive) {
        statusLabelStr = "Ativo";
        badgeClass = "glpichat-badge-active";
      } else if (isAccepted) {
        statusLabelStr = "Aceito";
        badgeClass = "glpichat-badge-accepted";
      }

      card.innerHTML = `
        <div class="glpichat-invite-avatar">${sanitize(initials)}</div>
        <div class="glpichat-invite-info">
          <span class="glpichat-invite-name" title="${sanitize(invite.guest_name || "")}">${sanitize(invite.guest_name || "")}</span>
          <span class="glpichat-invite-email" title="${sanitize(invite.guest_email || "Sem e-mail")}">${sanitize(invite.guest_email || "Sem e-mail")}</span>
        </div>
        <div class="glpichat-invite-meta">
          <span class="glpichat-badge ${badgeClass}">${statusLabelStr}</span>
          <span class="glpichat-invite-access">Acessou: ${invite.was_accessed ? "Sim" : "Não"}</span>
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

      if (isActive) {
        const reissueBtn = document.createElement("button");
        reissueBtn.type = "button";
        reissueBtn.textContent = "Reemitir";
        reissueBtn.addEventListener("click", async () => {
          const fd = new FormData();
          fd.set("invite_id", String(invite.id));
          fd.set("_glpi_csrf_token", state.csrf);
          try {
            const result = await postForm(`${pluginBase}/Invite/Reissue`, fd);
            await refreshInvites(ticketsId, result.url, invite.id);
          } catch (err) {
            alert("Erro ao reemitir convite: " + err.message);
          }
        });

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
            await refreshInvites(ticketsId);
          } catch (err) {
            alert("Erro ao cancelar convite: " + err.message);
          }
        });

        actionsDiv.append(reissueBtn, revokeBtn);
      } else if (isExpired || isRevoked) {
        const reissueBtn = document.createElement("button");
        reissueBtn.type = "button";
        reissueBtn.textContent = "Reemitir";
        reissueBtn.addEventListener("click", async () => {
          const fd = new FormData();
          fd.set("invite_id", String(invite.id));
          fd.set("_glpi_csrf_token", state.csrf);
          try {
            const result = await postForm(`${pluginBase}/Invite/Reissue`, fd);
            await refreshInvites(ticketsId, result.url, invite.id);
          } catch (err) {
            alert("Erro ao reemitir convite: " + err.message);
          }
        });
        actionsDiv.appendChild(reissueBtn);
      } else if (isAccepted) {
        // No action buttons for accepted invites
      }

      listContainer.appendChild(card);
    }

    ui.launcherInvites.appendChild(listContainer);
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
    ui.launcherInvites = container;
    await refreshInvites(ticketsId);
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
    const seen = new Set((channel.messages || []).map((item) => Number(item.id || 0)));
    
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
        channel.last_message_id = channel.messages.length > 0
          ? Number(channel.messages[channel.messages.length - 1].id || channel.last_message_id || 0)
          : Number(channel.last_message_id || 0);
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
      fd.set("channels[0][after_event_id]", String(channel.last_event_id || 0));
      const pollData = await postForm(`${pluginBase}/Widget/Poll`, fd);
      const key = channelKey(channel.tickets_id, channel.room_type);
      const patch = pollData.channels && pollData.channels[key] ? pollData.channels[key] : null;
      if (!patch || !Array.isArray(patch.messages)) {
        channel.history_failed = true;
        return;
      }

      channel.messages = patch.messages;
      channel.last_message_id = Number(
        patch.last_message_id
        || (patch.messages.length > 0 ? patch.messages[patch.messages.length - 1].id : channel.last_message_id || 0)
      );
      channel.last_event_id = Number(patch.last_event_id || channel.last_event_id || 0);
      applyChannelUnread(channel, patch);
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
      const hasLoadedHistory = channel.messages_loaded === true;
      payload.push({
        tickets_id: channel.tickets_id,
        room_type: channel.room_type,
        after_id: hasLoadedHistory ? (channel.last_message_id || 0) : 0,
        after_event_id: channel.last_event_id || 0,
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
    });

    const data = await postForm(`${pluginBase}/Widget/Poll`, fd);
    let hasDockChanges = false;
    const changedTicketIds = new Set();

    for (const key of Object.keys(data.channels || {})) {
      const patch = data.channels[key];
      const channel = state.channels.get(key);
      if (!channel) {
        continue;
      }

      let channelChanged = false;

      if (Array.isArray(patch.messages) && patch.messages.length > 0) {
        mergeChannelMessages(channel, patch.messages);
        channel.last_message_id = Number(patch.last_message_id || channel.last_message_id || 0);
        channel.messages_loaded = true;
        channel.history_failed = false;
        channelChanged = true;
        hasDockChanges = true;
      }

      if (applyChannelUnread(channel, patch)) {
        channelChanged = true;
        hasDockChanges = true;
      }

      if (Array.isArray(patch.events) && patch.events.length > 0) {
        channel.events.push(...patch.events);
        channel.last_event_id = Number(patch.last_event_id || channel.last_event_id || 0);
        channelChanged = true;
        hasDockChanges = true;
      }

      if (channelChanged) {
        changedTicketIds.add(Number(channel.tickets_id || 0));
      }
    }

    for (const ticketId of state.openTicketIds) {
      if (changedTicketIds.has(ticketId)) {
        renderTicketTabs(ticketId);
        renderTicketMessages(ticketId);
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

  const bootstrapGuest = async (guestRoot) => {
    let lastMessageId = 0;
    let lastEventId = 0;
    let initialSyncDone = false;
    const messages = guestRoot.querySelector("[data-role='messages']");
    const form = guestRoot.querySelector("[data-role='guest-send-form']");

    const addMessage = (message, skipAnimation = false) => {
      // Determine if message is from the guest
      const own = message.actor_type === "guest";
      const item = document.createElement("div");
      const isNew = !skipAnimation && !message.is_optimistic;
      item.className = `glpichat-message ${own ? "is-own" : "is-other"}${isNew ? " is-new" : ""}${message.is_optimistic ? " is-optimistic" : ""}`;
      
      let bodyHtml = "";
      if (Number(message.documents_id || 0) > 0) {
        const contentStr = String(message.content || "");
        const filename = contentStr.includes(":") 
          ? contentStr.split(":").slice(1).join(":").trim() 
          : contentStr;
        
        const isImg = /\.(apng|avif|gif|jpg|jpeg|jfif|pjpeg|pjpg|png|svg|webp)$/i.test(filename);
        if (isImg) {
          bodyHtml = `
            <div class="glpichat-attachment-title">${sanitize(contentStr)}</div>
            <div class="glpichat-attachment-preview">
              <img src="/front/document.send.php?docid=${message.documents_id}" alt="${sanitize(filename)}" class="glpichat-zoomable-img" />
            </div>
          `;
        } else {
          bodyHtml = `
            <a class="glpichat-attachment-link" href="/front/document.send.php?docid=${message.documents_id}" target="_blank" download="${sanitize(filename)}">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:4px;"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              ${sanitize(filename)}
            </a>
          `;
        }
      } else {
        bodyHtml = sanitize(message.content);
      }

      item.innerHTML = `
        <div class="glpichat-message-head">${sanitize(message.sender_name || (own ? "Você" : "Suporte"))}</div>
        <div class="glpichat-message-body">${bodyHtml}</div>
        <div class="glpichat-message-time">${sanitize(message.date_creation || "")}</div>
      `;
      messages.appendChild(item);
    };

    const syncGuest = async () => {
      const params = new URLSearchParams({
        last_message_id: String(lastMessageId),
        last_event_id: String(lastEventId),
      });
      const data = await fetchJson(`${pluginBase}/Guest/Sync?${params.toString()}`);
      
      // Clean up temporary messages if we are syncing new ones
      if (Array.isArray(data.messages) && data.messages.length > 0) {
        const tempItems = messages.querySelectorAll(".glpichat-message.is-optimistic");
        tempItems.forEach((el) => el.remove());
      }
      
      for (const message of data.messages || []) {
        addMessage(message, !initialSyncDone);
        lastMessageId = Math.max(lastMessageId, Number(message.id || 0));
      }
      for (const event of data.events || []) {
        lastEventId = Math.max(lastEventId, Number(event.id || 0));
      }
      messages.scrollTop = messages.scrollHeight;
      initialSyncDone = true;
    };

    // Add Lightbox to guest view
    const lightbox = document.createElement("div");
    lightbox.className = "glpichat-lightbox";
    lightbox.innerHTML = `
      <button type="button" class="glpichat-lightbox-close">×</button>
      <img class="glpichat-lightbox-img" src="" alt="Preview">
    `;
    guestRoot.appendChild(lightbox);
    const lightboxImg = lightbox.querySelector(".glpichat-lightbox-img");
    const closeBtn = lightbox.querySelector(".glpichat-lightbox-close");
    
    const closeLightbox = () => {
      lightbox.classList.remove("is-open");
    };
    closeBtn.addEventListener("click", closeLightbox);
    lightbox.addEventListener("click", (e) => {
      if (e.target === lightbox) closeLightbox();
    });

    guestRoot.addEventListener("click", (event) => {
      const zoomImg = event.target.closest(".glpichat-zoomable-img");
      if (zoomImg) {
        lightboxImg.src = zoomImg.src;
        lightbox.classList.add("is-open");
      }
    });

    if (form) {
      const textarea = form.querySelector("textarea");
      const fileInput = form.querySelector("input[type='file']");
      const panel = guestRoot.querySelector(".glpichat-chatbox-panel");

      if (textarea && fileInput) {
        // Ctrl+V paste support for guest
        textarea.addEventListener("paste", (e) => {
          if (e.clipboardData && e.clipboardData.files && e.clipboardData.files.length > 0) {
            fileInput.files = e.clipboardData.files;
            submitForm(form);
            e.preventDefault();
          }
        });

        // Enter to send, Shift+Enter for newline
        textarea.addEventListener("keydown", (e) => {
          if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            submitForm(form);
          }
        });
      }

      if (panel && fileInput) {
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
          if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            submitForm(form);
          }
        });
      }

      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const fd = new FormData(form);
        const content = String(fd.get("content") || "").trim();
        const file = fd.get("file");
        
        const hasFile = file instanceof File && file.size > 0;
        if (content === "" && !hasFile) {
          return;
        }

        // Guest optimistic render placeholder
        const tempItem = document.createElement("div");
        tempItem.className = "glpichat-message is-own is-optimistic";
        const displayContent = hasFile ? `Enviando arquivo: ${file.name}...` : content;
        tempItem.innerHTML = `
          <div class="glpichat-message-head">Você</div>
          <div class="glpichat-message-body">${sanitize(displayContent)}</div>
          <div class="glpichat-message-time">Enviando...</div>
        `;
        messages.appendChild(tempItem);
        messages.scrollTop = messages.scrollHeight;

        if (textarea) textarea.value = "";
        if (fileInput) fileInput.value = "";

        try {
          if (content !== "") {
            await postForm(`${pluginBase}/Guest/Send`, fd);
          }
          if (hasFile) {
            await postForm(`${pluginBase}/Guest/Upload`, fd);
          }
        } catch (err) {
          tempItem.remove();
          if (textarea && content !== "") textarea.value = content;
          alert("Erro ao enviar: " + err.message);
        }

        setTimeout(() => {
          tempItem.remove();
        }, 5000);

        await syncGuest();
      });
    }

    await syncGuest();
    window.setInterval(() => {
      if (document.visibilityState === "visible") {
        syncGuest().catch(console.error);
      }
    }, 5000);
  };

  const bootstrap = async () => {
    const guestRoot = document.querySelector(".glpichat[data-guest='1']");
    if (guestRoot) {
      await bootstrapGuest(guestRoot);
      return;
    }

    if (!document.body || window.location.pathname.includes("/install/")) {
      return;
    }

    updateObstructionElementsCache();
    // Refresh cache periodically in case other plugins dynamically inject elements
    window.setInterval(updateObstructionElementsCache, 8000);

    state.activeTicketId = activeTicketIdFromPage();
    loadUiState();
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
    const activeKey = ticket.channels[ticket.activeTab];
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
})();
