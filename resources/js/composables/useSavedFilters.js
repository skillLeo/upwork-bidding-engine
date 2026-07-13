import { ref } from "vue";
import { apiClient } from "@/lib/api-client";

export function useSavedFilters() {
  const filters = ref([]);
  const isLoading = ref(true);
  const error = ref(null);

  async function fetchFilters() {
    isLoading.value = true;
    try {
      const res = await apiClient.get("/saved-filters");
      filters.value = res.data.data;
      error.value = null;
    } catch (e) {
      error.value = e;
    } finally {
      isLoading.value = false;
    }
  }

  fetchFilters();

  return { filters, isLoading, error, refetch: fetchFilters };
}

export async function createSavedFilter(input) {
  const res = await apiClient.post("/saved-filters", input);
  return res.data.data;
}

export async function updateSavedFilter(id, input) {
  const res = await apiClient.put(`/saved-filters/${id}`, input);
  return res.data.data;
}

export async function deleteSavedFilter(id) {
  await apiClient.delete(`/saved-filters/${id}`);
}
