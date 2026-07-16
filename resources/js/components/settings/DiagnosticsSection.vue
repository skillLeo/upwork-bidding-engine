<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { CheckCircle2, XCircle, AlertTriangle, RefreshCw } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import { apiClient } from "@/lib/api-client";
import { relativeTime } from "@/lib/utils";

const POLL_INTERVAL_MS = 15_000;

const data = ref(null);
const loading = ref(true);

async function fetchDiagnostics() {
  try {
    const res = await apiClient.get("/diagnostics");
    data.value = res.data.data;
  } catch {
    // A failed diagnostics fetch shouldn't itself crash the panel - the
    // last-known values just stay on screen until the next poll succeeds.
  } finally {
    loading.value = false;
  }
}

let pollTimer;
onMounted(() => {
  fetchDiagnostics();
  pollTimer = setInterval(fetchDiagnostics, POLL_INTERVAL_MS);
});
onUnmounted(() => clearInterval(pollTimer));
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Diagnostics</CardTitle>
      <Button variant="secondary" size="sm" :loading="loading" @click="fetchDiagnostics">
        <RefreshCw class="h-3.5 w-3.5" /> Refresh
      </Button>
    </CardHeader>
    <CardContent v-if="data" class="space-y-4">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-md bg-neutral-bg p-3">
          <p class="text-xs text-text-tertiary">Queue depth</p>
          <p class="mt-1 text-lg font-semibold text-text-primary">{{ data.queue_depth }}</p>
        </div>
        <div class="rounded-md bg-neutral-bg p-3">
          <p class="text-xs text-text-tertiary">Failed jobs</p>
          <p
            class="mt-1 text-lg font-semibold"
            :class="data.failed_jobs > 0 ? 'text-danger' : 'text-text-primary'"
          >
            {{ data.failed_jobs }}
          </p>
        </div>
        <div class="rounded-md bg-neutral-bg p-3">
          <p class="text-xs text-text-tertiary">AI engine</p>
          <p class="mt-1 flex items-center gap-1.5 text-sm font-semibold">
            <CheckCircle2 v-if="data.ai_engine_enabled" class="h-4 w-4 text-success" />
            <XCircle v-else class="h-4 w-4 text-danger" />
            {{ data.ai_engine_enabled ? "On" : "Off" }}
          </p>
        </div>
        <div class="rounded-md bg-neutral-bg p-3">
          <p class="text-xs text-text-tertiary">OpenClaw</p>
          <p class="mt-1 flex items-center gap-1.5 text-sm font-semibold">
            <CheckCircle2 v-if="data.openclaw_online" class="h-4 w-4 text-success" />
            <XCircle v-else class="h-4 w-4 text-danger" />
            {{ data.openclaw_online ? "Online" : "Offline" }}
          </p>
        </div>
      </div>

      <div class="space-y-1.5 text-sm">
        <p class="text-text-secondary">
          Last successful score:
          <span class="font-medium text-text-primary">
            {{ data.last_scored_at ? relativeTime(data.last_scored_at) : "never" }}
          </span>
        </p>
        <p class="text-text-secondary">
          Last webhook received from Vollna:
          <span class="font-medium text-text-primary">
            {{ data.last_webhook_received_at ? relativeTime(data.last_webhook_received_at) : "never" }}
          </span>
        </p>
        <p v-if="data.last_webhook_rejected" class="flex items-start gap-1.5 text-danger">
          <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
          Last webhook rejected ({{ data.last_webhook_rejected.reason }}) —
          {{ relativeTime(data.last_webhook_rejected.at) }}
        </p>
        <p v-if="data.last_error" class="flex items-start gap-1.5 text-danger">
          <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
          {{ data.last_error.message || data.last_error.type }} —
          {{ relativeTime(data.last_error.at) }}
        </p>
      </div>
    </CardContent>
  </Card>
</template>
