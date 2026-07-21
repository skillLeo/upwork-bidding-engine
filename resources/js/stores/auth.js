import { defineStore } from "pinia";

const STORAGE_KEY = "skillleo-auth";
const LAST_ROUTE_KEY = "skillleo-last-route";

// "Remember me" decides WHERE the session lives: localStorage survives a
// browser restart, sessionStorage is cleared when the tab/window closes.
// Either way it survives a page refresh, so a refresh never logs you out.
function loadPersisted() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY) ?? sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return { token: null, user: null, remember: true };
    const parsed = JSON.parse(raw);
    return { token: parsed.token ?? null, user: parsed.user ?? null, remember: parsed.remember ?? true };
  } catch {
    return { token: null, user: null, remember: true };
  }
}

function persist(token, user, remember) {
  const payload = JSON.stringify({ token, user, remember });
  // Write to the chosen store and clear the other so there's exactly one copy.
  (remember ? sessionStorage : localStorage).removeItem(STORAGE_KEY);
  (remember ? localStorage : sessionStorage).setItem(STORAGE_KEY, payload);
}

export const useAuthStore = defineStore("auth", {
  state: () => ({
    ...loadPersisted(),
    hasHydrated: true,
  }),
  getters: {
    isAdmin: (state) => state.user?.role === "admin",
  },
  actions: {
    setAuth(token, user, remember = true) {
      this.token = token;
      this.user = user;
      this.remember = remember;
      persist(token, user, remember);
    },
    setUser(user) {
      this.user = user;
      persist(this.token, user, this.remember);
    },
    logout() {
      this.token = null;
      this.user = null;
      localStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem(STORAGE_KEY);
    },
    // Remember the last real page so a later sign-in can return the user
    // exactly where they left off, whether they were logged out or left.
    rememberRoute(fullPath) {
      if (fullPath && !fullPath.startsWith("/login")) {
        localStorage.setItem(LAST_ROUTE_KEY, fullPath);
      }
    },
    takeLastRoute() {
      return localStorage.getItem(LAST_ROUTE_KEY) || "/leads";
    },
  },
});
