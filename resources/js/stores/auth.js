import { defineStore } from "pinia";

const STORAGE_KEY = "skillleo-auth";

function loadPersisted() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return { token: null, user: null };
    const parsed = JSON.parse(raw);
    return { token: parsed.token ?? null, user: parsed.user ?? null };
  } catch {
    return { token: null, user: null };
  }
}

function persist(token, user) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify({ token, user }));
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
    setAuth(token, user) {
      this.token = token;
      this.user = user;
      persist(token, user);
    },
    setUser(user) {
      this.user = user;
      persist(this.token, user);
    },
    logout() {
      this.token = null;
      this.user = null;
      localStorage.removeItem(STORAGE_KEY);
    },
  },
});
