/**
 * Format Module
 * Handles shared formatting for system events and typing indicators
 */

const VISIBLE_SYSTEM_EVENT_TYPES = new Set([
  "ticket_status_changed",
  "ticket_priority_changed",
  "ticket_urgency_changed",
  "ticket_impact_changed",
  "ticket_type_changed",
  "ticket_category_changed",
  "ticket_assigned_users_changed",
  "ticket_assigned_groups_changed",
  "ticket_followup_added",
  "guest_joined",
  "guest_left",
  "guest_left_timeout",
  "user_joined",
  "user_left",
  "messages_purged",
]);

export const isVisibleSystemEvent = (event) => {
  if (!event || typeof event.event_type !== "string") {
    return false;
  }

  return VISIBLE_SYSTEM_EVENT_TYPES.has(event.event_type);
};

/**
 * Format a system event message based on its type and payload
 * @param {Object} event 
 * @returns {string} Formatted system message
 */
export const formatSystemEventMessage = (event) => {
  if (!event) {
    return "Evento operacional registrado.";
  }
  const payload = event && typeof event.payload === "object" && event.payload !== null ? event.payload : {};
  const asList = (items) => Array.isArray(items) && items.length > 0 ? items.join(", ") : "nenhum";

  if (event.event_type === "ticket_status_changed") {
    return `Status alterado de ${String(payload.old_label || "desconhecido")} para ${String(payload.new_label || "desconhecido")}.`;
  }
  if (event.event_type === "ticket_priority_changed") {
    return `Prioridade alterada de ${String(payload.old_label || payload.old_priority || 0)} para ${String(payload.new_label || payload.new_priority || 0)}.`;
  }
  if (event.event_type === "ticket_urgency_changed") {
    return `Urgência alterada de ${String(payload.old_label || payload.old_urgency || 0)} para ${String(payload.new_label || payload.new_urgency || 0)}.`;
  }
  if (event.event_type === "ticket_impact_changed") {
    return `Impacto alterado de ${String(payload.old_label || payload.old_impact || 0)} para ${String(payload.new_label || payload.new_impact || 0)}.`;
  }
  if (event.event_type === "ticket_type_changed") {
    return `Tipo alterado de ${String(payload.old_label || payload.old_type || 0)} para ${String(payload.new_label || payload.new_type || 0)}.`;
  }
  if (event.event_type === "ticket_category_changed") {
    return `Categoria alterada de ${String(payload.old_label || "Sem categoria")} para ${String(payload.new_label || "Sem categoria")}.`;
  }
  if (event.event_type === "ticket_assigned_users_changed") {
    return `Técnicos atribuídos atualizados: ${asList(payload.new_assigned_users)}.`;
  }
  if (event.event_type === "ticket_assigned_groups_changed") {
    return `Grupos atribuídos atualizados: ${asList(payload.new_assigned_groups)}.`;
  }
  if (event.event_type === "ticket_followup_added") {
    const sourceLabel = String(payload.source_label || "GLPI");
    const authorName = String(payload.author_name || "Sistema");
    const visibilityLabel = payload.is_private ? "interno" : "público";

    return `Acompanhamento ${visibilityLabel} adicionado via ${sourceLabel} por ${authorName}.`;
  }
  if (event.event_type === "guest_joined") {
    return `Convidado ${String(payload.guest_name || "desconhecido")} entrou no chat.`;
  }
  if (event.event_type === "guest_left") {
    return `Convidado ${String(payload.guest_name || "desconhecido")} saiu do chat.`;
  }
  if (event.event_type === "guest_left_timeout") {
    return `Sessão do convidado ${String(payload.guest_name || "desconhecido")} expirou por inatividade.`;
  }
  if (event.event_type === "user_joined") {
    return `${String(payload.user_name || "Usuário")} entrou no chat.`;
  }
  if (event.event_type === "user_left") {
    return `${String(payload.user_name || "Usuário")} saiu do chat.`;
  }
  if (event.event_type === "messages_purged") {
    const date = String(payload.purged_before || "");
    return date
      ? `⚠ Mensagens anteriores a ${date} foram expurgadas do histórico do chat.`
      : "⚠ Mensagens antigas foram expurgadas do histórico do chat.";
  }

  return "Evento operacional registrado.";
};

/**
 * Format typing message showing who is typing
 * @param {Array<string>} names 
 * @returns {string} Formatted typing message
 */
export const formatTypingMessage = (names) => {
  if (!Array.isArray(names) || names.length === 0) return "";
  if (names.length === 1) {
    return `${names[0]} está digitando`;
  }
  if (names.length === 2) {
    return `${names[0]} e ${names[1]} estão digitando`;
  }
  return `${names.slice(0, -1).join(", ")} e ${names.at(-1)} estão digitando`;
};
