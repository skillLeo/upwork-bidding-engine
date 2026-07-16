import { defineStore } from "pinia";
import { apiClient } from "@/lib/api-client";

const STORAGE_KEY = "skillleo-branding";
const DEFAULTS = { name: "SkillLeo", logoUrl: null };

function loadPersisted() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return { ...DEFAULTS };
    return { ...DEFAULTS, ...JSON.parse(raw) };
  } catch {
    return { ...DEFAULTS };
  }
}

export const useBrandingStore = defineStore("branding", {
  state: () => ({ ...loadPersisted() }),
  actions: {
    // Public endpoint (no auth) — safe to call before sign-in, and cheap
    // enough to call once per app boot. Failures just keep whatever was
    // persisted from last time (or the hardcoded default) — branding is
    // cosmetic, never worth surfacing an error for.
    async fetch() {
      try {
        const res = await apiClient.get("/branding");
        this.name = res.data.data.name;
        this.logoUrl = res.data.data.logo_url;
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ name: this.name, logoUrl: this.logoUrl }));
        document.title = `${this.name} Bidding Engine`;
      } catch {
        // keep current state
      }
    },
  },
});
