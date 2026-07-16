import { ref, watch, toValue } from "vue";
import { apiClient } from "@/lib/api-client";

export function buildQuery(filters) {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.score_min !== undefined) params.set("score_min", String(filters.score_min));
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.include_keywords?.length) {
    params.set("include_keywords", filters.include_keywords.join(","));
  }
  if (filters.exclude_keywords?.length) {
    params.set("exclude_keywords", filters.exclude_keywords.join(","));
  }
  if (filters.budget_min !== undefined) params.set("budget_min", String(filters.budget_min));
  if (filters.budget_max !== undefined) params.set("budget_max", String(filters.budget_max));
  if (filters.payment_verified_only) params.set("payment_verified_only", "1");
  if (filters.min_client_spend !== undefined) {
    params.set("min_client_spend", String(filters.min_client_spend));
  }
  if (filters.client_countries_include?.length) {
    params.set("client_countries_include", filters.client_countries_include.join(","));
  }
  if (filters.client_countries_exclude?.length) {
    params.set("client_countries_exclude", filters.client_countries_exclude.join(","));
  }
  if (filters.posted_within_minutes !== undefined) {
    params.set("posted_within_minutes", String(filters.posted_within_minutes));
  }
  if (filters.posted_from) params.set("posted_from", filters.posted_from);
  if (filters.posted_to) params.set("posted_to", filters.posted_to);
  return params.toString();
}

/**
 * `filtersRef` may be a plain object or a getter/ref - re-fetches whenever
 * its serialized query string actually changes (not on every render), same
 * intent as SWR's key-based caching in the Next.js version.
 */
export function useLeads(filtersRef) {
  const leads = ref([]);
  const meta = ref(null);
  const isLoading = ref(true);
  const error = ref(null);

  async function fetchLeads() {
    const filters = toValue(filtersRef);
    const query = buildQuery(filters);
    isLoading.value = true;
    try {
      const res = await apiClient.get(`/leads${query ? `?${query}` : ""}`);
      leads.value = res.data.data;
      meta.value = res.data.meta;
      error.value = null;
    } catch (e) {
      error.value = e;
    } finally {
      isLoading.value = false;
    }
  }

  watch(
    () => buildQuery(toValue(filtersRef)),
    () => fetchLeads(),
    { immediate: true },
  );

  return { leads, meta, isLoading, error, refetch: fetchLeads };
}
