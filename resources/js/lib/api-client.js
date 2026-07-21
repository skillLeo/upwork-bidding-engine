import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import router from "@/router";

const baseURL = import.meta.env.VITE_API_URL ?? "/api";

export const apiClient = axios.create({
  baseURL,
  headers: {
    Accept: "application/json",
  },
});

apiClient.interceptors.request.use((config) => {
  const auth = useAuthStore();
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`;
  }
  return config;
});

// Only ONE token-verification is ever in flight, so a burst of 401s (e.g. the
// leads list + the background alert poll failing together) triggers a single
// /me check, not a stampede.
let verifying = null;

function endSession() {
  const auth = useAuthStore();
  const current = router.currentRoute.value;
  auth.logout();
  if (current.name !== "login") {
    // Carry where they were so signing back in returns them to it.
    router.push({ name: "login", query: { redirect: current.fullPath } });
  }
}

apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status;
    const url = error.config?.url ?? "";
    const auth = useAuthStore();
    const isAuthCall = url.includes("/auth/") || url.endsWith("/me");

    // A 401 no longer nukes the session outright. Tokens never expire
    // server-side, so a lone 401 is almost always transient (a blip on a
    // background poll). Confirm the token is genuinely dead with one /me probe
    // (raw axios, so it can't recurse through this interceptor) before logging
    // out. An auth call 401ing IS conclusive, so it logs out immediately.
    if (status === 401 && auth.token && !isAuthCall) {
      try {
        verifying ??= axios.get(`${baseURL}/me`, {
          headers: { Authorization: `Bearer ${auth.token}`, Accept: "application/json" },
        });
        await verifying;
        verifying = null;
        // Token still valid — this 401 was transient, swallow the logout.
      } catch {
        verifying = null;
        endSession();
      }
    } else if (status === 401 && auth.token && isAuthCall) {
      endSession();
    }

    return Promise.reject(error);
  },
);

export function apiErrorMessage(error, fallback = "Something went wrong.") {
  const body = error?.response?.data;
  if (body?.errors) {
    const first = Object.values(body.errors)[0]?.[0];
    if (first) return first;
  }
  if (body?.message) return body.message;
  return fallback;
}

export const fetcher = (url) => apiClient.get(url).then((res) => res.data);
