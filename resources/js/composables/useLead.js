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

export async function toggleLeadFavorite(id) {
  const res = await apiClient.post(`/leads/${id}/favorite`);
  return res.data.data;
}

export async function regenerateLeadScore(id) {
  // Synchronous rubric re-score (~3-5s) — generous timeout for cold runs.
  const res = await apiClient.post(`/leads/${id}/regenerate-score`, null, { timeout: 60000 });
  return res.data.data;
}

export async function regenerateLeadProposal(id) {
  // Draft + AI rule-review + up to 2 auto-revisions runs ~30-70s server-side.
  const res = await apiClient.post(`/leads/${id}/regenerate-proposal`, null, { timeout: 150000 });
  return res.data.data;
}

// Manual, by-hand edit. The server appends an immutable version and re-runs the
// linter, so the returned lead carries fresh proposal_warnings.
export async function saveLeadProposal(id, text) {
  const res = await apiClient.put(`/leads/${id}/proposal`, { proposal_text: text });
  return res.data.data;
}

export async function fetchProposalVersions(id) {
  const res = await apiClient.get(`/leads/${id}/proposal-versions`);
  return res.data.data;
}

export async function bulkUpdateLeadStatus(ids, status) {
  const res = await apiClient.post("/leads/bulk-status", { ids, status });
  return res.data.data;
}

export async function bulkToggleLeadFavorite(ids, isFavorite) {
  const res = await apiClient.post("/leads/bulk-favorite", { ids, is_favorite: isFavorite });
  return res.data.data;
}
