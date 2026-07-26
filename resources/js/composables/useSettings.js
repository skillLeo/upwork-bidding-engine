import { ref } from "vue";
import { toast } from "vue-sonner";
import { apiClient } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

// Stale-while-revalidate: settings change rarely, so after the first load
// re-opening the Settings page paints instantly from this module cache while
// a fresh copy loads silently in the background - no full-page skeleton on a
// high-latency connection.
let settingsCache = null;

export function useSettings() {
  const settings = ref(settingsCache);
  const isLoading = ref(settingsCache === null);
  const error = ref(null);

  async function fetchSettings({ silent = false } = {}) {
    if (!silent) isLoading.value = settings.value === null;
    try {
      const res = await apiClient.get("/settings");
      settings.value = res.data.data;
      settingsCache = res.data.data;
      error.value = null;
    } catch (e) {
      if (!silent) error.value = e;
    } finally {
      isLoading.value = false;
    }
  }

  // Revalidate in the background if we already have a cached copy.
  fetchSettings({ silent: settingsCache !== null });

  return { settings, isLoading, error, refetch: fetchSettings };
}

/**
 * Save settings, sending only the keys this user is actually allowed to
 * change — and saying plainly which ones were left out.
 *
 * WHY THE FILTER EXISTS. Each settings section posts every field it renders,
 * and the server refuses the WHOLE request with a 403 naming the first key
 * the caller doesn't hold. That refusal is right (a silent partial save that
 * looks successful is worse), but it turns one missing per-key permission
 * into a form that cannot be saved at all — the operator hit exactly this:
 * "You do not have permission to change [notify_score_min]" while editing
 * budget floors they were perfectly entitled to edit.
 *
 * Dropping the forbidden keys client-side keeps every field the user CAN
 * change working, and this stays correct as new settings keys ship: a role
 * that has not been granted a brand-new key loses that one field, not the
 * whole screen.
 *
 * Never silently: the caller is told what was skipped, because a field that
 * appears to save and doesn't is the failure this is meant to avoid.
 */
export async function saveSettings(payload) {
  const auth = useAuthStore();

  const allowed = {};
  const skipped = [];

  for (const [key, value] of Object.entries(payload)) {
    if (auth.can(`setting.${key}`)) {
      allowed[key] = value;
    } else {
      skipped.push(key);
    }
  }

  if (Object.keys(allowed).length === 0 && skipped.length > 0) {
    const error = new Error("no permitted keys");
    error.skippedSettings = skipped;
    throw error;
  }

  const res = await apiClient.post("/settings", allowed);

  if (skipped.length > 0) {
    toast.warning(
      `Saved, but ${skipped.length} field${skipped.length === 1 ? "" : "s"} could not be changed`,
      { description: `You don't have permission for: ${skipped.join(", ")}. Ask the workspace owner to grant these under Roles & permissions.` },
    );
  }

  return res.data.data;
}

export async function testConnection(service) {
  const res = await apiClient.post(`/settings/test/${service}`);
  return res.data.data;
}
