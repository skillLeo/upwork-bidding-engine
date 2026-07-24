<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { Eye } from "@lucide/vue";
import PlatformShell from "@/components/platform/PlatformShell.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import { relativeTime } from "@/lib/utils";

const props = defineProps({ id: { type: [String, Number], required: true } });
const auth = useAuthStore();

const loading = ref(true);
const data = ref(null);
const reasonFor = ref(null); // member id currently showing the reason prompt
const reason = ref("");
const impersonating = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get(`/platform/tenants/${props.id}`);
    data.value = res.data.data;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load this tenant."));
  } finally {
    loading.value = false;
  }
}

async function startImpersonation(member) {
  if (!reason.value.trim()) return;
  impersonating.value = true;
  try {
    const res = await apiClient.post(`/platform/tenants/${props.id}/users/${member.id}/impersonate`, {
      reason: reason.value.trim(),
    });
    auth.startImpersonation(res.data.data.token, res.data.data.user, res.data.data.reason, res.data.data.expires_at);
    toast.success(`Viewing as ${member.name}.`);
    window.location.href = "/leads";
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not start impersonation."));
  } finally {
    impersonating.value = false;
    reasonFor.value = null;
    reason.value = "";
  }
}

const healthLabel = (h) => ({ ok: "Healthy", attention: "Needs attention", billing_blocked: "Billing blocked" }[h?.status] ?? "Unknown");

onMounted(load);
</script>

<template>
  <PlatformShell :title="data ? data.tenant.name : 'Tenant'">
    <div v-if="loading" class="text-white/40">Loading…</div>
    <div v-else-if="data" class="space-y-5">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Plan</p>
          <p class="mt-1 text-sm font-medium text-white capitalize">{{ data.tenant.plan }}</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Status</p>
          <p class="mt-1 text-sm font-medium text-white capitalize">{{ data.tenant.status }}</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Leads this month</p>
          <p class="mt-1 text-sm font-medium text-white">{{ data.leads_this_month }}</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-white/5 p-3">
          <p class="text-xs text-white/40">Health</p>
          <p class="mt-1 text-sm font-medium text-white">{{ healthLabel(data.health) }}</p>
        </div>
      </div>
      <p v-if="data.tenant.deleted_at" class="rounded-md border border-danger/40 bg-danger-bg/10 px-3 py-2 text-sm text-danger">
        This workspace was deleted {{ relativeTime(data.tenant.deleted_at) }}.
      </p>

      <div>
        <h2 class="mb-2 text-sm font-semibold text-white">Members</h2>
        <div class="overflow-hidden rounded-lg border border-white/10">
          <table class="w-full text-sm">
            <thead class="bg-white/5 text-left text-xs tracking-wide text-white/50 uppercase">
              <tr><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Role</th><th class="px-3 py-2 font-medium"></th></tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-for="m in data.members" :key="m.id" class="text-white/80">
                <td class="px-3 py-2.5">{{ m.name }} <span class="text-xs text-white/30">{{ m.email }}</span></td>
                <td class="px-3 py-2.5 capitalize">{{ m.role }}<span v-if="m.is_owner" class="ml-1 text-xs text-amber-400">owner</span></td>
                <td class="px-3 py-2.5 text-right">
                  <template v-if="auth.user?.platform_role === 'platform_owner'">
                    <div v-if="reasonFor === m.id" class="flex items-center justify-end gap-2">
                      <input
                        v-model="reason"
                        placeholder="Reason (required)"
                        class="h-7 w-48 rounded border border-white/10 bg-white/5 px-2 text-xs text-white placeholder:text-white/30 focus:border-amber-500 focus:outline-none"
                      />
                      <button
                        type="button"
                        :disabled="!reason.trim() || impersonating"
                        @click="startImpersonation(m)"
                        class="rounded bg-amber-500 px-2 py-1 text-xs font-semibold text-black disabled:opacity-50"
                      >
                        Go
                      </button>
                      <button type="button" @click="reasonFor = null" class="text-xs text-white/40 hover:text-white">Cancel</button>
                    </div>
                    <button
                      v-else
                      type="button"
                      @click="reasonFor = m.id"
                      class="flex items-center gap-1 rounded-pill border border-white/15 px-2.5 py-1 text-xs text-white/70 hover:bg-white/5 hover:text-white"
                    >
                      <Eye class="h-3 w-3" /> Impersonate
                    </button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <h2 class="mb-2 text-sm font-semibold text-white">Usage — last 6 months</h2>
        <div class="overflow-hidden rounded-lg border border-white/10">
          <table class="w-full text-sm">
            <thead class="bg-white/5 text-left text-xs tracking-wide text-white/50 uppercase">
              <tr><th class="px-3 py-2 font-medium">Month</th><th class="px-3 py-2 font-medium">Calls</th><th class="px-3 py-2 font-medium">Tokens</th><th class="px-3 py-2 font-medium">Cost</th></tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-for="row in data.usage_over_time" :key="row.month" class="text-white/80">
                <td class="px-3 py-2.5">{{ row.month }}</td>
                <td class="px-3 py-2.5">{{ row.calls }}</td>
                <td class="px-3 py-2.5">{{ row.tokens.toLocaleString() }}</td>
                <td class="px-3 py-2.5">${{ row.cost_usd.toFixed(2) }}</td>
              </tr>
              <tr v-if="!data.usage_over_time.length"><td colspan="4" class="px-3 py-4 text-center text-white/30">No AI usage yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <h2 class="mb-2 text-sm font-semibold text-white">Recent auth events</h2>
        <ul class="divide-y divide-white/5 overflow-hidden rounded-lg border border-white/10">
          <li v-for="e in data.recent_auth_events" :key="e.created_at + e.event" class="flex items-center justify-between px-3 py-2 text-sm text-white/70">
            <span>{{ e.label }}</span>
            <span class="text-xs text-white/30">{{ e.approx_location ?? e.ip }} · {{ relativeTime(e.created_at) }}</span>
          </li>
          <li v-if="!data.recent_auth_events.length" class="px-3 py-4 text-center text-sm text-white/30">No recent activity.</li>
        </ul>
      </div>
    </div>
  </PlatformShell>
</template>
