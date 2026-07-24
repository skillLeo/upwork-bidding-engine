<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { BellRing } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const loading = ref(true);
const saving = ref(false);
const prefs = ref(null);
const useQuietHours = ref(false);

const HOURS = Array.from({ length: 24 }, (_, i) => i);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/profile/notification-preferences");
    prefs.value = res.data.data;
    useQuietHours.value = prefs.value.quiet_hours_start !== null && prefs.value.quiet_hours_end !== null;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load notification preferences."));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      ...prefs.value,
      quiet_hours_start: useQuietHours.value ? prefs.value.quiet_hours_start ?? 22 : null,
      quiet_hours_end: useQuietHours.value ? prefs.value.quiet_hours_end ?? 7 : null,
    };
    const res = await apiClient.put("/profile/notification-preferences", payload);
    prefs.value = res.data.data;
    toast.success("Notification preferences saved.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save notification preferences."));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <BellRing class="h-4 w-4 text-primary" /> Notification preferences
      </CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <div v-if="loading" class="space-y-2">
        <Skeleton v-for="i in 4" :key="i" class="h-8 w-full" />
      </div>
      <template v-else>
        <CardDescription>Which events reach you, on which channel, and when to stay quiet.</CardDescription>

        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-md border border-border p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-text-tertiary uppercase">Email me</p>
            <label class="mb-1.5 flex items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" v-model="prefs.email_on_new_lead" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20" />
              A new lead is ready
            </label>
            <label class="flex items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" v-model="prefs.email_on_reminder" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20" />
              A follow-up reminder is due
            </label>
          </div>
          <div class="rounded-md border border-border p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-text-tertiary uppercase">Push me</p>
            <label class="mb-1.5 flex items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" v-model="prefs.push_on_new_lead" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20" />
              A new lead is ready
            </label>
            <label class="flex items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" v-model="prefs.push_on_reminder" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20" />
              A follow-up reminder is due
            </label>
          </div>
        </div>

        <div class="rounded-md border border-border p-3">
          <label class="flex items-center gap-2 text-sm font-medium text-text-primary">
            <input type="checkbox" v-model="useQuietHours" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20" />
            Quiet hours — pause email and push during a window each day
          </label>
          <div v-if="useQuietHours" class="mt-3 flex items-center gap-2 text-sm text-text-secondary">
            From
            <select v-model.number="prefs.quiet_hours_start" class="h-8 rounded-md border border-border-strong bg-white px-2 text-sm focus:border-primary focus:outline-none">
              <option v-for="h in HOURS" :key="h" :value="h">{{ String(h).padStart(2, "0") }}:00</option>
            </select>
            to
            <select v-model.number="prefs.quiet_hours_end" class="h-8 rounded-md border border-border-strong bg-white px-2 text-sm focus:border-primary focus:outline-none">
              <option v-for="h in HOURS" :key="h" :value="h">{{ String(h).padStart(2, "0") }}:00</option>
            </select>
          </div>
        </div>

        <div class="flex justify-end">
          <Button @click="save" :loading="saving">Save preferences</Button>
        </div>
      </template>
    </CardContent>
  </Card>
</template>
