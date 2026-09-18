const loginView = document.querySelector("#login-view");
const appView = document.querySelector("#app-view");
const logoutButton = document.querySelector("#logout-button");
const loginForm = document.querySelector("#login-form");
const passwordInput = document.querySelector("#password");
const loginError = document.querySelector("#login-error");
const cleanForm = document.querySelector("#clean-form");
const urlInput = document.querySelector("#url-input");
const cleanButton = document.querySelector("#clean-button");
const cleanError = document.querySelector("#clean-error");
const resultPanel = document.querySelector("#result-panel");
const cleanUrlElement = document.querySelector("#clean-url");
const originalUrlElement = document.querySelector("#original-url");
const finalUrlElement = document.querySelector("#final-url");
const redirectCount = document.querySelector("#redirect-count");
const copyButton = document.querySelector("#copy-button");
const openButton = document.querySelector("#open-button");

let currentCleanUrl = "";

function setAuthenticated(authenticated) {
  loginView.classList.toggle("hidden", authenticated);
  appView.classList.toggle("hidden", !authenticated);
  logoutButton.classList.toggle("hidden", !authenticated);
  if (authenticated) queueMicrotask(() => urlInput.focus());
  else queueMicrotask(() => passwordInput.focus());
}

async function api(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(body.message || "Something went wrong.");
    error.status = response.status;
    throw error;
  }
  return body;
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  loginError.textContent = "";
  const submitButton = loginForm.querySelector("button");
  submitButton.disabled = true;
  try {
    await api("/api/login", { method: "POST", body: JSON.stringify({ password: passwordInput.value }) });
    passwordInput.value = "";
    setAuthenticated(true);
  } catch (error) {
    loginError.textContent = error.message;
    passwordInput.select();
  } finally {
    submitButton.disabled = false;
  }
});

logoutButton.addEventListener("click", async () => {
  await api("/api/logout", { method: "POST" }).catch(() => {});
  resultPanel.classList.add("hidden");
  setAuthenticated(false);
});

async function cleanLink(url) {
  cleanError.textContent = "";
  resultPanel.classList.add("hidden");
  cleanButton.disabled = true;
  cleanButton.classList.add("is-loading");

  try {
    const result = await api("/api/clean", {
      method: "POST",
      body: JSON.stringify({ url }),
    });
    currentCleanUrl = result.cleanUrl;
    cleanUrlElement.textContent = result.cleanUrl;
    cleanUrlElement.href = result.cleanUrl;
    originalUrlElement.textContent = result.originalUrl;
    finalUrlElement.textContent = result.finalUrl;
    openButton.href = result.cleanUrl;
    redirectCount.textContent = `${result.redirectCount} ${result.redirectCount === 1 ? "redirect" : "redirects"}`;
    copyButton.querySelector("span").textContent = "Copy";
    resultPanel.classList.remove("hidden");
    resultPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
    return result;
  } catch (error) {
    if (error.status === 401) setAuthenticated(false);
    cleanError.textContent = error.message;
    throw error;
  } finally {
    cleanButton.disabled = false;
    cleanButton.classList.remove("is-loading");
  }
}

cleanForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  await cleanLink(urlInput.value.trim()).catch(() => {});
});

copyButton.addEventListener("click", async () => {
  try {
    await navigator.clipboard.writeText(currentCleanUrl);
    copyButton.querySelector("span").textContent = "Copied";
    setTimeout(() => { copyButton.querySelector("span").textContent = "Copy"; }, 1600);
  } catch {
    cleanError.textContent = "Could not copy automatically. Select the clean URL instead.";
  }
});

api("/api/session")
  .then((session) => setAuthenticated(Boolean(session.authenticated)))
  .catch(() => setAuthenticated(false));
