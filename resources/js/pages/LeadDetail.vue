<script setup>
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import {
  AlertTriangle,
  ArrowLeft,
  Copy,
  ExternalLink,
  Globe2,
  MessageSquare,
  RefreshCw,
  ShieldCheck,
  ShieldOff,
  Star,
  Users,
  Wallet,
} from "@lucide/vue";
import { useLead, updateLeadStatus, rescoreLead } from "@/composables/useLead";
import { useSavedFilters } from "@/composables/useSavedFilters";
import PageContainer from "@/components/layout/PageContainer.vue";
import Card from "@/components/ui/Card.vue";
import Button from "@/components/ui/Button.vue";
import ScoreBadge from "@/components/ui/ScoreBadge.vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SkeletonText from "@/components/ui/SkeletonText.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import { relativeTime } from "@/lib/utils";
import { apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const statusActions = [
  { status: "sent", label: "Mark Sent" },
  { status: "replied", label: "Mark Replied" },
  { status: "won", label: "Mark Won" },
  { status: "archived", label: "Archive" },
];

const filterId = computed(() => route.query.filter ?? null);
const { filters: savedFilters } = useSavedFilters();
const activeFilter = computed(() =>
  filterId.value ? savedFilters.value.find((f) => f.id === Number(filterId.value)) : undefined,
);

const { lead, isLoading, refetch } = useLead(
  () => route.params.id,
  () => activeFilter.value?.criteria,
);
const actionLoading = ref(null);

const statBlocks = computed(() => {
  if (!lead.value) return [];
  return [
    { icon: Wallet, label: "Budget", value: lead.value.budget ?? "—" },
    { icon: Globe2, label: "Client country", value: lead.value.client_country ?? "—" },
    { icon: Users, label: "Proposals so far", value: String(lead.value.proposal_count) },
    { icon: Wallet, label: "Client spend", value: lead.value.client_spend ?? "—" },
    { icon: Users, label: "Hire rate", value: lead.value.client_hire_rate ?? "—" },
    {
      icon: lead.value.payment_verified ? ShieldCheck : ShieldOff,
      label: "Payment",
      value: lead.value.payment_verified ? "Verified" : "Unverified",
      tone: lead.value.payment_verified ? "success" : "neutral",
    },
    {
      icon: Star,
      label: "Client rating",
      value:
        lead.value.client_rating != null
          ? `${lead.value.client_rating}${lead.value.client_reviews != null ? ` (${lead.value.client_reviews} reviews)` : ""}`
          : "—",
    },
  ];
});

async function handleStatus(status) {
  if (!lead.value) return;
  actionLoading.value = status;
  try {
    const updated = await updateLeadStatus(lead.value.id, status);
    lead.value = updated;
    toast.success(`Lead marked ${status}.`);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update status."));
  } finally {
    actionLoading.value = null;
  }
}

async function handleRescore() {
  if (!lead.value) return;
  actionLoading.value = "rescore";
  try {
    const updated = await rescoreLead(lead.value.id);
    lead.value = updated;
    toast.success("Rescoring started — check back shortly.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not rescore."));
  } finally {
    actionLoading.value = null;
  }
}

async function handleCopy() {
  if (!lead.value?.proposal_text) return;
  await navigator.clipboard.writeText(lead.value.proposal_text);
  toast.success("Proposal copied to clipboard.");
}
</script>

<template>
  <PageContainer v-if="isLoading" class="max-w-[760px]">
    <Skeleton class="mb-4 h-5 w-32" />
    <Card class="p-6">
      <SkeletonText :lines="6" />
    </Card>
  </PageContainer>

  <PageContainer v-else-if="!lead" class="max-w-[760px]">
    <EmptyState title="Lead not found" description="It may have been removed.">
      <template #action>
        <Button @click="router.push('/leads')">Back to leads</Button>
      </template>
    </EmptyState>
  </PageContainer>

  <PageContainer v-else class="max-w-[760px]">
    <router-link
      to="/leads"
      class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-secondary hover:text-primary"
    >
      <ArrowLeft class="h-4 w-4" /> Back to leads
    </router-link>

    <Card class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-semibold text-text-primary">{{ lead.title }}</h1>
          <p class="mt-1 text-xs text-text-tertiary">
            Posted {{ relativeTime(lead.posted_at) }} · External ID {{ lead.external_id }}
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <StatusPill :status="lead.status" />
          <ScoreBadge :score="lead.score" />
        </div>
      </div>

      <a
        v-if="lead.url"
        :href="lead.url"
        target="_blank"
        rel="noreferrer"
        class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
      >
        View on Upwork <ExternalLink class="h-3.5 w-3.5" />
      </a>

      <div
        v-if="lead.matches_filter === false && lead.filter_fail_reasons"
        class="mt-4 rounded-md border border-danger-border bg-danger-bg px-4 py-3"
      >
        <p class="flex items-center gap-1.5 text-sm font-semibold text-danger">
          <AlertTriangle class="h-4 w-4" /> Why this job isn't in your filter
        </p>
        <ul class="mt-2 space-y-1">
          <li
            v-for="reason in lead.filter_fail_reasons"
            :key="reason"
            class="flex gap-1.5 text-sm text-danger/90"
          >
            <span aria-hidden="true">•</span>
            {{ reason }}
          </li>
        </ul>
      </div>

      <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div v-for="block in statBlocks" :key="block.label" class="rounded-md bg-neutral-bg p-3">
          <div class="flex items-center gap-1.5 text-xs font-medium text-text-tertiary">
            <component
              :is="block.icon"
              :class="block.tone === 'success' ? 'h-3.5 w-3.5 text-success' : 'h-3.5 w-3.5'"
            />
            {{ block.label }}
          </div>
          <p class="mt-1 text-sm font-medium text-text-primary">{{ block.value }}</p>
        </div>
      </div>

      <div class="mt-5 border-t border-border pt-5">
        <p class="text-sm font-semibold text-text-primary">Full brief</p>
        <p class="mt-2 text-sm whitespace-pre-wrap text-text-secondary">{{ lead.full_brief }}</p>
      </div>
    </Card>

    <Card v-if="lead.score !== null" class="mt-4 p-6">
      <div class="flex items-center gap-2">
        <ScoreBadge :score="lead.score" />
        <p class="text-sm font-semibold text-text-primary">Why this score</p>
      </div>
      <p class="mt-2 text-sm text-text-secondary">{{ lead.score_reason }}</p>
    </Card>

    <Card v-if="lead.proposal_text" class="mt-4 p-6">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-text-primary">Proposal</p>
        <Button variant="secondary" size="sm" @click="handleCopy">
          <Copy class="h-3.5 w-3.5" /> Copy
        </Button>
      </div>
      <p class="mt-3 rounded-md bg-neutral-bg p-4 text-sm whitespace-pre-wrap text-text-primary">
        {{ lead.proposal_text }}
      </p>
    </Card>

    <Card class="mt-4 p-6">
      <p class="mb-3 text-sm font-semibold text-text-primary">Actions</p>
      <div class="flex flex-wrap gap-2">
        <Button
          v-for="action in statusActions"
          :key="action.status"
          :variant="lead.status === action.status ? 'primary' : 'secondary'"
          size="sm"
          :disabled="lead.status === action.status || actionLoading !== null"
          :loading="actionLoading === action.status"
          @click="handleStatus(action.status)"
        >
          {{ action.label }}
        </Button>
        <Button
          v-if="auth.isAdmin"
          variant="ghost"
          size="sm"
          :disabled="actionLoading !== null"
          :loading="actionLoading === 'rescore'"
          @click="handleRescore"
        >
          <RefreshCw class="h-3.5 w-3.5" /> Rescore
        </Button>
      </div>

      <router-link
        v-if="lead.client_id"
        :to="`/clients/${lead.client_id}`"
        class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
      >
        <MessageSquare class="h-4 w-4" /> Open client memory
      </router-link>
    </Card>
  </PageContainer>
</template>
