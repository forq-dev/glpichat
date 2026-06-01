(function () {
  "use strict";

  const pluginBase = "/plugins/glpichat";
  const POLL_FOCUSED_MS = 2500;
  const POLL_IDLE_MS = 9000;
  const POLL_BG_MS = 18000;
  const META_SYNC_MS = 15000;
  const DESKTOP_MAX_OPEN = 3;
  const MOBILE_MAX_OPEN = 1;

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
    pendingMutations: 0,
  };

  const ui = {
    root: null,
    launcher: null,
    launcherPanel: null,
    launcherNotifications: null,
    launcherInvites: null,
    dock: null,
  };

  const channelKey = (ticketsId, roomType) => `${ticketsId}:${roomType}`;
  const maxOpenChats = () => (window.innerWidth <= 980 ? MOBILE_MAX_OPEN : DESKTOP_MAX_OPEN);

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
  };

  const saveUiState = () => {
    storageSave({
      openTicketIds: state.openTicketIds,
      closedTicketIds: Array.from(state.closedTicketIds),
    });
  };

  const fetchJson = async (url, options) => {
    const response = await fetch(url, options || { credentials: "same-origin" });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || "Request failed.");
    }
    if (data._glpi_csrf_token) {
      state.csrf = data._glpi_csrf_token;
    }
    return data;
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
    .replace(/>/g, "&gt;");

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
    const merged = {
      ...prev,
      ...payload,
      messages: Array.isArray(prev.messages) ? prev.messages : [],
      events: Array.isArray(prev.events) ? prev.events : [],
      messages_loaded: Boolean(prev.messages_loaded || false),
      history_loading: false,
      older_loading: false,
      history_failed: false,
      last_message_id: Number(prev.last_message_id || payload.last_message_id || 0),
      last_event_id: Number(prev.last_event_id || payload.last_event_id || 0),
      unread_count: Number(prev.unread_count || payload.unread_count || 0),
    };
    const payloadLastMessageId = Number(payload.last_message_id || 0);
    if (payloadLastMessageId > 0 && payloadLastMessageId > merged.last_message_id) {
      merged.last_message_id = payloadLastMessageId;
    }
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
            <div class="glpichat-panel-invites" data-role="invites"></div>
          </div>
          <button type="button" class="glpichat-chatbox-tab" data-role="launcher-tab">
            <span>Chat</span>
            <em data-role="launcher-badge"></em>
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(root);
    ui.root = root;
    ui.dock = root.querySelector("[data-role='dock']");
    ui.launcher = root.querySelector("[data-role='launcher']");
    ui.launcherPanel = root.querySelector("[data-role='launcher-panel']");
    ui.launcherNotifications = root.querySelector("[data-role='notifications']");
    ui.launcherInvites = root.querySelector("[data-role='invites']");

    root.querySelector("[data-role='launcher-tab']").addEventListener("click", toggleLauncher);

    root.querySelector(".glpichat-panel-head").addEventListener("click", (event) => {
      if (event.target.closest("button")) {
        return;
      }
      minimizeLauncher();
    });
  };

  const minimizeLauncher = () => {
    state.launcherOpen = false;
    ui.launcherPanel.hidden = true;
    ui.launcher.classList.remove("is-open");
  };

  const openLauncher = () => {
    state.launcherOpen = true;
    ui.launcherPanel.hidden = false;
    ui.launcher.classList.add("is-open");
    if (state.activeTicketId > 0) {
      refreshInvites(state.activeTicketId).catch(console.error);
    }
  };

  const toggleLauncher = () => {
    if (state.launcherOpen) {
      minimizeLauncher();
      return;
    }
    openLauncher();
  };

  const ticketBoxId = (ticketId) => `glpichat-box-${ticketId}`;

  const ensureTicketBox = (ticketId) => {
    if (document.getElementById(ticketBoxId(ticketId))) {
      return;
    }

    const box = document.createElement("div");
    box.id = ticketBoxId(ticketId);
    box.className = "glpichat-chatbox";
    box.dataset.ticketId = String(ticketId);
    box.innerHTML = `
      <div class="glpichat-chatbox-panel" hidden>
        <div class="glpichat-window-head">
          <strong>Chamado #${ticketId}</strong>
          <button type="button" data-role="close">×</button>
        </div>
        <div class="glpichat-tabs" data-role="tabs"></div>
        <div class="glpichat-empty" data-role="empty" hidden></div>
        <div class="glpichat-messages" data-role="messages"></div>
        <form class="glpichat-send" data-role="send-form">
          <textarea name="content" rows="2" placeholder="Escreva uma mensagem..."></textarea>
          <div class="glpichat-send-row">
            <input id="attach-${ticketId}" class="glpichat-file-input" type="file" name="file">
            <label for="attach-${ticketId}" class="glpichat-attach">📎</label>
            <button type="submit">Enviar</button>
          </div>
        </form>
      </div>
      <button type="button" class="glpichat-chatbox-tab" data-role="tab"></button>
    `;

    ui.dock.insertBefore(box, ui.launcher);

    box.querySelector("[data-role='tab']").addEventListener("click", () => {
      const isOpen = state.openTicketIds.includes(ticketId);
      if (isOpen) {
        minimizeTicket(ticketId);
      } else {
        openTicket(ticketId);
      }
    });

    box.querySelector("[data-role='close']").addEventListener("click", (event) => {
      event.stopPropagation();
      closeTicket(ticketId);
    });

    box.querySelector(".glpichat-window-head").addEventListener("click", (event) => {
      if (event.target.closest("[data-role='close']")) {
        return;
      }
      minimizeTicket(ticketId);
    });

    box.querySelector("[data-role='send-form']").addEventListener("submit", async (event) => {
      event.preventDefault();
      const ticket = state.tickets.get(ticketId);
      if (!ticket) {
        return;
      }
      const activeRoom = ticket.activeTab;
      const fd = new FormData(event.currentTarget);
      const content = String(fd.get("content") || "").trim();
      const file = fd.get("file");
      fd.set("tickets_id", String(ticketId));
      fd.set("room_type", activeRoom);
      fd.set("_glpi_csrf_token", state.csrf);
      const submitButton = event.currentTarget.querySelector("button[type='submit']");
      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
      }
      state.pendingMutations += 1;

      try {
        if (content !== "") {
          // keepalive improves odds of completing small POSTs during accidental refresh/navigation.
          await postForm(`${pluginBase}/Send`, fd, { keepalive: true });
        }
        if (file instanceof File && file.size > 0) {
          await postForm(`${pluginBase}/Upload`, fd);
        }
      } finally {
        state.pendingMutations = Math.max(0, state.pendingMutations - 1);
        if (submitButton instanceof HTMLButtonElement) {
          submitButton.disabled = false;
        }
      }

      event.currentTarget.reset();
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
    const messages = Array.isArray(channel?.messages) ? channel.messages : [];
    messagesNode.innerHTML = "";

    if (messages.length === 0) {
      empty.hidden = false;
      empty.textContent = ticket.activeTab === "internal"
        ? "Nenhuma mensagem na discussão técnica ainda."
        : "Nenhuma mensagem com o solicitante ainda.";
    } else {
      empty.hidden = true;
      for (const msg of messages) {
        const own = msg.actor_type === "user" && Number(msg.users_id || 0) === Number(state.currentUserId || 0);
        const system = msg.actor_type !== "user" && msg.actor_type !== "guest";
        const item = document.createElement("div");
        item.className = `glpichat-message ${own ? "is-own" : "is-other"} ${system ? "is-system" : ""}`;
        item.innerHTML = `
          <div class="glpichat-message-head">${sanitize(msg.sender_name || "Sistema")}</div>
          <div class="glpichat-message-body">${sanitize(msg.content)}</div>
          <div class="glpichat-message-time">${sanitize(msg.date_creation || "")}</div>
        `;
        messagesNode.appendChild(item);
      }
      const lastId = Number(messages[messages.length - 1].id || 0);
      if (channel && lastId > 0) {
        markRead(channel.rooms_id, lastId).catch(console.error);
        channel.unread_count = 0;
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
          box.classList.remove("is-open");
          box.querySelector(".glpichat-chatbox-panel").hidden = true;
        }
      }
    }
  };

  const openTicket = async (ticketId) => {
    if (!state.tickets.has(ticketId)) {
      return;
    }
    state.closedTicketIds.delete(ticketId);
    ensureTicketBox(ticketId);
    state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
    state.openTicketIds.push(ticketId);
    enforceOpenLimit();

    const box = document.getElementById(ticketBoxId(ticketId));
    box.classList.add("is-open");
    box.querySelector(".glpichat-chatbox-panel").hidden = false;
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
    if (box) {
      box.classList.remove("is-open");
      box.querySelector(".glpichat-chatbox-panel").hidden = true;
    }
    state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
    saveUiState();
    renderDock();
  };

  const closeTicket = (ticketId) => {
    const box = document.getElementById(ticketBoxId(ticketId));
    if (box) {
      box.remove();
    }
    state.closedTicketIds.add(ticketId);
    state.openTicketIds = state.openTicketIds.filter((id) => id !== ticketId);
    saveUiState();
    renderDock();
  };

  const renderDock = () => {
    const allTicketIds = Array.from(state.tickets.keys()).sort((a, b) => a - b);
    let ticketIds = allTicketIds.filter((id) => !state.closedTicketIds.has(id));
    if (ticketIds.length === 0 && allTicketIds.length > 0) {
      state.closedTicketIds = new Set();
      ticketIds = allTicketIds;
      saveUiState();
    }
    for (const ticketId of ticketIds) {
      ensureTicketBox(ticketId);
      const ticket = state.tickets.get(ticketId);
      recalcUnread(ticket);
      const box = document.getElementById(ticketBoxId(ticketId));
      const tab = box.querySelector("[data-role='tab']");
      const isOpen = state.openTicketIds.includes(ticketId);
      box.classList.toggle("is-open", isOpen);
      box.querySelector(".glpichat-chatbox-panel").hidden = !isOpen;

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
    renderLauncherNotifications();
  };

  const renderLauncherNotifications = () => {
    const pending = Array.from(state.tickets.values())
      .filter((ticket) => ticket.unreadTotal > 0)
      .sort((a, b) => b.unreadTotal - a.unreadTotal);

    const total = pending.reduce((acc, ticket) => acc + ticket.unreadTotal, 0);
    const header = `<div class="glpichat-notification-head">Notificações pendentes: <strong>${total}</strong></div>`;
    const list = pending.slice(0, 8).map((ticket) => {
      const publicUnread = Number(state.channels.get(ticket.channels.public || "")?.unread_count || 0);
      const internalUnread = Number(state.channels.get(ticket.channels.internal || "")?.unread_count || 0);
      return `<button type="button" class="glpichat-notification-item" data-ticket-id="${ticket.id}">
        <span class="glpichat-notification-label">Chamado #${ticket.id}</span>
        <span class="glpichat-notification-badges">
          <i class="glpichat-notification-badge glpichat-notification-badge-public" title="Solicitante">${publicUnread}</i>
          <i class="glpichat-notification-badge glpichat-notification-badge-internal" title="Discussão técnica">${internalUnread}</i>
        </span>
      </button>`;
    }).join("");

    const notificationList = list !== ""
      ? `<div class="glpichat-notification-list">${list}</div>`
      : `<div class="glpichat-notification-empty">Nenhuma mensagem em espera.</div>`;

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

    ui.launcherNotifications.innerHTML = `${header}${notificationList}${statusFilters}${ticketList}`;
    ui.launcherNotifications.querySelectorAll(".glpichat-notification-item").forEach((btn) => {
      btn.addEventListener("click", () => {
        const ticketId = Number(btn.getAttribute("data-ticket-id") || "0");
        if (ticketId > 0) {
          state.closedTicketIds.delete(ticketId);
          openTicket(ticketId);
        }
      });
    });
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
          state.closedTicketIds.delete(ticketId);
          openTicket(ticketId);
        }
      });
    });
  };

  const refreshInvites = async (ticketsId) => {
    ui.launcherInvites.innerHTML = "";

    const head = document.createElement("div");
    head.className = "glpichat-invite-head";
    head.innerHTML = `<strong>Invites #${ticketsId}</strong>`;

    const form = document.createElement("form");
    form.className = "glpichat-invite";
    form.innerHTML = `
      <input type="text" name="guest_name" placeholder="Nome do convidado" required>
      <input type="email" name="guest_email" placeholder="Email do convidado">
      <button type="submit">Criar invite</button>
      <div data-role="result"></div>
    `;

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const fd = new FormData(form);
      fd.set("tickets_id", String(ticketsId));
      fd.set("_glpi_csrf_token", state.csrf);
      const created = await postForm(`${pluginBase}/Invite`, fd);
      form.querySelector("[data-role='result']").textContent = `Link: ${created.url}`;
      await refreshInvites(ticketsId);
    });

    ui.launcherInvites.append(head, form);

    const listData = await fetchJson(`${pluginBase}/Invite/List?tickets_id=${ticketsId}`, { credentials: "same-origin" });
    const table = document.createElement("table");
    table.className = "glpichat-invite-table";
    table.innerHTML = "<thead><tr><th>Convidado</th><th>Status</th><th>Expira</th><th>Acesso</th><th>Ações</th></tr></thead>";

    const tbody = document.createElement("tbody");
    for (const invite of listData.invites || []) {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${sanitize(invite.guest_name || "")}</td>
        <td>${sanitize(invite.status || "")}</td>
        <td>${sanitize(invite.expires_at || "")}</td>
        <td>${invite.was_accessed ? "Sim" : "Não"}</td>
        <td></td>
      `;
      const actions = tr.lastElementChild;

      const revokeBtn = document.createElement("button");
      revokeBtn.type = "button";
      revokeBtn.textContent = "Revogar";
      revokeBtn.addEventListener("click", async () => {
        const fd = new FormData();
        fd.set("invite_id", String(invite.id));
        fd.set("_glpi_csrf_token", state.csrf);
        await postForm(`${pluginBase}/Invite/Revoke`, fd);
        await refreshInvites(ticketsId);
      });

      const reissueBtn = document.createElement("button");
      reissueBtn.type = "button";
      reissueBtn.textContent = "Reemitir";
      reissueBtn.addEventListener("click", async () => {
        const fd = new FormData();
        fd.set("invite_id", String(invite.id));
        fd.set("_glpi_csrf_token", state.csrf);
        const result = await postForm(`${pluginBase}/Invite/Reissue`, fd);
        window.alert(`Novo link: ${result.url}`);
        await refreshInvites(ticketsId);
      });

      actions.append(revokeBtn, reissueBtn);
      tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    ui.launcherInvites.appendChild(table);
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
    await postForm(`${pluginBase}/Widget/MarkRead`, fd);
  };

  const mergeChannelMessages = (channel, incomingMessages) => {
    if (!Array.isArray(incomingMessages) || incomingMessages.length === 0) {
      return;
    }
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

  const loadChannelHistory = async (channel) => {
    if (!channel || channel.messages_loaded === true || channel.history_loading === true) {
      return;
    }
    channel.history_loading = true;
    try {
      try {
        const data = await fetchJson(`${pluginBase}/Widget/History?rooms_id=${channel.rooms_id}&limit=50`, { credentials: "same-origin" });
        channel.messages = Array.isArray(data.messages) ? data.messages : [];
        channel.last_message_id = channel.messages.length > 0
          ? Number(channel.messages[channel.messages.length - 1].id || channel.last_message_id || 0)
          : Number(channel.last_message_id || 0);
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
      channel.messages_loaded = true;
      channel.history_failed = false;
    } finally {
      channel.history_loading = false;
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
    for (const key of Object.keys(data.channels || {})) {
      const patch = data.channels[key];
      const channel = state.channels.get(key);
      if (!channel) {
        continue;
      }

      if (Array.isArray(patch.messages) && patch.messages.length > 0) {
        mergeChannelMessages(channel, patch.messages);
        channel.last_message_id = Number(patch.last_message_id || channel.last_message_id || 0);
        channel.messages_loaded = true;
        channel.history_failed = false;
        const ticket = state.tickets.get(channel.tickets_id);
        const activeTab = ticket?.activeTab || "public";
        const isOpen = state.openTicketIds.includes(channel.tickets_id);
        if (!isOpen || activeTab !== channel.room_type) {
          channel.unread_count += patch.messages.length;
        }
      }

      if (Array.isArray(patch.events) && patch.events.length > 0) {
        channel.events.push(...patch.events);
        channel.last_event_id = Number(patch.last_event_id || channel.last_event_id || 0);
      }
    }

    for (const ticketId of state.openTicketIds) {
      renderTicketTabs(ticketId);
      renderTicketMessages(ticketId);
    }

    renderDock();
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
    const messages = guestRoot.querySelector("[data-role='messages']");
    const form = guestRoot.querySelector("[data-role='guest-send-form']");

    const addMessage = (message) => {
      const item = document.createElement("div");
      item.className = "glpichat-message is-own";
      item.innerHTML = `<div class='glpichat-message-body'>${sanitize(message.content)}</div>`;
      messages.appendChild(item);
    };

    const syncGuest = async () => {
      const params = new URLSearchParams({
        last_message_id: String(lastMessageId),
        last_event_id: String(lastEventId),
      });
      const data = await fetchJson(`${pluginBase}/Guest/Sync?${params.toString()}`);
      for (const message of data.messages || []) {
        addMessage(message);
        lastMessageId = Math.max(lastMessageId, Number(message.id || 0));
      }
      for (const event of data.events || []) {
        lastEventId = Math.max(lastEventId, Number(event.id || 0));
      }
      messages.scrollTop = messages.scrollHeight;
    };

    if (form) {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const fd = new FormData(form);
        const content = String(fd.get("content") || "").trim();
        const file = fd.get("file");
        if (content !== "") {
          await postForm(`${pluginBase}/Guest/Send`, fd);
        }
        if (file instanceof File && file.size > 0) {
          await postForm(`${pluginBase}/Guest/Upload`, fd);
        }
        form.reset();
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

    state.activeTicketId = activeTicketIdFromPage();
    loadUiState();
    createShell();

    const endpoint = `${pluginBase}/Widget/Channels${state.activeTicketId > 0 ? `?tickets_id=${state.activeTicketId}` : ""}`;
    const data = await fetchJson(endpoint, { credentials: "same-origin" });
    state.currentUserId = Number(data.current_user_id || 0);

    for (const ch of data.channels || []) {
      setChannel(ch);
    }

    if (state.activeTicketId > 0 && state.tickets.has(state.activeTicketId) && !state.openTicketIds.includes(state.activeTicketId)) {
      state.openTicketIds.push(state.activeTicketId);
    }

    enforceOpenLimit();
    renderDock();

    for (const ticketId of state.openTicketIds) {
      openTicket(ticketId);
    }

    if (state.activeTicketId > 0) {
      refreshInvites(state.activeTicketId).catch(console.error);
    }

    schedulePolling();

    window.addEventListener("resize", () => {
      enforceOpenLimit();
      renderDock();
    });

    document.addEventListener("visibilitychange", schedulePolling);
  };

  document.addEventListener("DOMContentLoaded", () => {
    bootstrap().catch(console.error);
  });

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
