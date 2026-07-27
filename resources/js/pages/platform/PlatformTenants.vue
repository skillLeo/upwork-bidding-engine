<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { Plus, Search } from "@lucide/vue";
import PlatformShell from "@/components/platform/PlatformShell.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { relativeTime } from "@/lib/utils";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const auth = useAuthStore();
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

// ---------------------------------------------------------- new workspace
// The closed-signup door: the platform owner opens a workspace for a
// customer and the owner invitation goes out by email. This is the only
// place an owner invitation is ever minted.
const creating = ref(false);
const saving = ref(false);
const form = reactive({ name: "", slug: "", specialization_preset: "", owner_email: "" });

// Offered by the server so the dropdown and the validator can never
// disagree about which trades exist.
const presets = ref([]);

// Slug suggested from the name, but only until it is typed into by hand —
// silently rewriting somebody's deliberate slug is worse than a blank field.
const slugTouched = ref(false);
watch(
  () => form.name,
  (name) => {
    if (!slugTouched.value) {
      form.slug = name.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    }
  },
);

async function openCreate() {
  Object.assign(form, { name: "", slug: "", specialization_preset: "", owner_email: "" });
  slugTouched.value = false;
  creating.value = true;

  if (presets.value.length === 0) {
    try {
      const res = await apiClient.get("/platform/specializations");
      presets.value = res.data.data.presets;
    } catch {
      // The dropdown falls back to "let them set it up themselves", which
      // is a working workspace — not a reason to block creation.
      presets.value = [];
    }
  }
}

async function createWorkspace() {
  saving.value = true;
  try {
    const res = await apiClient.post("/platform/tenants", {
      name: form.name.trim(),
      slug: form.slug.trim(),
      specialization_preset: form.specialization_preset || null,
      owner_email: form.owner_email.trim(),
    });
    toast.success(res.data.data.message);
    creating.value = false;
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not create the workspace."));
  } finally {
    saving.value = false;
  }
}

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

      <button
        v-if="auth.isPlatformOwner"
        type="button"
        @click="openCreate"
        class="ml-auto flex h-9 items-center gap-1.5 rounded-md bg-amber-500 px-3 text-sm font-medium text-black hover:bg-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
      >
        <Plus class="h-4 w-4" /> New workspace
      </button>
    </div>

    <div v-if="creating" class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
      <h2 class="mb-1 text-sm font-semibold text-white">New workspace</h2>
      <p class="mb-4 text-xs text-white/50">
        Creates the workspace on a trial and emails its owner an invitation. They become the
        owner when they accept — nobody else can be made an owner of it afterwards.
      </p>

      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-xs font-medium text-white/70">Workspace name</label>
          <input
            v-model="form.name"
            placeholder="Northwind Design"
            class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm text-white placeholder:text-white/25 focus:border-amber-500 focus:outline-none"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-white/70">Slug</label>
          <input
            v-model="form.slug"
            @input="slugTouched = true"
            placeholder="northwind-design"
            class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-3 font-mono text-sm text-white placeholder:text-white/25 focus:border-amber-500 focus:outline-none"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-white/70">What they do</label>
          <select
            v-model="form.specialization_preset"
            class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm text-white focus:border-amber-500 focus:outline-none"
          >
            <option value="" class="bg-neutral-900">Let them set it up themselves</option>
            <option v-for="p in presets" :key="p.key" :value="p.key" class="bg-neutral-900">{{ p.label }}</option>
          </select>
          <p class="mt-1 text-xs text-white/40">
            Seeds their starting stacks, so their board scores from day one. They own these lists
            afterwards and can change every entry.
          </p>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-white/70">Owner email</label>
          <input
            v-model="form.owner_email"
            type="email"
            placeholder="owner@northwind.com"
            class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm text-white placeholder:text-white/25 focus:border-amber-500 focus:outline-none"
          />
        </div>
      </div>

      <div class="mt-4 flex items-center gap-2">
        <button
          type="button"
          :disabled="saving || !form.name || !form.slug || !form.owner_email"
          @click="createWorkspace"
          class="h-9 rounded-md bg-amber-500 px-3 text-sm font-medium text-black hover:bg-amber-400 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
        >
          {{ saving ? "Creating…" : "Create and invite owner" }}
        </button>
        <button
          type="button"
          @click="creating = false"
          class="h-9 rounded-md border border-white/10 px-3 text-sm text-white/70 hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/30"
        >
          Cancel
        </button>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-white/10">
      <table class="w-full text-sm">
        <thead class="bg-white/5 text-left text-xs tracking-wide text-white/50 uppercase">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Specialization</th>
            <th class="px-3 py-2 font-medium">Plan</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Owner</th>
            <th class="px-3 py-2 font-medium">Team</th>
            <th class="px-3 py-2 font-medium">Leads / mo</th>
            <th class="px-3 py-2 font-medium">AI spend / mo</th>
            <th class="px-3 py-2 font-medium">Last activity</th>
            <th class="px-3 py-2 font-medium">Health</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/5">
          <tr v-if="loading"><td colspan="10" class="px-3 py-6 text-center text-white/40">Loading…</td></tr>
          <tr v-else-if="!tenants.length"><td colspan="10" class="px-3 py-6 text-center text-white/40">No tenants match.</td></tr>
          <tr
            v-for="t in tenants"
            :key="t.id"
            class="cursor-pointer text-white/80 hover:bg-white/5"
            @click="router.push(`/platform/tenants/${t.id}`)"
          >
            <td class="px-3 py-2.5 font-medium text-white">{{ t.name }}<span class="ml-1.5 text-xs text-white/30">{{ t.slug }}</span></td>
            <td class="px-3 py-2.5 text-white/60">{{ t.specialization || "—" }}</td>
            <td class="px-3 py-2.5 capitalize">{{ t.plan }}</td>
            <td class="px-3 py-2.5 capitalize">{{ t.status }}</td>
            <td class="px-3 py-2.5 text-white/60">{{ t.owner_email || "—" }}</td>
            <td class="px-3 py-2.5 whitespace-nowrap">
              <span class="text-white">{{ t.members }}</span>
              <span class="ml-1.5 text-xs text-white/40">
                {{ t.roles.bidder }} bidder{{ t.roles.bidder === 1 ? "" : "s" }},
                {{ t.roles.viewer }} viewer{{ t.roles.viewer === 1 ? "" : "s" }}
              </span>
            </td>
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
