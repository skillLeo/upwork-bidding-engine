<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { Search } from "@lucide/vue";
import PlatformShell from "@/components/platform/PlatformShell.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { relativeTime } from "@/lib/utils";

const router = useRouter();
const loading = ref(true);
const tenants = ref([]);
const search = ref("");
const plan = ref("");
const status = ref("");

let debounceTimer = null;

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/platform/tenants", {
      params: { search: search.value || undefined, plan: plan.value || undefined, status: status.value || undefined },
    });
    tenants.value = res.data.data.tenants;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load tenants."));
  } finally {
    loading.value = false;
  }
}

watch([search, plan, status], () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 250);
});

const healthDot = (status) => ({
  ok: "bg-success",
  attention: "bg-warning",
  billing_blocked: "bg-danger",
}[status] ?? "bg-white/30");

onMounted(load);
</script>

<template>
  <PlatformShell title="Tenants">
    <div class="mb-4 flex flex-wrap gap-3">
      <div class="relative">
        <Search class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
        <input
          v-model="search"
          placeholder="Search name or slug…"
          class="h-9 w-64 rounded-md border border-white/10 bg-white/5 pl-8 pr-3 text-sm text-white placeholder:text-white/30 focus:border-amber-500 focus:outline-none"
        />
      </div>
      <select v-model="plan" class="h-9 rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
        <option value="">All plans</option>
        <option value="trial">Trial</option>
        <option value="internal">Internal</option>
      </select>
      <select v-model="status" class="h-9 rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
        <option value="">All statuses</option>
        <option value="trialing">Trialing</option>
        <option value="active">Active</option>
        <option value="past_due">Past due</option>
        <option value="suspended">Suspended</option>
      </select>
    </div>

    <div class="overflow-hidden rounded-lg border border-white/10">
      <table class="w-full text-sm">
        <thead class="bg-white/5 text-left text-xs tracking-wide text-white/50 uppercase">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Plan</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Members</th>
            <th class="px-3 py-2 font-medium">Leads / mo</th>
            <th class="px-3 py-2 font-medium">AI spend / mo</th>
            <th class="px-3 py-2 font-medium">Last activity</th>
            <th class="px-3 py-2 font-medium">Health</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/5">
          <tr v-if="loading"><td colspan="8" class="px-3 py-6 text-center text-white/40">Loading…</td></tr>
          <tr v-else-if="!tenants.length"><td colspan="8" class="px-3 py-6 text-center text-white/40">No tenants match.</td></tr>
          <tr
            v-for="t in tenants"
            :key="t.id"
            class="cursor-pointer text-white/80 hover:bg-white/5"
            @click="router.push(`/platform/tenants/${t.id}`)"
          >
            <td class="px-3 py-2.5 font-medium text-white">{{ t.name }}<span class="ml-1.5 text-xs text-white/30">{{ t.slug }}</span></td>
            <td class="px-3 py-2.5 capitalize">{{ t.plan }}</td>
            <td class="px-3 py-2.5 capitalize">{{ t.status }}</td>
            <td class="px-3 py-2.5">{{ t.members }}</td>
            <td class="px-3 py-2.5">{{ t.leads_this_month }}</td>
            <td class="px-3 py-2.5">${{ t.ai_spend_this_month.toFixed(2) }}</td>
            <td class="px-3 py-2.5 text-white/50">{{ t.last_activity_at ? relativeTime(t.last_activity_at) : "—" }}</td>
            <td class="px-3 py-2.5"><span :class="['inline-block h-2.5 w-2.5 rounded-full', healthDot(t.health)]" :title="t.health" /></td>
          </tr>
        </tbody>
      </table>
    </div>
  </PlatformShell>
</template>
