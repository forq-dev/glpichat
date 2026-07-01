import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  fetchJson,
  getCsrfToken,
  postForm,
  setCsrfToken
} from '../../../src/js/modules/http.js';

const createJsonResponse = (payload, status = 200) => new Response(
  JSON.stringify(payload),
  {
    status,
    headers: {
      'Content-Type': 'application/json',
    },
  }
);

describe('HTTP Module', () => {
  beforeEach(() => {
    setCsrfToken('');
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    setCsrfToken('');
  });

  it('keeps the active CSRF token in module state', () => {
    setCsrfToken('token-123');
    expect(getCsrfToken()).toBe('token-123');
  });

  it('serializes headers, injects the CSRF token and refreshes it from the response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      createJsonResponse({
        ok: true,
        _glpi_csrf_token: 'token-456',
      })
    );
    vi.stubGlobal('fetch', fetchMock);

    const formData = new FormData();
    formData.set('_glpi_csrf_token', 'stale-token');
    formData.set('message', 'hello');

    setCsrfToken('token-123');

    const result = await fetchJson('/api/messages', {
      method: 'POST',
      headers: { 'X-Custom': 'value' },
      body: formData,
    });

    expect(result).toEqual({
      ok: true,
      _glpi_csrf_token: 'token-456',
    });
    expect(getCsrfToken()).toBe('token-456');
    expect(formData.get('_glpi_csrf_token')).toBe('token-123');
    expect(fetchMock).toHaveBeenCalledTimes(1);

    const [url, requestOptions] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/messages');
    expect(requestOptions.method).toBe('POST');
    expect(requestOptions.credentials).toBe('same-origin');
    expect(requestOptions.headers).toBeInstanceOf(Headers);
    expect(requestOptions.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    expect(requestOptions.headers.get('Accept')).toBe('application/json');
    expect(requestOptions.headers.get('X-Custom')).toBe('value');
    expect(requestOptions.headers.get('X-Glpi-Csrf-Token')).toBe('token-123');
  });

  it('serializes requests so the second fetch waits for the first one', async () => {
    let resolveFirst;
    const fetchMock = vi.fn()
      .mockImplementationOnce(() => new Promise((resolve) => {
        resolveFirst = resolve;
      }))
      .mockResolvedValueOnce(createJsonResponse({ second: true }));
    vi.stubGlobal('fetch', fetchMock);

    const firstPromise = fetchJson('/first');
    const secondPromise = fetchJson('/second');

    await Promise.resolve();
    expect(fetchMock).toHaveBeenCalledTimes(1);

    resolveFirst(createJsonResponse({ first: true }));
    await expect(firstPromise).resolves.toEqual({ first: true });

    await Promise.resolve();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await expect(secondPromise).resolves.toEqual({ second: true });
  });

  it('throws a canonical parse error when the body is not JSON', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('plain text body', {
      status: 200,
      headers: {
        'Content-Type': 'text/plain',
      },
    }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(fetchJson('/broken')).rejects.toThrow('Unexpected response format (200).');
  });

  it('prefers the GLPI message field for failed responses', async () => {
    const fetchMock = vi.fn().mockResolvedValue(createJsonResponse({
      message: 'Request rejected by GLPI',
      error: true,
    }, 400));
    vi.stubGlobal('fetch', fetchMock);

    await expect(fetchJson('/denied')).rejects.toThrow('Request rejected by GLPI');
  });

  it('forwards POST FormData through the same fetch pipeline', async () => {
    const fetchMock = vi.fn().mockResolvedValue(createJsonResponse({ saved: true }));
    vi.stubGlobal('fetch', fetchMock);

    const formData = new FormData();
    formData.set('message', 'hello');

    await expect(postForm('/api/messages', formData, { keepalive: true })).resolves.toEqual({ saved: true });
    expect(fetchMock).toHaveBeenCalledTimes(1);

    const [, requestOptions] = fetchMock.mock.calls[0];
    expect(requestOptions.method).toBe('POST');
    expect(requestOptions.keepalive).toBe(true);
    expect(requestOptions.body).toBe(formData);
    expect(requestOptions.credentials).toBe('same-origin');
  });
});
