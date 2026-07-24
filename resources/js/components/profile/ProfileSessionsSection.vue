<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { Monitor } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { relativeTime } from "@/lib/utils";

const sessions = ref([]);
const events = ref([]);
const loading = ref(true);
const busyId = ref(null);
const revokingOthers = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/profile/sessions");
    sessions.value = res.data.data.sessions;
    events.value = res.data.data.events;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load your sessions."));
  } finally {
    loading.value = false;
  }
}

async function signOut(session) {
  busyId.value = session.id;
  try {
    await apiClient.delete(`/profile/sessions/${session.id}`);
    sessions.value = sessions.value.filter((s) => s.id !== session.id);
    toast.success(`Signed ${session.device_label} out.`);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not sign that device out."));
  } finally {
    busyId.value = null;
  }
}

async function signOutOthers() {
  revokingOthers.value = true;
  try {
    const res = await apiClient.delete("/profile/sessions/others");
    toast.success(res.data.data.message);
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not sign the other devices out."));
  } finally {
    revokingOthers.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <Monitor class="h-4 w-4 text-primary" /> Devices and sessions
      </CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <CardDescription>
        Every device currently signed in to your account. Sign out anything you don't recognise,
        then change your password.
      </CardDescription>

      <div v-if="loading" class="space-y-2">
        <Skeleton v-for="i in 2" :key="i" class="h-14 w-full" />
      </div>

      <template v-else>
        <ul class="divide-y divide-border rounded-md border border-border">
          <li
            v-for="session in sessions"
            :key="session.id"
            class="flex items-center justify-between gap-3 px-4 py-3"
          >
            <div class="min-w-0">
              <p class="flex items-center gap-2 text-sm font-medium text-text-primary">
                <span class="truncate">{{ session.device_label }}</span>
                <span
                  v-if="session.is_current"
                  class="shrink-0 rounded-pill bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                >
                  This device
                </span>
              </p>
              <p class="mt-0.5 truncate text-xs text-text-tertiary">
                <!-- Location is absent until a GeoLite2 database is installed;
                     showing the IP alone is still useful, so never render an
                     empty separator. -->
                <span v-if="session.approx_location">{{ session.approx_location }} · </span>
                <span v-if="session.ip_address">{{ session.ip_address }} · </span>
                active {{ relativeTime(session.last_used_at) }}
              </p>
            </div>
            <Button
              v-if="!session.is_current"
              type="button"
              variant="secondary"
              size="sm"
              :loading="busyId === session.id"
              @click="signOut(session)"
            >
              Sign out
            </Button>
          </li>
        </ul>

        <div v-if="sessions.length > 1" class="flex justify-end">
          <Button type="button" variant="secondary" size="sm" :loading="revokingOthers" @click="signOutOthers">
            Sign out everywhere else
          </Button>
        </div>

        <div v-if="events.length" class="pt-2">
          <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-text-tertiary">
            Recent account activity
          </p>
          <ul class="divide-y divide-border rounded-md border border-border">
            <li
              v-for="event in events"
              :key="event.id"
              class="flex items-center justify-between gap-3 px-4 py-2"
            >
              <span class="truncate text-sm text-text-secondary">
                {{ event.label }}
                <span v-if="event.approx_location" class="text-text-tertiary">
                  — {{ event.approx_location }}
                </span>
              </span>
              <span class="shrink-0 text-xs text-text-tertiary">{{ relativeTime(event.created_at) }}</span>
            </li>
          </ul>
          <p class="mt-2 text-xs text-text-tertiary">
            Read-only, and kept permanently — this record can't be edited or deleted from anywhere
            in the app.
          </p>
        </div>
      </template>
    </CardContent>
  </Card>
</template>
