import { ref, watch, toValue, onMounted, onUnmounted } from "vue";
import { apiClient } from "@/lib/api-client";

// Leads score themselves in real time server-side (the Vollna webhook
// scores inline), so the only thing missing is the browser noticing - a
// lightweight poll is far simpler than standing up websockets for a
// single-bidder tool, and is quiet (no loading flicker) since it's a
// background refresh, not a user-initiated fetch.
const POLL_INTERVAL_MS = 20_000;

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

// Module-scoped stale-while-revalidate cache, keyed by query string. The app
// server responds in ~40ms; the felt slowness is network round-trip to the
// datacenter, so the highest-impact win is to NOT block navigation on it -
// show the last results for this exact query instantly, then revalidate
// silently in the background.
const leadsCache = new Map();

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

  async function fetchLeads({ silent = false } = {}) {
    const query = buildQuery(toValue(filtersRef));
    if (!silent) isLoading.value = true;
    try {
      const res = await apiClient.get(`/leads${query ? `?${query}` : ""}`);
      leads.value = res.data.data;
      meta.value = res.data.meta;
      leadsCache.set(query, { data: res.data.data, meta: res.data.meta });
      error.value = null;
    } catch (e) {
      // A background poll failing silently is fine - the next tick retries.
      if (!silent) error.value = e;
    } finally {
      if (!silent) isLoading.value = false;
    }
  }

  watch(
    () => buildQuery(toValue(filtersRef)),
    (query) => {
      const cached = leadsCache.get(query);
      if (cached) {
        // Instant paint from cache, then revalidate without a loading flicker.
        leads.value = cached.data;
        meta.value = cached.meta;
        isLoading.value = false;
        fetchLeads({ silent: true });
      } else {
        fetchLeads();
      }
    },
    { immediate: true },
  );

  let pollTimer;
  onMounted(() => {
    pollTimer = setInterval(() => fetchLeads({ silent: true }), POLL_INTERVAL_MS);
  });
  onUnmounted(() => clearInterval(pollTimer));

  return { leads, meta, isLoading, error, refetch: fetchLeads };
}
