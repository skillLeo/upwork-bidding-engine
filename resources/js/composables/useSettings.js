import { ref } from "vue";
import { apiClient } from "@/lib/api-client";

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

export async function saveSettings(payload) {
  const res = await apiClient.post("/settings", payload);
  return res.data.data;
}

export async function testConnection(service) {
  const res = await apiClient.post(`/settings/test/${service}`);
  return res.data.data;
}
