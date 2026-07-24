<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { Building2, LogOut } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const loading = ref(true);
const workspaces = ref([]);
const busyId = ref(null);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/profile/workspaces");
    workspaces.value = res.data.data.workspaces;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load your workspaces."));
  } finally {
    loading.value = false;
  }
}

async function leave(ws) {
  if (!confirm(`Leave ${ws.name}? You'll lose access immediately.`)) return;
  busyId.value = ws.id;
  try {
    await apiClient.delete(`/profile/workspaces/${ws.id}`);
    toast.success(`Left ${ws.name}.`);
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not leave that workspace."));
  } finally {
    busyId.value = null;
  }
}

onMounted(load);
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <Building2 class="h-4 w-4 text-primary" /> My workspaces
      </CardTitle>
    </CardHeader>
    <CardContent class="p-0">
      <div v-if="loading" class="space-y-2 p-5">
        <Skeleton v-for="i in 2" :key="i" class="h-12 w-full" />
      </div>
      <template v-else>
        <CardDescription class="px-5 pt-4 pb-1">Every workspace your account belongs to.</CardDescription>
        <ul class="divide-y divide-border">
          <li v-for="ws in workspaces" :key="ws.id" class="flex items-center justify-between gap-3 px-5 py-3">
            <div class="min-w-0">
              <p class="flex items-center gap-2 truncate text-sm font-medium text-text-primary">
                {{ ws.name }}
                <span v-if="ws.is_current" class="rounded-pill bg-primary-tint px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary uppercase">Current</span>
              </p>
              <p class="truncate text-xs text-text-tertiary capitalize">{{ ws.role }}<span v-if="ws.is_owner"> · owner</span></p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <a
                v-if="ws.switch_url && !ws.is_current"
                :href="ws.switch_url"
                class="rounded-pill border border-border-strong px-3 py-1.5 text-xs font-medium text-text-secondary hover:bg-black/5"
              >
                Switch
              </a>
              <Button
                v-if="!ws.is_owner"
                variant="ghost"
                size="sm"
                :loading="busyId === ws.id"
                @click="leave(ws)"
              >
                <LogOut class="h-3.5 w-3.5" /> Leave
              </Button>
            </div>
          </li>
        </ul>
      </template>
    </CardContent>
  </Card>
</template>
