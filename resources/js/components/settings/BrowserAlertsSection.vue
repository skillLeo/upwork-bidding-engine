<script setup>
import { ref } from "vue";
import { toast } from "vue-sonner";
import { Bell, BellOff, BellRing } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import {
  leadAlerts,
  enableLeadAlerts,
  disableLeadAlerts,
  sendTestNotification,
} from "@/stores/leadAlerts";

const enabling = ref(false);
const testing = ref(false);
const blocked = ref(typeof Notification !== "undefined" && Notification.permission === "denied");

async function handleTest() {
  testing.value = true;
  try {
    const result = await sendTestNotification();
    if (result.ok) {
      toast.success("Test notification sent — check your device's notification tray.");
    } else if (result.reason === "denied") {
      blocked.value = true;
      toast.error("Notifications are blocked for this site. Re-allow them in your browser settings.");
    } else if (result.reason === "unsupported") {
      toast.error("This browser doesn't support notifications.");
    } else {
      toast.error("Notification permission was not granted.");
    }
  } finally {
    testing.value = false;
  }
}

async function handleEnable() {
  enabling.value = true;
  try {
    const ok = await enableLeadAlerts();
    if (ok) {
      toast.success("Browser alerts enabled — keep this tab open to get pinged.");
    } else {
      blocked.value = Notification.permission === "denied";
      toast.error(
        blocked.value
          ? "Notifications are blocked for this site in your browser settings."
          : "Notification permission was not granted.",
      );
    }
  } finally {
    enabling.value = false;
  }
}

function handleDisable() {
  disableLeadAlerts();
  toast.success("Browser alerts turned off.");
}
</script>

<template>
  <Card>
    <CardHeader>
      <div>
        <CardTitle>Browser alerts</CardTitle>
        <CardDescription class="mt-1">
          A WhatsApp failover: while this dashboard is open in any tab, a fresh 8+ scored lead pings
          you here too — even if WhatsApp is slow or down.
        </CardDescription>
      </div>
    </CardHeader>
    <CardContent class="space-y-4">
      <div v-if="!leadAlerts.supported" class="text-sm text-text-tertiary">
        This browser doesn't support notifications.
      </div>
      <template v-else>
        <div class="flex items-center gap-3 rounded-md border border-border p-4">
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
            :class="leadAlerts.enabled ? 'bg-success-bg text-success' : 'bg-neutral-bg text-text-tertiary'"
          >
            <BellRing v-if="leadAlerts.enabled" class="h-4 w-4" />
            <Bell v-else class="h-4 w-4" />
          </span>
          <div class="flex-1">
            <p class="text-sm font-semibold text-text-primary">
              {{ leadAlerts.enabled ? "Enabled" : "Disabled" }}
            </p>
            <p class="text-xs text-text-tertiary">
              {{
                leadAlerts.enabled
                  ? "Checking every 20 seconds while a tab is open."
                  : "Turn on to get pinged the moment a strong lead lands."
              }}
            </p>
          </div>
          <Button v-if="leadAlerts.enabled" variant="secondary" size="sm" @click="handleDisable">
            <BellOff class="h-3.5 w-3.5" /> Turn off
          </Button>
          <Button v-else :loading="enabling" size="sm" @click="handleEnable">
            <Bell class="h-3.5 w-3.5" /> Enable browser alerts
          </Button>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-neutral-bg/40 p-4">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-text-primary">Test it on this device</p>
            <p class="text-xs text-text-tertiary">
              Open this page in Chrome on your phone and tap Send — you should see a notification
              appear. That confirms alerts reach this device.
            </p>
          </div>
          <Button variant="secondary" size="sm" :loading="testing" @click="handleTest">
            <BellRing class="h-3.5 w-3.5" /> Send test notification
          </Button>
        </div>

        <p v-if="blocked" class="text-xs text-warning">
          Notifications are blocked for this site. Re-allow them in your browser's site settings, then
          try again.
        </p>

        <p class="text-xs text-text-tertiary">
          Heads-up: these fire only while the app is open in a tab on the device — they can't wake a
          fully-closed browser. For alerts that arrive even when the browser is shut, ask for true
          web-push (a bigger, separate feature).
        </p>
      </template>
    </CardContent>
  </Card>
</template>
