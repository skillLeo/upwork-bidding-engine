<script setup>
import { ref, computed } from "vue";
import { toast } from "vue-sonner";
import { Bookmark, ChevronRight, ExternalLink, Loader2, ShieldCheck, Star } from "@lucide/vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import Button from "@/components/ui/Button.vue";
import { toggleLeadFavorite, updateLeadStatus } from "@/composables/useLead";
import { apiErrorMessage } from "@/lib/api-client";
import { cn, scoreTier, compactAge } from "@/lib/utils";

const props = defineProps({
  lead: { type: Object, required: true },
  activeFilterId: { type: [Number, null], default: null },
  // Skills that match the active saved filter's keywords get highlighted,
  // same idea as Vollna bolding a matched keyword in a job title.
  matchKeywords: { type: Array, default: () => [] },
});

// Local to this row — nothing coordinates it with siblings, so any number
// of rows can be previewed open at once, same as Vollna's list.
const expanded = ref(false);
const favoriting = ref(false);
const statusLoading = ref(null);

const to = computed(() =>
  props.activeFilterId != null
    ? { path: `/leads/${props.lead.id}`, query: { filter: props.activeFilterId } }
    : { path: `/leads/${props.lead.id}` },
);

const tier = computed(() => scoreTier(props.lead.score));
const age = computed(() => compactAge(props.lead.posted_at));

const railBorderClass = computed(
  () =>
    ({ success: "border-success", info: "border-info", neutral: "border-neutral" })[tier.value],
);
const scoreClass = computed(
  () => ({ success: "text-success", info: "text-info", neutral: "text-text-tertiary" })[tier.value],
);
const ageClass = computed(() =>
  cn(
    age.value.tier === "fresh" && "font-semibold text-text-primary",
    age.value.tier === "normal" && "text-text-secondary",
    age.value.tier === "stale" && "text-text-tertiary",
  ),
);

function isMatchedSkill(skill) {
  return props.matchKeywords.some((kw) => skill.toLowerCase().includes(kw.toLowerCase()));
}

async function handleToggleFavorite() {
  favoriting.value = true;
  try {
    const updated = await toggleLeadFavorite(props.lead.id);
    props.lead.is_favorite = updated.is_favorite;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update favorite."));
  } finally {
    favoriting.value = false;
  }
}

const quickActions = [
  { status: "sent", label: "Sent" },
  { status: "replied", label: "Replied" },
  { status: "won", label: "Won" },
  { status: "archived", label: "Not interested" },
];

async function handleQuickStatus(status) {
  statusLoading.value = status;
  try {
    const updated = await updateLeadStatus(props.lead.id, status);
    props.lead.status = updated.status;
    toast.success(`Lead marked ${status}.`);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update status."));
  } finally {
    statusLoading.value = null;
  }
}
</script>

<template>
  <div
    :class="
      cn(
        'leads-row-grid min-h-9 items-center border-b border-border text-sm text-text-primary',
        lead.matches_filter === false && 'opacity-60',
        expanded && 'bg-neutral-bg/40',
      )
    "
  >
    <button
      type="button"
      @click="expanded = !expanded"
      :aria-expanded="expanded"
      :aria-label="expanded ? 'Hide quick preview' : 'Show quick preview'"
      :class="cn('relative flex h-full items-center justify-center border-l-[3px] transition-colors hover:bg-black/5', railBorderClass)"
    >
      <ChevronRight
        class="h-3.5 w-3.5 shrink-0 text-text-tertiary transition-transform"
        :class="expanded && 'rotate-90 text-primary'"
      />
      <Bookmark
        v-if="lead.is_favorite"
        class="absolute top-1 right-1 h-2.5 w-2.5 fill-current text-primary"
        aria-hidden="true"
      />
    </button>

    <router-link :to="to" class="contents">
      <span class="font-mono text-sm font-bold tabular-nums" :class="scoreClass">
        <span class="sr-only">Score </span>{{ lead.score ?? "–" }}
      </span>

      <span class="flex min-w-0 items-center gap-1.5 py-2">
        <span class="truncate font-medium">{{ lead.title }}</span>
        <span
          v-if="lead.matches_filter === false"
          class="shrink-0 rounded-pill bg-danger-bg px-1.5 py-0 text-[10px] font-semibold whitespace-nowrap text-danger"
        >
          Not in filter
        </span>
      </span>

      <span
        class="truncate text-right font-mono text-xs text-text-secondary"
        :title="lead.budget || undefined"
      >
        {{ lead.budget || "—" }}
      </span>

      <span class="text-right font-mono text-sm font-semibold tabular-nums">
        {{ lead.proposal_count }}<span class="sr-only"> proposals</span>
      </span>

      <span class="text-right font-mono text-xs text-text-secondary">
        {{ lead.connects_required ?? "—" }}<span class="sr-only"> connects required</span>
      </span>

      <span class="font-mono text-xs" :class="ageClass" :title="age.title">
        <span class="sr-only">Posted </span>{{ age.label }}
      </span>

      <span class="flex min-w-0 items-center gap-1 truncate text-xs text-text-secondary">
        <ShieldCheck
          v-if="lead.payment_verified"
          class="h-3 w-3 shrink-0 text-success"
          title="Payment verified"
        />
        <span class="truncate">{{ lead.client_country || "—" }}</span>
      </span>

      <span class="flex items-center overflow-hidden">
        <StatusPill :status="lead.status" />
      </span>
    </router-link>
  </div>

  <div
    v-if="expanded"
    :class="cn('space-y-3 border-b border-border bg-neutral-bg/40 px-4 py-3 pl-[31px] text-sm', 'border-l-[3px]', railBorderClass)"
  >
    <div v-if="lead.skills?.length" class="flex flex-wrap items-center gap-1.5">
      <span
        v-for="skill in lead.skills"
        :key="skill"
        :class="
          cn(
            'rounded-pill border px-2.5 py-0.5 text-xs font-medium',
            isMatchedSkill(skill)
              ? 'border-warning-border bg-warning-bg text-warning'
              : 'border-border-strong text-text-secondary',
          )
        "
      >
        {{ skill }}
      </span>
    </div>

    <p v-if="lead.score_reason" class="text-text-secondary">
      <span class="font-semibold text-text-primary">Why this score —</span>
      {{ lead.score_reason }}
    </p>

    <p v-if="lead.full_brief" class="line-clamp-3 text-text-secondary">
      {{ lead.full_brief }}
    </p>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-text-secondary">
      <span v-if="lead.client_rating != null" class="flex items-center gap-1">
        <Star class="h-3 w-3 fill-current text-warning" />
        {{ lead.client_rating }}
        <template v-if="lead.client_reviews != null">({{ lead.client_reviews }} reviews)</template>
      </span>
      <span v-if="lead.client_hire_rate">Hire rate {{ lead.client_hire_rate }}</span>
      <span v-if="lead.client_spend">Client spend {{ lead.client_spend }}</span>
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-0.5">
      <button
        type="button"
        @click="handleToggleFavorite"
        :disabled="favoriting"
        :class="
          cn(
            'flex items-center gap-1.5 rounded-pill border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50',
            lead.is_favorite
              ? 'border-primary bg-primary-tint text-primary'
              : 'border-border-strong text-text-secondary hover:bg-black/5',
          )
        "
      >
        <Loader2 v-if="favoriting" class="h-3 w-3 animate-spin" />
        <Bookmark v-else class="h-3 w-3" :class="lead.is_favorite && 'fill-current'" />
        {{ lead.is_favorite ? "Saved" : "Save" }}
      </button>

      <Button
        v-for="action in quickActions"
        :key="action.status"
        variant="ghost"
        size="sm"
        :disabled="lead.status === action.status || statusLoading !== null"
        :loading="statusLoading === action.status"
        @click="handleQuickStatus(action.status)"
      >
        Mark {{ action.label }}
      </Button>
    </div>

    <div class="flex items-center gap-4 pt-0.5 text-xs font-medium">
      <a
        v-if="lead.url"
        :href="lead.url"
        target="_blank"
        rel="noopener noreferrer"
        class="flex items-center gap-1 text-text-secondary hover:text-text-primary"
      >
        Open on Upwork <ExternalLink class="h-3 w-3" />
      </a>
      <router-link :to="to" class="flex items-center gap-1 text-primary hover:underline">
        View full details <ChevronRight class="h-3 w-3" />
      </router-link>
    </div>
  </div>
</template>
