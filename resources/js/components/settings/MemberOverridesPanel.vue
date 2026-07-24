<script setup>
import { computed, onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

/**
 * Per-person permission exceptions: for each permission, Inherit (whatever
 * the role says), Grant (add it just for this person) or Deny (take it away
 * just for this person — a deny beats the role).
 */
const props = defineProps({
  member: { type: Object, required: true },
});
const emit = defineEmits(["close"]);

const loading = ref(true);
const saving = ref(false);
const fromRole = ref(new Set());
const state = ref({}); // permission -> "inherit" | "grant" | "deny"
const allPermissions = ref([]);
const search = ref("");

function labelOf(p) {
  return p.startsWith("setting.") ? "setting: " + p.slice(8).replace(/_/g, " ") : p;
}

const visible = computed(() => {
  const q = search.value.trim().toLowerCase();
  const list = q ? allPermissions.value.filter((p) => p.includes(q)) : allPermissions.value;
  // Overridden ones first so existing exceptions are always in view.
  return [...list].sort((a, b) => {
    const ao = state.value[a] !== "inherit" ? 0 : 1;
    const bo = state.value[b] !== "inherit" ? 0 : 1;
    return ao - bo || a.localeCompare(b);
  });
});

const overrideCount = computed(
  () => Object.values(state.value).filter((v) => v !== "inherit").length,
);

async function load() {
  loading.value = true;
  try {
    const [matrix, overrides] = await Promise.all([
      apiClient.get("/roles-matrix"),
      apiClient.get(`/members/${props.member.id}/overrides`),
    ]);
    allPermissions.value = Object.values(matrix.data.data.groups).flat();
    const d = overrides.data.data;
    fromRole.value = new Set(d.from_role);
    const next = {};
    for (const p of allPermissions.value) next[p] = "inherit";
    for (const p of d.grants) next[p] = "grant";
    for (const p of d.denies) next[p] = "deny";
    state.value = next;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load overrides."));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const grants = Object.entries(state.value).filter(([, v]) => v === "grant").map(([p]) => p);
    const denies = Object.entries(state.value).filter(([, v]) => v === "deny").map(([p]) => p);
    const res = await apiClient.put(`/members/${props.member.id}/overrides`, { grants, denies });
    toast.success(res.data.data.message);
    emit("close");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save overrides."));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="rounded-md border border-border bg-surface-subtle p-4">
    <div class="mb-3 flex items-center justify-between gap-3">
      <div>
        <p class="text-sm font-semibold text-text-primary">
          Personal permissions — {{ member.name }}
        </p>
        <p class="text-xs text-text-tertiary">
          Inherit follows the {{ member.role }} role. Grant adds just for them; Deny removes just
          for them (deny always wins).
          <span v-if="overrideCount"> {{ overrideCount }} exception(s) set.</span>
        </p>
      </div>
      <div class="flex shrink-0 gap-2">
        <Button variant="secondary" size="sm" @click="emit('close')">Cancel</Button>
        <Button size="sm" :loading="saving" @click="save">Save</Button>
      </div>
    </div>

    <div v-if="loading" class="space-y-2">
      <Skeleton v-for="i in 4" :key="i" class="h-8 w-full" />
    </div>

    <template v-else>
      <Input v-model="search" type="text" placeholder="Search permissions… (e.g. rewrite, anthropic, score)" class="mb-2" />

      <ul class="max-h-72 divide-y divide-border overflow-y-auto rounded-md border border-border bg-white">
        <li v-for="p in visible" :key="p" class="flex items-center justify-between gap-3 px-3 py-1.5">
          <span class="min-w-0 truncate text-xs text-text-secondary" :title="p">
            {{ labelOf(p) }}
            <span v-if="fromRole.has(p)" class="text-success">· role ✓</span>
          </span>
          <select
            :value="state[p]"
            @change="state[p] = $event.target.value"
            :class="[
              'h-7 shrink-0 rounded-md border px-1.5 text-xs font-medium focus:outline-none',
              state[p] === 'grant'
                ? 'border-success/40 bg-success/10 text-success'
                : state[p] === 'deny'
                  ? 'border-danger/40 bg-danger/10 text-danger'
                  : 'border-border-strong bg-white text-text-tertiary',
            ]"
          >
            <option value="inherit">Inherit</option>
            <option value="grant">Grant</option>
            <option value="deny">Deny</option>
          </select>
        </li>
      </ul>
    </template>
  </div>
</template>
