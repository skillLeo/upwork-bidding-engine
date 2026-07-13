import { ref } from "vue";
import { apiClient } from "@/lib/api-client";

export function useAnalytics() {
  const analytics = ref(null);
  const isLoading = ref(true);
  const error = ref(null);

  apiClient
    .get("/analytics")
    .then((res) => {
      analytics.value = res.data.data;
    })
    .catch((e) => {
      error.value = e;
    })
    .finally(() => {
      isLoading.value = false;
    });

  return { analytics, isLoading, error };
}
