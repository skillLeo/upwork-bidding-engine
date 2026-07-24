import { defineStore } from "pinia";
import { apiClient } from "@/lib/api-client";

const STORAGE_KEY = "skillleo-branding";
// signupMode/googleEnabled default to the safe closed-ish state until the
// public /branding call fills them in, so a sign-up screen never flashes an
// open form it shouldn't.
const DEFAULTS = { name: "SkillLeo", logoUrl: null, signupMode: "invite_code", googleEnabled: false };

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
        const d = res.data.data;
        this.name = d.name;
        this.logoUrl = d.logo_url;
        // signup_mode / google_enabled are optional public hints the auth
        // screens read; fall back to the current value if an older API omits them.
        this.signupMode = d.signup_mode ?? this.signupMode;
        this.googleEnabled = d.google_enabled ?? this.googleEnabled;
        localStorage.setItem(
          STORAGE_KEY,
          JSON.stringify({ name: this.name, logoUrl: this.logoUrl }),
        );
        document.title = `${this.name} Bidding Engine`;
      } catch {
        // keep current state
      }
    },
  },
});
