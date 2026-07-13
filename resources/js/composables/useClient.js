import { ref, watch, toValue } from "vue";
import { apiClient } from "@/lib/api-client";

export function useClient(idRef) {
  const client = ref(null);
  const isLoading = ref(true);
  const error = ref(null);

  async function fetchClient() {
    const id = toValue(idRef);
    if (!id) {
      client.value = null;
      isLoading.value = false;
      return;
    }
    isLoading.value = true;
    try {
      const res = await apiClient.get(`/clients/${id}`);
      client.value = res.data.data;
      error.value = null;
    } catch (e) {
      error.value = e;
    } finally {
      isLoading.value = false;
    }
  }

  watch(() => toValue(idRef), fetchClient, { immediate: true });

  return { client, isLoading, error, refetch: fetchClient };
}

export async function draftReply(clientId, message) {
  const res = await apiClient.post(`/clients/${clientId}/draft-reply`, { message });
  return res.data.data;
}
