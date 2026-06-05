import { describe, it, expect } from 'vitest';
import { formatSystemEventMessage, formatTypingMessage } from '../../../../src/js/modules/format.js';

describe('Format Module', () => {
  describe('formatSystemEventMessage', () => {
    it('should format ticket_status_changed event correctly', () => {
      const event = {
        event_type: 'ticket_status_changed',
        payload: {
          old_label: 'Novo',
          new_label: 'Em andamento'
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Status alterado de Novo para Em andamento.');
    });

    it('should handle missing payload properties in ticket_status_changed', () => {
      const event = {
        event_type: 'ticket_status_changed',
        payload: {}
      };
      expect(formatSystemEventMessage(event)).toBe('Status alterado de desconhecido para desconhecido.');
    });

    it('should format ticket_priority_changed event correctly', () => {
      const event = {
        event_type: 'ticket_priority_changed',
        payload: {
          old_priority: 2,
          new_priority: 4
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Prioridade alterada de 2 para 4.');
    });

    it('should handle missing payload properties in ticket_priority_changed', () => {
      const event = {
        event_type: 'ticket_priority_changed',
        payload: {}
      };
      expect(formatSystemEventMessage(event)).toBe('Prioridade alterada de 0 para 0.');
    });

    it('should format ticket_assigned_users_changed event correctly', () => {
      const event = {
        event_type: 'ticket_assigned_users_changed',
        payload: {
          new_assigned_users: ['João', 'Maria']
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Técnicos atribuídos atualizados: João, Maria.');
    });

    it('should handle empty assigned users list', () => {
      const event = {
        event_type: 'ticket_assigned_users_changed',
        payload: {
          new_assigned_users: []
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Técnicos atribuídos atualizados: nenhum.');
    });

    it('should format ticket_assigned_groups_changed event correctly', () => {
      const event = {
        event_type: 'ticket_assigned_groups_changed',
        payload: {
          new_assigned_groups: ['Suporte N1', 'Suporte N2']
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Grupos atribuídos atualizados: Suporte N1, Suporte N2.');
    });

    it('should format guest_joined event correctly', () => {
      const event = {
        event_type: 'guest_joined',
        payload: {
          guest_name: 'Marcos'
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Convidado Marcos entrou no chat.');
    });

    it('should format guest_left event correctly', () => {
      const event = {
        event_type: 'guest_left',
        payload: {
          guest_name: 'Marcos'
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Convidado Marcos saiu do chat.');
    });

    it('should format guest_left_timeout event correctly', () => {
      const event = {
        event_type: 'guest_left_timeout',
        payload: {
          guest_name: 'Marcos'
        }
      };
      expect(formatSystemEventMessage(event)).toBe('Sessão do convidado Marcos expirou por inatividade.');
    });

    it('should handle unknown event types', () => {
      const event = {
        event_type: 'unknown_type',
        payload: {}
      };
      expect(formatSystemEventMessage(event)).toBe('Evento operacional registrado.');
    });

    it('should handle null or invalid event gracefully', () => {
      expect(formatSystemEventMessage(null)).toBe('Evento operacional registrado.');
      expect(formatSystemEventMessage({})).toBe('Evento operacional registrado.');
    });
  });

  describe('formatTypingMessage', () => {
    it('should return empty string when names is empty or invalid', () => {
      expect(formatTypingMessage([])).toBe('');
      expect(formatTypingMessage(null)).toBe('');
      expect(formatTypingMessage(undefined)).toBe('');
    });

    it('should format a single user typing', () => {
      expect(formatTypingMessage(['João'])).toBe('João está digitando');
    });

    it('should format two users typing', () => {
      expect(formatTypingMessage(['João', 'Maria'])).toBe('João e Maria estão digitando');
    });

    it('should format three or more users typing', () => {
      expect(formatTypingMessage(['João', 'Maria', 'Pedro'])).toBe('João, Maria e Pedro estão digitando');
    });
  });
});
