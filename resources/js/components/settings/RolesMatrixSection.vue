<script setup>
import { computed, onMounted, ref } from "vue";
import { Check, Minus } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient } from "@/lib/api-client";

const permissions = ref([]);
const roles = ref([]);
const loading = ref(true);

// Turn "leads.update_status" into "Update status" for the row labels.
function humanize(permission) {
  const [, action] = permission.split(".");
  return action.replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase());
}

const groups = computed(() => {
  const out = {};
  for (const p of permissions.value) {
    const [group] = p.split(".");
    (out[group] ??= []).push(p);
  }
  return out;
});

function granted(role, permission) {
  return role.granted.includes(permission);
}

onMounted(async () => {
  try {
    const res = await apiClient.get("/roles-matrix");
    permissions.value = res.data.data.permissions;
    roles.value = res.data.data.roles;
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Roles and permissions</CardTitle>
    </CardHeader>
    <CardContent>
      <CardDescription class="mb-4">
        What each role can do. These four roles are fixed — a read-only reference, not an editor.
      </CardDescription>

      <div v-if="loading" class="space-y-2">
        <Skeleton v-for="i in 6" :key="i" class="h-8 w-full" />
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[560px] border-collapse text-sm">
          <thead>
            <tr>
              <th class="border-b border-border py-2 pr-4 text-left font-medium text-text-tertiary">Permission</th>
              <th
                v-for="role in roles"
                :key="role.value"
                class="border-b border-border px-3 py-2 text-center font-medium text-text-primary"
              >
                {{ role.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(perms, group) in groups" :key="group">
              <tr>
                <td
                  :colspan="roles.length + 1"
                  class="border-b border-border bg-surface-subtle px-1 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-text-tertiary"
                >
                  {{ group }}
                </td>
              </tr>
              <tr v-for="permission in perms" :key="permission">
                <td class="border-b border-border py-2 pr-4 text-text-secondary">{{ humanize(permission) }}</td>
                <td v-for="role in roles" :key="role.value" class="border-b border-border px-3 py-2 text-center">
                  <Check v-if="granted(role, permission)" class="mx-auto h-4 w-4 text-success" />
                  <Minus v-else class="mx-auto h-4 w-4 text-text-tertiary/40" />
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </CardContent>
  </Card>
</template>
