<script setup>
import { computed } from "vue";
import { ShieldCheck, Star, Users } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import ScoreBadge from "@/components/ui/ScoreBadge.vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import { relativeTime } from "@/lib/utils";

const props = defineProps({
  lead: { type: Object, required: true },
  activeFilterId: { type: [Number, null], default: null },
});

const to = computed(() =>
  props.activeFilterId != null
    ? { path: `/leads/${props.lead.id}`, query: { filter: props.activeFilterId } }
    : { path: `/leads/${props.lead.id}` },
);
</script>

<template>
  <router-link :to="to" class="block">
    <Card class="cursor-pointer p-5 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-card-hover">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h3 class="line-clamp-1 font-semibold text-text-primary">{{ lead.title }}</h3>
            <span
              v-if="lead.matches_filter === false"
              class="shrink-0 rounded-pill bg-danger-bg px-2 py-0.5 text-[11px] font-semibold text-danger"
            >
              Not in filter
            </span>
          </div>
          <p class="mt-1 text-xs text-text-tertiary">
            Posted {{ relativeTime(lead.posted_at) }}
            <template v-if="lead.client_country"> · {{ lead.client_country }}</template>
          </p>
        </div>
        <ScoreBadge :score="lead.score" />
      </div>

      <p class="mt-3 line-clamp-2 text-sm text-text-secondary">{{ lead.full_brief }}</p>

      <div class="mt-4 flex flex-wrap items-center gap-2">
        <StatusPill :status="lead.status" />
        <span
          v-if="lead.budget"
          class="rounded-pill bg-neutral-bg px-2.5 py-0.5 text-xs font-medium text-neutral"
        >
          {{ lead.budget }}
        </span>
        <span class="flex items-center gap-1 rounded-pill bg-neutral-bg px-2.5 py-0.5 text-xs font-medium text-neutral">
          <Users class="h-3 w-3" /> {{ lead.proposal_count }} proposals
        </span>
        <span
          v-if="lead.payment_verified"
          class="flex items-center gap-1 rounded-pill bg-success-bg px-2.5 py-0.5 text-xs font-medium text-success"
        >
          <ShieldCheck class="h-3 w-3" /> Payment verified
        </span>
        <span
          v-if="lead.client_rating != null"
          class="flex items-center gap-1 rounded-pill bg-warning-bg px-2.5 py-0.5 text-xs font-medium text-warning"
        >
          <Star class="h-3 w-3 fill-current" /> {{ lead.client_rating }}
          <template v-if="lead.client_reviews != null"> ({{ lead.client_reviews }})</template>
        </span>
      </div>
    </Card>
  </router-link>
</template>
