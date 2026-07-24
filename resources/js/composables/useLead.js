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

// Tri-state, and null is a real value meaning "not recorded" - passing it
// clears the field rather than being a no-op.
export async function updateLeadClientView(id, clientView) {
  const res = await apiClient.post(`/leads/${id}/client-view`, { client_view: clientView });
  return res.data.data;
}

export async function updateLeadOutcome(id, outcome) {
  const res = await apiClient.post(`/leads/${id}/outcome`, { outcome });
  return res.data.data;
}

// Upwork shows this per proposal but exposes no API for it, so it is typed
// in by hand. "" (null server-side) stays distinct from "not_viewed".
export const CLIENT_VIEW_STATES = [
  { value: "not_viewed", label: "Not opened" },
  { value: "viewed", label: "Opened" },
  { value: "shortlisted", label: "Shortlisted" },
];

// WHY a lead ended. `status` owns HOW (won / lost / archived), so nothing
// here repeats a status word. The two sets never mix: a Lost lead can only
// take a post-bid reason, an Archived one only a pre-bid reason. The server
// enforces the same split, so a mismatched pair is a 422, not a silent
// contradiction.
export const LOST_REASONS = [
  { value: "no_response", label: "No response" },
  { value: "hired_other", label: "Client hired someone else" },
  { value: "closed_no_hire", label: "Job closed, nobody hired" },
  { value: "expired", label: "Posting expired" },
  { value: "unknown", label: "Never found out" },
];

// "auto_filtered" is absent on purpose — the engine writes it when a lead
// fails the hard filters or the score cutoff. Offering it would let a
// deliberate skip be mislabelled as an automatic one.
export const ARCHIVE_REASONS = [
  { value: "not_relevant", label: "Not relevant, skipped it" },
  { value: "no_connects", label: "No Connects available" },
  { value: "too_late", label: "Too late / too many proposals" },
];

// Read-only label for a reason the engine set itself.
export const ENGINE_REASON_LABEL = "Filtered out by the engine";

export function reasonsForStatus(status) {
  if (status === "lost") return LOST_REASONS;
  if (status === "archived") return ARCHIVE_REASONS;
  return [];
}

export function reasonLabel(value) {
  if (value === "auto_filtered") return ENGINE_REASON_LABEL;
  return [...LOST_REASONS, ...ARCHIVE_REASONS].find((r) => r.value === value)?.label ?? value;
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

// Preview-only: the server returns { old_text, new_text, edit_type,
// linter_violations, model, cost } and persists nothing until accept.
export async function aiEditProposal(id, payload) {
  const res = await apiClient.post(`/leads/${id}/proposal/ai-edit`, payload, { timeout: 120000 });
  return res.data.data;
}

export async function acceptAiEditProposal(id, payload) {
  const res = await apiClient.post(`/leads/${id}/proposal/ai-edit/accept`, payload);
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
