<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { Activity, RefreshCw } from "@lucide/vue";
import PageContainer from "@/components/layout/PageContainer.vue";
import Card from "@/components/ui/Card.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { relativeTime } from "@/lib/utils";
import { toast } from "vue-sonner";

const data = ref(null);
const loading = ref(true);
const refreshing = ref(false);
let timer = null;

async function load({ silent = false } = {}) {
  if (!silent) refreshing.value = true;
  try {
    const res = await apiClient.get("/diagnostics");
    data.value = res.data.data;
  } catch (error) {
    if (!silent) toast.error(apiErrorMessage(error, "Could not load health data."));
  } finally {
    loading.value = false;
    refreshing.value = false;
  }
}

onMounted(() => {
  load({ silent: true });
  timer = setInterval(() => load({ silent: true }), 30_000);
});
onUnmounted(() => clearInterval(timer));

const vollnaHealthy = computed(() => {
  if (!data.value?.vollna_last_webhook_at) return false;
  const ageHours = (Date.now() - new Date(data.value.vollna_last_webhook_at).getTime()) / 3_600_000;
  return ageHours < (data.value.vollna_silence_threshold_hours ?? 6);
});

const cronHealthy = computed(() => {
  if (!data.value?.cron_last_tick) return false;
  return Date.now() - new Date(data.value.cron_last_tick).getTime() < 3 * 60_000;
});

const services = computed(() => {
  if (!data.value) return [];
  return [
    {
      name: "Scheduler (cron)",
      healthy: cronHealthy.value,
      detail: data.value.cron_last_tick
        ? cronHealthy.value
          ? `last tick ${relativeTime(data.value.cron_last_tick)}`
          : `DEAD — last tick ${relativeTime(data.value.cron_last_tick)}. Queue, health checks and alerts are all stopped. Re-enable the schedule:run cron job in Hostinger hPanel.`
        : "never ticked — the schedule:run cron job is not running on the server",
    },
    {
      name: "Vollna webhook",
      healthy: vollnaHealthy.value,
      detail: data.value.vollna_last_webhook_at
        ? `last delivery ${relativeTime(data.value.vollna_last_webhook_at)}`
        : "no delivery recorded yet",
    },
    {
      name: "OpenClaw",
      healthy: data.value.openclaw_online,
      detail: data.value.openclaw_online ? "reachable" : "unreachable — leads will queue",
    },
    {
      name: "WhatsApp",
      healthy: data.value.whatsapp?.connected ?? false,
      detail: data.value.whatsapp?.connected
        ? `connected (${data.value.whatsapp.number ?? "linked"})`
        : `not connected (${data.value.whatsapp?.health ?? "unknown"})`,
    },
    {
      name: "AI engine",
      healthy: data.value.ai_engine_enabled,
      detail: data.value.ai_engine_enabled ? "enabled" : "paused via Settings toggle",
    },
  ];
});

const stats = computed(() => {
  if (!data.value) return [];
  return [
    { label: "Leads today", value: data.value.leads_today ?? 0 },
    { label: "Scored today", value: data.value.leads_scored_today ?? 0 },
    {
      label: "Avg scoring time",
      value: data.value.avg_scoring_ms != null ? `${(data.value.avg_scoring_ms / 1000).toFixed(1)}s` : "—",
    },
    { label: "Queued jobs", value: data.value.queue_depth ?? 0 },
    { label: "Failed jobs", value: data.value.failed_jobs ?? 0 },
  ];
});
</script>

<template>
  <PageContainer>
    <div class="mb-5 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <Activity class="h-5 w-5 text-primary" />
        <h1 class="text-xl font-semibold text-text-primary">System health</h1>
      </div>
      <Button variant="secondary" size="sm" :loading="refreshing" @click="load()">
        <RefreshCw class="h-4 w-4" /> Refresh
      </Button>
    </div>

    <div v-if="loading" class="space-y-3">
      <Skeleton v-for="i in 4" :key="i" class="h-14 w-full" />
    </div>

    <template v-else-if="data">
      <Card class="divide-y divide-border">
        <div v-for="service in services" :key="service.name" class="flex items-center gap-3 px-5 py-3.5">
          <span
            class="h-2.5 w-2.5 shrink-0 rounded-full"
            :class="service.healthy ? 'bg-success' : 'bg-danger'"
          />
          <span class="w-36 shrink-0 text-sm font-medium text-text-primary">{{ service.name }}</span>
          <span class="text-sm text-text-secondary">{{ service.detail }}</span>
        </div>
      </Card>

      <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <Card v-for="stat in stats" :key="stat.label" class="px-4 py-3">
          <p class="text-xs font-medium text-text-tertiary">{{ stat.label }}</p>
          <p class="mt-1 text-lg font-semibold text-text-primary">{{ stat.value }}</p>
        </Card>
      </div>

      <Card v-if="data.last_error" class="mt-4 px-5 py-3.5">
        <p class="text-xs font-medium text-text-tertiary">Last error</p>
        <p class="mt-1 text-sm text-text-secondary">
          {{ data.last_error.type }} — {{ data.last_error.message }}
          <span class="text-text-tertiary">({{ relativeTime(data.last_error.at) }})</span>
        </p>
      </Card>

      <p class="mt-4 text-xs text-text-tertiary">
        The server checks OpenClaw and WhatsApp every 5 minutes and the Vollna webhook hourly; you
        get one WhatsApp alert per outage (email if WhatsApp itself is down). This page refreshes
        every 30 seconds.
      </p>
    </template>
  </PageContainer>
</template>
