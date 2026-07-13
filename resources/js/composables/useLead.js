import { ref, watch, toValue } from "vue";
import { apiClient } from "@/lib/api-client";
import { buildQuery } from "@/composables/useLeads";

export function useLead(idRef, criteriaRef) {
  const lead = ref(null);
  const isLoading = ref(true);
  const error = ref(null);

  async function fetchLead() {
    const id = toValue(idRef);
    if (!id) {
      lead.value = null;
      isLoading.value = false;
      return;
    }
    const criteria = toValue(criteriaRef);
    const query = criteria ? buildQuery(criteria) : "";
    isLoading.value = true;
    try {
      const res = await apiClient.get(`/leads/${id}${query ? `?${query}` : ""}`);
      lead.value = res.data.data;
      error.value = null;
    } catch (e) {
      error.value = e;
    } finally {
      isLoading.value = false;
    }
  }

  watch([() => toValue(idRef), () => toValue(criteriaRef)], fetchLead, { immediate: true });

  return { lead, isLoading, error, refetch: fetchLead };
}

export async function updateLeadStatus(id, status) {
  const res = await apiClient.post(`/leads/${id}/status`, { status });
  return res.data.data;
}

export async function rescoreLead(id) {
  const res = await apiClient.post(`/leads/${id}/rescore`);
  return res.data.data;
}
