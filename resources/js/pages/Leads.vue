<script setup>
import { ref, computed, watch } from "vue";
import { Inbox, Search, Sparkles, TrendingUp } from "@lucide/vue";
import { useLeads } from "@/composables/useLeads";
import { useSavedFilters } from "@/composables/useSavedFilters";
import LeadCard from "@/components/leads/LeadCard.vue";
import LeadFiltersRail from "@/components/leads/LeadFiltersRail.vue";
import SavedFiltersBar from "@/components/leads/SavedFiltersBar.vue";
import LeftRail from "@/components/layout/LeftRail.vue";
import RightRail from "@/components/layout/RightRail.vue";
import PageContainer from "@/components/layout/PageContainer.vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import Button from "@/components/ui/Button.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import LeadCardSkeleton from "@/components/ui/LeadCardSkeleton.vue";

const TIPS = [
  "Proposals sent within the first hour get read far more often — check Ready leads often.",
  "Mention one specific detail from the brief in your opener; generic proposals get skipped.",
  "A short, confident proposal beats a long one. Aim for four tight paragraphs.",
  "If the client's hire rate is 0%, ask a clarifying question before committing to scope.",
  'Always end with one clear, low-friction next step — a question, not just "let me know".',
  "Archived isn't wasted — check score_reason to see if a rule needs tuning in Settings.",
];
const tipOfTheDay = TIPS[new Date().getDate() % TIPS.length];

const sortOptions = [
  { value: "-created_at", label: "Newest first" },
  { value: "-score", label: "Highest score" },
  { value: "-proposal_count", label: "Most proposals" },
  { value: "posted_at", label: "Oldest posted" },
];

const status = ref("all");
const scoreMin = ref(undefined);
const searchInput = ref("");
const search = ref("");
let searchDebounce;
watch(searchInput, (value) => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    search.value = value;
  }, 350);
});

const page = ref(1);
const sortParam = ref("-created_at");

const { filters: savedFilters } = useSavedFilters();
const activeFilterId = ref(null);
let hasAppliedDefault = false;

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

watch([status, scoreMin, search, sortParam, activeFilterId], () => {
  page.value = 1;
});

const leadFilters = computed(() => ({
  status: status.value === "all" ? undefined : status.value,
  score_min: scoreMin.value,
  search: search.value || undefined,
  sort: sortParam.value,
  page: page.value,
  per_page: 12,
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

      <div class="min-w-0 flex-1 space-y-4">
        <SavedFiltersBar :active-id="activeFilterId" @select="handleSelectFilter" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-xl font-semibold text-text-primary">Leads</h1>
            <p class="text-sm text-text-secondary">
              {{ meta ? `${meta.total} lead${meta.total === 1 ? "" : "s"}` : "Loading…" }}
            </p>
          </div>
          <div class="flex gap-2">
            <div class="relative">
              <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-text-tertiary" />
              <Input
                v-model="searchInput"
                placeholder="Search leads…"
                class="w-full pl-9 sm:w-56"
              />
            </div>
            <select
              v-model="sortParam"
              class="h-10 rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
              <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
        </div>

        <div v-if="isLoading" class="space-y-3">
          <LeadCardSkeleton v-for="i in 5" :key="i" />
        </div>
        <EmptyState
          v-else-if="leads.length === 0"
          :icon="Inbox"
          title="No leads match these filters"
          description="Try widening your status or score filter, or clear your search."
        >
          <template #action>
            <Button variant="secondary" @click="clearFilters">Clear filters</Button>
          </template>
        </EmptyState>
        <div v-else class="space-y-3">
          <LeadCard
            v-for="lead in leads"
            :key="lead.id"
            :lead="lead"
            :active-filter-id="activeFilterId"
          />
        </div>

        <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between pt-2">
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

      <RightRail>
        <Card class="p-4">
          <p class="flex items-center gap-1.5 text-sm font-semibold text-text-primary">
            <TrendingUp class="h-4 w-4 text-primary" /> Quick stats
          </p>
          <div class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-text-secondary">Matching filters</span>
              <span class="font-medium text-text-primary">{{ meta?.total ?? "—" }}</span>
            </div>
          </div>
        </Card>
        <Card class="p-4">
          <p class="flex items-center gap-1.5 text-sm font-semibold text-text-primary">
            <Sparkles class="h-4 w-4 text-primary" /> Tip of the day
          </p>
          <p class="mt-2 text-sm text-text-secondary">{{ tipOfTheDay }}</p>
        </Card>
      </RightRail>
    </div>
  </PageContainer>
</template>
