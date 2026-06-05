/**
 * HTTP Module
 * Handles serialized fetch requests and CSRF synchronization
 */

let csrfToken = "";

/**
 * Set the active CSRF token for headers/forms
 * @param {string} token 
 */
export const setCsrfToken = (token) => {
  csrfToken = token;
};

/**
 * Get the current CSRF token
 * @returns {string}
 */
export const getCsrfToken = () => csrfToken;

let activeRequestPromise = Promise.resolve();

/**
 * Perform a serialized JSON fetch request
 * @param {string} url 
 * @param {Object} [options] 
 * @returns {Promise<Object>}
 */
export const fetchJson = async (url, options) => {
  const nextPromise = activeRequestPromise.then(async () => {
    const requestOptions = {
      credentials: "same-origin",
      ...(options || {}),
    };
    const headers = new Headers(requestOptions.headers || {});
    headers.set("X-Requested-With", "XMLHttpRequest");
    headers.set("Accept", "application/json");
    if (csrfToken) {
      headers.set("X-Glpi-Csrf-Token", csrfToken);
    }
    requestOptions.headers = headers;

    if (requestOptions.body instanceof FormData) {
      if (requestOptions.body.has("_glpi_csrf_token") && csrfToken) {
        requestOptions.body.set("_glpi_csrf_token", csrfToken);
      }
    }
    const response = await fetch(url, requestOptions);
    const responseText = await response.text();
    let data = {};
    if (responseText !== "") {
      try {
        data = JSON.parse(responseText);
      } catch (error) {
        throw new Error(`Unexpected response format (${response.status}).`);
      }
    }
    if (!response.ok) {
      // GLPI's ErrorController returns {error: true, message: "...", title: "..."}
      // for 403/400/500 responses to JSON requests. Prefer the human-readable
      // 'message' field over 'error', which may be a boolean rather than a string.
      const errorMsg =
        (typeof data.message === "string" && data.message !== "")
          ? data.message
          : (typeof data.error === "string" && data.error !== "")
            ? data.error
            : "Request failed.";
      throw new Error(errorMsg);
    }
    if (data._glpi_csrf_token) {
      csrfToken = data._glpi_csrf_token;
    }
    return data;
  });

  activeRequestPromise = nextPromise.catch(() => {});
  return nextPromise;
};

/**
 * Perform a serialized POST request with FormData
 * @param {string} url 
 * @param {FormData} formData 
 * @param {Object} [requestOptions] 
 * @returns {Promise<Object>}
 */
export const postForm = async (url, formData, requestOptions = {}) => fetchJson(url, {
  method: "POST",
  body: formData,
  credentials: "same-origin",
  ...requestOptions,
});
