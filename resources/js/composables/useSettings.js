import { ref } from "vue";
import { apiClient } from "@/lib/api-client";

export function useSettings() {
  const settings = ref(null);
  const isLoading = ref(true);
  const error = ref(null);

  async function fetchSettings() {
    isLoading.value = true;
    try {
      const res = await apiClient.get("/settings");
      settings.value = res.data.data;
      error.value = null;
    } catch (e) {
      error.value = e;
    } finally {
      isLoading.value = false;
    }
  }

  fetchSettings();

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
