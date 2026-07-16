<script setup>
import { ref, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Inbox,
  Search,
  SlidersHorizontal,
  RefreshCw,
  Loader2,
  ChevronUp,
  ChevronDown,
  ChevronsUpDown,
} from "@lucide/vue";
import { toast } from "vue-sonner";
import { useLeads } from "@/composables/useLeads";
import { useSavedFilters } from "@/composables/useSavedFilters";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import LeadRow from "@/components/leads/LeadRow.vue";
import LeadFiltersRail from "@/components/leads/LeadFiltersRail.vue";
import SavedFiltersBar from "@/components/leads/SavedFiltersBar.vue";
import DateRangeFilter from "@/components/leads/DateRangeFilter.vue";
import LeftRail from "@/components/layout/LeftRail.vue";
import PageContainer from "@/components/layout/PageContainer.vue";
import Input from "@/components/ui/Input.vue";
import Button from "@/components/ui/Button.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import LeadRowSkeleton from "@/components/ui/LeadRowSkeleton.vue";

const sortOptions = [
  { value: "-created_at", label: "Newest first" },
  { value: "-score", label: "Highest score" },
  { value: "-budget_max", label: "Highest budget" },
  { value: "-proposal_count", label: "Most proposals" },
  { value: "-connects_required", label: "Most connects required" },
  { value: "-posted_at", label: "Recently posted" },
  { value: "posted_at", label: "Oldest posted" },
];

// Column headers double as the second, more direct way to sort (click to
// sort, click again to flip direction) — the dropdown above stays as a
// handful of quick presets, both ends just drive the same `sortParam`.
const sortColumn = computed(() => sortParam.value.replace(/^-/, ""));
const sortDirection = computed(() => (sortParam.value.startsWith("-") ? "desc" : "asc"));

const columnLabels = {
  created_at: "Created date",
  score: "Score",
  budget_max: "Budget",
  proposal_count: "Proposals",
  connects_required: "Connects",
  posted_at: "Posted date",
};

// The dropdown only lists common presets, but a header click can land on a
// combination that isn't one of them (e.g. "Score, lowest first") — without
// this, the native <select> would just render blank since none of its
// <option> values would match sortParam.
const dropdownOptions = computed(() => {
  if (sortOptions.some((opt) => opt.value === sortParam.value)) return sortOptions;
  const label = `${columnLabels[sortColumn.value] ?? sortColumn.value}, ${
    sortDirection.value === "asc" ? "lowest first" : "highest first"
  }`;
  return [...sortOptions, { value: sortParam.value, label }];
});

function toggleSort(column) {
  if (sortColumn.value === column) {
    sortParam.value = sortDirection.value === "desc" ? column : `-${column}`;
  } else {
    // First click on a column sorts "most relevant first" — highest
    // score/budget/proposals/connects, most recently posted.
    sortParam.value = `-${column}`;
  }
}

function sortIcon(column) {
  if (sortColumn.value !== column) return ChevronsUpDown;
  return sortDirection.value === "desc" ? ChevronDown : ChevronUp;
}

const route = useRoute();
const router = useRouter();

const status = ref(route.query.status ?? "all");
const scoreMin = ref(route.query.score_min ? Number(route.query.score_min) : undefined);
const postedFrom = ref(null);
const postedTo = ref(null);
const searchInput = ref(route.query.search ?? "");
const search = ref(route.query.search ?? "");
let searchDebounce;
watch(searchInput, (value) => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    search.value = value;
  }, 350);
});

const page = ref(route.query.page ? Number(route.query.page) : 1);
const perPage = ref(route.query.per_page ? Number(route.query.per_page) : 50);
const perPageOptions = [25, 50, 100];
const sortParam = ref(route.query.sort ?? "-created_at");
const mobileFiltersOpen = ref(false);
const mobileFilterCount = computed(
  () => (status.value !== "all" ? 1 : 0) + (scoreMin.value ? 1 : 0),
);

const { filters: savedFilters } = useSavedFilters();
const activeFilterId = ref(route.query.filter ? Number(route.query.filter) : null);
// A filter id restored from the URL, or picked by the user, both count as
// "already decided" - only fall back to the saved-default filter when
// neither happened yet.
let hasAppliedDefault = activeFilterId.value !== null;

watch(
  savedFilters,
  (filters) => {
    if (hasAppliedDefault || filters.length === 0) return;
    const defaultFilter = filters.find((f) => f.is_default);
    if (defaultFilter) activeFilterId.value = defaultFilter.id;
    hasAppliedDefault = true;
  },
  { immediate: true },
);

const activeFilter = computed(
  () => savedFilters.value.find((f) => f.id === activeFilterId.value) ?? null,
);

function handleSelectFilter(filter) {
  activeFilterId.value = filter?.id ?? null;
  hasAppliedDefault = true;
}

watch([status, scoreMin, postedFrom, postedTo, search, sortParam, activeFilterId, perPage], () => {
  page.value = 1;
});

// Everything that defines "what you're looking at" round-trips through the
// URL - refresh, browser back/forward, and a pasted link all land on the
// exact same view instead of resetting to defaults. Posted-date range is
// deliberately excluded: DateRangeFilter always re-applies its own "last 3
// days" default on mount, so restoring it here would just get overwritten.
watch([status, scoreMin, search, sortParam, page, perPage, activeFilterId], () => {
  router.replace({
    path: route.path,
    query: {
      ...(status.value !== "all" && { status: status.value }),
      ...(scoreMin.value && { score_min: scoreMin.value }),
      ...(search.value && { search: search.value }),
      ...(sortParam.value !== "-created_at" && { sort: sortParam.value }),
      ...(page.value !== 1 && { page: page.value }),
      ...(perPage.value !== 50 && { per_page: perPage.value }),
      ...(activeFilterId.value != null && { filter: activeFilterId.value }),
    },
  });
});

const leadFilters = computed(() => ({
  status: status.value === "all" ? undefined : status.value,
  score_min: scoreMin.value,
  posted_from: postedFrom.value,
  posted_to: postedTo.value,
  search: search.value || undefined,
  sort: sortParam.value,
  page: page.value,
  per_page: perPage.value,
  include_keywords: activeFilter.value?.criteria.include_keywords,
  exclude_keywords: activeFilter.value?.criteria.exclude_keywords,
  budget_min: activeFilter.value?.criteria.budget_min,
  budget_max: activeFilter.value?.criteria.budget_max,
  payment_verified_only: activeFilter.value?.criteria.payment_verified_only,
  min_client_spend: activeFilter.value?.criteria.min_client_spend,
  posted_within_minutes: activeFilter.value?.criteria.posted_within_minutes,
}));

const { leads, meta, isLoading } = useLeads(leadFilters);

function clearFilters() {
  status.value = "all";
  scoreMin.value = undefined;
  searchInput.value = "";
  search.value = "";
  handleSelectFilter(null);
}

const syncing = ref(false);
async function handleSync() {
  syncing.value = true;
  try {
    const res = await apiClient.post("/leads/sync-vollna");
    // The job runs for 1-3 minutes in the background (Vollna's own rate
    // limit) - the page's existing 20s poll picks up results as they land,
    // no need to force an immediate refetch here.
    toast.success(res.data.data.message);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not start the sync."));
  } finally {
    syncing.value = false;
  }
}
</script>

<template>
  <PageContainer>
    <div class="flex items-start gap-4">
      <LeftRail>
        <LeadFiltersRail
          :status="status"
          @update:status="status = $event"
          :score-min="scoreMin"
          @update:score-min="scoreMin = $event"
        />
      </LeftRail>

      <div class="min-w-0 flex-1 space-y-3">
        <SavedFiltersBar :active-id="activeFilterId" @select="handleSelectFilter" />

        <div class="flex flex-col gap-2.5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 class="text-lg font-semibold text-text-primary">Leads</h1>
            <p class="text-xs text-text-secondary">
              {{ meta ? `${meta.total} lead${meta.total === 1 ? "" : "s"}` : "Loading…" }}
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              class="lg:hidden"
              @click="mobileFiltersOpen = !mobileFiltersOpen"
            >
              <SlidersHorizontal class="h-3.5 w-3.5" /> Filters
              <span
                v-if="mobileFilterCount > 0"
                class="rounded-pill bg-primary px-1.5 text-[10px] text-white"
              >
                {{ mobileFilterCount }}
              </span>
            </Button>

            <button
              type="button"
              @click="handleSync"
              :disabled="syncing"
              title="Sync leads from Vollna"
              aria-label="Sync leads from Vollna"
              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-border-strong text-text-secondary transition-colors hover:bg-black/5 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Loader2 v-if="syncing" class="h-4 w-4 animate-spin" />
              <RefreshCw v-else class="h-4 w-4" />
            </button>

            <DateRangeFilter
              :from="postedFrom"
              @update:from="postedFrom = $event"
              :to="postedTo"
              @update:to="postedTo = $event"
            />

            <div class="relative shrink-0">
              <Search class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-text-tertiary" />
              <Input
                v-model="searchInput"
                placeholder="Search leads…"
                class="h-9 w-40 pl-8 text-sm sm:w-52"
              />
            </div>

            <select
              v-model="sortParam"
              class="h-9 shrink-0 rounded-md border border-border-strong bg-white px-2.5 text-xs font-medium text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
              <option v-for="opt in dropdownOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
        </div>

        <div v-if="mobileFiltersOpen" class="lg:hidden">
          <LeadFiltersRail
            :status="status"
            @update:status="status = $event"
            :score-min="scoreMin"
            @update:score-min="scoreMin = $event"
          />
        </div>

        <div class="overflow-hidden rounded-md border border-border bg-surface">
          <EmptyState
            v-if="!isLoading && leads.length === 0"
            :icon="Inbox"
            title="No leads match these filters"
            description="Try widening your status or score filter, or clear your search."
          >
            <template #action>
              <Button variant="secondary" size="sm" @click="clearFilters">Clear filters</Button>
            </template>
          </EmptyState>

          <div v-else class="overflow-x-auto">
            <div
              class="leads-row-grid min-h-9 items-center border-b border-border bg-surface px-3 text-[11px] font-semibold tracking-wide text-text-tertiary uppercase"
            >
              <span aria-hidden="true" />
              <button type="button" class="flex min-w-0 items-center gap-0.5 truncate text-left hover:text-text-primary" @click="toggleSort('score')">
                <span class="truncate">Score</span>
                <component :is="sortIcon('score')" class="h-3 w-3 shrink-0" :class="sortColumn === 'score' ? 'text-primary' : 'text-text-tertiary/60'" />
              </button>
              <span class="truncate">Title</span>
              <button type="button" class="flex min-w-0 items-center justify-end gap-0.5 truncate hover:text-text-primary" @click="toggleSort('budget_max')">
                <component :is="sortIcon('budget_max')" class="h-3 w-3 shrink-0" :class="sortColumn === 'budget_max' ? 'text-primary' : 'text-text-tertiary/60'" />
                <span class="truncate">Budget</span>
              </button>
              <button type="button" class="flex min-w-0 items-center justify-end gap-0.5 truncate hover:text-text-primary" @click="toggleSort('proposal_count')">
                <component :is="sortIcon('proposal_count')" class="h-3 w-3 shrink-0" :class="sortColumn === 'proposal_count' ? 'text-primary' : 'text-text-tertiary/60'" />
                <span class="truncate">Proposals</span>
              </button>
              <button type="button" class="flex min-w-0 items-center justify-end gap-0.5 truncate hover:text-text-primary" title="Connects required" @click="toggleSort('connects_required')">
                <component :is="sortIcon('connects_required')" class="h-3 w-3 shrink-0" :class="sortColumn === 'connects_required' ? 'text-primary' : 'text-text-tertiary/60'" />
                <span class="truncate">Connects</span>
              </button>
              <button type="button" class="flex min-w-0 items-center gap-0.5 truncate text-left hover:text-text-primary" @click="toggleSort('posted_at')">
                <span class="truncate">Age</span>
                <component :is="sortIcon('posted_at')" class="h-3 w-3 shrink-0" :class="sortColumn === 'posted_at' ? 'text-primary' : 'text-text-tertiary/60'" />
              </button>
              <span class="truncate">Client</span>
              <span class="truncate">Status</span>
            </div>

            <template v-if="isLoading">
              <LeadRowSkeleton v-for="i in 20" :key="i" />
            </template>
            <template v-else>
              <LeadRow
                v-for="lead in leads"
                :key="lead.id"
                :lead="lead"
                :active-filter-id="activeFilterId"
                :match-keywords="activeFilter?.criteria.include_keywords ?? []"
              />
            </template>
          </div>
        </div>

        <div v-if="meta" class="flex items-center justify-between pt-1">
          <label class="flex items-center gap-2 text-xs text-text-tertiary">
            Rows per page
            <select
              v-model.number="perPage"
              class="h-7 rounded-md border border-border-strong bg-white px-2 text-xs font-medium text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
              <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </label>

          <div v-if="meta.last_page > 1" class="flex items-center gap-3">
            <Button
              variant="ghost"
              size="sm"
              :disabled="page <= 1"
              @click="page = Math.max(1, page - 1)"
            >
              Previous
            </Button>
            <span class="text-xs text-text-tertiary">
              Page {{ meta.current_page }} of {{ meta.last_page }}
            </span>
            <Button
              variant="ghost"
              size="sm"
              :disabled="page >= meta.last_page"
              @click="page = page + 1"
            >
              Next
            </Button>
          </div>
        </div>
      </div>
    </div>
  </PageContainer>
</template>
