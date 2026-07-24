<script setup>
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import PlatformShell from "@/components/platform/PlatformShell.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const router = useRouter();
const loading = ref(true);
const data = ref(null);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/platform/health");
    data.value = res.data.data;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load platform health."));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <PlatformShell title="Platform health">
    <div v-if="loading" class="text-white/40">Loading…</div>
    <div v-else-if="data" class="space-y-5">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Queue depth</p>
          <p class="mt-1 text-xl font-semibold text-white">{{ data.queue_depth }}</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Failed jobs</p>
          <p class="mt-1 text-xl font-semibold" :class="data.failed_jobs > 0 ? 'text-danger' : 'text-white'">{{ data.failed_jobs }}</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Cron last tick</p>
          <p class="mt-1 text-sm font-medium text-white">{{ data.cron_last_tick ?? "never" }}</p>
        </div>
      </div>

      <div>
        <h2 class="mb-2 text-sm font-semibold text-white">AI error rate by provider (24h)</h2>
        <div class="overflow-hidden rounded-lg border border-white/10">
          <table class="w-full text-sm">
            <thead class="bg-white/5 text-left text-xs tracking-wide text-white/50 uppercase">
              <tr><th class="px-3 py-2 font-medium">Provider</th><th class="px-3 py-2 font-medium">Calls</th><th class="px-3 py-2 font-medium">Failed</th><th class="px-3 py-2 font-medium">Error rate</th></tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-for="row in data.ai_error_rate_by_provider" :key="row.provider" class="text-white/80">
                <td class="px-3 py-2.5 capitalize">{{ row.provider }}</td>
                <td class="px-3 py-2.5">{{ row.total_calls }}</td>
                <td class="px-3 py-2.5">{{ row.failed_calls }}</td>
                <td class="px-3 py-2.5" :class="row.error_rate_pct > 10 ? 'text-danger' : ''">{{ row.error_rate_pct }}%</td>
              </tr>
              <tr v-if="!data.ai_error_rate_by_provider.length"><td colspan="4" class="px-3 py-4 text-center text-white/30">No AI calls in the last 24h.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <h2 class="mb-2 text-sm font-semibold text-white">Tenants with silent Vollna intake</h2>
        <ul class="divide-y divide-white/5 overflow-hidden rounded-lg border border-white/10">
          <li
            v-for="t in data.vollna_silent_tenants"
            :key="t.id"
            class="cursor-pointer px-3 py-2.5 text-sm text-white/80 hover:bg-white/5"
            @click="router.push(`/platform/tenants/${t.id}`)"
          >
            {{ t.name }} <span class="text-xs text-white/30">{{ t.slug }}</span>
          </li>
          <li v-if="!data.vollna_silent_tenants.length" class="px-3 py-4 text-center text-sm text-white/30">Every tenant's intake looks healthy.</li>
        </ul>
      </div>
    </div>
  </PlatformShell>
</template>
