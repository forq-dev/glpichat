import {
  pluginBase,
  rootDoc,
  TERMINAL_TICKET_STATUSES
} from './modules/constants.js';

import {
  fetchJson,
  postForm
} from './modules/http.js';

import {
  submitForm
} from './modules/dom.js';

import { sanitize } from './modules/sanitize.js';

import {
  isVisibleSystemEvent,
  formatSystemEventMessage,
  formatTypingMessage
} from './modules/format.js';

import {
  normalizeEventCursor,
  isNodeNearBottom,
  hasSelectionInsideNode,
  firstFileFromTransfer,
  assignSingleFileToInput,
} from './modules/shared.js';

"use strict";

const AUTO_SCROLL_THRESHOLD_PX = 160;

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

const applyGuestComposerState = (guestRoot, ticketStatus, isSessionExpired = false) => {
  if (!(guestRoot instanceof HTMLElement)) {
    return;
  }

  const archived = isSessionExpired || TERMINAL_TICKET_STATUSES.has(Number(ticketStatus || 0));
  const form = guestRoot.querySelector("[data-role='guest-send-form']");
  if (!(form instanceof HTMLElement)) {
    return;
  }

  const messagesEl = guestRoot.querySelector("[data-role='messages']");

  let message = `Este chamado está ${ticketStatusLabel(ticketStatus).toLowerCase()}. O chat foi arquivado.`;
  if (isSessionExpired) {
    message = "Sessão expirada ou revogada. O chat foi encerrado.";
  }

  let archivedNotice = guestRoot.querySelector("[data-role='guest-archived-notice']");
  if (archived) {
    if (!(archivedNotice instanceof HTMLElement)) {
      archivedNotice = document.createElement("div");
      archivedNotice.className = "glpichat-empty glpichat-archived-notice";
      archivedNotice.setAttribute("data-role", "guest-archived-notice");
      if (messagesEl instanceof HTMLElement) {
        messagesEl.appendChild(archivedNotice);
      }
    }
    archivedNotice.textContent = message;
    archivedNotice.hidden = false;
    if (messagesEl instanceof HTMLElement) {
      messagesEl.style.alignContent = 'center';
      messagesEl.style.justifyItems = 'center';
      messagesEl.style.textAlign = 'center';
    }
  } else if (archivedNotice instanceof HTMLElement) {
    archivedNotice.hidden = true;
    if (messagesEl instanceof HTMLElement) {
      messagesEl.style.alignContent = '';
      messagesEl.style.justifyItems = '';
      messagesEl.style.textAlign = '';
    }
  }

  form.style.display = archived ? 'none' : '';

  const textarea = form.querySelector("textarea");
  const fileInput = form.querySelector("input[type='file']");
  const submitButton = form.querySelector("button[type='submit']");
  const attachButton = form.querySelector(".glpichat-attach");

  if (textarea instanceof HTMLTextAreaElement) {
    textarea.disabled = archived;
    textarea.readOnly = archived;
    textarea.placeholder = archived ? message : "Escreva uma mensagem...";
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
};

const bootstrapGuest = async (guestRoot) => {
  let lastMessageId = 0;
  let lastEventId = "";
  let initialSyncDone = false;
  let isExpired = false;
  let guestIsTyping = false;
  let guestTypingTimeout = null;
  let leaveNotificationSent = false;
  const messages = guestRoot.querySelector("[data-role='messages']");
  const form = guestRoot.querySelector("[data-role='guest-send-form']");

  const head = guestRoot.querySelector(".glpichat-window-head");
  let countdownEl = null;
  if (head) {
    countdownEl = document.createElement("span");
    countdownEl.className = "glpichat-countdown";
    head.appendChild(countdownEl);
  }
  let secondsRemaining = 0;

  const updateCountdownDisplay = () => {
    if (!countdownEl) return;
    if (secondsRemaining <= 0) {
      isExpired = true;
      countdownEl.textContent = "Sessão expirada";
      countdownEl.classList.add("is-expired");
      applyGuestComposerState(guestRoot, 0, true);
      return;
    }
    const mins = Math.floor(secondsRemaining / 60);
    const secs = secondsRemaining % 60;
    countdownEl.textContent = `Expira em ${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
  };

  const countdownIntervalId = window.setInterval(() => {
    if (secondsRemaining > 0 && !isExpired) {
      secondsRemaining--;
      updateCountdownDisplay();
    }
  }, 1000);

  const addMessage = (message, skipAnimation = false) => {
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
            <img src="${rootDoc}/front/document.send.php?docid=${message.documents_id}" alt="${sanitize(filename)}" class="glpichat-zoomable-img" />
          </div>
        `;
      } else {
        bodyHtml = `
          <a class="glpichat-attachment-link" href="${rootDoc}/front/document.send.php?docid=${message.documents_id}" target="_blank" download="${sanitize(filename)}">
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

  const addEvent = (event, skipAnimation = false) => {
    if (!isVisibleSystemEvent(event)) {
      return;
    }

    // Supported event types include: guest_joined, guest_left, guest_left_timeout,
    // user_joined, user_left, ticket_status_changed, ticket_priority_changed,
    // ticket_urgency_changed, ticket_impact_changed, ticket_type_changed,
    // ticket_category_changed, ticket_assigned_users_changed,
    // ticket_assigned_groups_changed, ticket_followup_added,
    // messages_purged (payload: { purged_before: "YYYY-MM-DD" })
    // Formatting is delegated to formatSystemEventMessage in modules/format.js
    const item = document.createElement("div");
    item.className = `glpichat-message is-system${skipAnimation ? "" : " is-new"}`;
    item.innerHTML = `
      <div class="glpichat-message-head">Sistema</div>
      <div class="glpichat-message-body">${sanitize(formatSystemEventMessage(event))}</div>
      <div class="glpichat-message-time">${sanitize(event.date_creation || "")}</div>
    `;
    messages.appendChild(item);
  };

  const notifyGuestLeave = () => {
    if (leaveNotificationSent || isExpired) {
      return;
    }

    leaveNotificationSent = true;
    const leaveUrl = `${pluginBase}/Guest/Leave`;

    try {
      if (typeof navigator.sendBeacon === "function") {
        const beaconBody = new URLSearchParams();
        const beaconSent = navigator.sendBeacon(leaveUrl, beaconBody);
        if (beaconSent) {
          return;
        }
      }
    } catch (error) {
      console.error("Guest leave beacon failed:", error);
    }

    fetch(leaveUrl, {
      method: "POST",
      credentials: "same-origin",
      keepalive: true,
      headers: {
        "X-Requested-With": "XMLHttpRequest",
      },
    }).catch((error) => {
      console.error("Guest leave fallback failed:", error);
    });
  };

  const syncGuest = async () => {
    if (isExpired) return;
    try {
      const shouldAutoScroll = !hasSelectionInsideNode(messages)
        && (isNodeNearBottom(messages, window.__glpichatConfig?.auto_scroll_threshold_px ?? AUTO_SCROLL_THRESHOLD_PX) || !initialSyncDone);
      const params = new URLSearchParams({
        last_message_id: String(lastMessageId),
        last_event_id: String(lastEventId),
        is_typing: guestIsTyping ? "1" : "0",
      });
      const data = await fetchJson(`${pluginBase}/Guest/Sync?${params.toString()}`);
      applyGuestComposerState(guestRoot, Number(data.ticket_status || 0));

      if (typeof data.session_remaining_seconds === "number") {
        const diff = Math.abs(data.session_remaining_seconds - secondsRemaining);
        if (!initialSyncDone || data.session_remaining_seconds > secondsRemaining || diff > 10 || data.session_remaining_seconds <= 0) {
          secondsRemaining = data.session_remaining_seconds;
          updateCountdownDisplay();
        }
      }

      const typingIndicator = guestRoot.querySelector("[data-role='typing-indicator']");
      if (typingIndicator) {
        if (Array.isArray(data.typing_names) && data.typing_names.length > 0) {
          typingIndicator.textContent = formatTypingMessage(data.typing_names);
          typingIndicator.hidden = false;
        } else {
          typingIndicator.hidden = true;
        }
      }
      
      if (Array.isArray(data.messages) && data.messages.length > 0) {
        const tempItems = messages.querySelectorAll(".glpichat-message.is-optimistic");
        tempItems.forEach((el) => el.remove());
      }
      
      for (const message of data.messages || []) {
        addMessage(message, !initialSyncDone);
        lastMessageId = Math.max(lastMessageId, Number(message.id || 0));
      }
      for (const event of data.events || []) {
        addEvent(event, !initialSyncDone);
        lastEventId = pickLatestEventCursor(lastEventId, event.id);
      }
      if (shouldAutoScroll) {
        messages.scrollTop = messages.scrollHeight;
      }
      initialSyncDone = true;
    } catch (err) {
      isExpired = true;
      secondsRemaining = 0;
      updateCountdownDisplay();
      applyGuestComposerState(guestRoot, 0, true);
      throw err;
    }
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
      textarea.addEventListener("paste", (e) => {
        const pastedFile = firstFileFromTransfer(e.clipboardData);
        if (assignSingleFileToInput(fileInput, pastedFile)) {
          submitForm(form);
          e.preventDefault();
        }
      });

      fileInput.addEventListener("change", () => {
        if (fileInput.files instanceof FileList && fileInput.files.length > 0) {
          submitForm(form);
        }
      });

      textarea.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey) {
          e.preventDefault();
          submitForm(form);
        }
      });

      textarea.addEventListener("input", () => {
        if (!guestIsTyping) {
          guestIsTyping = true;
          syncGuest().catch(console.error);
        }
        if (guestTypingTimeout) clearTimeout(guestTypingTimeout);
        guestTypingTimeout = setTimeout(() => {
          guestIsTyping = false;
          syncGuest().catch(console.error);
        }, window.__glpichatConfig?.typing_indicator_timeout_ms ?? 3000);
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
        const droppedFile = firstFileFromTransfer(e.dataTransfer);
        if (assignSingleFileToInput(fileInput, droppedFile)) {
          submitForm(form);
        }
      });
    }

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (form.hidden) {
        return;
      }
      guestIsTyping = false;
      if (guestTypingTimeout) {
        clearTimeout(guestTypingTimeout);
        guestTypingTimeout = null;
      }
      const fd = new FormData(form);
      const content = String(fd.get("content") || "").trim();
      const file = fd.get("file");
      
      const hasFile = file instanceof File && file.size > 0;
      if (content === "" && !hasFile) {
        return;
      }

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

      try {
        await syncGuest();
      } catch (syncErr) {
        console.error("Sync after send failed:", syncErr);
      }
    });
  }

  try {
    await syncGuest();
  } catch (e) {
    console.error("Initial guest sync failed:", e);
  }

  const syncIntervalId = window.setInterval(() => {
    if (document.visibilityState === "visible" && !isExpired) {
      syncGuest().catch(console.error);
    }
  }, window.__glpichatConfig?.guest_poll_ms ?? 5000);

  window.addEventListener("pagehide", () => {
    window.clearInterval(countdownIntervalId);
    window.clearInterval(syncIntervalId);
    notifyGuestLeave();
  }, { capture: true });
};

const bootGuest = () => {
  const guestRoot = document.querySelector(".glpichat[data-guest='1']");
  if (guestRoot) {
    bootstrapGuest(guestRoot).catch(console.error);
  }
};

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bootGuest, { once: true });
} else {
  bootGuest();
}
