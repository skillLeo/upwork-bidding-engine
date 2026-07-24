<script setup>
import { computed, onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { Check, Lock, Minus } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const groups = ref({});
const roles = ref([]);
const canEdit = ref(false);
const loading = ref(true);
const saving = ref(false);

// role value -> Set of granted permission names (working copy)
const draft = ref({});
// snapshot for dirty detection
let original = {};

function humanize(permission) {
  if (permission.startsWith("setting.")) {
    return permission.slice("setting.".length).replace(/_/g, " ");
  }
  const [, action] = permission.split(".");
  return (action ?? permission).replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase());
}

const dirtyRoles = computed(() =>
  roles.value
    .filter((r) => !r.locked)
    .filter((r) => {
      const a = [...(draft.value[r.value] ?? [])].sort().join(",");
      const b = [...(original[r.value] ?? [])].sort().join(",");
      return a !== b;
    })
    .map((r) => r.value),
);

function has(roleValue, permission) {
  return draft.value[roleValue]?.has(permission) ?? false;
}

function toggle(role, permission) {
  if (role.locked || !canEdit.value) return;
  const set = draft.value[role.value];
  set.has(permission) ? set.delete(permission) : set.add(permission);
  // reassign for reactivity
  draft.value = { ...draft.value, [role.value]: new Set(set) };
}

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/roles-matrix");
    groups.value = res.data.data.groups;
    roles.value = res.data.data.roles;
    canEdit.value = res.data.data.can_edit;
    draft.value = Object.fromEntries(res.data.data.roles.map((r) => [r.value, new Set(r.granted)]));
    original = Object.fromEntries(res.data.data.roles.map((r) => [r.value, new Set(r.granted)]));
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load the roles matrix."));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    for (const roleValue of dirtyRoles.value) {
      await apiClient.put(`/roles/${roleValue}/permissions`, {
        granted: [...draft.value[roleValue]],
      });
    }
    toast.success("Role permissions saved.");
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save role permissions."));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center justify-between gap-3">
        <span>Roles and permissions</span>
        <Button v-if="canEdit && dirtyRoles.length" size="sm" :loading="saving" @click="save">
          Save {{ dirtyRoles.length === 1 ? "changes" : `${dirtyRoles.length} roles` }}
        </Button>
      </CardTitle>
    </CardHeader>
    <CardContent>
      <CardDescription class="mb-4">
        <template v-if="canEdit">
          Tick or untick what each role may do — every feature and every settings key is its own
          permission. Owner is locked at full access so the workspace can always be repaired.
        </template>
        <template v-else>What each role can do. You don't have permission to edit these.</template>
      </CardDescription>

      <div v-if="loading" class="space-y-2">
        <Skeleton v-for="i in 8" :key="i" class="h-8 w-full" />
      </div>

      <div v-else class="max-h-[70vh] overflow-auto rounded-md border border-border">
        <table class="w-full min-w-[620px] border-collapse text-sm">
          <thead class="sticky top-0 z-10 bg-surface">
            <tr>
              <th class="border-b border-border py-2 pr-4 pl-3 text-left font-medium text-text-tertiary">Permission</th>
              <th
                v-for="role in roles"
                :key="role.value"
                class="border-b border-border px-3 py-2 text-center font-medium text-text-primary"
                :title="role.description"
              >
                <span class="inline-flex items-center gap-1">
                  {{ role.label }}
                  <Lock v-if="role.locked" class="h-3 w-3 text-text-tertiary" />
                </span>
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(perms, group) in groups" :key="group">
              <tr>
                <td
                  :colspan="roles.length + 1"
                  class="border-b border-border bg-surface-subtle px-3 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-text-tertiary"
                >
                  {{ group }}
                </td>
              </tr>
              <tr v-for="permission in perms" :key="permission">
                <td class="border-b border-border py-1.5 pr-4 pl-3 text-text-secondary" :title="permission">
                  {{ humanize(permission) }}
                </td>
                <td v-for="role in roles" :key="role.value" class="border-b border-border px-3 py-1.5 text-center">
                  <Check v-if="role.locked" class="mx-auto h-4 w-4 text-success/60" />
                  <input
                    v-else-if="canEdit"
                    type="checkbox"
                    :checked="has(role.value, permission)"
                    @change="toggle(role, permission)"
                    class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
                  />
                  <template v-else>
                    <Check v-if="has(role.value, permission)" class="mx-auto h-4 w-4 text-success" />
                    <Minus v-else class="mx-auto h-4 w-4 text-text-tertiary/40" />
                  </template>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <p v-if="canEdit" class="mt-3 text-xs text-text-tertiary">
        Changes apply to everyone holding the role, immediately. Per-person exceptions live on the
        Members tab ("Permissions" next to each member).
      </p>
    </CardContent>
  </Card>
</template>
